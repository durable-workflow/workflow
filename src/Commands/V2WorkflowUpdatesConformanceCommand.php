<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory as HttpFactory;
use JsonException;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Client\WorkflowClient;
use Workflow\V2\Client\WorkflowClientException;
use Workflow\V2\Conformance\WorkflowUpdatesConformanceWorkflow;
use Workflow\V2\Support\PlatformConformanceSuite;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\Worker\StandaloneWorkflowWorker;
use Workflow\V2\Worker\WorkerProtocolClient;

class V2WorkflowUpdatesConformanceCommand extends Command
{
    protected $signature = 'workflow:v2:workflow-updates-conformance
        {--server-url= : Base URL for the standalone server under test}
        {--token= : Bearer token for control-plane and worker-plane requests}
        {--namespace=default : Namespace for the PHP update surface probe}
        {--task-queue=workflow-updates-php : Task queue for the PHP update worker}
        {--workflow-type=workflow-v2-update-conformance : Workflow type key to start}
        {--run-id= : Stable run suffix for generated workflow and worker ids}
        {--poll-timeout=1 : Worker long-poll timeout in seconds}
        {--artifact-version=* : Repeatable actor=version option for the published artifact tuple}
        {--artifact-source=* : Repeatable actor=source option proving the published artifact install channel}
        {--json : Emit a single machine-readable JSON report}
        {--output= : Write the JSON report to a file instead of stdout}';

    protected $description = 'Emit the Workflow PHP workflow-updates conformance evidence shard';

    private const RESULT_SCHEMA = 'durable-workflow.v2.workflow-update-runtime.result';

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
        'declared_update_contract_visibility',
        'accepted_update_control_plane_and_history',
        'running_or_waiting_update_operator_visibility',
        'completed_update_result_round_trip',
        'failed_update_outcome',
        'duplicate_request_idempotency',
        'unknown_update_refusal',
        'invalid_input_refusal',
        'payload_envelope_round_trip',
        'terminal_workflow_update_behavior',
        'principal_attribution_with_auth',
        'php_client_worker_update_surface',
        'python_client_worker_update_surface',
        'operator_diagnostics_surfaces',
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

    /**
     * @var list<string>
     */
    private const PHP_REQUIRED_CELLS = [
        'accepted',
        'completed',
        'failed',
        'refused_unknown_update',
        'invalid_input_refusal',
        'duplicate_idempotent',
        'terminal_refusal',
        'payload_round_trip',
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
        $scenarioResults = [];
        $findings = [];
        $findingLinks = [];

        $this->appendScenario(
            $scenarioResults,
            $findings,
            $findingLinks,
            $this->publishedArtifactScenario($artifactVersions, $artifactSources),
        );
        $this->appendScenario(
            $scenarioResults,
            $findings,
            $findingLinks,
            $this->declaredContractScenario(),
        );
        $this->appendScenario(
            $scenarioResults,
            $findings,
            $findingLinks,
            $this->livePhpUpdateScenario($artifactVersions, $artifactSources),
        );
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
        $phpSurface = $scenarioResults['php_client_worker_update_surface']['observed_outputs'] ?? [];
        $report = [
            'schema' => self::RESULT_SCHEMA,
            'schema_version' => self::RESULT_VERSION,
            'suite_version' => PlatformConformanceSuite::VERSION,
            'coverage_scope' => 'workflow-php-updates-shard',
            'outcome' => $hasFailures ? 'fail' : 'non_passing',
            'runner_blocked' => false,
            'local_product_source_checkouts_used' => false,
            'source_policy' => 'published_artifact_install_only',
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'generated_at' => $finishedAt,
            'artifact_versions' => $artifactVersions,
            'artifact_sources' => $artifactSources,
            'runtime_matrix' => [
                'runtimes' => ['workflow-php'],
                'client_paths' => ['workflow-php-control-plane-client'],
                'worker_paths' => ['workflow-php-standalone-worker'],
                'update_cells' => $phpSurface['covered_cells'] ?? [],
                'unsupported_cells' => $phpSurface['unsupported_cells'] ?? [],
            ],
            'php_client_worker_update_surface' => $phpSurface,
            'scenario_results' => array_values($scenarioResults),
            'findings' => $findings,
            'finding_links' => $findingLinks,
        ];

        $this->emit($report);

        return $hasFailures ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<string, string> $artifactVersions
     * @param array<string, string> $artifactSources
     */
    private function livePhpUpdateScenario(array $artifactVersions, array $artifactSources): array
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

            return $this->failedScenario(
                'php_client_worker_update_surface',
                'PHP workflow update conformance cannot run without server connection options.',
                $this->phpSurfaceOutputs(
                    $artifactVersions,
                    $artifactSources,
                    [],
                    [],
                    [],
                    [
                        [
                            'cell' => 'php_client_worker_update_surface',
                            'reason' => 'missing_server_connection_options',
                            'missing_options' => $missing,
                        ],
                    ],
                    publishedArtifactCellExecuted: false,
                ),
                'workflow',
            );
        }

