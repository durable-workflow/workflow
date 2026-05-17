<?php

declare(strict_types=1);

namespace Workflow\V2\Worker;

use Carbon\CarbonImmutable;
use LogicException;
use Throwable;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Exceptions\InvalidQueryArgumentsException;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\WorkerProtocolVersion;
use Workflow\V2\Support\WorkflowQueryContract;
use Workflow\V2\Support\WorkflowReplayer;

/**
 * Executes server-routed query tasks inside a standalone PHP worker process.
 *
 * The server owns durable storage. The worker receives a self-contained
 * history-export snapshot, replays it in memory, invokes the declared query
 * handler, and returns an encoded result envelope through WorkerProtocolClient.
 *
 * @api Stable v2 worker protocol API.
 */
final class WorkflowQueryTaskExecutor
{
    public const CAPABILITY = WorkerProtocolVersion::CAPABILITY_QUERY_TASKS;

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    public function execute(array $task): array
    {
        $queryTaskId = $this->requiredString($task, 'query_task_id');
        $attempt = $this->intValue($task['query_task_attempt'] ?? null) ?? 1;
        $queryName = $this->requiredString($task, 'query_name');

        try {
            $codec = $this->payloadCodec($task);
            $run = $this->runFromTask($task);
            $target = WorkflowQueryContract::resolveTargetForRun($run, $queryName);

            if ($target === null) {
                return $this->failure(
                    $queryTaskId,
                    $attempt,
                    sprintf(
                        'Workflow query [%s] is not declared on workflow [%s].',
                        $queryName,
                        $run->workflow_instance_id,
                    ),
                    'rejected_unknown_query',
                    'QueryNotFound',
                );
            }

            $arguments = WorkflowQueryContract::validatedArgumentsForRun(
                $run,
                $target['name'],
                $this->queryArguments($task, $codec, $run),
            );

            if ($arguments['validation_errors'] !== []) {
                $exception = new InvalidQueryArgumentsException($target['name'], $arguments['validation_errors']);

                return $this->failure(
                    $queryTaskId,
                    $attempt,
                    $exception->getMessage(),
                    'invalid_query_arguments',
                    InvalidQueryArgumentsException::class,
                    validationErrors: $exception->validationErrors(),
                );
            }

            $result = (new QueryStateReplayer())->query($run, $target['method'], $arguments['arguments']);
            $resultBlob = Serializer::serializeWithCodec($codec, $result);

            return [
                'outcome' => 'completed',
                'query_task_id' => $queryTaskId,
                'query_task_attempt' => $attempt,
                'result' => $result,
                'result_envelope' => [
                    'codec' => $codec,
                    'blob' => $resultBlob,
                ],
            ];
        } catch (Throwable $throwable) {
            return $this->failure(
                $queryTaskId,
                $attempt,
                $throwable->getMessage() !== '' ? $throwable->getMessage() : 'Workflow query execution failed.',
                $this->failureReason($throwable),
                $throwable::class,
                $throwable->getTraceAsString(),
            );
        }
    }

