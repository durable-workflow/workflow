<?php

declare(strict_types=1);

namespace Workflow\V2\Worker;

use LogicException;
use Throwable;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\HistoryPayloadCompression;
use Workflow\V2\Support\WorkerProtocolVersion;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\Workflow;

/**
 * Small worker-protocol driver for PHP workflows hosted outside Laravel queues.
 *
 * The driver intentionally processes query tasks before workflow tasks on each
 * tick so workers that advertise query_tasks do not starve public query calls
 * behind a workflow-task long poll.
 *
 * @api Stable v2 worker protocol API.
 */
final class StandaloneWorkflowWorker
{
    private const READY_QUERY_DRAIN_ATTEMPTS = 3;
    private const INITIAL_PRE_WORKFLOW_QUERY_DRAIN_ATTEMPTS = 1;
    private const INITIAL_READY_QUERY_DRAIN_ATTEMPTS = 10;
    private const QUERY_PENDING_DRAIN_ATTEMPTS = 10;
    private const QUERY_DRAIN_POLL_TIMEOUT_SECONDS = 0;

    /**
     * @var array<string, class-string<Workflow>>
     */
    private readonly array $workflowClassesByType;

    private readonly WorkflowQueryTaskExecutor $queryExecutor;

    /**
     * @param array<string, class-string<Workflow>> $workflowClassesByType
     */
    public function __construct(
        private readonly WorkerProtocolClient $client,
        array $workflowClassesByType,
        ?WorkflowQueryTaskExecutor $queryExecutor = null,
    ) {
        $this->workflowClassesByType = $this->normalizeWorkflowClasses($workflowClassesByType);
        $this->queryExecutor = $queryExecutor ?? new WorkflowQueryTaskExecutor($this->workflowClassesByType);
    }

