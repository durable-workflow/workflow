<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory as HttpFactory;
use JsonException;
use Throwable;
use Workflow\V2\Client\ControlPlaneClient;
use Workflow\V2\Conformance\SearchAttributesConformanceWorkflow;
use Workflow\V2\Exceptions\ControlPlaneRequestException;
use Workflow\V2\Support\PlatformConformanceSuite;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\Worker\StandaloneWorkflowWorker;
use Workflow\V2\Worker\WorkerProtocolClient;

class V2SearchAttributesConformanceCommand extends Command
{
    protected $signature = 'workflow:v2:search-attributes-conformance
        {--server-url= : Base URL for the standalone server under test}
        {--token= : Bearer token for control-plane and worker-plane requests}
        {--namespace=default : Namespace for the PHP search-attribute runtime probe}
        {--peer-namespace= : Peer namespace used for PHP namespace isolation proof}
        {--task-queue=search-attributes : Task queue for the PHP search-attribute workflow}
        {--workflow-type=workflow-v2-search-attributes-conformance : Durable workflow type key to start}
        {--run-id= : Stable run suffix for workflow, worker, and attribute ids}
        {--poll-timeout=1 : Worker long-poll timeout in seconds}
        {--artifact-version=* : Repeatable actor=version option for the published artifact tuple}
        {--artifact-source=* : Repeatable actor=source option proving the published artifact install channel}
        {--json : Emit a single machine-readable JSON report}
        {--output= : Write the JSON report to a file instead of stdout}';

    protected $description = 'Emit the Workflow PHP search-attributes conformance evidence shard';

