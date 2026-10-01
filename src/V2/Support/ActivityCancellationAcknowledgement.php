<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/** @internal Records the original owner's callback-stop report without granting task authority. */
final class ActivityCancellationAcknowledgement
{
    /**
     * The SDK may call this only after its callback has stopped and been joined.
     * A cancelled row, expired lease or disconnected worker is not that report.
     *
     * @return array{acknowledged: bool, duplicate: bool, reason: ?string, history_event_id: ?string}
     */
    public static function recordStopped(
        string $attemptId,
        string $leaseOwner,
        string $workerAttemptId,
        string $requestId,
    ): array {
        return DB::transaction(static function () use ($attemptId, $leaseOwner, $workerAttemptId, $requestId): array {
            $rows = ActivityRowLockOrder::lockForAttempt($attemptId);
            $attempt = $rows['attempt'];
            $execution = $rows['execution'];
            if (! $attempt instanceof ActivityAttempt || ! $execution instanceof ActivityExecution) {
                return self::refused('activity_attempt_not_found');
            }
            if (LocalActivityRuntime::isExecution($execution)) {
                return self::refused('local_cancellation_acknowledgement_requires_workflow_claim');
            }
            /** @var WorkflowRun|null $run */
            $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->lockForUpdate()
                ->find($attempt->workflow_run_id);
            /** @var WorkflowTask|null $task */
            $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                ->lockForUpdate()
                ->find($attempt->workflow_task_id);
            if (! $run instanceof WorkflowRun || ! $task instanceof WorkflowTask) {
                return self::refused('activity_claim_not_found');
            }
            if ($leaseOwner === '' || $workerAttemptId === '' || $requestId === ''
                || $execution->current_attempt_id !== $attemptId
                || $execution->workflow_run_id !== $run->id
                || $task->workflow_run_id !== $run->id
                || $attempt->lease_owner !== $leaseOwner
                || $attempt->worker_attempt_id !== $workerAttemptId
                || $task->lease_owner !== $leaseOwner) {
                return self::refused('activity_cancellation_acknowledgement_fence_mismatch');
            }
            if ($run->cancellation_request_command_id !== $requestId) {
                return self::refused('cancellation_request_mismatch');
            }
            $events = $run->historyEvents()
                ->where('workflow_command_id', $requestId)
                ->where('payload->activity_execution_id', $execution->id)
                ->whereIn('event_type', [
                    HistoryEventType::ActivityCancelled->value,
                    HistoryEventType::ActivityCancellationAcknowledged->value,
                ])
                ->get();
            /** @var WorkflowHistoryEvent|null $cancelled */
            $cancelled = $events->first(static fn (WorkflowHistoryEvent $event): bool =>
                $event->event_type === HistoryEventType::ActivityCancelled
                && ($event->payload['activity_execution_id'] ?? null) === $execution->id
                && ($event->payload['activity_attempt_id'] ?? null) === $attemptId);
            if (! $cancelled instanceof WorkflowHistoryEvent) {
                return self::refused('activity_cancellation_not_recorded');
            }
            $snapshot = $cancelled->payload['activity_attempt'] ?? [];
            if (! is_array($snapshot) || array_is_list($snapshot)
                || ($snapshot['id'] ?? null) !== $attemptId
                || ($snapshot['status'] ?? null) !== ActivityAttemptStatus::Cancelled->value
                || ($snapshot['lease_owner'] ?? null) !== $leaseOwner
                || ($snapshot['worker_attempt_id'] ?? null) !== $workerAttemptId
                || ($snapshot['task_id'] ?? null) !== $task->id
                || ($snapshot['activity_execution_id'] ?? null) !== $execution->id) {
                return self::refused('activity_cancellation_snapshot_mismatch');
            }
            /** @var WorkflowHistoryEvent|null $existing */
            $existing = $events->first(static fn (WorkflowHistoryEvent $event): bool =>
                $event->event_type === HistoryEventType::ActivityCancellationAcknowledged
                && ($event->payload['cancellation_history_event_id'] ?? null) === $cancelled->id
                && ($event->payload['activity_attempt_id'] ?? null) === $attemptId);
            if ($existing instanceof WorkflowHistoryEvent) {
                return [
                    'acknowledged' => true,
                    'duplicate' => true,
                    'reason' => null,
                    'history_event_id' => $existing->id,
                ];
            }
            if ($attempt->status !== ActivityAttemptStatus::Cancelled
                || $execution->status !== ActivityStatus::Cancelled
                || $task->status !== TaskStatus::Cancelled) {
                return self::refused('activity_cancellation_not_fenced');
            }
            $context = CooperativeCancellationDelivery::context($run);
            if ($context === null || $context->requestId !== $requestId) {
                return self::refused('cancellation_context_not_recorded');
            }
            $receivedAt = now();
            $event = WorkflowHistoryEvent::record($run, HistoryEventType::ActivityCancellationAcknowledged, [
                'sequence' => $execution->sequence,
                'activity_execution_id' => $execution->id,
                'activity_attempt_id' => $attemptId,
                'worker_attempt_id' => $workerAttemptId,
                'lease_owner' => $leaseOwner,
                'cancellation_history_event_id' => $cancelled->id,
                'request_id' => $requestId,
                'root_request_id' => $context->rootRequestId,
                'cleanup_deadline_at' => $context->deadline()
                    ->toISOString(),
                'callback_state' => 'stopped',
                'evidence_source' => 'activity_worker',
                'acknowledged_at' => $receivedAt->toISOString(),
                'received_after_deadline' => $receivedAt->gte($context->deadline()),
            ], $task, $requestId);

            return [
                'acknowledged' => true,
                'duplicate' => false,
                'reason' => null,
                'history_event_id' => $event->id,
            ];
        }, 5);
    }

    /**
     * @return array{acknowledged: bool, duplicate: bool, reason: string, history_event_id: null}
     */
    private static function refused(string $reason): array
    {
        return [
            'acknowledged' => false,
            'duplicate' => false,
            'reason' => $reason,
            'history_event_id' => null,
        ];
    }
}