        try {
            return $this->exerciseLivePhpUpdateSurface($serverUrl, $token, $artifactVersions, $artifactSources);
        } catch (Throwable $exception) {
            return $this->failedScenario(
                'php_client_worker_update_surface',
                'PHP workflow update conformance failed before update surface evidence completed.',
                $this->phpSurfaceOutputs(
                    $artifactVersions,
                    $artifactSources,
                    [],
                    [],
                    [],
                    [[
                        'cell' => 'php_client_worker_update_surface',
                        'exception_class' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]],
                    publishedArtifactCellExecuted: false,
                ),
                'workflow',
            );
        }
    }

    /**
     * @param array<string, string> $artifactVersions
     * @param array<string, string> $artifactSources
     * @return array<string, mixed>
     */
    private function exerciseLivePhpUpdateSurface(
        string $serverUrl,
        string $token,
        array $artifactVersions,
        array $artifactSources,
    ): array {
        $runId = $this->runId();
        $namespace = $this->namespace();
        $taskQueue = $this->taskQueue();
        $workflowType = $this->workflowType();
        $workflowClass = WorkflowUpdatesConformanceWorkflow::class;
        $workflowClient = new WorkflowClient($this->http, $serverUrl, $token, $namespace);
        $workerClient = new WorkerProtocolClient($this->http, $serverUrl, $token, $namespace);
        $worker = new StandaloneWorkflowWorker($workerClient, [
            $workflowType => $workflowClass,
        ]);
        $workerId = sprintf('%s-php-update-worker', $runId);
        $workflowId = sprintf('%s-php-updates', $runId);
        $terminalWorkflowId = sprintf('%s-php-updates-terminal', $runId);
        $definitionFingerprint = WorkflowDefinition::fingerprint($workflowClass);
        $definitionContract = WorkflowDefinition::commandContract($workflowClass);

        $registration = $workerClient->registerWorker(
            workerId: $workerId,
            taskQueue: $taskQueue,
            supportedWorkflowTypes: [$workflowType],
            runtime: 'php',
            sdkVersion: $artifactVersions['workflow-php'] ?? WorkerProtocolClient::DEFAULT_SDK_VERSION,
            workflowDefinitionFingerprints: $definitionFingerprint === null
                ? null
                : [$workflowType => $definitionFingerprint],
            capabilities: ['workflow_updates'],
            workflowCommandContracts: [$workflowType => $definitionContract],
        );
        $start = $workflowClient->startWorkflow($workflowType, $workflowId, [], [
            'task_queue' => $taskQueue,
        ]);
        $initialWorkerTask = $worker->processOneWorkflowTask($taskQueue, $workerId, $this->pollTimeoutSeconds());

        $accepted = $this->requestAndDriveUpdate(
            $workflowClient,
            $worker,
            $workflowId,
            'approve',
            [true, 'accepted'],
            sprintf('%s-accepted', $runId),
        );
        $completed = $this->requestAndDriveUpdate(
            $workflowClient,
            $worker,
            $workflowId,
            'approve',
            [true, 'completed'],
            sprintf('%s-completed', $runId),
        );
        $completedWait = $this->requestUpdate(
            $workflowClient,
            $workflowId,
            'approve',
            [true, 'completed'],
            sprintf('%s-completed', $runId),
            'completed',
        );
        $failed = $this->requestAndDriveUpdate(
            $workflowClient,
            $worker,
            $workflowId,
            'fail_update',
            ['PHP update failure cell'],
            sprintf('%s-failed', $runId),
        );
        $failedWait = $this->requestUpdate(
            $workflowClient,
            $workflowId,
            'fail_update',
            ['PHP update failure cell'],
            sprintf('%s-failed', $runId),
            'completed',
        );
        $payload = [
            'string' => 'hello',
            'number' => 42,
            'nested' => [
                'bool' => true,
                'list' => [1, 2, 3],
            ],
        ];
        $payloadRoundTrip = $this->requestAndDriveUpdate(
            $workflowClient,
            $worker,
            $workflowId,
            'adjust_payload',
            [$payload],
            sprintf('%s-payload', $runId),
        );
        $payloadWait = $this->requestUpdate(
            $workflowClient,
            $workflowId,
            'adjust_payload',
            [$payload],
            sprintf('%s-payload', $runId),
            'completed',
        );
        $unknown = $this->requestUpdate(
            $workflowClient,
            $workflowId,
            'unknown_update',
            [],
            sprintf('%s-unknown', $runId),
        );
        $unknownWorkerTask = isset($unknown['exception'])
            ? null
            : $worker->processOneWorkflowTask($taskQueue, $workerId, $this->pollTimeoutSeconds());
        $terminalStart = $workflowClient->startWorkflow($workflowType, $terminalWorkflowId, ['complete'], [
            'task_queue' => $taskQueue,
        ]);
        $terminalCompletion = $worker->processOneWorkflowTask($taskQueue, $workerId, $this->pollTimeoutSeconds());
        $terminal = $this->requestUpdate(
            $workflowClient,
            $terminalWorkflowId,
            'approve',
            [true, 'terminal'],
            sprintf('%s-terminal', $runId),
        );
        $terminalUnexpectedWorkerTask = isset($terminal['exception'])
            ? null
            : $worker->processOneWorkflowTask($taskQueue, $workerId, $this->pollTimeoutSeconds());

        $clientRequests = [
            'accepted' => $accepted['client'] ?? null,
            'completed' => $completedWait,
            'failed' => $failedWait,
            'refused_unknown_update' => $unknown,
            'invalid_input_refusal' => [
                'outcome' => 'typed_unsupported',
                'reason' => 'php_client_payload_validation_not_local',
            ],
            'duplicate_idempotent' => $completedWait,
            'terminal_refusal' => $terminal,
            'payload_round_trip' => $payloadRoundTrip['client'] ?? null,
            'payload_round_trip_completed' => $payloadWait,
        ];
        $handlerBehavior = [
            'registration' => $registration,
            'definition_contract' => $definitionContract,
            'definition_fingerprint' => $definitionFingerprint,
            'start' => $start,
            'initial_worker_task' => $initialWorkerTask,
            'accepted_worker_task' => $accepted['worker'] ?? null,
            'completed_worker_task' => $completed['worker'] ?? null,
            'failed_worker_task' => $failed['worker'] ?? null,
            'payload_worker_task' => $payloadRoundTrip['worker'] ?? null,
            'unknown_update_unexpected_worker_task' => $unknownWorkerTask,
            'terminal_start' => $terminalStart,
            'terminal_completion' => $terminalCompletion,
            'terminal_unexpected_worker_task' => $terminalUnexpectedWorkerTask,
        ];
        $typedErrors = array_values(array_filter([
            $failedWait['exception'] ?? null,
            $unknown['exception'] ?? null,
            $terminal['exception'] ?? null,
        ]));
        $unsupportedCells = [[
            'cell' => 'invalid_input_refusal',
            'classification' => 'typed_unsupported',
            'reason' => 'php_client_payload_validation_not_local',
            'message' => 'The PHP package client sends typed payload envelopes and does not perform local update parameter validation before server admission.',
        ]];
        $cellOutcomes = [
            'accepted' => $this->acceptedCell($accepted['client'] ?? []),
            'completed' => $this->completedClientCell($completedWait, $completed['command'] ?? []),
            'failed' => $this->failedClientCell($failedWait, $failed['command'] ?? []),
            'refused_unknown_update' => $this->exceptionCell($unknown, [
                'unknown_update',
                'update_not_found',
                'rejected_unknown_update',
                'missing_workflow_update',
                'update_not_registered',
            ]),
            'invalid_input_refusal' => $this->unsupportedCell($unsupportedCells[0]),
            'duplicate_idempotent' => $this->duplicateCell($completed['client'] ?? [], $completedWait),
            'terminal_refusal' => $this->exceptionCell($terminal, [
                'workflow_not_running',
                'workflow_terminal',
                'run_not_active',
                'rejected_not_active',
                'not_active',
                'instance_not_found',
                'workflow_not_found',
                'run_not_found',
            ]),
            'payload_round_trip' => $this->payloadCell($payloadRoundTrip['command'] ?? [], $payloadWait, $payload),
        ];

        $failedCells = array_filter(
            $cellOutcomes,
            static fn (array $cell): bool => ($cell['status'] ?? null) === 'fail',
        );
        $coveredCells = array_keys(array_filter(
            $cellOutcomes,
            static fn (array $cell): bool => ($cell['status'] ?? null) === 'pass',
        ));
        $unsupportedCellIds = array_values(array_filter(array_map(
            static fn (array $cell): ?string => self::nonEmptyString($cell['cell'] ?? null),
            $unsupportedCells,
        )));
        $missingRequiredCells = array_values(array_diff(
            self::PHP_REQUIRED_CELLS,
            array_merge($coveredCells, $unsupportedCellIds),
        ));
        $observedOutputs = $this->phpSurfaceOutputs(
            $artifactVersions,
            $artifactSources,
            $handlerBehavior,
            $clientRequests,
            $coveredCells,
            $unsupportedCells,
            $typedErrors,
            $cellOutcomes,
            $missingRequiredCells,
        );
        $status = $failedCells === [] && $missingRequiredCells === [] ? 'pass' : 'fail';

        return [
            'scenario_id' => 'php_client_worker_update_surface',
            'status' => $status,
            'classification' => $status === 'pass' ? 'product-evidence' : 'product-gap',
            'published_artifact_cell_executed' => true,
            'local_product_source_checkouts_used' => false,
            'observed_outputs' => $observedOutputs,
            'linked_findings' => $status === 'pass'
                ? []
                : [$this->finding(
                    'php_client_worker_update_surface',
                    'PHP client/worker update shard did not prove every required update cell.',
                    [
                        'failed_cells' => $failedCells,
                        'missing_required_cells' => $missingRequiredCells,
                        'covered_cells' => $coveredCells,
                        'unsupported_cells' => $unsupportedCells,
                    ],
                    'workflow',
                )],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestAndDriveUpdate(
        WorkflowClient $workflowClient,
        StandaloneWorkflowWorker $worker,
        string $workflowId,
        string $updateName,
        array $arguments,
        string $requestId,
        string $waitFor = 'accepted',
    ): array {
        $client = $this->requestUpdate($workflowClient, $workflowId, $updateName, $arguments, $requestId, $waitFor);

        if (isset($client['exception'])) {
            return [
                'client' => $client,
            ];
        }

        $workerTask = $worker->processOneWorkflowTask($this->taskQueue(), $this->runId().'-php-update-worker', $this->pollTimeoutSeconds());
        $command = $this->firstCommand($workerTask);

        return [
            'client' => $client,
            'worker' => $workerTask,
            'command' => $command,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestUpdate(
        WorkflowClient $workflowClient,
        string $workflowId,
        string $updateName,
        array $arguments,
        string $requestId,
        string $waitFor = 'accepted',
    ): array {
        try {
            $response = $workflowClient->updateWorkflow(
                $workflowId,
                $updateName,
                $arguments,
                waitFor: $waitFor,
                waitTimeoutSeconds: $this->pollTimeoutSeconds(),
                requestId: $requestId,
            );

            return [
                'outcome' => 'response',
                'request' => [
                    'workflow_id' => $workflowId,
                    'update_name' => $updateName,
                    'request_id' => $requestId,
                    'wait_for' => $waitFor,
                ],
                'response' => $this->decorateClientResponse($response),
            ];
        } catch (WorkflowClientException $exception) {
            $body = $exception->body();

            return [
                'outcome' => 'exception',
                'request' => [
                    'workflow_id' => $workflowId,
                    'update_name' => $updateName,
                    'request_id' => $requestId,
                    'wait_for' => $waitFor,
                ],
                'exception' => [
                    'class' => $exception::class,
                    'status' => $exception->statusCode(),
                    'body' => $this->decorateClientResponse($body),
                    'message' => $exception->getMessage(),
                ],
            ];
        }
    }

    /**
     * @param array<string, mixed> $workerTask
     * @return array<string, mixed>
     */
    private function firstCommand(array $workerTask): array
    {
        $commands = $workerTask['commands'] ?? null;

        if (! is_array($commands) || ! is_array($commands[0] ?? null)) {
            return [];
        }

        $command = $commands[0];

        if (($command['type'] ?? null) === 'complete_update') {
            $command['decoded_result'] = $this->decodePayloadEnvelope($command['result'] ?? null);
        }

        return $command;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function decorateClientResponse(array $response): array
    {
        $envelope = $response['result_envelope'] ?? null;

        if (is_array($envelope)) {
            $response['decoded_result'] = $this->decodePayloadEnvelope($envelope);
        } elseif (array_key_exists('result', $response)) {
            $response['decoded_result'] = $response['result'];
        }

        return $response;
    }

    private function decodePayloadEnvelope(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            return null;
        }

        $codec = is_string($payload['codec'] ?? null) ? $payload['codec'] : 'avro';
        $blob = is_string($payload['blob'] ?? null) ? $payload['blob'] : null;

        if ($blob === null) {
            return null;
        }

        try {
            return Serializer::unserializeWithCodec($codec, $blob);
        } catch (Throwable $exception) {
            return [
                'decode_error' => [
                    'class' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            ];
        }
    }

    /**
     * @param array<string, mixed> $client
     * @return array{status: string, evidence: array<string, mixed>}
     */
    private function acceptedCell(array $client): array
    {
        $response = is_array($client['response'] ?? null) ? $client['response'] : [];
        $accepted = ($response['accepted'] ?? false) === true
            || in_array($response['update_status'] ?? null, ['accepted', 'completed'], true);

        return [
            'status' => $accepted ? 'pass' : 'fail',
            'evidence' => $client,
        ];
    }

    /**
     * @param array<string, mixed> $command
     * @return array{status: string, evidence: array<string, mixed>}
     */
    private function completeCommandCell(array $command): array
    {
        return [
            'status' => ($command['type'] ?? null) === 'complete_update' ? 'pass' : 'fail',
            'evidence' => $command,
        ];
    }

    /**
     * @param array<string, mixed> $client
     * @param array<string, mixed> $command
     * @return array{status: string, evidence: array<string, mixed>}
     */
    private function completedClientCell(array $client, array $command): array
    {
        $response = is_array($client['response'] ?? null) ? $client['response'] : [];
        $clientCompleted = ($client['outcome'] ?? null) === 'response'
            && ($response['update_status'] ?? null) === 'completed'
            && ($response['wait_timed_out'] ?? false) === false;
        $handlerCompleted = ($this->completeCommandCell($command)['status'] ?? null) === 'pass';
        $passed = $clientCompleted && $handlerCompleted;

        return [
            'status' => $passed ? 'pass' : 'fail',
            'evidence' => [
                'client' => $client,
                'handler_command' => $command,
                'checks' => [
                    'client_completed' => $clientCompleted,
                    'handler_completed' => $handlerCompleted,
                    'observed_update_status' => $response['update_status'] ?? null,
                    'wait_timed_out' => $response['wait_timed_out'] ?? null,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $command
     * @return array{status: string, evidence: array<string, mixed>}
     */
    private function failCommandCell(array $command): array
    {
        return [
            'status' => ($command['type'] ?? null) === 'fail_update' ? 'pass' : 'fail',
            'evidence' => $command,
        ];
    }

    /**
     * @param array<string, mixed> $client
     * @param array<string, mixed> $command
     * @return array{status: string, evidence: array<string, mixed>}
     */
    private function failedClientCell(array $client, array $command): array
    {
        $response = is_array($client['response'] ?? null) ? $client['response'] : [];
        $exception = is_array($client['exception'] ?? null) ? $client['exception'] : [];
        $body = is_array($exception['body'] ?? null) ? $exception['body'] : [];
        $exceptionStatus = is_int($exception['status'] ?? null) ? $exception['status'] : null;
        $clientFailedResponse = ($client['outcome'] ?? null) === 'response'
            && (($response['update_status'] ?? null) === 'failed'
                || ($response['outcome'] ?? null) === 'update_failed');
        $clientFailedException = ($client['outcome'] ?? null) === 'exception'
            && ($exceptionStatus === null || in_array($exceptionStatus, [400, 409, 422], true))
            && (($body['update_status'] ?? null) === 'failed'
                || ($body['outcome'] ?? null) === 'update_failed'
                || is_string($body['failure_message'] ?? null));
        $handlerFailed = ($this->failCommandCell($command)['status'] ?? null) === 'pass';
        $passed = $handlerFailed && ($clientFailedResponse || $clientFailedException);

        return [
            'status' => $passed ? 'pass' : 'fail',
            'evidence' => [
                'client' => $client,
                'handler_command' => $command,
                'checks' => [
                    'client_failed_response' => $clientFailedResponse,
                    'client_failed_exception' => $clientFailedException,
                    'handler_failed' => $handlerFailed,
                    'observed_response_update_status' => $response['update_status'] ?? null,
                    'observed_exception_update_status' => $body['update_status'] ?? null,
                    'observed_exception_status' => $exceptionStatus,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $request
     * @param list<string> $acceptedReasons
     * @return array{status: string, evidence: array<string, mixed>}
     */
    private function exceptionCell(array $request, array $acceptedReasons): array
    {
        $exception = is_array($request['exception'] ?? null) ? $request['exception'] : null;
        $body = is_array($exception['body'] ?? null) ? $exception['body'] : [];
        $observedReasons = array_values(array_filter([
            self::nonEmptyString($body['reason'] ?? null),
            self::nonEmptyString($body['rejection_reason'] ?? null),
            self::nonEmptyString($body['command_reason'] ?? null),
            self::nonEmptyString($body['outcome'] ?? null),
        ]));
        $reason = $observedReasons[0] ?? null;
        $status = is_int($exception['status'] ?? null) ? $exception['status'] : null;
        $reasonAccepted = $acceptedReasons === []
            || $observedReasons === []
            || array_intersect($observedReasons, $acceptedReasons) !== [];
        $passed = $exception !== null
            && ($status === null || in_array($status, [400, 404, 409, 422], true))
            && $reasonAccepted;

        $evidence = $request;
        $evidence['checks'] = [
            'accepted_reasons' => $acceptedReasons,
            'observed_reason' => $reason,
            'observed_reasons' => $observedReasons,
            'reason_accepted' => $reasonAccepted,
            'observed_status' => $status,
        ];

        return [
            'status' => $passed ? 'pass' : 'fail',
            'evidence' => $evidence,
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array{status: string, evidence: array<string, mixed>}
     */
    private function unsupportedCell(array $evidence): array
    {
        return [
            'status' => 'unsupported',
            'evidence' => $evidence,
        ];
    }

    /**
     * @param array<string, mixed> $original
     * @param array<string, mixed> $duplicate
     * @return array{status: string, evidence: array<string, mixed>}
     */
    private function duplicateCell(array $original, array $duplicate): array
    {
        $originalRequest = is_array($original['request'] ?? null) ? $original['request'] : [];
        $duplicateRequest = is_array($duplicate['request'] ?? null) ? $duplicate['request'] : [];
        $originalResponse = is_array($original['response'] ?? null) ? $original['response'] : [];
        $duplicateResponse = is_array($duplicate['response'] ?? null) ? $duplicate['response'] : [];
        $originalUpdateId = self::nonEmptyString($originalResponse['update_id'] ?? null);
        $duplicateUpdateId = self::nonEmptyString($duplicateResponse['update_id'] ?? null);
        $sameUpdateId = $originalUpdateId !== null && $originalUpdateId === $duplicateUpdateId;
        $sameRequest = self::sameRequestField($originalRequest, $duplicateRequest, 'workflow_id')
            && self::sameRequestField($originalRequest, $duplicateRequest, 'update_name')
            && self::sameRequestField($originalRequest, $duplicateRequest, 'request_id');
        $originalAcceptedResponse = ($original['outcome'] ?? null) === 'response'
            && (
                ($originalResponse['accepted'] ?? null) === true
                || in_array($originalResponse['update_status'] ?? null, ['accepted', 'completed'], true)
            );
        $duplicateAcceptedResponse = ($duplicate['outcome'] ?? null) === 'response'
            && (
                ($duplicateResponse['accepted'] ?? null) === true
                || in_array($duplicateResponse['update_status'] ?? null, ['accepted', 'completed'], true)
            );
        $passed = $sameRequest && $sameUpdateId && $originalAcceptedResponse && $duplicateAcceptedResponse;
        $evidence = $duplicate;
        $evidence['original'] = $original;
        $evidence['checks'] = [
            'same_request' => $sameRequest,
            'same_update_id' => $sameUpdateId,
            'original_accepted_response' => $originalAcceptedResponse,
            'duplicate_accepted_response' => $duplicateAcceptedResponse,
            'original_update_id' => $originalUpdateId,
            'duplicate_update_id' => $duplicateUpdateId,
        ];

        return [
            'status' => $passed ? 'pass' : 'fail',
            'evidence' => $evidence,
        ];
    }

    /**
     * @param array<string, mixed> $command
     * @param array<string, mixed> $client
     * @param array<string, mixed> $expected
     * @return array{status: string, evidence: array<string, mixed>}
     */
    private function payloadCell(array $command, array $client, array $expected): array
    {
        $commandDecoded = is_array($command['decoded_result'] ?? null) ? $command['decoded_result'] : [];
        $response = is_array($client['response'] ?? null) ? $client['response'] : [];
        $clientDecoded = is_array($response['decoded_result'] ?? null) ? $response['decoded_result'] : [];
        $commandReceived = is_array($commandDecoded['received'] ?? null) ? $commandDecoded['received'] : null;
        $clientReceived = is_array($clientDecoded['received'] ?? null) ? $clientDecoded['received'] : null;
        $passed = $commandReceived === $expected && $clientReceived === $expected;

        return [
            'status' => $passed ? 'pass' : 'fail',
            'evidence' => [
                'handler_command' => $command,
                'client' => $client,
                'checks' => [
                    'handler_received_expected_payload' => $commandReceived === $expected,
                    'client_received_expected_payload' => $clientReceived === $expected,
                ],
            ],
        ];
    }

    /**
     * @param array<string, string> $artifactVersions
     * @param array<string, string> $artifactSources
     * @param array<string, mixed> $handlerBehavior
     * @param array<string, mixed> $clientRequests
     * @param list<string> $coveredCells
     * @param list<array<string, mixed>> $unsupportedCells
     * @param list<array<string, mixed>> $typedErrors
     * @param array<string, array<string, mixed>> $cellOutcomes
     * @param list<string> $missingRequiredCells
     * @param bool $publishedArtifactCellExecuted
     * @return array<string, mixed>
     */
    private function phpSurfaceOutputs(
        array $artifactVersions,
        array $artifactSources,
        array $handlerBehavior,
        array $clientRequests,
        array $coveredCells,
        array $unsupportedCells,
        array $typedErrors = [],
        array $cellOutcomes = [],
        array $missingRequiredCells = [],
        bool $publishedArtifactCellExecuted = true,
    ): array {
        return [
            'workflow_php_artifact_version' => $artifactVersions['workflow-php'] ?? null,
            'workflow_php_artifact_source' => $artifactSources['workflow-php'] ?? null,
            'php_worker_update_handler' => $handlerBehavior,
            'php_client_update_request' => $clientRequests,
            'covered_cells' => $coveredCells,
            'required_cells' => self::PHP_REQUIRED_CELLS,
            'unsupported_cells' => $unsupportedCells,
            'typed_errors' => $typedErrors,
            'cell_outcomes' => $cellOutcomes,
            'missing_required_cells' => $missingRequiredCells,
            'published_artifact_cell_executed' => $publishedArtifactCellExecuted,
            'local_product_source_checkouts_used' => false,
        ];
    }

    private function declaredContractScenario(): array
    {
        $workflowType = $this->workflowType();
        $contract = WorkflowDefinition::commandContract(WorkflowUpdatesConformanceWorkflow::class);
        $updates = $contract['updates'] ?? [];
        $expected = ['adjust_payload', 'approve', 'fail_update'];
        sort($updates);
        sort($expected);
        $passed = $updates === $expected;

        return [
            'scenario_id' => 'declared_update_contract_visibility',
            'status' => $passed ? 'pass' : 'fail',
            'observed_outputs' => [
                'workflow_type' => $workflowType,
                'workflow_class' => WorkflowUpdatesConformanceWorkflow::class,
                'declared_updates' => $updates,
                'update_contracts' => $contract['update_contracts'] ?? [],
            ],
            'linked_findings' => $passed
                ? []
                : [$this->finding(
                    'declared_update_contract_visibility',
                    'PHP workflow update conformance workflow did not advertise every required update handler.',
                    ['declared_updates' => $updates, 'expected_updates' => $expected],
                    'workflow',
                )],
        ];
    }

    /**
     * @param array<string, string> $artifactVersions
     * @param array<string, string> $artifactSources
     * @return array<string, mixed>
     */
    private function publishedArtifactScenario(array $artifactVersions, array $artifactSources): array
    {
        $missingVersions = array_values(array_filter(
            self::REQUIRED_ARTIFACTS,
            static fn (string $artifact): bool => ! isset($artifactVersions[$artifact])
                || trim($artifactVersions[$artifact]) === '',
        ));
        $invalidSources = [];

        foreach (self::REQUIRED_ARTIFACTS as $artifact) {
            $source = $artifactSources[$artifact] ?? null;

            if ($source === null || ! in_array($source, self::PUBLISHED_ARTIFACT_SOURCES[$artifact], true)) {
                $invalidSources[$artifact] = $source;
            }
        }

        $passed = $missingVersions === [] && $invalidSources === [];

        return [
            'scenario_id' => 'published_artifact_install_only',
            'status' => $passed ? 'pass' : 'fail',
            'observed_outputs' => [
                'artifact_versions' => $artifactVersions,
                'artifact_sources' => $artifactSources,
                'missing_versions' => $missingVersions,
                'invalid_sources' => $invalidSources,
                'local_source_checkout_used' => false,
            ],
            'linked_findings' => $passed
                ? []
                : [$this->finding(
                    'published_artifact_install_only',
                    'Workflow update PHP shard did not prove a complete published artifact tuple.',
                    ['missing_versions' => $missingVersions, 'invalid_sources' => $invalidSources],
                    'workflow',
                )],
        ];
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
            ],
            'linked_findings' => [],
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
                'coverage_scope' => 'workflow-php-updates-shard',
            ],
            'linked_findings' => [],
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function failedScenario(string $scenarioId, string $message, array $evidence, string $productArea): array
    {
        return [
            'scenario_id' => $scenarioId,
            'status' => 'fail',
            'observed_outputs' => $evidence,
            'linked_findings' => [
                $this->finding($scenarioId, $message, $evidence, $productArea),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function finding(string $scenarioId, string $message, array $evidence, string $productArea): array
    {
        return [
            'scenario_id' => $scenarioId,
            'product_area' => $productArea,
            'message' => $message,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $scenarioResults
     * @param list<array<string, mixed>> $findings
     * @param list<array<string, mixed>> $findingLinks
     * @param array<string, mixed> $scenario
     */
    private function appendScenario(
        array &$scenarioResults,
        array &$findings,
        array &$findingLinks,
        array $scenario,
    ): void {
        $scenarioId = (string) ($scenario['scenario_id'] ?? 'unknown');
        $scenarioResults[$scenarioId] = $scenario;

        foreach ($scenario['linked_findings'] ?? [] as $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $findingId = sprintf('workflow-updates:%s:%d', $scenarioId, count($findings) + 1);
            $finding['id'] = $findingId;
            $findings[] = $finding;
            $findingLinks[] = [
                'scenario_id' => $scenarioId,
                'finding_id' => $findingId,
            ];
        }
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
     * @param array<string, mixed> $report
     */
    private function emit(array $report): void
    {
        $json = $this->emitJson($report);
        $outputPath = $this->stringOption('output');

        if ($outputPath !== null) {
            $this->files->put($outputPath, $json.PHP_EOL);

            return;
        }

        $this->line($json);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function emitJson(array $report): string
    {
        try {
            return json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Workflow update conformance report could not be encoded as JSON.', 0, $exception);
        }
    }

    private function namespace(): string
    {
        return $this->stringOption('namespace') ?? 'default';
    }

    private function taskQueue(): string
    {
        return $this->stringOption('task-queue') ?? 'workflow-updates-php';
    }

    private function workflowType(): string
    {
        return $this->stringOption('workflow-type') ?? WorkflowUpdatesConformanceWorkflow::TYPE_KEY;
    }

    private function runId(): string
    {
        if ($this->resolvedRunId !== null) {
            return $this->resolvedRunId;
        }

        $provided = $this->stringOption('run-id');

        if ($provided !== null) {
            $this->resolvedRunId = $provided;

            return $this->resolvedRunId;
        }

        $this->resolvedRunId = 'php-updates-'.bin2hex(random_bytes(4));

        return $this->resolvedRunId;
    }

    private function pollTimeoutSeconds(): int
    {
        $raw = $this->option('poll-timeout');

        if (is_int($raw)) {
            return max(0, $raw);
        }

        if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1) {
            return max(0, (int) $raw);
        }

        return 1;
    }

    /**
     * @return array<string, string>
     */
    private function artifactVersions(): array
    {
        return $this->keyValueOptions((array) $this->option('artifact-version'));
    }

    /**
     * @return array<string, string>
     */
    private function artifactSources(): array
    {
        return $this->keyValueOptions((array) $this->option('artifact-source'));
    }

    /**
     * @param list<string> $values
     * @return array<string, string>
     */
    private function keyValueOptions(array $values): array
    {
        $parsed = [];

        foreach ($values as $value) {
            if (! is_string($value) || ! str_contains($value, '=')) {
                continue;
            }

            [$key, $raw] = explode('=', $value, 2);
            $key = $this->artifactKey(trim($key));
            $raw = trim($raw);

            if ($key !== null && $raw !== '') {
                $parsed[$key] = $raw;
            }
        }

        ksort($parsed);

        return $parsed;
    }

    private function artifactKey(string $key): ?string
    {
        return match ($key) {
            'workflow', 'workflow-php', 'php', 'package' => 'workflow-php',
            'python', 'sdk-python' => 'sdk-python',
            'server', 'cli', 'waterline' => $key,
            default => null,
        };
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param array<string, mixed> $original
     * @param array<string, mixed> $duplicate
     */
    private static function sameRequestField(array $original, array $duplicate, string $field): bool
    {
        $originalValue = self::nonEmptyString($original[$field] ?? null);
        $duplicateValue = self::nonEmptyString($duplicate[$field] ?? null);

        return $originalValue !== null && $originalValue === $duplicateValue;
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function timestamp(): string
    {
        return gmdate('c');
    }
}