    /**
     * Process at most one unit of work, preferring query tasks over workflow
     * tasks so query calls can complete while a workflow worker is otherwise
     * idle or waiting on workflow-task history.
     *
     * @return array<string, mixed>
     */
    public function tick(
        ?string $queue = null,
        ?string $workerId = null,
        int $queryPollTimeoutSeconds = 1,
        int $workflowPollTimeoutSeconds = 1,
    ): array {
        $query = $this->processOneQueryTask($queue, $workerId, $queryPollTimeoutSeconds);

        if (($query['processed'] ?? false) === true) {
            return $query;
        }

        if (($query['poll_status'] ?? null) === 'workflow_task_pending') {
            $workflow = $this->processOneWorkflowTask($queue, $workerId, $workflowPollTimeoutSeconds);

            if (($workflow['processed'] ?? false) === true) {
                $workflow['deferred_query_poll'] = $query;

                return $workflow;
            }

            return [
                'kind' => 'idle',
                'processed' => false,
                'query' => $query,
                'workflow' => $workflow,
            ];
        }

        $workflow = $this->processOneWorkflowTask($queue, $workerId, $workflowPollTimeoutSeconds);

        if (($workflow['processed'] ?? false) === true) {
            return $workflow;
        }

        return [
            'kind' => 'idle',
            'processed' => false,
            'query' => $query,
            'workflow' => $workflow,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function processOneQueryTask(
        ?string $queue = null,
        ?string $workerId = null,
        int $timeoutSeconds = 1,
    ): array {
        $tasks = $this->client->pollQueryTasks(
            queue: $queue,
            timeoutSeconds: $timeoutSeconds,
            workerId: $workerId,
        );

        if ($tasks === []) {
            return [
                'kind' => 'query_task',
                'processed' => false,
            ] + $this->client->lastQueryTaskPoll();
        }

        $task = $tasks[0];
        $result = $this->queryExecutor->execute($task);
        $queryTaskId = $this->requiredString($result, 'query_task_id');

        if (($result['outcome'] ?? null) === 'completed') {
            $response = $this->client->completeQueryTask(
                $queryTaskId,
                $result['result'] ?? null,
                is_array($result['result_envelope'] ?? null) ? $result['result_envelope'] : null,
                $this->stringValue($task['lease_owner'] ?? null),
                $this->intValue($task['query_task_attempt'] ?? null),
            );

            if (! $this->workerResponseAccepted($response, 'completed')) {
                $failure = $this->completionRejectedFailure(
                    'Query task completion was rejected by the server.',
                    $response,
                    'QueryTaskCompletionRejected',
                );
                $failResponse = $this->client->failQueryTask(
                    $queryTaskId,
                    $failure['message'],
                    reason: 'query_rejected',
                    failureType: $failure['type'],
                    leaseOwner: $this->stringValue($task['lease_owner'] ?? null),
                    queryTaskAttempt: $this->intValue($task['query_task_attempt'] ?? null),
                );

                return [
                    'kind' => 'query_task',
                    'processed' => true,
                    'outcome' => 'failed',
                    'query_task_id' => $queryTaskId,
                    'query_task' => $this->queryTaskMetadata($task, $result),
                    'failure' => $failure,
                    'worker_response' => $response,
                    'failure_response' => $failResponse,
                ];
            }

            return [
                'kind' => 'query_task',
                'processed' => true,
                'outcome' => 'completed',
                'query_task_id' => $queryTaskId,
                'query_task' => $this->queryTaskMetadata($task, $result),
                'worker_response' => $response,
            ];
        }

        $failure = is_array($result['failure'] ?? null) ? $result['failure'] : [];
        $response = $this->client->failQueryTask(
            $queryTaskId,
            $this->stringValue($failure['message'] ?? null) ?? 'Workflow query execution failed.',
            reason: $this->stringValue($failure['reason'] ?? null),
            failureType: $this->stringValue($failure['type'] ?? null),
            stackTrace: $this->stringValue($failure['stack_trace'] ?? null),
            leaseOwner: $this->stringValue($task['lease_owner'] ?? null),
            queryTaskAttempt: $this->intValue($task['query_task_attempt'] ?? null),
            validationErrors: is_array($failure['validation_errors'] ?? null)
                ? $failure['validation_errors']
                : null,
        );

        return [
            'kind' => 'query_task',
            'processed' => true,
            'outcome' => 'failed',
            'query_task_id' => $queryTaskId,
            'query_task' => $this->queryTaskMetadata($task, $result),
            'failure' => $failure,
            'worker_response' => $response,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function processOneWorkflowTask(
        ?string $queue = null,
        ?string $workerId = null,
        int $timeoutSeconds = 1,
    ): array {
        $tasks = $this->client->pollWorkflowTasks(
            queue: $queue,
            timeoutSeconds: $timeoutSeconds,
            workerId: $workerId,
            acceptHistoryEncoding: WorkerProtocolVersion::SUPPORTED_HISTORY_ENCODINGS[0] ?? null,
        );

        if ($tasks === []) {
            $poll = $this->client->lastWorkflowTaskPoll();

            if (($poll['poll_status'] ?? null) === 'query_task_pending') {
                [$query, $failure] = $this->drainPendingQueryTask($queue, $workerId);

                if (($query['processed'] ?? false) === true) {
                    $query['deferred_workflow_poll'] = $poll;

                    return $query;
                }

                if ($failure !== null) {
                    $poll['deferred_query_poll_failure'] = $failure;
                }
            }

            return [
                'kind' => 'workflow_task',
                'processed' => false,
            ] + $poll;
        }

        $task = $tasks[0];
        $taskId = $this->requiredString($task, 'task_id');
        $initialWorkflowTask = $this->isInitialWorkflowTask($task);
        $preWorkflowQuery = null;
        $preWorkflowQueryFailure = null;

        try {
            $workflowClass = $this->workflowClassForTask($task);

            if ($this->shouldDrainReadyQueryTaskBeforeWorkflowTask($initialWorkflowTask, $workflowClass)) {
                [$preWorkflowQuery, $preWorkflowQueryFailure] = $this->drainReadyQueryTaskBeforeWorkflowTask(
                    $queue,
                    $workerId,
                );
            }

            $runner = WorkflowFiberRunner::forClass(
                $workflowClass,
                $this->workflowIdForTask($task),
                $this->runIdForTask($task),
                $this->workflowArguments($task),
                $this->payloadCodec($task),
                $this->historyEventsForTask($taskId, $task),
                $this->client->namespace(),
            );
            $workflowUpdateId = $this->workflowUpdateIdForTask($task);
            $step = $workflowUpdateId === null
                ? $runner->step()
                : $runner->applyUpdate($workflowUpdateId, $this->stringValue($task['update_name'] ?? null));

            $response = $this->client->completeWorkflowTask(
                $taskId,
                $step->commands,
                $this->stringValue($task['lease_owner'] ?? null),
                $this->intValue($task['workflow_task_attempt'] ?? null),
            );

            $acceptedOutcomes = $step->commands === []
                ? ['completed', 'waiting_for_history']
                : ['completed'];

            if (! $this->workerResponseAccepted($response, $acceptedOutcomes)) {
                $failure = $this->completionRejectedFailure(
                    'Workflow task completion was rejected by the server.',
                    $response,
                    'WorkflowTaskCompletionRejected',
                );
                $failResponse = $this->client->failWorkflowTask(
                    $taskId,
                    $failure['message'],
                    $failure['type'],
                    leaseOwner: $this->stringValue($task['lease_owner'] ?? null),
                    workflowTaskAttempt: $this->intValue($task['workflow_task_attempt'] ?? null),
                );

                return $this->workflowResultWithPreWorkflowQuery([
                    'kind' => 'workflow_task',
                    'processed' => true,
                    'outcome' => 'failed',
                    'task_id' => $taskId,
                    'workflow_update_id' => $workflowUpdateId,
                    'workflow_wait_kind' => $this->stringValue($task['workflow_wait_kind'] ?? null),
                    'commands' => $step->commands,
                    'failure' => $failure,
                    'worker_response' => $response,
                    'failure_response' => $failResponse,
                ], $preWorkflowQuery, $preWorkflowQueryFailure);
            }

            $workflowResult = [
                'kind' => 'workflow_task',
                'processed' => true,
                'outcome' => $this->workerResponseOutcome($response, 'completed'),
                'task_id' => $taskId,
                'workflow_update_id' => $workflowUpdateId,
                'workflow_wait_kind' => $this->stringValue($task['workflow_wait_kind'] ?? null),
                'commands' => $step->commands,
                'worker_response' => $response,
            ];
            $workflowResult = $this->workflowResultWithPreWorkflowQuery(
                $workflowResult,
                $preWorkflowQuery,
                $preWorkflowQueryFailure,
            );

            if (($workflowResult['kind'] ?? null) === 'query_task') {
                return $workflowResult;
            }

            if (! $this->shouldDrainReadyQueryTaskAfterWorkflowTask($workflowResult)) {
                return $workflowResult;
            }

            $attempts = $initialWorkflowTask
                ? self::INITIAL_READY_QUERY_DRAIN_ATTEMPTS
                : self::READY_QUERY_DRAIN_ATTEMPTS;

            return $this->drainReadyQueryTaskAfterWorkflowTask($workflowResult, $queue, $workerId, $attempts);
        } catch (Throwable $throwable) {
            $response = $this->client->failWorkflowTask(
                $taskId,
                $throwable->getMessage() !== '' ? $throwable->getMessage() : 'Workflow task execution failed.',
                $throwable::class,
                $throwable->getTraceAsString(),
                $this->stringValue($task['lease_owner'] ?? null),
                $this->intValue($task['workflow_task_attempt'] ?? null),
            );

            return $this->workflowResultWithPreWorkflowQuery([
                'kind' => 'workflow_task',
                'processed' => true,
                'outcome' => 'failed',
                'task_id' => $taskId,
                'failure' => [
                    'message' => $throwable->getMessage(),
                    'type' => $throwable::class,
                ],
                'worker_response' => $response,
            ], $preWorkflowQuery, $preWorkflowQueryFailure);
        }
    }

    /**
     * A public query can be enqueued after the initial workflow task is leased
     * but before the workflow opens its first wait. Give query-capable
     * workflows one short chance to answer from the WorkflowStarted snapshot,
     * then still complete the workflow task so the run records its wait.
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, string>|null}
     */
    private function drainReadyQueryTaskBeforeWorkflowTask(?string $queue, ?string $workerId): array
    {
        return $this->drainReadyQueryTask(
            $queue,
            $workerId,
            self::INITIAL_PRE_WORKFLOW_QUERY_DRAIN_ATTEMPTS,
        );
    }

    /**
     * @param class-string<Workflow> $workflowClass
     */
    private function shouldDrainReadyQueryTaskBeforeWorkflowTask(bool $initialWorkflowTask, string $workflowClass): bool
    {
        return $initialWorkflowTask && WorkflowDefinition::queryMethods($workflowClass) !== [];
    }

    /**
     * @param array<string, mixed> $task
     */
    private function isInitialWorkflowTask(array $task): bool
    {
        $payload = HistoryPayloadCompression::decompress($task);
        $events = $this->historyEvents($payload['history_events'] ?? []);

        if ($events === []) {
            return false;
        }

        foreach ($events as $event) {
            $type = $this->stringValue($event['event_type'] ?? null)
                ?? $this->stringValue($event['type'] ?? null);

            if ($type !== null && $type !== 'WorkflowStarted' && $type !== 'StartAccepted') {
                return false;
            }
        }

        return true;
    }

    /**
     * A public query can be enqueued while the PHP worker is executing a
     * workflow task. Drain a bounded window after the task has recorded its
     * commands so that routed queries observe the latest committed wait and do
     * not wait for the caller's next loop turn.
     *
     * @param array<string, mixed> $workflowResult
     * @return array<string, mixed>
     */
    private function drainReadyQueryTaskAfterWorkflowTask(
        array $workflowResult,
        ?string $queue,
        ?string $workerId,
        int $attempts = self::READY_QUERY_DRAIN_ATTEMPTS,
    ): array {
        [$query, $failure] = $this->drainReadyQueryTask($queue, $workerId, $attempts);

        if ($failure !== null) {
            $workflowResult['deferred_query_poll_failure'] = [
                'message' => $failure['message'],
                'type' => $failure['type'],
            ];

            return $workflowResult;
        }

        if (($query['processed'] ?? false) !== true) {
            return $workflowResult;
        }

        $query['deferred_workflow_task'] = $workflowResult;

        return $query;
    }

    /**
     * A workflow-task poll with query_task_pending means the server withheld a
     * workflow lease to let a public query run first. Use the same startup
     * window as the initial post-workflow drain because the query can become
     * claimable just after the workflow poll returns.
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, string>|null}
     */
    private function drainPendingQueryTask(?string $queue, ?string $workerId): array
    {
        return $this->drainReadyQueryTask(
            $queue,
            $workerId,
            self::QUERY_PENDING_DRAIN_ATTEMPTS,
        );
    }

    /**
     * @param array<string, mixed> $workflowResult
     * @param array<string, mixed>|null $query
     * @param array<string, string>|null $failure
     * @return array<string, mixed>
     */
    private function workflowResultWithPreWorkflowQuery(
        array $workflowResult,
        ?array $query,
        ?array $failure,
    ): array {
        if ($query !== null) {
            $query['deferred_workflow_task'] = $workflowResult;

            return $query;
        }

        if ($failure !== null) {
            $workflowResult['pre_workflow_query_poll_failure'] = $failure;
        }

        return $workflowResult;
    }

    /**
     * Server-routed query tasks can appear just after a workflow poll observes
     * a started run or just after the first wait is recorded. Poll a short,
     * bounded window of immediate probes so the public query path is not left
     * waiting for a later worker loop turn, without turning startup drains into
     * long polls that can starve heartbeat or the next workflow task.
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, string>|null}
     */
    private function drainReadyQueryTask(
        ?string $queue,
        ?string $workerId,
        int $attempts = self::READY_QUERY_DRAIN_ATTEMPTS,
    ): array {
        for ($attempt = 0; $attempt < max(1, $attempts); $attempt++) {
            try {
                $query = $this->processOneQueryTask(
                    $queue,
                    $workerId,
                    self::QUERY_DRAIN_POLL_TIMEOUT_SECONDS,
                );
            } catch (Throwable $throwable) {
                return [
                    null,
                    [
                        'message' => $throwable->getMessage(),
                        'type' => $throwable::class,
                    ],
                ];
            }

            if (($query['processed'] ?? false) === true) {
                return [$query, null];
            }
        }

        return [null, null];
    }

    /**
     * @param array<string, mixed> $workflowResult
     */
    private function shouldDrainReadyQueryTaskAfterWorkflowTask(array $workflowResult): bool
    {
        $outcome = $workflowResult['outcome'] ?? null;
        if ($outcome === 'waiting_for_history') {
            return true;
        }

        if ($outcome !== 'completed') {
            return false;
        }

        $commands = $workflowResult['commands'] ?? null;
        if (! is_array($commands) || $commands === []) {
            return false;
        }

        foreach ($commands as $command) {
            if (! is_array($command)) {
                continue;
            }

            $type = $this->stringValue($command['type'] ?? null);
            if ($type !== null && in_array($type, WorkerProtocolVersion::terminalCommandTypes(), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, class-string<Workflow>> $workflowClassesByType
     * @return array<string, class-string<Workflow>>
     */
    private function normalizeWorkflowClasses(array $workflowClassesByType): array
    {
        $normalized = [];

        foreach ($workflowClassesByType as $workflowType => $workflowClass) {
            if (! is_string($workflowType) || trim($workflowType) === '') {
                throw new LogicException('Standalone workflow worker registry keys must be non-empty workflow types.');
            }

            if (! is_string($workflowClass) || ! is_subclass_of($workflowClass, Workflow::class)) {
                throw new LogicException(sprintf(
                    'Standalone workflow worker registry entry [%s] must point to a loadable %s subclass.',
                    trim($workflowType),
                    Workflow::class,
                ));
            }

            /** @var class-string<Workflow> $workflowClass */
            $normalized[trim($workflowType)] = $workflowClass;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $task
     * @return class-string<Workflow>
     */
    private function workflowClassForTask(array $task): string
    {
        $workflowClass = $this->stringValue($task['workflow_class'] ?? null);

        if ($workflowClass !== null
            && class_exists($workflowClass)
            && is_subclass_of($workflowClass, Workflow::class)) {
            /** @var class-string<Workflow> $workflowClass */
            return $workflowClass;
        }

        $workflowType = $this->stringValue($task['workflow_type'] ?? null);

        if ($workflowType !== null && isset($this->workflowClassesByType[$workflowType])) {
            return $this->workflowClassesByType[$workflowType];
        }

        if ($workflowClass !== null && isset($this->workflowClassesByType[$workflowClass])) {
            return $this->workflowClassesByType[$workflowClass];
        }

        throw new LogicException(sprintf(
            'Workflow task type [%s] is not registered with the standalone workflow worker.',
            $workflowType ?? $workflowClass ?? 'unknown',
        ));
    }

    /**
     * @param array<string, mixed> $task
     * @return array<int, mixed>
     */
    private function workflowArguments(array $task): array
    {
        $payload = $task['arguments'] ?? $task['workflow_arguments'] ?? null;

        if ($payload === null) {
            return [];
        }

        $codec = $this->payloadEnvelopeCodec($payload, $this->payloadCodec($task));
        $blob = ExternalPayloads::payloadBlob($payload, $codec, $this->client->namespace());

        if ($blob === null) {
            return [];
        }

        $decoded = Serializer::unserializeWithCodec($codec, $blob);

        return is_array($decoded) ? array_values($decoded) : [$decoded];
    }

    /**
     * @param array<string, mixed> $task
     * @return list<array<string, mixed>>
     */
    private function historyEventsForTask(string $taskId, array $task): array
    {
        $payload = HistoryPayloadCompression::decompress($task);
        $events = $this->historyEvents($payload['history_events'] ?? []);
        $nextPageToken = $this->stringValue($payload['next_history_page_token'] ?? null);

        while ($nextPageToken !== null) {
            $page = $this->client->workflowTaskHistory(
                $taskId,
                nextHistoryPageToken: $nextPageToken,
                acceptHistoryEncoding: WorkerProtocolVersion::SUPPORTED_HISTORY_ENCODINGS[0] ?? null,
            );

            if (! is_array($page)) {
                break;
            }

            $page = HistoryPayloadCompression::decompress($page);
            array_push($events, ...$this->historyEvents($page['history_events'] ?? []));
            $nextPageToken = $this->stringValue($page['next_history_page_token'] ?? null);
        }

        return $events;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyEvents(mixed $events): array
    {
        if (! is_array($events)) {
            return [];
        }

        $normalized = [];

        foreach ($events as $event) {
            if (is_array($event)) {
                $normalized[] = $event;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $task
     */
    private function workflowIdForTask(array $task): string
    {
        return $this->stringValue($task['workflow_id'] ?? null)
            ?? $this->requiredString($task, 'workflow_instance_id');
    }

    /**
     * @param array<string, mixed> $task
     */
    private function runIdForTask(array $task): string
    {
        return $this->stringValue($task['run_id'] ?? null)
            ?? $this->requiredString($task, 'workflow_run_id');
    }

    /**
     * @param array<string, mixed> $task
     */
    private function workflowUpdateIdForTask(array $task): ?string
    {
        $waitKind = $this->stringValue($task['workflow_wait_kind'] ?? null);
        $updateId = $this->stringValue($task['workflow_update_id'] ?? null);

        if ($updateId === null && $waitKind !== 'update') {
            return null;
        }

        if ($updateId === null) {
            throw new LogicException('Update workflow task is missing workflow_update_id.');
        }

        return $updateId;
    }

    /**
     * @param array<string, mixed> $task
     */
    private function payloadCodec(array $task): string
    {
        return CodecRegistry::canonicalize(
            $this->stringValue($task['payload_codec'] ?? null) ?? CodecRegistry::defaultCodec(),
        );
    }

    private function payloadEnvelopeCodec(mixed $payload, string $fallbackCodec): string
    {
        if (is_array($payload)) {
            return CodecRegistry::canonicalize($this->stringValue($payload['codec'] ?? null) ?? $fallbackCodec);
        }

        return $fallbackCodec;
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function queryTaskMetadata(array $task, array $result): array
    {
        return array_filter([
            'query_task_id' => $this->stringValue($result['query_task_id'] ?? null)
                ?? $this->stringValue($task['query_task_id'] ?? null),
            'query_task_attempt' => $this->intValue($result['query_task_attempt'] ?? null)
                ?? $this->intValue($task['query_task_attempt'] ?? null),
            'workflow_id' => $this->stringValue($task['workflow_id'] ?? null),
            'run_id' => $this->stringValue($task['run_id'] ?? null)
                ?? $this->stringValue($task['workflow_run_id'] ?? null),
            'workflow_type' => $this->stringValue($task['workflow_type'] ?? null),
            'query_name' => $this->stringValue($task['query_name'] ?? null),
            'task_queue' => $this->stringValue($task['task_queue'] ?? null),
            'lease_owner' => $this->stringValue($task['lease_owner'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed>|null $response
     * @param string|list<string> $expectedOutcomes
     */
    private function workerResponseAccepted(?array $response, string|array $expectedOutcomes): bool
    {
        if ($response === null) {
            return true;
        }

        $expectedOutcomes = is_array($expectedOutcomes) ? $expectedOutcomes : [$expectedOutcomes];
        $outcome = $this->stringValue($response['outcome'] ?? null);
        if ($outcome !== null && ! in_array($outcome, $expectedOutcomes, true)) {
            return false;
        }

        if (($response['recorded'] ?? null) === false) {
            return false;
        }

        $status = $this->intValue($response['status'] ?? null);

        return $status === null || $status < 400;
    }

    /**
     * @param array<string, mixed>|null $response
     */
    private function workerResponseOutcome(?array $response, string $fallback): string
    {
        if ($response === null) {
            return $fallback;
        }

        return $this->stringValue($response['outcome'] ?? null) ?? $fallback;
    }

    /**
     * @param array<string, mixed>|null $response
     * @return array{message: string, type: string, response?: array<string, mixed>}
     */
    private function completionRejectedFailure(string $fallbackMessage, ?array $response, string $type): array
    {
        $message = $this->stringValue($response['error'] ?? null)
            ?? $this->stringValue($response['message'] ?? null)
            ?? $this->stringValue($response['reason'] ?? null)
            ?? $fallbackMessage;

        return array_filter([
            'message' => $message,
            'type' => $type,
            'response' => $response,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function requiredString(array $values, string $key): string
    {
        $value = $this->stringValue($values[$key] ?? null);

        if ($value === null) {
            throw new LogicException(sprintf('Worker task field [%s] must be a non-empty string.', $key));
        }

        return $value;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }
}
