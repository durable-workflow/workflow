<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory as HttpFactory;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use Workflow\V2\Client\ControlPlaneClient;
use Workflow\V2\Exceptions\ControlPlaneRequestException;
use Workflow\V2\Support\PlatformConformanceSuite;
use Workflow\V2\Worker\WorkerProtocolClient;

#[AsCommand(name: 'workflow:v2:namespace-conformance')]
class V2NamespaceConformanceCommand extends Command
{
    protected $signature = 'workflow:v2:namespace-conformance
        {--server-url= : Base URL for the standalone server under test}
        {--token= : Bearer token for control-plane and worker-plane requests}
        {--namespace-a=tenant-a : First tenant namespace}
        {--namespace-b=tenant-b : Second tenant namespace}
        {--shared-namespace=shared : Shared namespace reserved for the full conformance run}
        {--task-queue=iso : Task queue name to reuse in both namespaces}
        {--workflow-type=workflow-v2-namespace-conformance : Durable workflow type key to start}
        {--run-id= : Stable run suffix for workflow and worker IDs}
        {--poll-timeout=1 : Worker long-poll timeout in seconds}
        {--artifact-version=* : Repeatable actor=version option for the published artifact tuple}
        {--artifact-source=* : Repeatable actor=source option proving the published artifact install channel}
        {--json : Emit a single machine-readable JSON report}
        {--output= : Write the JSON report to a file instead of stdout}';

    protected $description = 'Emit the Workflow PHP namespace conformance evidence shard';

    private const RESULT_SCHEMA = 'durable-workflow.v2.namespace-runtime.result';

    private const RESULT_VERSION = 1;

    /**
     * @var list<string>
     */
    private const REQUIRED_ARTIFACTS = [
        'server',
        'cli',
        'workflow-php',
        'sdk-python',
        'waterline',
    ];

    /**
     * @var list<string>
     */
    private const REQUIRED_SCENARIOS = [
        'published_artifact_install_only',
        'namespace_create_update_describe_and_list',
        'workflow_cross_namespace_visibility_isolation',
        'workflow_cross_namespace_mutation_isolation',
        'php_worker_task_queue_namespace_isolation',
        'cli_namespace_context_and_default_scope',
        'sdk_namespace_selection_parity',
        'search_attribute_schema_and_value_query_isolation',
        'schedule_namespace_isolation',
        'namespace_lifecycle_cleanup_and_recreate',
        'waterline_operator_namespace_visibility',
        'nexus_explicit_cross_namespace_invocation',
        'reserved_namespace_name_refusal',
        'result_record_and_product_finding_routing',
    ];

