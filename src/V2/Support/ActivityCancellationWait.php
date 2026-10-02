<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
use LogicException;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\CancellationPolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/** @internal Source kernel. Public activity policy admission remains a separate qualification gate. */
final class ActivityCancellationWait
{
    public static function policy(WorkflowRun $run, int $sequence): CancellationPolicy
    {
        if (! $run->relationLoaded('historyEvents')) {
            $run->loadMissing('historyEvents');
        }
        $scheduled = $run->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::ActivityScheduled
            && ($event->payload['sequence'] ?? null) === $sequence);
        $value = $scheduled?->payload['activity']['cancellation_policy'] ?? CancellationPolicy::TryCancel->value;
        return is_string($value) ? CancellationPolicy::from($value)
            : throw new LogicException('Recorded activity cancellation policy is invalid.');
    }

    /**
     * The caller owns the run lock. True means release the workflow claim until callback exit is proved.
     */
    public static function prepare(WorkflowRun $run, WorkflowTask $workflowTask, ActivityExecution $execution): bool
    {
        $context = CooperativeCancellationDelivery::context($run);
        if ($context === null || $execution->workflow_run_id !== $run->id) {
            throw new LogicException('Activity cancellation waiting requires the original run context.');
        }
        if (in_array($execution->status, [ActivityStatus::Pending, ActivityStatus::Running], true)) {
            $activityTask = $run->tasks->first(static fn (WorkflowTask $task): bool =>
                $task->task_type === TaskType::Activity
                && ($task->payload['activity_execution_id'] ?? null) === $execution->id);
            $event = ActivityCancellation::record($run, $execution, $activityTask, $context->requestId);
            if ($event !== null) {
                $run->historyEvents->push($event);
            }
        }
        if (ActivityCancellationCompletion::resolved($run, $execution->id)) {
            return false;
        }
        $payload = $workflowTask->payload;
        $ids = $payload['cancellation_activity_wait']['execution_ids'] ?? [];
        $payload['cancellation_activity_wait'] = [
            'request_id' => $context->requestId,
            'root_request_id' => $context->rootRequestId,
            'cleanup_deadline_at' => $context->deadline()
                ->toISOString(),
            'execution_ids' => array_values(array_unique([...$ids, $execution->id])),
        ];
        $workflowTask->payload = $payload;
        return true;
    }

    public static function bindBoundary(
        WorkflowTask $task,
        int $sequence,
        string $callKind,
        int $sequenceSpan,
        ?int $operationSequence,
        int $operationSequenceSpan,
    ): void {
        $payload = $task->payload;
        if (isset($payload['cancellation_activity_wait'])) {
            $payload['cancellation_activity_wait']['boundary'] = [
                'sequence' => $sequence,
                'call_kind' => $callKind,
                'sequence_span' => $sequenceSpan,
                'operation_sequence' => $operationSequence,
                'operation_sequence_span' => $operationSequenceSpan,
            ];
            $task->payload = $payload;
        }
    }

    public static function validateBoundary(
        WorkflowRun $run,
        int $sequence,
        string $callKind,
        int $sequenceSpan,
        ?int $operationSequence,
        int $operationSequenceSpan,
    ): ?string {
        // Pure replay models can supply their task relation. Persisted runs read
        // completed hosting claims under the run lock even after replacement.
        if (! $run->relationLoaded('tasks')) {
            if (! $run->exists) {
                return null;
            }
            $run->loadMissing('tasks');
        }
        $expected = [
            'sequence' => $sequence,
            'call_kind' => $callKind,
            'sequence_span' => $sequenceSpan,
            'operation_sequence' => $operationSequence,
            'operation_sequence_span' => $operationSequenceSpan,
        ];
        foreach ($run->tasks as $task) {
            $wait = $task->payload['cancellation_activity_wait'] ?? null;
            if ($task->status === TaskStatus::Completed && is_array($wait)
                && ($wait['request_id'] ?? null) === $run->cancellation_request_command_id) {
                $context = CooperativeCancellationDelivery::context($run);
                if ($context === null || ($wait['root_request_id'] ?? null) !== $context->rootRequestId
                    || ($wait['cleanup_deadline_at'] ?? null) !== $context->deadline()->toISOString()) {
                    return 'cancellation_wait_context_mismatch';
                }
                if (($wait['boundary'] ?? null) !== $expected) {
                    return 'cancellation_wait_boundary_mismatch';
                }
            }
        }
        return null;
    }

    /**
     * Called within stop-receipt persistence under the same run lock.
     */
    public static function resume(WorkflowRun $run, WorkflowHistoryEvent $receipt): ?WorkflowTask
    {
        if ($run->status !== RunStatus::Waiting || $run->cancellation_deadline_at === null
            || now()
                ->gte($run->cancellation_deadline_at)) {
            return null;
        }
        $run->setRelation('historyEvents', $run->historyEvents()->get());
        if (CooperativeCancellationDelivery::recorded($run) !== null) {
            return null;
        }
        $context = CooperativeCancellationDelivery::context($run);
        if ($context === null || $receipt->workflow_command_id !== $context->requestId) {
            return null;
        }
        $tasks = $run->tasks()
            ->where('task_type', TaskType::Workflow->value)->lockForUpdate()->get();
        if ($tasks->contains(static fn (WorkflowTask $task): bool =>
            in_array($task->status, [TaskStatus::Ready, TaskStatus::Leased], true))) {
            return null;
        }
        $waitingTask = $tasks->sortByDesc('created_at')
            ->first(static fn (WorkflowTask $task): bool =>
                        $task->status === TaskStatus::Completed
                        && ($task->payload['cancellation_activity_wait']['request_id'] ?? null) === $context->requestId);
        $wait = $waitingTask?->payload['cancellation_activity_wait'] ?? null;
        if (! is_array($wait) || ($wait['root_request_id'] ?? null) !== $context->rootRequestId
            || ($wait['cleanup_deadline_at'] ?? null) !== $context->deadline()->toISOString()
            || ! is_array($wait['execution_ids'] ?? null) || $wait['execution_ids'] === []
            || ! in_array($receipt->payload['activity_execution_id'] ?? null, $wait['execution_ids'], true)) {
            return null;
        }
        foreach ($wait['execution_ids'] as $executionId) {
            if (! is_string($executionId) || ! ActivityCancellationCompletion::resolved($run, $executionId)) {
                return null;
            }
        }
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now(),
            'payload' => [
                'resume_source_kind' => 'activity_cancellation_acknowledged',
                'resume_source_id' => $receipt->id,
                'workflow_command_id' => $context->requestId,
            ],
            'connection' => $run->connection,
            'queue' => $run->queue,
            'compatibility' => $run->compatibility,
        ]);
        app(HistoryProjectionRole::class)->projectRun(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
        DB::afterCommit(static fn () => TaskDispatcher::dispatch($task));
        return $task;
    }
}
