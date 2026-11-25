<?php

declare(strict_types=1);

namespace Workflow\V2\Interceptors;

use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ConfiguredV2Models;

/**
 * Immutable context passed to workflow-task interceptor hooks.
 */
final class WorkflowTaskInterceptorContext
{
    /**
     * History event types a workflow task records for a workflow command. Their
     * presence means the workflow code replays a committed prefix before it
     * reaches new execution.
     */
    public const COMMAND_EVENT_TYPES = [
        'ActivityScheduled', 'TimerScheduled', 'ChildWorkflowScheduled', 'ServiceOperationScheduled',
        'ServiceCallStarted', 'ExternalSignalRequested', 'ConditionWaitOpened', 'SignalWaitOpened',
        'CancellationScopeOpened', 'SideEffectRecorded', 'VersionMarkerRecorded', 'MemoUpserted',
        'SearchAttributesUpserted', 'SignalApplied', 'UpdateApplied', 'UpdateCompleted',
    ];

    /**
     * @param array<string, mixed> $task snapshot of the claimed task row
     * @param bool $replaying true when earlier workflow tasks already recorded commands for
     *                        this run, so the workflow code re-runs that committed prefix
     *                        before any new execution
     */
    public function __construct(
        public readonly ?string $workerId,
        public readonly ?string $connection,
        public readonly ?string $taskQueue,
        public readonly ?string $namespace,
        public readonly array $task,
        public readonly string $taskId,
        public readonly string $workflowType,
        public readonly string $workflowId,
        public readonly string $runId,
        public readonly int $workflowTaskAttempt,
        public readonly int $historyLength,
        public readonly bool $replaying,
    ) {
    }

    /**
     * @internal built by the engine
     */
    public static function forTask(WorkflowRun $run, WorkflowTask $task): self
    {
        $historyLength = (int) ($run->last_history_sequence ?? 0);
        $replaying = $historyLength > 0 && ConfiguredV2Models::query(
            'history_event_model',
            WorkflowHistoryEvent::class
        )
            ->where('workflow_run_id', $run->id)
            ->whereIn('event_type', self::COMMAND_EVENT_TYPES)
            ->exists();

        return new self(
            WorkerInterceptors::workerId(),
            self::nullableString($task->connection ?? $run->connection),
            self::nullableString($task->queue ?? $run->queue),
            self::nullableString($task->namespace ?? $run->namespace),
            WorkerInterceptors::taskSnapshot($task),
            (string) $task->id,
            (string) $run->workflow_type,
            (string) $run->workflow_instance_id,
            (string) $run->id,
            (int) $task->attempt_count,
            $historyLength,
            $replaying,
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