    private const RESULT_SCHEMA = 'durable-workflow.v2.search-attribute-runtime.result';

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
        'schema_definition_and_reserved_name_refusal',
        'python_worker_start_and_upsert_visibility',
        'php_worker_start_and_upsert_visibility',
        'cli_query_and_error_surface',
        'waterline_operator_visibility',
        'python_to_php_codec_round_trip',
        'php_to_python_codec_round_trip',
        'equality_range_bool_query_behavior',
        'or_not_query_grammar',
        'keyword_list_membership',
        'type_safety_wrong_literal',
        'undefined_key_rejection',
        'indexing_latency_distribution',
        'load_and_bounded_latency',
        'namespace_isolation',
        'query_injection_hardening',
    ];

    /**
     * @var list<string>
     */
    private const PHP_SHARD_SCENARIOS = [
        'published_artifact_install_only',
        'php_worker_start_and_upsert_visibility',
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
            'composer_release',
            'packagist',
            'packagist_package',
        ],
        'sdk-python' => [
            'pip_package',
            'pypi',
            'pypi_package',
            'pypi_release',
            'python_package',
        ],
        'waterline' => [
            'composer',
            'composer_package',
            'composer_release',
            'packagist',
            'packagist_package',
            'published_waterline_release',
        ],
    ];

    private ?string $resolvedRunId = null;

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
        $attributeDefinitions = $this->attributeDefinitions();

        $scenarioResults = [];
        $findings = [];
        $findingLinks = [];

        $this->appendScenario(
            $scenarioResults,
            $findings,
            $findingLinks,
            $this->publishedArtifactScenario($artifactVersions, $artifactSources),
        );

        foreach ($this->livePhpScenarios($artifactVersions, $artifactSources, $attributeDefinitions) as $scenario) {
            $this->appendScenario($scenarioResults, $findings, $findingLinks, $scenario);
        }

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
            'coverage_scope' => 'workflow-php-search-attribute-shard',
            'outcome' => $hasFailures ? 'non_passing_with_root_cause_finding' : 'non_passing',
            'runner_blocked' => false,
            'run_id' => $this->runId(),
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'generated_at' => $finishedAt,
            'artifact_versions' => $artifactVersions,
            'artifact_sources' => $artifactSources,
            'topology' => [
                'namespaces' => [
                    'primary' => $this->namespace(),
                    'isolation_peer' => $this->peerNamespace(),
                ],
                'schema_keys' => $this->logicalSchemaKeys($attributeDefinitions),
                'dynamic_schema_keys' => $this->schemaKeys($attributeDefinitions),
                'workflow_storage_fields' => $this->storageFields($attributeDefinitions),
                'task_queue' => $this->taskQueue(),
                'workflow_type' => $this->workflowType(),
            ],
            'runtime_matrix' => [
                'runtimes' => ['workflow-php', 'sdk-python'],
                'client_paths' => ['cli', 'workflow-php-sdk', 'sdk-python'],
                'observer_paths' => [
                    'waterline-workflow-list-filter',
                    'waterline-selected-run-detail',
                    'waterline-saved-filter',
                ],
                'runtime_cells' => [
                    [
                        'worker' => 'sdk-python',
                        'clients' => ['cli', 'sdk-python'],
                        'scenario' => 'python_worker_start_and_upsert_visibility',
                    ],
                    [
                        'worker' => 'workflow-php',
                        'clients' => ['cli', 'workflow-php-sdk'],
                        'scenario' => 'php_worker_start_and_upsert_visibility',
                    ],
                ],
                'cross_language_cells' => [
                    [
                        'writer' => 'sdk-python',
                        'readers' => ['workflow-php-sdk', 'cli'],
                        'scenario' => 'python_to_php_codec_round_trip',
                    ],
                    [
                        'writer' => 'workflow-php',
                        'readers' => ['sdk-python', 'cli'],
                        'scenario' => 'php_to_python_codec_round_trip',
                    ],
                ],
            ],
            'scenario_results' => array_values($scenarioResults),
            'php_worker_search_attribute_visibility' => $scenarioResults['php_worker_start_and_upsert_visibility']['observed_outputs'] ?? [],
            'query_verdicts' => [
                'php_worker_visibility' => $scenarioResults['php_worker_start_and_upsert_visibility']['observed_outputs']['query_verdicts'] ?? [],
            ],
            'codec_round_trips' => [],
            'latency_distribution' => [],
            'load_profile' => [],
            'findings' => $findings,
            'finding_links' => $findingLinks,
        ];

        $this->emit($report);

        return $hasFailures ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<string, string> $artifactVersions
     * @param array<string, string> $artifactSources
     * @param array<string, array<string, string>> $attributeDefinitions
     * @return list<array<string, mixed>>
     */
    private function livePhpScenarios(
        array $artifactVersions,
        array $artifactSources,
        array $attributeDefinitions,
    ): array {
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
                    'php_worker_start_and_upsert_visibility',
                    'PHP search-attribute conformance cannot run without server connection options.',
                    ['missing_options' => $missing],
                    'workflow',
                ),
            ];
        }

        try {
            return [
                $this->exerciseLivePhpSearchAttributeSurface(
                    $serverUrl,
                    $token,
                    $artifactVersions,
                    $artifactSources,
                    $attributeDefinitions,
                ),
            ];
        } catch (Throwable $exception) {
            $evidence = [
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ];

            return [
                $this->failedScenario(
                    'php_worker_start_and_upsert_visibility',
                    'PHP search-attribute conformance failed before workflow visibility evidence completed.',
                    $evidence,
                    'workflow',
                ),
            ];
        }
    }

    /**
     * @param array<string, string> $artifactVersions
     * @param array<string, string> $artifactSources
     * @param array<string, array<string, string>> $attributeDefinitions
     * @return array<string, mixed>
     */
    private function exerciseLivePhpSearchAttributeSurface(
        string $serverUrl,
        string $token,
        array $artifactVersions,
        array $artifactSources,
        array $attributeDefinitions,
    ): array {
        $runId = $this->runId();
        $namespace = $this->namespace();
        $peerNamespace = $this->peerNamespace();
        $taskQueue = $this->taskQueue();
        $workflowType = $this->workflowType();
        $workflowClass = SearchAttributesConformanceWorkflow::class;

        $adminClient = new ControlPlaneClient($this->http, $serverUrl, $token);
        $client = new ControlPlaneClient($this->http, $serverUrl, $token, $namespace);
        $peerClient = new ControlPlaneClient($this->http, $serverUrl, $token, $peerNamespace);
        $workerClient = new WorkerProtocolClient($this->http, $serverUrl, $token, $namespace);
        $worker = new StandaloneWorkflowWorker($workerClient, [
            $workflowType => $workflowClass,
        ]);

        $this->ensureNamespace($adminClient, $namespace);
        $this->ensureNamespace($adminClient, $peerNamespace);
        $schemaDefinitionResponses = [
            'primary' => $this->createSearchAttributeDefinitions($client, $attributeDefinitions),
            'isolation_peer' => $this->createSearchAttributeDefinitions($peerClient, $attributeDefinitions),
        ];

        $workerId = sprintf('%s-php-search-attribute-worker', $runId);
        $workflowId = sprintf('%s-php-search-attributes', $runId);
        $peerWorkflowId = sprintf('%s-peer-search-attributes', $runId);
        $definitionFingerprint = WorkflowDefinition::fingerprint($workflowClass);
        $definitionContract = WorkflowDefinition::commandContract($workflowClass);
        $startAttributes = $this->startAttributes($attributeDefinitions);
        $upsertAttributes = $this->upsertAttributes($attributeDefinitions);
        $peerAttributes = $this->peerAttributes($attributeDefinitions);

        $registration = $workerClient->registerWorker(
            workerId: $workerId,
            taskQueue: $taskQueue,
            supportedWorkflowTypes: [$workflowType],
            runtime: 'php',
            sdkVersion: $artifactVersions['workflow-php'] ?? WorkerProtocolClient::DEFAULT_SDK_VERSION,
            workflowDefinitionFingerprints: $definitionFingerprint === null
                ? null
                : [$workflowType => $definitionFingerprint],
            workflowCommandContracts: [$workflowType => $definitionContract],
        );

        $start = $client->startWorkflow($workflowType, $workflowId, [$upsertAttributes], [
            'task_queue' => $taskQueue,
            'search_attributes' => $startAttributes,
        ]);
        $workerTask = $worker->processOneWorkflowTask($taskQueue, $workerId, $this->pollTimeoutSeconds());
        $runIdFromStart = $this->runIdFromStart($start);
        $runDetail = $runIdFromStart === null
            ? $client->describeWorkflow($workflowId)
            : $client->describeWorkflowRun($workflowId, $runIdFromStart);

        $peerStart = $peerClient->startWorkflow($workflowType, $peerWorkflowId, [$upsertAttributes], [
            'task_queue' => $taskQueue,
            'search_attributes' => $peerAttributes,
        ]);

        $queries = [
            'start_customer' => sprintf('%s = "%s"', $attributeDefinitions['customer_id']['key'], $startAttributes[$attributeDefinitions['customer_id']['key']]),
            'upsert_priority' => sprintf('%s = "%s"', $attributeDefinitions['priority_tier']['key'], $upsertAttributes[$attributeDefinitions['priority_tier']['key']]),
            'peer_customer_from_primary_namespace' => sprintf('%s = "%s"', $attributeDefinitions['customer_id']['key'], $peerAttributes[$attributeDefinitions['customer_id']['key']]),
            'peer_customer_from_peer_namespace' => sprintf('%s = "%s"', $attributeDefinitions['customer_id']['key'], $peerAttributes[$attributeDefinitions['customer_id']['key']]),
        ];
        $queryResponses = [
            'start_customer' => $client->listWorkflows(['query' => $queries['start_customer'], 'page_size' => 100]),
            'upsert_priority' => $client->listWorkflows(['query' => $queries['upsert_priority'], 'page_size' => 100]),
            'peer_customer_from_primary_namespace' => $client->listWorkflows(['query' => $queries['peer_customer_from_primary_namespace'], 'page_size' => 100]),
            'peer_customer_from_peer_namespace' => $peerClient->listWorkflows(['query' => $queries['peer_customer_from_peer_namespace'], 'page_size' => 100]),
        ];

        $semanticChecks = [
            'worker_task_processed' => ($workerTask['processed'] ?? false) === true,
            'worker_task_completed' => ($workerTask['outcome'] ?? null) === 'completed',
            'upsert_command_emitted' => $this->commandsIncludeType($workerTask['commands'] ?? [], 'upsert_search_attributes'),
            'workflow_completion_emitted' => $this->commandsIncludeType($workerTask['commands'] ?? [], 'complete_workflow'),
            'start_query_matches_workflow' => $this->workflowsContain($queryResponses['start_customer'], $workflowId),
            'upsert_query_matches_workflow' => $this->workflowsContain($queryResponses['upsert_priority'], $workflowId),
            'peer_value_hidden_from_primary_namespace' => ! $this->workflowsContain($queryResponses['peer_customer_from_primary_namespace'], $peerWorkflowId),
            'peer_value_visible_in_peer_namespace' => $this->workflowsContain($queryResponses['peer_customer_from_peer_namespace'], $peerWorkflowId),
            'float_contract_uses_value_float' => ($attributeDefinitions['discount_ratio']['workflow_type'] ?? null) === 'float'
                && ($attributeDefinitions['discount_ratio']['storage_field'] ?? null) === 'value_float',
        ];
        $passed = ! in_array(false, $semanticChecks, true);
        $observedOutputs = [
            'worker' => 'workflow-php',
            'worker_runtime' => 'workflow-php',
            'workflow_id' => $workflowId,
            'run_id' => $runIdFromStart,
            'namespace' => $namespace,
            'peer_namespace' => $peerNamespace,
            'start_search_attributes' => $startAttributes,
            'upserted_search_attributes' => $upsertAttributes,
            'search_attribute_types' => $this->schemaKeys($attributeDefinitions),
            'workflow_storage_fields' => $this->storageFields($attributeDefinitions),
            'wire_value_context' => [
                'wire_values' => $this->wireValues($attributeDefinitions, array_replace($startAttributes, $upsertAttributes)),
            ],
            'visibility_query_match' => $semanticChecks['start_query_matches_workflow']
                && $semanticChecks['upsert_query_matches_workflow'],
            'query_verdicts' => [
                'start_customer' => [
                    'query' => $queries['start_customer'],
                    'matched' => $semanticChecks['start_query_matches_workflow'],
                ],
                'upsert_priority' => [
                    'query' => $queries['upsert_priority'],
                    'matched' => $semanticChecks['upsert_query_matches_workflow'],
                ],
            ],
            'namespace_isolation' => [
                'same_schema_keys_defined_in_peer_namespace' => true,
                'peer_value_hidden_from_primary_namespace' => $semanticChecks['peer_value_hidden_from_primary_namespace'],
                'peer_value_visible_in_peer_namespace' => $semanticChecks['peer_value_visible_in_peer_namespace'],
                'primary_query' => $queries['peer_customer_from_primary_namespace'],
                'peer_query' => $queries['peer_customer_from_peer_namespace'],
            ],
            'type_contract' => [
                'logical_schema_keys' => $this->logicalSchemaKeys($attributeDefinitions),
                'dynamic_schema_keys' => $this->schemaKeys($attributeDefinitions),
                'storage_fields' => $this->storageFields($attributeDefinitions),
                'discount_ratio_storage_field' => $attributeDefinitions['discount_ratio']['storage_field'],
            ],
            'published_artifact_versions' => $artifactVersions,
            'published_artifact_sources' => $artifactSources,
            'schema_definition_responses' => $schemaDefinitionResponses,
            'worker_registration' => $registration,
            'start_response' => $start,
            'peer_start_response' => $peerStart,
            'worker_task' => $workerTask,
            'run_detail' => $runDetail,
            'query_responses' => $queryResponses,
            'semantic_checks' => $semanticChecks,
        ];

        return [
            'scenario_id' => 'php_worker_start_and_upsert_visibility',
            'status' => $passed ? 'pass' : 'fail',
            'classification' => $passed ? 'product-evidence' : 'product-gap',
            'published_artifact_cell_executed' => true,
            'local_product_source_checkouts_used' => false,
            'observed_outputs' => $observedOutputs,
            'linked_findings' => $passed
                ? []
                : [$this->finding(
                    'php_worker_start_and_upsert_visibility',
                    'PHP worker search-attribute visibility evidence did not prove start, upsert, query, type, and namespace behavior.',
                    [
                        'semantic_checks' => $semanticChecks,
                        'workflow_id' => $workflowId,
                        'peer_workflow_id' => $peerWorkflowId,
                    ],
                    'workflow',
                )],
        ];
    }

    private function ensureNamespace(ControlPlaneClient $client, string $namespace): void
    {
        try {
            $client->createNamespace($namespace, sprintf('PHP search-attribute conformance peer %s', $this->runId()));
        } catch (ControlPlaneRequestException $exception) {
            if ($exception->status() === 409) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, array<string, string>> $attributeDefinitions
     * @return array<string, array<string, mixed>>
     */
    private function createSearchAttributeDefinitions(ControlPlaneClient $client, array $attributeDefinitions): array
    {
        $responses = [];

        foreach ($attributeDefinitions as $name => $definition) {
            try {
                $response = $client->createSearchAttribute(
                    $definition['key'],
                    $this->serverDefinitionType($definition['workflow_type']),
                );
                $responses[$name] = [
                    'outcome' => 'created_or_present',
                    'key' => $definition['key'],
                    'workflow_type' => $definition['workflow_type'],
                    'storage_field' => $definition['storage_field'],
                    'response_name' => self::stringValue($response['name'] ?? null),
                ];
            } catch (ControlPlaneRequestException $exception) {
                if ($exception->status() !== 409) {
                    throw $exception;
                }

                $responses[$name] = [
                    'outcome' => 'already_exists',
                    'status' => $exception->status(),
                    'key' => $definition['key'],
                    'workflow_type' => $definition['workflow_type'],
                    'storage_field' => $definition['storage_field'],
                ];
            }
        }

        return $responses;
    }

    private function serverDefinitionType(string $workflowType): string
    {
        return $workflowType === 'float' ? 'double' : $workflowType;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function attributeDefinitions(): array
    {
        $suffix = $this->attributeSuffix();

        return [
            'customer_id' => [
                'key' => 'customer_id_' . $suffix,
                'workflow_type' => 'string',
                'storage_field' => 'value_string',
            ],
            'order_total_cents' => [
                'key' => 'order_cents_' . $suffix,
                'workflow_type' => 'int',
                'storage_field' => 'value_int',
            ],
            'discount_ratio' => [
                'key' => 'discount_ratio_' . $suffix,
                'workflow_type' => 'float',
                'storage_field' => 'value_float',
            ],
            'priority_tier' => [
                'key' => 'priority_tier_' . $suffix,
                'workflow_type' => 'keyword',
                'storage_field' => 'value_keyword',
            ],
            'is_vip' => [
                'key' => 'is_vip_' . $suffix,
                'workflow_type' => 'bool',
                'storage_field' => 'value_bool',
            ],
            'created_at' => [
                'key' => 'created_at_' . $suffix,
                'workflow_type' => 'datetime',
                'storage_field' => 'value_datetime',
            ],
            'tags' => [
                'key' => 'tags_' . $suffix,
                'workflow_type' => 'keyword_list',
                'storage_field' => 'value_keyword_list',
            ],
        ];
    }

    /**
     * @param array<string, array<string, string>> $attributeDefinitions
     * @return array<string, string>
     */
    private function schemaKeys(array $attributeDefinitions): array
    {
        $keys = [];

        foreach ($attributeDefinitions as $definition) {
            $keys[$definition['key']] = $definition['workflow_type'];
        }

        return $keys;
    }

    /**
     * @param array<string, array<string, string>> $attributeDefinitions
     * @return array<string, string>
     */
    private function logicalSchemaKeys(array $attributeDefinitions): array
    {
        $keys = [];

        foreach ($attributeDefinitions as $logicalName => $definition) {
            $keys[$logicalName] = $definition['workflow_type'];
        }

        return $keys;
    }

    /**
     * @param array<string, array<string, string>> $attributeDefinitions
     * @return array<string, string>
     */
    private function storageFields(array $attributeDefinitions): array
    {
        $fields = [];

        foreach ($attributeDefinitions as $logicalName => $definition) {
            $fields[$logicalName] = $definition['storage_field'];
        }

        return $fields;
    }

    /**
     * @param array<string, array<string, string>> $attributeDefinitions
     * @return array<string, scalar|list<string>>
     */
    private function startAttributes(array $attributeDefinitions): array
    {
        return [
            $attributeDefinitions['customer_id']['key'] => 'cust-php-alpha',
            $attributeDefinitions['order_total_cents']['key'] => 4250,
            $attributeDefinitions['discount_ratio']['key'] => 0.15,
            $attributeDefinitions['priority_tier']['key'] => 'gold',
            $attributeDefinitions['is_vip']['key'] => true,
            $attributeDefinitions['created_at']['key'] => '2026-07-08T12:00:00Z',
            $attributeDefinitions['tags']['key'] => ['php', 'mirror'],
        ];
    }

    /**
     * @param array<string, array<string, string>> $attributeDefinitions
     * @return array<string, scalar|list<string>>
     */
    private function upsertAttributes(array $attributeDefinitions): array
    {
        return [
            $attributeDefinitions['order_total_cents']['key'] => 7350,
            $attributeDefinitions['discount_ratio']['key'] => 0.2,
            $attributeDefinitions['priority_tier']['key'] => 'platinum',
            $attributeDefinitions['is_vip']['key'] => false,
            $attributeDefinitions['tags']['key'] => ['php', 'mirror', 'upserted'],
        ];
    }

    /**
     * @param array<string, array<string, string>> $attributeDefinitions
     * @return array<string, scalar|list<string>>
     */
    private function peerAttributes(array $attributeDefinitions): array
    {
        return [
            $attributeDefinitions['customer_id']['key'] => 'cust-php-peer',
            $attributeDefinitions['order_total_cents']['key'] => 9150,
            $attributeDefinitions['discount_ratio']['key'] => 0.05,
            $attributeDefinitions['priority_tier']['key'] => 'silver',
            $attributeDefinitions['is_vip']['key'] => false,
            $attributeDefinitions['created_at']['key'] => '2026-07-08T12:05:00Z',
            $attributeDefinitions['tags']['key'] => ['php', 'peer'],
        ];
    }

    /**
     * @param array<string, array<string, string>> $attributeDefinitions
     * @param array<string, mixed> $attributes
     * @return array<string, array<string, mixed>>
     */
    private function wireValues(array $attributeDefinitions, array $attributes): array
    {
        $values = [];

        foreach ($attributeDefinitions as $logicalName => $definition) {
            $key = $definition['key'];
            if (! array_key_exists($key, $attributes)) {
                continue;
            }

            $values[$logicalName] = [
                'key' => $key,
                'type' => $definition['workflow_type'],
                $definition['storage_field'] => $attributes[$key],
            ];
        }

        return $values;
    }

    /**
     * @param array<mixed> $commands
     */
    private function commandsIncludeType(array $commands, string $type): bool
    {
        foreach ($commands as $command) {
            if (is_array($command) && ($command['type'] ?? null) === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function workflowsContain(array $response, string $workflowId): bool
    {
        $entries = [];
        if (isset($response['workflows']) && is_array($response['workflows'])) {
            $entries = $response['workflows'];
        } elseif (isset($response['data']) && is_array($response['data'])) {
            $entries = $response['data'];
        } elseif (array_is_list($response)) {
            $entries = $response;
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = self::stringValue($entry['workflow_id'] ?? $entry['workflowId'] ?? $entry['id'] ?? null);
            if ($id === $workflowId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $start
     */
    private function runIdFromStart(array $start): ?string
    {
        foreach (['run_id', 'runId'] as $key) {
            $value = self::stringValue($start[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        foreach (['run', 'workflow_run', 'workflowRun'] as $key) {
            $value = $start[$key] ?? null;
            if (is_array($value)) {
                $runId = $this->runIdFromStart($value);
                if ($runId !== null) {
                    return $runId;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $artifactVersions
     * @param array<string, string> $artifactSources
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
                    'Published-artifact search-attribute conformance inputs are incomplete.',
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
        $coveredBy = $this->coverageOwner($scenarioId);

        return [
            'scenario_id' => $scenarioId,
            'status' => 'not_covered',
            'observed_outputs' => [
                'coverage_gap_reason' => sprintf(
                    'Scenario [%s] is outside the Workflow PHP search-attribute runtime shard.',
                    $scenarioId,
                ),
                'covered_by' => $coveredBy,
                'php_shard_scenarios' => self::PHP_SHARD_SCENARIOS,
                'required_status_recorded' => true,
            ],
            'linked_findings' => [
                $this->finding(
                    $scenarioId,
                    sprintf('Search-attribute scenario [%s] requires %s evidence outside this PHP shard.', $scenarioId, $coveredBy),
                    ['covered_by' => $coveredBy],
                    $this->findingOwner($scenarioId),
                ),
            ],
        ];
    }

    private function coverageOwner(string $scenarioId): string
    {
        return match ($scenarioId) {
            'schema_definition_and_reserved_name_refusal',
            'equality_range_bool_query_behavior',
            'or_not_query_grammar',
            'keyword_list_membership',
            'type_safety_wrong_literal',
            'undefined_key_rejection',
            'namespace_isolation',
            'query_injection_hardening' => 'full_search_attributes_server_cli_sdk_harness',
            'python_worker_start_and_upsert_visibility' => 'sdk_python_search_attribute_shard',
            'cli_query_and_error_surface' => 'cli_search_attribute_surface_shard',
            'waterline_operator_visibility' => 'waterline_operator_search_attribute_shard',
            'python_to_php_codec_round_trip',
            'php_to_python_codec_round_trip' => 'cross_language_codec_shard',
            'indexing_latency_distribution',
            'load_and_bounded_latency' => 'latency_and_load_shard',
            default => 'full_search_attributes_conformance_harness',
        };
    }

    private function findingOwner(string $scenarioId): string
    {
        return match ($scenarioId) {
            'python_worker_start_and_upsert_visibility' => 'sdk-python',
            'cli_query_and_error_surface' => 'cli',
            'waterline_operator_visibility' => 'waterline',
            'python_to_php_codec_round_trip',
            'php_to_python_codec_round_trip' => 'conformance-harness',
            default => 'server',
        };
    }

    /**
     * @param array<string, mixed> $evidence
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
            'Workflow PHP search-attribute conformance shard: %s (%d/%d scenarios passed)',
            self::hasScenarioFailures($report['scenario_results']) ? '<error>NON-PASSING</error>' : '<info>SHARD PASS</info>',
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

    private function namespace(): string
    {
        return $this->stringOption('namespace') ?? 'default';
    }

    private function peerNamespace(): string
    {
        return $this->stringOption('peer-namespace') ?? ($this->namespace() . '-search-attributes-peer');
    }

    private function taskQueue(): string
    {
        return $this->stringOption('task-queue') ?? 'search-attributes';
    }

    private function workflowType(): string
    {
        return $this->stringOption('workflow-type') ?? SearchAttributesConformanceWorkflow::TYPE_KEY;
    }

    private function runId(): string
    {
        if ($this->resolvedRunId === null) {
            $this->resolvedRunId = $this->stringOption('run-id')
                ?? ('php-sa-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)));
        }

        return $this->resolvedRunId;
    }

    private function pollTimeoutSeconds(): int
    {
        $value = $this->option('poll-timeout');
        $seconds = is_numeric($value) ? (int) $value : 1;

        return max(1, min($seconds, 10));
    }

    private function attributeSuffix(): string
    {
        $suffix = strtolower(preg_replace('/[^a-z0-9]/i', '', $this->runId()) ?? '');
        if ($suffix === '') {
            $suffix = bin2hex(random_bytes(4));
        }

        return substr($suffix, 0, 12);
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
     * @return array<string, string>
     */
    private static function canonicalArtifactMetadata(array $metadata): array
    {
        if (isset($metadata['workflow']) && ! isset($metadata['workflow-php'])) {
            $metadata['workflow-php'] = $metadata['workflow'];
        }
        if (isset($metadata['workflow_php']) && ! isset($metadata['workflow-php'])) {
            $metadata['workflow-php'] = $metadata['workflow_php'];
        }
        if (isset($metadata['python']) && ! isset($metadata['sdk-python'])) {
            $metadata['sdk-python'] = $metadata['python'];
        }
        if (isset($metadata['sdk_python']) && ! isset($metadata['sdk-python'])) {
            $metadata['sdk-python'] = $metadata['sdk_python'];
        }

        unset($metadata['workflow'], $metadata['workflow_php'], $metadata['python'], $metadata['sdk_python']);

        return $metadata;
    }

    private static function unpublishedVersionReason(string $version): ?string
    {
        $trimmed = trim($version);
        $lower = strtolower($trimmed);

        if ($trimmed === '') {
            return 'empty';
        }

        if (in_array($lower, ['latest', 'current', 'head', 'main', 'master', 'unresolved', 'placeholder'], true)) {
            return 'placeholder';
        }

        if (str_contains($trimmed, '$') || str_contains($trimmed, '<') || str_contains($trimmed, '{{')) {
            return 'template';
        }

        if (str_starts_with($lower, 'dev-') || str_contains($lower, '@dev')) {
            return 'development_version';
        }

        return null;
    }

    private static function isLocalArtifactSource(string $source): bool
    {
        $normalized = strtolower(trim($source));

        return in_array($normalized, [
            'local',
            'local_checkout',
            'local_product_source_checkout',
            'workspace',
            'workspace_repo_as_artifact_under_test',
            'path_repository',
            'source_checkout',
        ], true);
    }

    private static function isPublishedArtifactSource(string $artifact, string $source): bool
    {
        $normalized = strtolower(str_replace([' ', '-'], '_', trim($source)));

        return in_array($normalized, self::PUBLISHED_ARTIFACT_SOURCES[$artifact] ?? [], true);
    }

    private static function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function timestamp(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