    /**
     * @param array<string, mixed> $task
     */
    private function runFromTask(array $task): WorkflowRun
    {
        $historyExport = $task['history_export'] ?? null;

        return (new WorkflowReplayer())->runFromHistoryExport(
            is_array($historyExport) ? $historyExport : $this->historyExportFromTask($task),
        );
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function historyExportFromTask(array $task): array
    {
        $codec = $this->payloadCodec($task);
        $workflowArguments = is_array($task['workflow_arguments'] ?? null)
            ? $task['workflow_arguments']
            : null;
        $workflowClass = $this->stringValue($task['workflow_class'] ?? null)
            ?? $this->requiredString($task, 'workflow_type');

        return [
            'schema' => HistoryExport::SCHEMA,
            'schema_version' => HistoryExport::SCHEMA_VERSION,
            'exported_at' => CarbonImmutable::now('UTC')->toJSON(),
            'history_complete' => false,
            'workflow' => [
                'instance_id' => $this->requiredString($task, 'workflow_id'),
                'run_id' => $this->requiredString($task, 'run_id'),
                'run_number' => 1,
                'workflow_type' => $this->requiredString($task, 'workflow_type'),
                'workflow_class' => $workflowClass,
                'status' => $this->stringValue($task['run_status'] ?? null) ?? 'running',
                'queue' => $this->stringValue($task['task_queue'] ?? null),
                'last_history_sequence' => $this->intValue($task['last_history_sequence'] ?? null) ?? 0,
            ],
            'payloads' => [
                'codec' => $codec,
                'arguments' => [
                    'available' => $workflowArguments !== null,
                    'data' => $workflowArguments,
                ],
                'output' => [
                    'available' => false,
                    'data' => null,
                ],
            ],
            'history_events' => $this->historyEvents($task),
            'commands' => [],
            'signals' => [],
            'updates' => [],
            'tasks' => [],
            'activities' => [],
            'timers' => [],
            'failures' => [],
            'links' => [
                'parents' => [],
                'children' => [],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $task
     * @return list<array<string, mixed>>
     */
    private function historyEvents(array $task): array
    {
        $events = [];

        foreach (($task['history_events'] ?? []) as $event) {
            if (! is_array($event)) {
                continue;
            }

            $events[] = [
                'id' => $this->stringValue($event['id'] ?? null) ?? sprintf('query-history-%d', count($events) + 1),
                'sequence' => $this->intValue($event['sequence'] ?? null) ?? (count($events) + 1),
                'type' => $this->stringValue($event['type'] ?? null)
                    ?? $this->stringValue($event['event_type'] ?? null)
                    ?? 'Unknown',
                'payload' => is_array($event['payload'] ?? null) ? $event['payload'] : [],
                'workflow_task_id' => $this->stringValue($event['workflow_task_id'] ?? null),
                'workflow_command_id' => $this->stringValue($event['workflow_command_id'] ?? null),
                'recorded_at' => $this->stringValue($event['recorded_at'] ?? null),
            ];
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $task
     * @return array<int|string, mixed>
     */
    private function queryArguments(array $task, string $fallbackCodec, WorkflowRun $run): array
    {
        $envelope = $task['query_arguments'] ?? null;

        if (! is_array($envelope)) {
            return [];
        }

        $codec = CodecRegistry::canonicalize($this->stringValue($envelope['codec'] ?? null) ?? $fallbackCodec);
        $blob = ExternalPayloads::payloadBlob(
            $envelope,
            $codec,
            $this->stringValue($run->namespace ?? null),
        );

        if ($blob === null) {
            return [];
        }

        $decoded = Serializer::unserializeWithCodec($codec, $blob);

        return is_array($decoded) ? $decoded : [$decoded];
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

    /**
     * @param array<string, list<string>>|null $validationErrors
     * @return array<string, mixed>
     */
    private function failure(
        string $queryTaskId,
        int $attempt,
        string $message,
        string $reason,
        ?string $type = null,
        ?string $stackTrace = null,
        ?array $validationErrors = null,
    ): array {
        $failure = array_filter([
            'message' => $message,
            'reason' => $reason,
            'type' => $type,
            'stack_trace' => $stackTrace,
            'validation_errors' => $validationErrors,
        ], static fn (mixed $value): bool => $value !== null);

        return [
            'outcome' => 'failed',
            'query_task_id' => $queryTaskId,
            'query_task_attempt' => $attempt,
            'failure' => $failure,
        ];
    }

    private function failureReason(Throwable $throwable): string
    {
        if ($throwable instanceof LogicException && str_contains($throwable->getMessage(), 'not declared')) {
            return 'rejected_unknown_query';
        }

        return 'query_rejected';
    }

    /**
     * @param array<string, mixed> $values
     */
    private function requiredString(array $values, string $key): string
    {
        $value = $this->stringValue($values[$key] ?? null);

        if ($value === null) {
            throw new LogicException(sprintf('Query task field [%s] must be a non-empty string.', $key));
        }

        return $value;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intValue(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