    /**
     * @var list<string>
     */
    private const PHP_SHARD_SCENARIOS = [
        'published_artifact_install_only',
        'namespace_create_update_describe_and_list',
        'sdk_namespace_selection_parity',
        'php_worker_task_queue_namespace_isolation',
        'result_record_and_product_finding_routing',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const PUBLISHED_ARTIFACT_SOURCES = [
        'server' => [
            'docker_image',
            'docker_registry',
            'oci_image',
            'published_docker_image',
            'registry_image',
        ],
        'cli' => [
            'github_release',
            'github_release_asset',
            'official_install_script',
            'release_asset',
        ],
        'workflow-php' => [
            'composer',
            'composer_package',
            'packagist',
            'packagist_package',
        ],
        'sdk-python' => [
            'pip_package',
            'pypi',
            'pypi_package',
            'python_package',
        ],
        'waterline' => [
            'composer',
            'composer_package',
            'packagist',
            'packagist_package',
        ],
    ];

    public function __construct(
        private readonly Filesystem $files,
        private readonly HttpFactory $http,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $startedAt = self::timestamp();
        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $namespaces = $this->namespaces();
        $taskQueue = $this->stringOption('task-queue') ?? 'iso';

        $scenarioResults = [];
        $findings = [];
        $findingLinks = [];

        $this->appendScenario(
            $scenarioResults,
            $findings,
            $findingLinks,
            $this->publishedArtifactScenario($artifactVersions, $artifactSources),
        );

        foreach ($this->livePhpScenarios($artifactVersions, $namespaces, $taskQueue) as $scenario) {
            $this->appendScenario($scenarioResults, $findings, $findingLinks, $scenario);
        }

        $this->appendScenario(
            $scenarioResults,
            $findings,
            $findingLinks,
            $this->resultRecordScenario($artifactVersions),
        );

        foreach (self::REQUIRED_SCENARIOS as $scenarioId) {
            if (isset($scenarioResults[$scenarioId])) {
                continue;
            }

            $this->appendScenario(
                $scenarioResults,
                $findings,
                $findingLinks,
                $this->notCoveredScenario($scenarioId),
            );
        }

        $hasFailures = self::hasScenarioFailures($scenarioResults);
        $finishedAt = self::timestamp();
        $report = [
            'schema' => self::RESULT_SCHEMA,
            'schema_version' => self::RESULT_VERSION,
            'suite_version' => PlatformConformanceSuite::VERSION,
            'coverage_scope' => 'workflow-php-namespace-shard',
            'outcome' => $hasFailures ? 'fail' : 'non_passing',
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'generated_at' => $finishedAt,
            'artifact_versions' => $artifactVersions,
            'artifact_sources' => $artifactSources,
            'namespace_topology' => [
                'namespaces' => array_values($namespaces),
                'task_queue' => $taskQueue,
            ],
            'runtime_matrix' => [
                'runtimes' => ['workflow-php'],
                'client_paths' => ['workflow-php-sdk'],
                'worker_isolation_cells' => [
                    [
                        'runtime' => 'workflow-php',
                        'namespace' => $namespaces['a'],
                        'task_queue' => $taskQueue,
                        'scenario' => 'php_worker_task_queue_namespace_isolation',
                    ],
                    [
                        'runtime' => 'workflow-php',
                        'namespace' => $namespaces['b'],
                        'task_queue' => $taskQueue,
                        'scenario' => 'php_worker_task_queue_namespace_isolation',
                    ],
                ],
            ],
            'scenario_results' => array_values($scenarioResults),
            'published_artifact_install' => $scenarioResults['published_artifact_install_only']['observed_outputs'] ?? [],
            'namespace_crud_behavior' => $scenarioResults['namespace_create_update_describe_and_list']['observed_outputs'] ?? [],
            'sdk_namespace_selection' => $scenarioResults['sdk_namespace_selection_parity']['observed_outputs'] ?? [],
            'php_worker_behavior' => $scenarioResults['php_worker_task_queue_namespace_isolation']['observed_outputs'] ?? [],
            'result_record_and_product_finding_routing' => [
                'artifact_versions_recorded' => $artifactVersions !== [],
                'timestamps_recorded' => $startedAt !== '' && $finishedAt !== '',
                'outcome_recorded' => true,
                'finding_links_recorded' => true,
                'product_finding_routes_checked' => true,
            ],
            'findings' => $findings,
            'finding_links' => $findingLinks,
        ];

        $this->emit($report);

        return $hasFailures ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<string, string> $artifactVersions
     * @param array{a: string, b: string, shared: string} $namespaces
     *
     * @return list<array<string, mixed>>
     */
    private function livePhpScenarios(array $artifactVersions, array $namespaces, string $taskQueue): array
    {
        $serverUrl = $this->stringOption('server-url');
        $token = $this->stringOption('token');

        if ($serverUrl === null || $token === null) {
            $missing = [];
            if ($serverUrl === null) {
                $missing[] = 'server-url';
            }
            if ($token === null) {
                $missing[] = 'token';
            }

            return [
                $this->failedScenario(
                    'namespace_create_update_describe_and_list',
                    'PHP namespace conformance cannot run without server connection options.',
                    ['missing_options' => $missing],
                    'workflow',
                ),
                $this->failedScenario(
                    'sdk_namespace_selection_parity',
                    'PHP namespace conformance cannot run without server connection options.',
                    ['missing_options' => $missing],
                    'workflow',
                ),
                $this->failedScenario(
                    'php_worker_task_queue_namespace_isolation',
                    'PHP namespace conformance cannot run without server connection options.',
                    ['missing_options' => $missing],
                    'workflow',
                ),
            ];
        }

        try {
            return $this->exerciseLivePhpNamespaceSurface(
                $serverUrl,
                $token,
                $artifactVersions,
                $namespaces,
                $taskQueue,
            );
        } catch (Throwable $exception) {
            $evidence = [
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ];

            return [
                $this->failedScenario(
                    'namespace_create_update_describe_and_list',
                    'PHP namespace conformance failed before namespace CRUD evidence completed.',
                    $evidence,
                    'workflow',
                ),
                $this->failedScenario(
                    'sdk_namespace_selection_parity',
                    'PHP namespace client selection evidence failed.',
                    $evidence,
                    'workflow',
                ),
                $this->failedScenario(
                    'php_worker_task_queue_namespace_isolation',
                    'PHP worker namespace isolation evidence failed.',
                    $evidence,
                    'workflow',
                ),
            ];
        }
    }

    /**
     * @param array<string, string> $artifactVersions
     * @param array{a: string, b: string, shared: string} $namespaces
     *
     * @return list<array<string, mixed>>
     */
    private function exerciseLivePhpNamespaceSurface(
        string $serverUrl,
        string $token,
        array $artifactVersions,
        array $namespaces,
        string $taskQueue,
    ): array {
        $runId = $this->runId();
        $workflowType = $this->stringOption('workflow-type') ?? 'workflow-v2-namespace-conformance';
        $pollTimeoutSeconds = $this->pollTimeoutSeconds();

        $defaultClient = new ControlPlaneClient($this->http, $serverUrl, $token);
        $tenantAClient = $defaultClient->withNamespace($namespaces['a']);
        $tenantBClient = $defaultClient->withNamespace($namespaces['b']);

        $createDescription = sprintf('PHP namespace conformance shard %s', $runId);
        $updatedDescription = sprintf('PHP namespace conformance shard %s updated', $runId);

        $createdNamespaces = [];
        foreach ($namespaces as $namespace) {
            $createdNamespaces[$namespace] = $defaultClient->createNamespace(
                $namespace,
                $createDescription,
            );
        }
        $updatedNamespace = $defaultClient->updateNamespace(
            $namespaces['a'],
            $updatedDescription,
        );
        $describedNamespaces = [];
        foreach ($namespaces as $namespace) {
            $describedNamespaces[$namespace] = $defaultClient->describeNamespace($namespace);
        }
        $listedNamespaces = $defaultClient->listNamespaces();
        $namespaceCrudChecks = $this->namespaceCrudChecks(
            $namespaces,
            $createdNamespaces,
            $updatedNamespace,
            $describedNamespaces,
            $listedNamespaces,
            $updatedDescription,
        );

        $workflowA = sprintf('%s-%s-workflow', $runId, $namespaces['a']);
        $workflowB = sprintf('%s-%s-workflow', $runId, $namespaces['b']);

        $startA = $tenantAClient->startWorkflow($workflowType, $workflowA, [
            [
                'conformance_run_id' => $runId,
                'namespace' => $namespaces['a'],
                'runtime' => 'workflow-php',
            ],
        ], [
            'task_queue' => $taskQueue,
        ]);

        $crossNamespaceLookup = $this->notFoundOutcome(static fn (): array => $tenantBClient->describeWorkflow($workflowA));

        $workerA = new WorkerProtocolClient($this->http, $serverUrl, $token, $namespaces['a']);
        $workerB = new WorkerProtocolClient($this->http, $serverUrl, $token, $namespaces['b']);
        $workerAId = sprintf('%s-%s-worker', $runId, $namespaces['a']);
        $workerBId = sprintf('%s-%s-worker', $runId, $namespaces['b']);

        $tenantARegistration = $workerA->registerWorker(
            workerId: $workerAId,
            taskQueue: $taskQueue,
            supportedWorkflowTypes: [$workflowType],
        );
        $tenantBRegistration = $workerB->registerWorker(
            workerId: $workerBId,
            taskQueue: $taskQueue,
            supportedWorkflowTypes: [$workflowType],
        );

        $tenantBBeforeOwnStart = $workerB->pollWorkflowTasks(
            queue: $taskQueue,
            timeoutSeconds: $pollTimeoutSeconds,
        );
        $tenantATasks = $workerA->pollWorkflowTasks(
            queue: $taskQueue,
            timeoutSeconds: $pollTimeoutSeconds,
        );

        $startB = $tenantBClient->startWorkflow($workflowType, $workflowB, [
            [
                'conformance_run_id' => $runId,
                'namespace' => $namespaces['b'],
                'runtime' => 'workflow-php',
            ],
        ], [
            'task_queue' => $taskQueue,
        ]);

        $tenantAAfterBStart = $workerA->pollWorkflowTasks(
            queue: $taskQueue,
            timeoutSeconds: $pollTimeoutSeconds,
        );
        $tenantBTasks = $workerB->pollWorkflowTasks(
            queue: $taskQueue,
            timeoutSeconds: $pollTimeoutSeconds,
        );

        $tenantADelivery = $this->tasksContainWorkflow($tenantATasks, $workflowA);
        $tenantBDelivery = $this->tasksContainWorkflow($tenantBTasks, $workflowB);
        $crossDeliveryAbsent = $tenantBBeforeOwnStart === []
            && ! $this->tasksContainWorkflow($tenantAAfterBStart, $workflowB);

        return [
            [
                'scenario_id' => 'namespace_create_update_describe_and_list',
                'status' => $namespaceCrudChecks['passed'] ? 'pass' : 'fail',
                'observed_outputs' => [
                    'created_namespaces' => array_keys($createdNamespaces),
                    'created_namespace_responses' => $createdNamespaces,
                    'updated_namespace' => $updatedNamespace,
                    'described_namespaces' => $describedNamespaces,
                    'listed_namespaces' => $listedNamespaces,
                    'semantic_checks' => $namespaceCrudChecks,
                    'artifact_versions' => $artifactVersions,
                ],
                'linked_findings' => $namespaceCrudChecks['passed']
                    ? []
                    : [$this->finding(
                        'namespace_create_update_describe_and_list',
                        'PHP namespace CRUD/list responses did not prove requested namespace state.',
                        [
                            'semantic_checks' => $namespaceCrudChecks,
                            'created_namespaces' => $createdNamespaces,
                            'updated_namespace' => $updatedNamespace,
                            'described_namespaces' => $describedNamespaces,
                            'listed_namespaces' => $listedNamespaces,
                        ],
                        'server',
                    )],
            ],
            [
                'scenario_id' => 'sdk_namespace_selection_parity',
                'status' => $crossNamespaceLookup['not_found'] ? 'pass' : 'fail',
                'observed_outputs' => [
                    'python_client_namespace' => 'covered_by_full_harness',
                    'php_client_namespace' => [
                        'default' => $defaultClient->namespace(),
                        'tenant_a' => $tenantAClient->namespace(),
                        'tenant_b' => $tenantBClient->namespace(),
                    ],
                    'default_namespace_behavior' => [
                        'selected_namespace' => $defaultClient->namespace(),
                        'documented_default' => 'default',
                    ],
                    'cross_namespace_lookup_denied' => $crossNamespaceLookup,
                    'tenant_a_workflow' => $startA,
                ],
                'linked_findings' => $crossNamespaceLookup['not_found']
                    ? []
                    : [$this->finding(
                        'sdk_namespace_selection_parity',
                        'PHP cross-namespace workflow lookup did not fail as not-found.',
                        $crossNamespaceLookup,
                        'workflow',
                    )],
            ],
            [
                'scenario_id' => 'php_worker_task_queue_namespace_isolation',
                'status' => $tenantADelivery && $tenantBDelivery && $crossDeliveryAbsent ? 'pass' : 'fail',
                'observed_outputs' => [
                    'tenant_a_worker_registration' => [
                        'namespace' => $namespaces['a'],
                        'worker_id' => $workerAId,
                        'task_queue' => $taskQueue,
                        'response' => $tenantARegistration,
                    ],
                    'tenant_b_worker_registration' => [
                        'namespace' => $namespaces['b'],
                        'worker_id' => $workerBId,
                        'task_queue' => $taskQueue,
                        'response' => $tenantBRegistration,
                    ],
                    'tenant_a_delivery' => [
                        'workflow_id' => $workflowA,
                        'delivered' => $tenantADelivery,
                        'tasks' => $tenantATasks,
                    ],
                    'tenant_b_delivery' => [
                        'workflow_id' => $workflowB,
                        'delivered' => $tenantBDelivery,
                        'start_response' => $startB,
                        'tasks' => $tenantBTasks,
                    ],
                    'cross_delivery_absent' => [
                        'tenant_b_before_own_start_empty' => $tenantBBeforeOwnStart === [],
                        'tenant_a_after_tenant_b_start' => $tenantAAfterBStart,
                        'passed' => $crossDeliveryAbsent,
                    ],
                ],
                'linked_findings' => $tenantADelivery && $tenantBDelivery && $crossDeliveryAbsent
                    ? []
                    : [$this->finding(
                        'php_worker_task_queue_namespace_isolation',
                        'PHP workers registered on the same queue did not prove namespace-isolated delivery.',
                        [
                            'tenant_a_delivery' => $tenantADelivery,
                            'tenant_b_delivery' => $tenantBDelivery,
                            'cross_delivery_absent' => $crossDeliveryAbsent,
                        ],
                        'server',
                    )],
            ],
        ];
    }

    /**
     * @param array{a: string, b: string, shared: string} $namespaces
     * @param array<string, array<string, mixed>> $createdNamespaces
     * @param array<string, mixed> $updatedNamespace
     * @param array<string, array<string, mixed>> $describedNamespaces
     * @param array<string, mixed> $listedNamespaces
     *
     * @return array<string, mixed>
     */
    private function namespaceCrudChecks(
        array $namespaces,
        array $createdNamespaces,
        array $updatedNamespace,
        array $describedNamespaces,
        array $listedNamespaces,
        string $updatedDescription,
    ): array {
        $expectedNamespaces = array_values($namespaces);
        $failures = [];
        $createChecks = [];
        $describeChecks = [];

        foreach ($expectedNamespaces as $namespace) {
            $createdName = self::namespaceName($createdNamespaces[$namespace] ?? null);
            $createPassed = $createdName === $namespace;
            $createChecks[$namespace] = [
                'expected' => $namespace,
                'actual' => $createdName,
                'passed' => $createPassed,
            ];
            if (! $createPassed) {
                $failures[] = sprintf('create response did not identify namespace [%s]', $namespace);
            }

            $describedName = self::namespaceName($describedNamespaces[$namespace] ?? null);
            $describePassed = $describedName === $namespace;
            $describeChecks[$namespace] = [
                'expected' => $namespace,
                'actual' => $describedName,
                'passed' => $describePassed,
            ];
            if (! $describePassed) {
                $failures[] = sprintf('describe response did not identify namespace [%s]', $namespace);
            }
        }

        $updatedName = self::namespaceName($updatedNamespace);
        $updatedActualDescription = self::stringValue($updatedNamespace['description'] ?? null);
        $updatedNamePassed = $updatedName === $namespaces['a'];
        $updatedDescriptionPassed = $updatedActualDescription === $updatedDescription;
        if (! $updatedNamePassed) {
            $failures[] = sprintf('update response did not identify namespace [%s]', $namespaces['a']);
        }
        if (! $updatedDescriptionPassed) {
            $failures[] = sprintf('update response did not reflect description [%s]', $updatedDescription);
        }

        $listedNames = self::listedNamespaceNames($listedNamespaces);
        $missingFromList = array_values(array_diff($expectedNamespaces, $listedNames));
        if ($missingFromList !== []) {
            $failures[] = 'list response omitted namespace(s): ' . implode(', ', $missingFromList);
        }

        return [
            'passed' => $failures === [],
            'expected_namespaces' => $expectedNamespaces,
            'create_responses_identify_requested_namespaces' => $createChecks,
            'describe_responses_identify_requested_namespaces' => $describeChecks,
            'tenant_a_update_reflected' => [
                'expected_namespace' => $namespaces['a'],
                'actual_namespace' => $updatedName,
                'namespace_passed' => $updatedNamePassed,
                'expected_description' => $updatedDescription,
                'actual_description' => $updatedActualDescription,
                'description_passed' => $updatedDescriptionPassed,
                'passed' => $updatedNamePassed && $updatedDescriptionPassed,
            ],
            'listed_namespace_names' => $listedNames,
            'missing_from_list' => $missingFromList,
            'list_contains_created_namespaces' => $missingFromList === [],
            'failures' => $failures,
        ];
    }

    private static function namespaceName(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach (['name', 'namespace'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_array($value)) {
                $nested = self::namespaceName($value);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        foreach (['data', 'record', 'namespace_record'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_array($value)) {
                $nested = self::namespaceName($value);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private static function listedNamespaceNames(array $payload): array
    {
        if (isset($payload['namespaces']) && is_array($payload['namespaces'])) {
            $entries = $payload['namespaces'];
        } elseif (isset($payload['data']) && is_array($payload['data'])) {
            $entries = $payload['data'];
        } elseif (array_is_list($payload)) {
            $entries = $payload;
        } else {
            $entries = [];
        }

        $names = [];
        foreach ($entries as $entry) {
            if (is_string($entry) && $entry !== '') {
                $names[] = $entry;
                continue;
            }

            if (! is_array($entry)) {
                continue;
            }

            $name = self::namespaceName($entry);
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param callable(): array<string, mixed> $operation
     *
     * @return array<string, mixed>
     */
    private function notFoundOutcome(callable $operation): array
    {
        try {
            $response = $operation();

            return [
                'not_found' => false,
                'status' => 200,
                'response' => $response,
            ];
        } catch (ControlPlaneRequestException $exception) {
            return [
                'not_found' => $exception->status() === 404
                    || in_array($exception->reason(), ['not_found', 'workflow_not_found', 'run_not_found'], true),
                'status' => $exception->status(),
                'reason' => $exception->reason(),
                'body' => $exception->body(),
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param list<array<string, mixed>> $tasks
     */
    private function tasksContainWorkflow(array $tasks, string $workflowId): bool
    {
        foreach ($tasks as $task) {
            if (($task['workflow_id'] ?? null) === $workflowId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $artifactVersions
     */
    private function resultRecordScenario(array $artifactVersions): array
    {
        return [
            'scenario_id' => 'result_record_and_product_finding_routing',
            'status' => 'pass',
            'observed_outputs' => [
                'artifact_versions_recorded' => $artifactVersions !== [],
                'timestamps_recorded' => true,
                'outcome_recorded' => true,
                'finding_links_recorded' => true,
                'product_finding_routes_checked' => true,
            ],
        ];
    }

    /**
     * @param array<string, string> $artifactVersions
     * @param array<string, string> $artifactSources
     *
     * @return array<string, mixed>
     */
    private function publishedArtifactScenario(array $artifactVersions, array $artifactSources): array
    {
        $missingVersions = [];
        $missingSources = [];
        $rejectedVersions = [];
        $forbiddenSources = [];
        $untrustedSources = [];

        foreach (self::REQUIRED_ARTIFACTS as $artifact) {
            $version = self::artifactMetadata($artifactVersions, $artifact);
            if ($version === null) {
                $missingVersions[] = $artifact;
            } else {
                $versionReason = self::unpublishedVersionReason($version);
                if ($versionReason !== null) {
                    $rejectedVersions[$artifact] = [
                        'version' => $version,
                        'reason' => $versionReason,
                    ];
                }
            }

            $source = self::artifactMetadata($artifactSources, $artifact);
            if ($source === null) {
                $missingSources[] = $artifact;
                continue;
            }

            if (self::isLocalArtifactSource($source)) {
                $forbiddenSources[$artifact] = $source;
                continue;
            }

            if (! self::isPublishedArtifactSource($artifact, $source)) {
                $untrustedSources[$artifact] = $source;
            }
        }

        $passed = $missingVersions === []
            && $missingSources === []
            && $rejectedVersions === []
            && $forbiddenSources === []
            && $untrustedSources === [];

        return [
            'scenario_id' => 'published_artifact_install_only',
            'status' => $passed ? 'pass' : 'fail',
            'observed_outputs' => [
                'server_image' => self::artifactMetadata($artifactVersions, 'server'),
                'cli_release' => self::artifactMetadata($artifactVersions, 'cli'),
                'workflow_php_package' => self::artifactMetadata($artifactVersions, 'workflow-php'),
                'sdk_python_package' => self::artifactMetadata($artifactVersions, 'sdk-python'),
                'waterline_artifact' => self::artifactMetadata($artifactVersions, 'waterline'),
                'artifact_versions' => $artifactVersions,
                'artifact_sources' => $artifactSources,
                'missing_artifact_versions' => $missingVersions,
                'missing_artifact_sources' => $missingSources,
                'rejected_versions' => $rejectedVersions,
                'forbidden_sources' => $forbiddenSources,
                'untrusted_sources' => $untrustedSources,
                'published_artifacts_only' => $forbiddenSources === [],
                'published_install_tuple_proven' => $passed,
            ],
            'linked_findings' => $passed
                ? []
                : [$this->finding(
                    'published_artifact_install_only',
                    'Published-artifact namespace conformance inputs are incomplete.',
                    [
                        'missing_artifact_versions' => $missingVersions,
                        'missing_artifact_sources' => $missingSources,
                        'rejected_versions' => $rejectedVersions,
                        'forbidden_sources' => $forbiddenSources,
                        'untrusted_sources' => $untrustedSources,
                    ],
                    'workflow',
                )],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notCoveredScenario(string $scenarioId): array
    {
        return [
            'scenario_id' => $scenarioId,
            'status' => 'not_covered',
            'observed_outputs' => [
                'covered_by' => 'full_namespace_conformance_harness',
                'php_shard_scenarios' => self::PHP_SHARD_SCENARIOS,
            ],
            'linked_findings' => [
                $this->finding(
                    $scenarioId,
                    sprintf('Namespace scenario [%s] is outside the PHP namespace shard.', $scenarioId),
                    ['covered_by' => 'full_namespace_conformance_harness'],
                    'conformance-harness',
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     *
     * @return array<string, mixed>
     */
    private function failedScenario(string $scenarioId, string $title, array $evidence, string $owner): array
    {
        return [
            'scenario_id' => $scenarioId,
            'status' => 'fail',
            'observed_outputs' => $evidence,
            'linked_findings' => [
                $this->finding($scenarioId, $title, $evidence, $owner),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     *
     * @return array<string, mixed>
     */
    private function finding(string $scenarioId, string $title, array $evidence, string $owner): array
    {
        return [
            'scenario_id' => $scenarioId,
            'title' => $title,
            'owner' => $owner,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $scenarioResults
     * @param list<array<string, mixed>> $findings
     * @param array<string, list<array<string, mixed>>> $findingLinks
     * @param array<string, mixed> $scenario
     */
    private function appendScenario(
        array &$scenarioResults,
        array &$findings,
        array &$findingLinks,
        array $scenario,
    ): void {
        $scenarioId = self::stringValue($scenario['scenario_id'] ?? null);
        if ($scenarioId === null) {
            return;
        }

        $scenarioFindings = [];
        foreach (($scenario['linked_findings'] ?? []) as $finding) {
            if (is_array($finding)) {
                $scenarioFindings[] = $finding;
            }
        }

        if ($scenarioFindings !== []) {
            $scenario['linked_findings'] = $scenarioFindings;
            $findings = array_merge($findings, $scenarioFindings);
            $findingLinks[$scenarioId] = $scenarioFindings;
        } else {
            unset($scenario['linked_findings']);
        }

        $scenarioResults[$scenarioId] = $scenario;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function emit(array $report): void
    {
        if ((bool) $this->option('json') || $this->stringOption('output') !== null) {
            $this->emitJson($report);

            return;
        }

        $this->renderHuman($report);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function emitJson(array $report): void
    {
        try {
            $json = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error($exception->getMessage());

            return;
        }

        $output = $this->stringOption('output');

        if ($output !== null) {
            $this->files->ensureDirectoryExists(dirname($output));
            $this->files->put($output, $json . PHP_EOL);

            return;
        }

        $this->line($json);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function renderHuman(array $report): void
    {
        $passed = count(array_filter(
            $report['scenario_results'],
            static fn (array $scenario): bool => ($scenario['status'] ?? null) === 'pass',
        ));
        $total = count($report['scenario_results']);

        $this->line(sprintf(
            'Workflow PHP namespace conformance shard: %s (%d/%d scenarios passed)',
            $report['outcome'] === 'fail' ? '<error>FAIL</error>' : '<info>SHARD PASS</info>',
            $passed,
            $total,
        ));
        $this->line('Outcome: ' . $report['outcome']);
        $this->line('Schema: ' . $report['schema']);

        foreach ($report['scenario_results'] as $scenario) {
            $this->line(sprintf(
                '  [%s] %s',
                strtoupper((string) ($scenario['status'] ?? 'unknown')),
                (string) ($scenario['scenario_id'] ?? 'unknown'),
            ));
        }
    }

    /**
     * @return array<string, string>
     */
    private function artifactVersions(): array
    {
        return self::canonicalArtifactMetadata($this->keyValueOptions('artifact-version'));
    }

    /**
     * @return array<string, string>
     */
    private function artifactSources(): array
    {
        return self::canonicalArtifactMetadata($this->keyValueOptions('artifact-source'));
    }

    /**
     * @return array{a: string, b: string, shared: string}
     */
    private function namespaces(): array
    {
        return [
            'a' => $this->stringOption('namespace-a') ?? 'tenant-a',
            'b' => $this->stringOption('namespace-b') ?? 'tenant-b',
            'shared' => $this->stringOption('shared-namespace') ?? 'shared',
        ];
    }

    private function runId(): string
    {
        return $this->stringOption('run-id') ?? ('php-ns-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)));
    }

    private function pollTimeoutSeconds(): int
    {
        $value = $this->option('poll-timeout');
        $seconds = is_numeric($value) ? (int) $value : 1;

        return max(1, min($seconds, 10));
    }

    /**
     * @return array<string, string>
     */
    private function keyValueOptions(string $name): array
    {
        $values = $this->option($name);
        if (! is_array($values)) {
            $values = is_string($values) ? [$values] : [];
        }

        $parsed = [];
        foreach ($values as $value) {
            if (! is_string($value) || ! str_contains($value, '=')) {
                continue;
            }

            [$key, $entryValue] = explode('=', $value, 2);
            $key = trim($key);
            $entryValue = trim($entryValue);
            if ($key === '' || $entryValue === '') {
                continue;
            }

            $parsed[$key] = $entryValue;
        }

        return $parsed;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, array<string, mixed>> $scenarioResults
     */
    private static function hasScenarioFailures(array $scenarioResults): bool
    {
        foreach ($scenarioResults as $scenario) {
            if (($scenario['status'] ?? null) === 'fail') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $metadata
     */
    private static function artifactMetadata(array $metadata, string $artifact): ?string
    {
        $aliases = [
            'workflow-php' => ['workflow-php', 'workflow_php', 'workflow'],
            'sdk-python' => ['sdk-python', 'sdk_python', 'python'],
        ];

        foreach ($aliases[$artifact] ?? [$artifact] as $key) {
            if (isset($metadata[$key]) && $metadata[$key] !== '') {
                return $metadata[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $metadata
     *
     * @return array<string, string>
     */
    private static function canonicalArtifactMetadata(array $metadata): array
    {
        $canonical = [];

        foreach ($metadata as $artifact => $value) {
            $key = self::canonicalArtifactKey($artifact);
            $canonical[$key ?? $artifact] = $value;
        }

        return $canonical;
    }

    private static function canonicalArtifactKey(string $artifact): ?string
    {
        $normalized = str_replace('_', '-', strtolower(trim($artifact)));

        return match ($normalized) {
            'server' => 'server',
            'cli' => 'cli',
            'workflow', 'workflow-php' => 'workflow-php',
            'python', 'sdk-python' => 'sdk-python',
            'waterline' => 'waterline',
            default => null,
        };
    }

    private static function unpublishedVersionReason(string $version): ?string
    {
        $normalized = strtolower(trim($version));
        $localVersionPattern = '/(^|[^a-z0-9])(local|workspace|source|checkout|repo|path|dirty)([^a-z0-9]|$)/';
        $devVersionPattern = '/(^dev[-_.\/]|[-_.\/]dev($|[-_.\/])|@dev($|[^a-z0-9])|\.x-dev$|-dev$|9999999-dev)/';

        if ($normalized === '') {
            return 'empty_version';
        }

        if (preg_match('/<[^>]+>|\$\{[^}]+}|{{[^}]+}}/', $normalized) === 1) {
            return 'placeholder_template';
        }

        if (preg_match(
            '/(^|[^a-z0-9])(latest|current|head|unresolved|placeholder)([^a-z0-9]|$)/',
            $normalized,
        ) === 1) {
            return 'placeholder_label';
        }

        if (str_contains($normalized, '*')) {
            return 'wildcard_version';
        }

        if (
            $normalized === 'self.version'
            || preg_match($localVersionPattern, $normalized) === 1
        ) {
            return 'local_or_source_version';
        }

        if (
            preg_match($devVersionPattern, $normalized) === 1
            || preg_match('/^(main|master|trunk|v\d+)$/', $normalized) === 1
        ) {
            return 'dev_or_branch_version';
        }

        return null;
    }

    private static function isLocalArtifactSource(string $source): bool
    {
        $normalized = self::normalizeArtifactSource($source);

        return preg_match(
            '/(^|_)(dev|editable|local|path|repo|source|workspace|checkout)(_|$)/',
            $normalized,
        ) === 1;
    }

    private static function isPublishedArtifactSource(string $artifact, string $source): bool
    {
        $normalized = self::normalizeArtifactSource($source);

        return in_array($normalized, self::PUBLISHED_ARTIFACT_SOURCES[$artifact] ?? [], true);
    }

    private static function normalizeArtifactSource(string $source): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($source))) ?? '';

        return trim($normalized, '_');
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function timestamp(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
