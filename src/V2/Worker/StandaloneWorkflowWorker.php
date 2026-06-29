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
            ];
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

            return [
                'kind' => 'query_task',
                'processed' => true,
                'outcome' => 'completed',
                'query_task_id' => $queryTaskId,
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
            return [
                'kind' => 'workflow_task',
                'processed' => false,
            ];
        }

        $task = $tasks[0];
        $taskId = $this->requiredString($task, 'task_id');

        try {
            $workflowClass = $this->workflowClassForTask($task);
            $step = WorkflowFiberRunner::forClass(
                $workflowClass,
                $this->workflowIdForTask($task),
                $this->runIdForTask($task),
                $this->workflowArguments($task),
                $this->payloadCodec($task),
                $this->historyEventsForTask($taskId, $task),
                $this->client->namespace(),
            )->step();

            $response = $this->client->completeWorkflowTask(
                $taskId,
                $step->commands,
                $this->stringValue($task['lease_owner'] ?? null),
                $this->intValue($task['workflow_task_attempt'] ?? null),
            );

            return [
                'kind' => 'workflow_task',
                'processed' => true,
                'outcome' => 'completed',
                'task_id' => $taskId,
                'commands' => $step->commands,
                'worker_response' => $response,
            ];
        } catch (Throwable $throwable) {
            $response = $this->client->failWorkflowTask(
                $taskId,
                $throwable->getMessage() !== '' ? $throwable->getMessage() : 'Workflow task execution failed.',
                $throwable::class,
                $throwable->getTraceAsString(),
                $this->stringValue($task['lease_owner'] ?? null),
                $this->intValue($task['workflow_task_attempt'] ?? null),
            );

            return [
                'kind' => 'workflow_task',
                'processed' => true,
                'outcome' => 'failed',
                'task_id' => $taskId,
                'failure' => [
                    'message' => $throwable->getMessage(),
                    'type' => $throwable::class,
                ],
                'worker_response' => $response,
            ];
        }
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
    private function payloadCodec(array $task): string
    {
        return CodecRegistry::canonicalize($this->stringValue($task['payload_codec'] ?? null));
    }

    private function payloadEnvelopeCodec(mixed $payload, string $fallbackCodec): string
    {
        if (is_array($payload)) {
            return CodecRegistry::canonicalize($this->stringValue($payload['codec'] ?? null) ?? $fallbackCodec);
        }

        return $fallbackCodec;
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
