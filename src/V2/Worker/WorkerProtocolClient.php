<?php

declare(strict_types=1);

namespace Workflow\V2\Worker;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use RuntimeException;
use Workflow\V2\Support\WorkerHeartbeatTelemetry;
use Workflow\V2\Support\WorkerProtocolVersion;

/**
 * Worker-plane HTTP client for Durable Workflow worker surfaces.
 *
 * This is the PHP SDK's reusable shim for processes that poll, execute,
 * heartbeat, complete, or fail worker tasks without embedding the Laravel
 * queue runner. The default mode targets the standalone server's
 * /api/worker protocol. Embedded package /webhooks routes remain available
 * when explicitly requested through the constructor.
 *
 * @api Stable v2 worker protocol API.
 */
final class WorkerProtocolClient
{
    public const DEFAULT_RUNTIME = 'php';

    public const DEFAULT_SDK_VERSION = 'durable-workflow-php/sdk';

    private readonly string $baseUrl;

    private readonly string $protocolVersion;

    private readonly string $workerApiPath;

    private readonly string $bridgePath;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $workflowTaskLeases = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $activityTaskLeases = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $queryTaskLeases = [];

    /**
     * @var array<string, string>
     */
    private array $activityAttemptTaskIds = [];

    private ?string $registeredWorkerId = null;

    private ?string $registeredTaskQueue = null;

    private ?string $registeredBuildId = null;

    private readonly int $processStartedAt;

    public function __construct(
        private readonly HttpFactory $http,
        string $baseUrl,
        private readonly string $token,
        private readonly string $namespace = 'default',
        ?string $protocolVersion = null,
        private readonly int $defaultRequestTimeoutSeconds = 30,
        string $workerApiPath = '/api/worker',
        ?string $bridgePath = null,
        private readonly bool $embeddedBridgeMode = false,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->protocolVersion = $protocolVersion ?? WorkerProtocolVersion::VERSION;
        $this->workerApiPath = self::normalizePath($workerApiPath);
        $this->bridgePath = self::normalizePath($bridgePath ?? (
            $this->embeddedBridgeMode ? '/webhooks' : $this->workerApiPath
        ));

        if ($this->defaultRequestTimeoutSeconds < 1) {
            throw new InvalidArgumentException('Default request timeout must be at least 1 second.');
        }

        if (trim($this->namespace) === '') {
            throw new InvalidArgumentException('Namespace must not be empty.');
        }

        $this->processStartedAt = time();
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function withNamespace(string $namespace): self
    {
        return new self(
            $this->http,
            $this->baseUrl,
            $this->token,
            $namespace,
            $this->protocolVersion,
            $this->defaultRequestTimeoutSeconds,
            $this->workerApiPath,
            $this->bridgePath,
            $this->embeddedBridgeMode,
        );
    }

    /**
     * @param list<string> $supportedWorkflowTypes
     * @param list<string> $supportedActivityTypes
     * @param array<string, string>|null $workflowDefinitionFingerprints
     * @param list<string>|null $capabilities
     * @param array<string, array<string, mixed>>|null $workflowCommandContracts
     * @return array<string, mixed>|null
     */
    public function registerWorker(
        string $workerId,
        string $taskQueue,
        array $supportedWorkflowTypes = [],
        array $supportedActivityTypes = [],
        string $runtime = self::DEFAULT_RUNTIME,
        ?string $sdkVersion = null,
        ?string $buildId = null,
        ?int $maxConcurrentWorkflowTasks = null,
        ?int $maxConcurrentActivityTasks = null,
        ?array $workflowDefinitionFingerprints = null,
        ?array $capabilities = null,
        ?array $workflowCommandContracts = null,
    ): ?array {
        if ($maxConcurrentWorkflowTasks !== null && $maxConcurrentWorkflowTasks < 1) {
            throw new InvalidArgumentException('maxConcurrentWorkflowTasks must be at least 1.');
        }

        if ($maxConcurrentActivityTasks !== null && $maxConcurrentActivityTasks < 1) {
            throw new InvalidArgumentException('maxConcurrentActivityTasks must be at least 1.');
        }

        $body = [
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
            'runtime' => $runtime,
            'sdk_version' => $sdkVersion ?? self::DEFAULT_SDK_VERSION,
            'supported_workflow_types' => $supportedWorkflowTypes,
            'supported_activity_types' => $supportedActivityTypes,
            'process_metrics' => WorkerHeartbeatTelemetry::processMetrics($this->processStartedAt),
        ];

        if ($workflowDefinitionFingerprints !== null) {
            $body['workflow_definition_fingerprints'] = $workflowDefinitionFingerprints;
        }

        if ($workflowCommandContracts !== null) {
            $body['workflow_command_contracts'] = $workflowCommandContracts;
        }

        $advertisedCapabilities = $this->workerCapabilities($supportedWorkflowTypes, $capabilities);
        if ($advertisedCapabilities !== null) {
            $body['capabilities'] = $advertisedCapabilities;
        }

        if ($buildId !== null) {
            $body['build_id'] = $buildId;
        }

        if ($maxConcurrentWorkflowTasks !== null) {
            $body['max_concurrent_workflow_tasks'] = $maxConcurrentWorkflowTasks;
        }

        if ($maxConcurrentActivityTasks !== null) {
            $body['max_concurrent_activity_tasks'] = $maxConcurrentActivityTasks;
        }

        $response = $this->workerPost($this->workerApiPath.'/register', $body, allowedStatuses: [200, 201]);

        $this->registeredWorkerId = $workerId;
        $this->registeredTaskQueue = $taskQueue;
        $this->registeredBuildId = $buildId;

        return $response;
    }

    /**
     * @param array<string, int>|null $taskSlots
     * @param array<string, mixed>|null $processMetrics
     * @return array<string, mixed>|null
     */
    public function heartbeatWorker(
        string $workerId,
        ?array $taskSlots = null,
        ?array $processMetrics = null,
        ?int $heartbeatIntervalSeconds = null,
    ): ?array {
        $body = ['worker_id' => $workerId];

        if ($taskSlots !== null) {
            $body['task_slots'] = $taskSlots;
        }

        if ($processMetrics !== null) {
            $body['process_metrics'] = $processMetrics;
        }

        if ($heartbeatIntervalSeconds !== null) {
            $body['heartbeat_interval_seconds'] = $heartbeatIntervalSeconds;
        }

        return $this->workerPost($this->workerApiPath.'/heartbeat', $body);
    }

    /**
     * Poll for workflow work. Standalone server polls lease the returned
     * task immediately; embedded bridge polls return unleased opportunities
     * that still need an explicit claim.
     *
     * @return list<array<string, mixed>>
     */
    public function pollWorkflowTasks(
        ?string $connection = null,
        ?string $queue = null,
        int $limit = 1,
        ?string $compatibility = null,
        int $timeoutSeconds = WorkerProtocolVersion::DEFAULT_LONG_POLL_TIMEOUT,
        ?string $workerId = null,
        ?string $buildId = null,
        ?string $pollRequestId = null,
        ?int $historyPageSize = null,
        ?string $acceptHistoryEncoding = null,
    ): array {
        if ($this->embeddedBridgeMode) {
            return $this->pollEmbeddedTaskList(
                $this->bridgePath.'/workflow-tasks/poll',
                $connection,
                $queue,
                $limit,
                $compatibility,
                $timeoutSeconds,
            );
        }

        return $this->pollStandaloneWorkflowTasks(
            $queue,
            $timeoutSeconds,
            $workerId,
            $buildId,
            $pollRequestId,
            $historyPageSize,
            $acceptHistoryEncoding,
        );
    }

    /**
     * Claim a workflow task and return the claim payload, or null on a
     * normal lost-race / not-claimable result.
     *
     * @return array<string, mixed>|null
     */
    public function claimWorkflowTask(string $taskId, ?string $leaseOwner = null): ?array
    {
        $status = $this->claimWorkflowTaskStatus($taskId, $leaseOwner);

        return ($status['claimed'] ?? false) === true ? $status : null;
    }

    /**
     * Claim a workflow task and return the full typed claim outcome.
     *
     * @return array<string, mixed>
     */
    public function claimWorkflowTaskStatus(string $taskId, ?string $leaseOwner = null): array
    {
        if ($this->embeddedBridgeMode) {
            return $this->claimTask(
                $this->bridgePath.'/workflow-tasks/'.$this->pathSegment($taskId).'/claim',
                $leaseOwner,
            );
        }

        return $this->standaloneWorkflowClaimStatus($taskId, $leaseOwner);
    }

    /**
     * @param list<array<string, mixed>> $commands
     * @return array<string, mixed>|null
     */
    public function completeWorkflowTask(
        string $taskId,
        array $commands,
        ?string $leaseOwner = null,
        ?int $workflowTaskAttempt = null,
    ): ?array {
        if ($this->embeddedBridgeMode) {
            return $this->bridgePost($this->bridgePath.'/workflow-tasks/'.$this->pathSegment($taskId).'/complete', [
                'commands' => $commands,
            ], allowedStatuses: [200, 404, 409, 422]);
        }

        if ($commands === []) {
            return $this->failWorkflowTask(
                $taskId,
                'Workflow task waiting for scheduled history.',
                'WorkflowTaskWaitingForHistory',
                leaseOwner: $leaseOwner,
                workflowTaskAttempt: $workflowTaskAttempt,
            );
        }

        return $this->workerPost($this->workerApiPath.'/workflow-tasks/'.$this->pathSegment($taskId).'/complete', [
            'lease_owner' => $this->resolveWorkflowLeaseOwner($taskId, $leaseOwner),
            'workflow_task_attempt' => $this->resolveWorkflowTaskAttempt($taskId, $workflowTaskAttempt),
            'commands' => $commands,
        ], allowedStatuses: [200, 404, 409, 422]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function failWorkflowTask(
        string $taskId,
        string $message,
        ?string $failureType = null,
        ?string $stackTrace = null,
        ?string $leaseOwner = null,
        ?int $workflowTaskAttempt = null,
    ): ?array {
        $failure = ['message' => $message];

        if ($failureType !== null) {
            $failure['type'] = $failureType;
        }

        if ($stackTrace !== null) {
            $failure['stack_trace'] = $stackTrace;
        }

        if ($this->embeddedBridgeMode) {
            return $this->bridgePost($this->bridgePath.'/workflow-tasks/'.$this->pathSegment($taskId).'/fail', [
                'failure' => $failure,
            ], allowedStatuses: [200, 404, 409, 422]);
        }

        return $this->workerPost($this->workerApiPath.'/workflow-tasks/'.$this->pathSegment($taskId).'/fail', [
            'lease_owner' => $this->resolveWorkflowLeaseOwner($taskId, $leaseOwner),
            'workflow_task_attempt' => $this->resolveWorkflowTaskAttempt($taskId, $workflowTaskAttempt),
            'failure' => $failure,
        ], allowedStatuses: [200, 404, 409, 422]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function workflowTaskHistory(
        string $taskId,
        ?string $leaseOwner = null,
        ?int $workflowTaskAttempt = null,
        ?string $nextHistoryPageToken = null,
        ?int $historyPageSize = null,
        ?string $acceptHistoryEncoding = null,
    ): ?array
    {
        if ($this->embeddedBridgeMode) {
            $response = $this->bridgeGet(
                $this->bridgePath.'/workflow-tasks/'.$this->pathSegment($taskId).'/history',
                allowedStatuses: [200, 404],
            );

            return ($response['reason'] ?? null) === 'task_not_found' ? null : $response;
        }

        $body = [
            'lease_owner' => $this->resolveWorkflowLeaseOwner($taskId, $leaseOwner),
            'workflow_task_attempt' => $this->resolveWorkflowTaskAttempt($taskId, $workflowTaskAttempt),
            'next_history_page_token' => $this->resolveWorkflowHistoryPageToken($taskId, $nextHistoryPageToken),
        ];

        if ($historyPageSize !== null) {
            $body['history_page_size'] = $historyPageSize;
        }

        if ($acceptHistoryEncoding !== null && $acceptHistoryEncoding !== '') {
            $body['accept_history_encoding'] = $acceptHistoryEncoding;
        }

        $response = $this->workerPost(
            $this->workerApiPath.'/workflow-tasks/'.$this->pathSegment($taskId).'/history',
            $body,
            allowedStatuses: [200, 404],
        );

        return ($response['reason'] ?? null) === 'task_not_found' ? null : $response;
    }

    /**
     * Poll for worker-routed workflow query work.
     *
     * @return list<array<string, mixed>>
     */
    public function pollQueryTasks(
        ?string $queue = null,
        int $timeoutSeconds = WorkerProtocolVersion::DEFAULT_LONG_POLL_TIMEOUT,
        ?string $workerId = null,
        ?string $pollRequestId = null,
    ): array {
        if ($this->embeddedBridgeMode) {
            return [];
        }

        $pollRequestId = is_string($pollRequestId) && trim($pollRequestId) !== ''
            ? trim($pollRequestId)
            : 'query-poll-'.bin2hex(random_bytes(16));
        $body = [
            'worker_id' => $this->resolveStandaloneWorkerId($workerId),
            'task_queue' => $this->resolveStandaloneTaskQueue($queue),
            'poll_request_id' => $pollRequestId,
        ];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = $this->workerPost(
                    $this->workerApiPath.'/query-tasks/poll',
                    $body,
                    $this->longPollRequestTimeoutSeconds($timeoutSeconds),
                );
            } catch (ConnectionException $exception) {
                if ($this->isHttpTimeout($exception)) {
                    continue;
                }

                throw $exception;
            }

            $task = $response['task'] ?? null;
            if (! is_array($task)) {
                return [];
            }

            $this->rememberQueryTaskLease($task);

            return [$task];
        }

        return [];
    }

    /**
     * @param array{codec: string, blob?: string|null, external_storage?: array<string, mixed>}|null $resultEnvelope
     * @return array<string, mixed>|null
     */
    public function completeQueryTask(
        string $queryTaskId,
        mixed $result = null,
        ?array $resultEnvelope = null,
        ?string $leaseOwner = null,
        ?int $queryTaskAttempt = null,
    ): ?array {
        $body = [
            'lease_owner' => $this->resolveQueryLeaseOwner($queryTaskId, $leaseOwner),
            'query_task_attempt' => $this->resolveQueryTaskAttempt($queryTaskId, $queryTaskAttempt),
            'result' => $result,
        ];

        if ($resultEnvelope !== null) {
            $body['result_envelope'] = $resultEnvelope;
        }

        return $this->workerPost(
            $this->workerApiPath.'/query-tasks/'.$this->pathSegment($queryTaskId).'/complete',
            $body,
            allowedStatuses: [200, 404, 409, 422],
        );
    }

    /**
     * @param array<string, list<string>>|null $validationErrors
     * @return array<string, mixed>|null
     */
    public function failQueryTask(
        string $queryTaskId,
        string $message,
        ?string $reason = null,
        ?string $failureType = null,
        ?string $stackTrace = null,
        ?string $leaseOwner = null,
        ?int $queryTaskAttempt = null,
        ?array $validationErrors = null,
    ): ?array {
        $failure = ['message' => $message];

        if ($reason !== null) {
            $failure['reason'] = $reason;
        }

        if ($failureType !== null) {
            $failure['type'] = $failureType;
        }

        if ($stackTrace !== null) {
            $failure['stack_trace'] = $stackTrace;
        }

        if ($validationErrors !== null) {
            $failure['validation_errors'] = $validationErrors;
        }

        return $this->workerPost(
            $this->workerApiPath.'/query-tasks/'.$this->pathSegment($queryTaskId).'/fail',
            [
                'lease_owner' => $this->resolveQueryLeaseOwner($queryTaskId, $leaseOwner),
                'query_task_attempt' => $this->resolveQueryTaskAttempt($queryTaskId, $queryTaskAttempt),
                'failure' => $failure,
            ],
            allowedStatuses: [200, 404, 409, 422],
        );
    }

    /**
     * Poll for activity work. Standalone server polls lease the returned
     * task immediately; embedded bridge polls return unleased opportunities
     * that still need an explicit claim.
     *
     * @return list<array<string, mixed>>
     */
    public function pollActivityTasks(
        ?string $connection = null,
        ?string $queue = null,
        int $limit = 1,
        ?string $compatibility = null,
        int $timeoutSeconds = WorkerProtocolVersion::DEFAULT_LONG_POLL_TIMEOUT,
        ?string $workerId = null,
        ?string $buildId = null,
    ): array {
        if ($this->embeddedBridgeMode) {
            return $this->pollEmbeddedTaskList(
                $this->bridgePath.'/activity-tasks/poll',
                $connection,
                $queue,
                $limit,
                $compatibility,
                $timeoutSeconds,
            );
        }

        return $this->pollStandaloneActivityTasks($queue, $timeoutSeconds, $workerId, $buildId);
    }

    /**
     * Claim an activity task and return the claim payload, or null on a
     * normal lost-race / not-claimable result.
     *
     * @return array<string, mixed>|null
     */
    public function claimActivityTask(string $taskId, ?string $leaseOwner = null): ?array
    {
        $status = $this->claimActivityTaskStatus($taskId, $leaseOwner);

        return ($status['claimed'] ?? false) === true ? $status : null;
    }

    /**
     * Claim an activity task and return the full typed claim outcome.
     *
     * @return array<string, mixed>
     */
    public function claimActivityTaskStatus(string $taskId, ?string $leaseOwner = null): array
    {
        if ($this->embeddedBridgeMode) {
            return $this->claimTask(
                $this->bridgePath.'/activity-tasks/'.$this->pathSegment($taskId).'/claim',
                $leaseOwner,
            );
        }

        return $this->standaloneActivityClaimStatus($taskId, $leaseOwner);
    }

    /**
     * @param mixed $result The serialized activity result.
     * @return array<string, mixed>|null
     */
    public function completeActivityAttempt(
        string $activityAttemptId,
        mixed $result,
        ?string $taskId = null,
        ?string $leaseOwner = null,
    ): ?array
    {
        if ($this->embeddedBridgeMode) {
            return $this->bridgePost(
                $this->bridgePath.'/activity-attempts/'.$this->pathSegment($activityAttemptId).'/complete',
                ['result' => $result],
                allowedStatuses: [200, 404, 409, 422],
            );
        }

        $resolvedTaskId = $this->resolveActivityTaskId($activityAttemptId, $taskId);

        return $this->workerPost(
            $this->workerApiPath.'/activity-tasks/'.$this->pathSegment($resolvedTaskId).'/complete',
            [
                'activity_attempt_id' => $activityAttemptId,
                'lease_owner' => $this->resolveActivityLeaseOwner($resolvedTaskId, $leaseOwner),
                'result' => $result,
            ],
            allowedStatuses: [200, 404, 409, 422],
        );
    }

    /**
     * @param array<string, mixed>|string|null $details
     * @return array<string, mixed>|null
     */
    public function failActivityAttempt(
        string $activityAttemptId,
        string $message,
        ?string $failureType = null,
        ?string $stackTrace = null,
        bool $nonRetryable = false,
        array|string|null $details = null,
        ?string $taskId = null,
        ?string $leaseOwner = null,
    ): ?array {
        $failure = ['message' => $message];

        if ($failureType !== null) {
            $failure['type'] = $failureType;
        }

        if ($stackTrace !== null) {
            $failure['stack_trace'] = $stackTrace;
        }

        if ($nonRetryable) {
            $failure['non_retryable'] = true;
        }

        if ($details !== null) {
            $failure['details'] = $details;
        }

        if ($this->embeddedBridgeMode) {
            return $this->bridgePost(
                $this->bridgePath.'/activity-attempts/'.$this->pathSegment($activityAttemptId).'/fail',
                ['failure' => $failure],
                allowedStatuses: [200, 404, 409, 422],
            );
        }

        $resolvedTaskId = $this->resolveActivityTaskId($activityAttemptId, $taskId);

        return $this->workerPost($this->workerApiPath.'/activity-tasks/'.$this->pathSegment($resolvedTaskId).'/fail', [
            'activity_attempt_id' => $activityAttemptId,
            'lease_owner' => $this->resolveActivityLeaseOwner($resolvedTaskId, $leaseOwner),
            'failure' => $failure,
        ], allowedStatuses: [200, 404, 409, 422]);
    }

    /**
     * @param array<string, mixed>|null $details
     * @return array<string, mixed>|null
     */
    public function heartbeatActivityAttempt(
        string $activityAttemptId,
        ?array $details = null,
        ?string $taskId = null,
        ?string $leaseOwner = null,
    ): ?array
    {
        if ($this->embeddedBridgeMode) {
            $body = [];

            if ($details !== null) {
                $body['progress'] = $details;
            }

            return $this->bridgePost(
                $this->bridgePath.'/activity-attempts/'.$this->pathSegment($activityAttemptId).'/heartbeat',
                $body,
                allowedStatuses: [200, 404, 409, 422],
            );
        }

        $resolvedTaskId = $this->resolveActivityTaskId($activityAttemptId, $taskId);
        $body = [
            'activity_attempt_id' => $activityAttemptId,
            'lease_owner' => $this->resolveActivityLeaseOwner($resolvedTaskId, $leaseOwner),
        ];

        if ($details !== null) {
            foreach (['message', 'current', 'total', 'unit'] as $field) {
                if (array_key_exists($field, $details)) {
                    $body[$field] = $details[$field];
                }
            }

            $body['details'] = is_array($details['details'] ?? null)
                ? $details['details']
                : $details;
        }

        return $this->workerPost(
            $this->workerApiPath.'/activity-tasks/'.$this->pathSegment($resolvedTaskId).'/heartbeat',
            $body,
            allowedStatuses: [200, 404, 409, 422],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activityAttemptStatus(string $activityAttemptId): ?array
    {
        if (! $this->embeddedBridgeMode) {
            $taskId = $this->activityAttemptTaskIds[$activityAttemptId] ?? null;

            return $taskId === null ? null : ($this->activityTaskLeases[$taskId] ?? null);
        }

        return $this->bridgeGet(
            $this->bridgePath.'/activity-attempts/'.$this->pathSegment($activityAttemptId),
            allowedStatuses: [200, 404],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pollEmbeddedTaskList(
        string $path,
        ?string $connection,
        ?string $queue,
        int $limit,
        ?string $compatibility,
        int $timeoutSeconds,
    ): array
    {
        $pollTimeoutSeconds = WorkerProtocolVersion::clampLongPollTimeout($timeoutSeconds);
        $requestTimeoutSeconds = max(
            $pollTimeoutSeconds,
            WorkerProtocolVersion::DEFAULT_LONG_POLL_TIMEOUT,
        ) + 5;

        $query = [
            'limit' => max(1, min($limit, 100)),
            'timeout_seconds' => $pollTimeoutSeconds,
        ];

        if ($connection !== null && $connection !== '') {
            $query['connection'] = $connection;
        }

        if ($queue !== null && $queue !== '') {
            $query['queue'] = $queue;
        }

        if ($compatibility !== null && $compatibility !== '') {
            $query['compatibility'] = $compatibility;
        }

        try {
            $response = $this->bridgeGet($path, $query, $requestTimeoutSeconds);
        } catch (ConnectionException $exception) {
            if ($this->isHttpTimeout($exception)) {
                return [];
            }

            throw $exception;
        }

        $tasks = $response['tasks'] ?? [];

        if (! is_array($tasks)) {
            return [];
        }

        $result = [];
        foreach ($tasks as $task) {
            if (is_array($task)) {
                $result[] = $task;
            }
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pollStandaloneWorkflowTasks(
        ?string $taskQueue,
        int $timeoutSeconds,
        ?string $workerId,
        ?string $buildId,
        ?string $pollRequestId,
        ?int $historyPageSize,
        ?string $acceptHistoryEncoding,
    ): array {
        $body = [
            'worker_id' => $this->resolveStandaloneWorkerId($workerId),
            'task_queue' => $this->resolveStandaloneTaskQueue($taskQueue),
        ];

        if (($buildId ?? $this->registeredBuildId) !== null) {
            $body['build_id'] = $buildId ?? $this->registeredBuildId;
        }

        if ($pollRequestId !== null && $pollRequestId !== '') {
            $body['poll_request_id'] = $pollRequestId;
        }

        if ($historyPageSize !== null) {
            $body['history_page_size'] = $historyPageSize;
        }

        if ($acceptHistoryEncoding !== null && $acceptHistoryEncoding !== '') {
            $body['accept_history_encoding'] = $acceptHistoryEncoding;
        }

        try {
            $response = $this->workerPost(
                $this->workerApiPath.'/workflow-tasks/poll',
                $body,
                $this->longPollRequestTimeoutSeconds($timeoutSeconds),
            );
        } catch (ConnectionException $exception) {
            if ($this->isHttpTimeout($exception)) {
                return [];
            }

            throw $exception;
        }

        $task = $response['task'] ?? null;
        if (! is_array($task)) {
            return [];
        }

        $this->rememberWorkflowTaskLease($task);

        return [$task];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pollStandaloneActivityTasks(
        ?string $taskQueue,
        int $timeoutSeconds,
        ?string $workerId,
        ?string $buildId,
    ): array {
        $body = [
            'worker_id' => $this->resolveStandaloneWorkerId($workerId),
            'task_queue' => $this->resolveStandaloneTaskQueue($taskQueue),
        ];

        if (($buildId ?? $this->registeredBuildId) !== null) {
            $body['build_id'] = $buildId ?? $this->registeredBuildId;
        }

        try {
            $response = $this->workerPost(
                $this->workerApiPath.'/activity-tasks/poll',
                $body,
                $this->longPollRequestTimeoutSeconds($timeoutSeconds),
            );
        } catch (ConnectionException $exception) {
            if ($this->isHttpTimeout($exception)) {
                return [];
            }

            throw $exception;
        }

        $task = $response['task'] ?? null;
        if (! is_array($task)) {
            return [];
        }

        $this->rememberActivityTaskLease($task);

        return [$task];
    }

    /**
     * @return array<string, mixed>
     */
    private function standaloneWorkflowClaimStatus(string $taskId, ?string $leaseOwner): array
    {
        $lease = $this->workflowTaskLeases[$taskId] ?? null;

        if ($lease === null) {
            return [
                'claimed' => false,
                'task_id' => $taskId,
                'reason' => 'task_not_polled',
            ];
        }

        if ($leaseOwner !== null && ($lease['lease_owner'] ?? null) !== $leaseOwner) {
            return [
                'claimed' => false,
                'task_id' => $taskId,
                'reason' => 'lease_owner_mismatch',
                'lease_owner' => $lease['lease_owner'] ?? null,
            ];
        }

        return ['claimed' => true] + $lease;
    }

    /**
     * @return array<string, mixed>
     */
    private function standaloneActivityClaimStatus(string $taskId, ?string $leaseOwner): array
    {
        $lease = $this->activityTaskLeases[$taskId] ?? null;

        if ($lease === null) {
            return [
                'claimed' => false,
                'task_id' => $taskId,
                'reason' => 'task_not_polled',
            ];
        }

        if ($leaseOwner !== null && ($lease['lease_owner'] ?? null) !== $leaseOwner) {
            return [
                'claimed' => false,
                'task_id' => $taskId,
                'reason' => 'lease_owner_mismatch',
                'lease_owner' => $lease['lease_owner'] ?? null,
            ];
        }

        return ['claimed' => true] + $lease;
    }

    /**
     * @return array<string, mixed>
     */
    private function claimTask(string $path, ?string $leaseOwner): array
    {
        $body = [];

        if ($leaseOwner !== null) {
            $body['lease_owner'] = $leaseOwner;
        }

        return $this->bridgePost($path, $body, allowedStatuses: [200, 404, 409]) ?? [];
    }

    private function longPollRequestTimeoutSeconds(int $timeoutSeconds): int
    {
        return WorkerProtocolVersion::clampLongPollTimeout($timeoutSeconds) + 5;
    }

    /**
     * @param list<string> $supportedWorkflowTypes
     * @param list<string>|null $capabilities
     * @return list<string>|null
     */
    private function workerCapabilities(array $supportedWorkflowTypes, ?array $capabilities): ?array
    {
        if ($capabilities === null) {
            return $this->hasSupportedWorkflowTypes($supportedWorkflowTypes)
                ? [WorkerProtocolVersion::CAPABILITY_QUERY_TASKS]
                : null;
        }

        return array_values(array_filter(
            $capabilities,
            static fn (mixed $capability): bool => is_string($capability) && $capability !== '',
        ));
    }

    /**
     * @param list<string> $supportedWorkflowTypes
     */
    private function hasSupportedWorkflowTypes(array $supportedWorkflowTypes): bool
    {
        foreach ($supportedWorkflowTypes as $workflowType) {
            if (is_string($workflowType) && trim($workflowType) !== '') {
                return true;
            }
        }

        return false;
    }

    private function resolveStandaloneWorkerId(?string $workerId): string
    {
        $resolved = $workerId ?? $this->registeredWorkerId;

        if ($resolved === null || $resolved === '') {
            throw new InvalidArgumentException(
                'Standalone worker API calls require workerId, or a prior registerWorker() call on this client.'
            );
        }

        return $resolved;
    }

    private function resolveStandaloneTaskQueue(?string $taskQueue): string
    {
        $resolved = $taskQueue ?? $this->registeredTaskQueue;

        if ($resolved === null || $resolved === '') {
            throw new InvalidArgumentException(
                'Standalone worker API calls require queue, or a prior registerWorker() call on this client.'
            );
        }

        return $resolved;
    }

    private function resolveWorkflowLeaseOwner(string $taskId, ?string $leaseOwner): string
    {
        $resolved = $leaseOwner
            ?? $this->stringValue($this->workflowTaskLeases[$taskId]['lease_owner'] ?? null)
            ?? $this->registeredWorkerId;

        if ($resolved === null || $resolved === '') {
            throw new InvalidArgumentException(
                'Workflow task completion requires leaseOwner, or a prior pollWorkflowTasks() lease on this client.'
            );
        }

        return $resolved;
    }

    private function resolveWorkflowTaskAttempt(string $taskId, ?int $workflowTaskAttempt): int
    {
        $resolved = $workflowTaskAttempt
            ?? $this->intValue($this->workflowTaskLeases[$taskId]['workflow_task_attempt'] ?? null);

        if ($resolved === null || $resolved < 1) {
            throw new InvalidArgumentException(
                'Workflow task completion requires workflowTaskAttempt, or a prior pollWorkflowTasks() lease '
                .'on this client.'
            );
        }

        return $resolved;
    }

    private function resolveWorkflowHistoryPageToken(string $taskId, ?string $nextHistoryPageToken): string
    {
        $resolved = $nextHistoryPageToken
            ?? $this->stringValue($this->workflowTaskLeases[$taskId]['next_history_page_token'] ?? null);

        if ($resolved === null || $resolved === '') {
            throw new InvalidArgumentException(
                'Workflow task history requires nextHistoryPageToken, or a prior pollWorkflowTasks() response '
                .'containing it.'
            );
        }

        return $resolved;
    }

    private function resolveQueryLeaseOwner(string $queryTaskId, ?string $leaseOwner): string
    {
        $resolved = $leaseOwner
            ?? $this->stringValue($this->queryTaskLeases[$queryTaskId]['lease_owner'] ?? null)
            ?? $this->registeredWorkerId;

        if ($resolved === null || $resolved === '') {
            throw new InvalidArgumentException(
                'Query task completion requires leaseOwner, or a prior pollQueryTasks() lease on this client.'
            );
        }

        return $resolved;
    }

    private function resolveQueryTaskAttempt(string $queryTaskId, ?int $queryTaskAttempt): int
    {
        $resolved = $queryTaskAttempt
            ?? $this->intValue($this->queryTaskLeases[$queryTaskId]['query_task_attempt'] ?? null);

        if ($resolved === null || $resolved < 1) {
            throw new InvalidArgumentException(
                'Query task completion requires queryTaskAttempt, or a prior pollQueryTasks() lease '
                .'on this client.'
            );
        }

        return $resolved;
    }

    private function resolveActivityTaskId(string $activityAttemptId, ?string $taskId): string
    {
        $resolved = $taskId ?? $this->activityAttemptTaskIds[$activityAttemptId] ?? null;

        if ($resolved === null || $resolved === '') {
            throw new InvalidArgumentException(
                'Activity completion requires taskId, or a prior pollActivityTasks() lease on this client.'
            );
        }

        return $resolved;
    }

    private function resolveActivityLeaseOwner(string $taskId, ?string $leaseOwner): string
    {
        $resolved = $leaseOwner
            ?? $this->stringValue($this->activityTaskLeases[$taskId]['lease_owner'] ?? null)
            ?? $this->registeredWorkerId;

        if ($resolved === null || $resolved === '') {
            throw new InvalidArgumentException(
                'Activity completion requires leaseOwner, or a prior pollActivityTasks() lease on this client.'
            );
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $task
     */
    private function rememberWorkflowTaskLease(array $task): void
    {
        $taskId = $this->stringValue($task['task_id'] ?? null);

        if ($taskId === null || $taskId === '') {
            return;
        }

        $lease = $task;
        if (! isset($lease['lease_owner']) && $this->registeredWorkerId !== null) {
            $lease['lease_owner'] = $this->registeredWorkerId;
        }

        $this->workflowTaskLeases[$taskId] = $lease;
    }

    /**
     * @param array<string, mixed> $task
     */
    private function rememberActivityTaskLease(array $task): void
    {
        $taskId = $this->stringValue($task['task_id'] ?? null);

        if ($taskId === null || $taskId === '') {
            return;
        }

        $lease = $task;
        if (! isset($lease['lease_owner']) && $this->registeredWorkerId !== null) {
            $lease['lease_owner'] = $this->registeredWorkerId;
        }

        $this->activityTaskLeases[$taskId] = $lease;

        $attemptId = $this->stringValue($task['activity_attempt_id'] ?? null);
        if ($attemptId !== null && $attemptId !== '') {
            $this->activityAttemptTaskIds[$attemptId] = $taskId;
        }
    }

    /**
     * @param array<string, mixed> $task
     */
    private function rememberQueryTaskLease(array $task): void
    {
        $queryTaskId = $this->stringValue($task['query_task_id'] ?? null);

        if ($queryTaskId === null || $queryTaskId === '') {
            return;
        }

        $lease = $task;
        if (! isset($lease['lease_owner']) && $this->registeredWorkerId !== null) {
            $lease['lease_owner'] = $this->registeredWorkerId;
        }

        $this->queryTaskLeases[$queryTaskId] = $lease;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function intValue(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $query
     * @param list<int> $allowedStatuses
     * @return array<string, mixed>|null
     */
    private function bridgeGet(
        string $path,
        array $query = [],
        ?int $requestTimeoutSeconds = null,
        array $allowedStatuses = [200],
    ): ?array {
        $response = $this->http
            ->withHeaders($this->workerHeaders())
            ->timeout($requestTimeoutSeconds ?? $this->defaultRequestTimeoutSeconds)
            ->get($this->baseUrl.$path, $query);

        $this->ensureOk($response, $path, $allowedStatuses);

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * @param array<string, mixed> $body
     * @param list<int> $allowedStatuses
     * @return array<string, mixed>|null
     */
    private function bridgePost(
        string $path,
        array $body,
        ?int $requestTimeoutSeconds = null,
        array $allowedStatuses = [200],
    ): ?array {
        return $this->workerPost($path, $body, $requestTimeoutSeconds, $allowedStatuses);
    }

    /**
     * @param array<string, mixed> $body
     * @param list<int> $allowedStatuses
     * @return array<string, mixed>|null
     */
    private function workerPost(
        string $path,
        array $body,
        ?int $requestTimeoutSeconds = null,
        array $allowedStatuses = [200],
    ): ?array
    {
        $response = $this->http
            ->withHeaders($this->workerHeaders())
            ->timeout($requestTimeoutSeconds ?? $this->defaultRequestTimeoutSeconds)
            ->post($this->baseUrl . $path, $body);

        $this->ensureOk($response, $path, $allowedStatuses);

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * @return array<string, string>
     */
    private function workerHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Namespace' => $this->namespace,
            'X-Durable-Workflow-Protocol-Version' => $this->protocolVersion,
        ];
    }

    /**
     * @param list<int> $allowedStatuses
     */
    private function ensureOk(Response $response, string $path, array $allowedStatuses = [200]): void
    {
        if (in_array($response->status(), $allowedStatuses, true)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Durable Workflow server request to %s failed with HTTP %d: %s',
            $path,
            $response->status(),
            (string) $response->body(),
        ));
    }

    private function isHttpTimeout(ConnectionException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'curl error 28');
    }

    private static function normalizePath(string $path): string
    {
        $path = trim($path);

        return $path === '' || $path === '/'
            ? ''
            : '/'.trim($path, '/');
    }

    private function pathSegment(string $value): string
    {
        return rawurlencode($value);
    }
}
