<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
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
    public static function recordStopped(string $attemptId, string $leaseOwner, string $requestId): array
    {
        return self::record($attemptId, $leaseOwner, $requestId, null);
    }

    /**
     * Reports a joined local callback using the workflow claim that started it.
     * The current workflow claim can belong to a replacement cleanup worker.
     *
     * @return array{acknowledged: bool, duplicate: bool, reason: ?string, history_event_id: ?string}
     */
    public static function recordLocalStopped(
        string $attemptId,
        string $leaseOwner,
        string $requestId,
        int $workflowTaskAttempt,
    ): array {
        if ($workflowTaskAttempt < 1) {
            return self::refused('local_activity_workflow_claim_mismatch');
        }

        return self::record($attemptId, $leaseOwner, $requestId, $workflowTaskAttempt);
    }

    /**
     * @return array{acknowledged: bool, duplicate: bool, reason: ?string, history_event_id: ?string}
     */
    private static function record(
        string $attemptId,
        string $leaseOwner,
        string $requestId,
        ?int $workflowTaskAttempt,
    ): array {
        return DB::transaction(static function () use (
            $attemptId,
            $leaseOwner,
            $requestId,
            $workflowTaskAttempt
        ): array {
            $rows = ActivityRowLockOrder::lockForAttempt($attemptId);
            $attempt = $rows['attempt'];
            $execution = $rows['execution'];
            if (! $attempt instanceof ActivityAttempt || ! $execution instanceof ActivityExecution) {
                return self::refused('activity_attempt_not_found');
            }
            $local = LocalActivityRuntime::isExecution($execution);
            if ($local && $workflowTaskAttempt === null) {
                return self::refused('local_cancellation_acknowledgement_requires_workflow_claim');
            }
            if (! $local && $workflowTaskAttempt !== null) {
                return self::refused('activity_is_not_local');
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
            if ($leaseOwner === '' || $requestId === ''
                || $execution->current_attempt_id !== $attemptId
                || $execution->workflow_run_id !== $run->id
                || $task->workflow_run_id !== $run->id
                || $attempt->lease_owner !== $leaseOwner
                || (! $local && $task->lease_owner !== $leaseOwner)
                || ($local && $task->task_type !== TaskType::Workflow)) {
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
                || ($snapshot['task_id'] ?? null) !== $task->id
                || ($snapshot['activity_execution_id'] ?? null) !== $execution->id) {
                return self::refused('activity_cancellation_snapshot_mismatch');
            }
            $originalClaim = null;
            if ($local) {
                $originalClaim = self::originalLocalClaim(
                    $run,
                    $task,
                    $cancelled,
                    $attemptId,
                    $leaseOwner,
                    $workflowTaskAttempt
                );
                if ($originalClaim === null) {
                    return self::refused('local_activity_workflow_claim_mismatch');
                }
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
                || (! $local && $task->status !== TaskStatus::Cancelled)) {
                return self::refused('activity_cancellation_not_fenced');
            }
            $context = CooperativeCancellationDelivery::context($run);
            if ($context === null || $context->requestId !== $requestId) {
                return self::refused('cancellation_context_not_recorded');
            }
            $receivedAt = now();
            $payload = [
                'sequence' => $execution->sequence,
                'activity_execution_id' => $execution->id,
                'activity_attempt_id' => $attemptId,
                'lease_owner' => $leaseOwner,
                'cancellation_history_event_id' => $cancelled->id,
                'request_id' => $requestId,
                'root_request_id' => $context->rootRequestId,
                'cleanup_deadline_at' => $context->deadline()
                    ->toISOString(),
                'callback_state' => 'stopped',
                'evidence_source' => $local ? 'workflow_worker' : 'activity_worker',
                'acknowledged_at' => $receivedAt->toISOString(),
                'received_after_deadline' => $receivedAt->gte($context->deadline()),
            ];
            if ($originalClaim !== null) {
                $payload = LocalActivityRuntime::eventPayload($payload);
                $payload['workflow_task_id'] = $task->id;
                // Keep the receipt's claim snapshot bound to the original owner.
                $payload['task'] = $originalClaim;
            }
            $event = WorkflowHistoryEvent::record(
                $run,
                HistoryEventType::ActivityCancellationAcknowledged,
                $payload,
                $task,
                $requestId
            );
            ActivityCancellationWait::resume($run, $event);

            return [
                'acknowledged' => true,
                'duplicate' => false,
                'reason' => null,
                'history_event_id' => $event->id,
            ];
        }, 5);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function originalLocalClaim(
        WorkflowRun $run,
        WorkflowTask $task,
        WorkflowHistoryEvent $cancelled,
        string $attemptId,
        string $leaseOwner,
        ?int $workflowTaskAttempt,
    ): ?array {
        if (($cancelled->payload['local_activity'] ?? null) !== true
            || ($cancelled->payload['execution_mode'] ?? null) !== LocalActivityRuntime::EXECUTION_MODE) {
            return null;
        }
        /** @var WorkflowHistoryEvent|null $started */
        $started = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityStarted)
            ->where('payload->activity_attempt_id', $attemptId)
            ->where('sequence', '<', $cancelled->sequence)
            ->first();
        if (! $started instanceof WorkflowHistoryEvent
            || ($started->payload['local_activity'] ?? null) !== true
            || ($started->payload['execution_mode'] ?? null) !== LocalActivityRuntime::EXECUTION_MODE
            || ($started->payload['activity_execution_id'] ?? null) !== $cancelled->payload['activity_execution_id']
            || ($started->payload['workflow_task_id'] ?? null) !== $task->id) {
            return null;
        }
        $claim = $started->payload['task'] ?? null;
        if (! is_array($claim) || array_is_list($claim)
            || ($claim['id'] ?? null) !== $task->id
            || ($claim['type'] ?? null) !== TaskType::Workflow->value
            || ($claim['status'] ?? null) !== TaskStatus::Leased->value
            || ($claim['lease_owner'] ?? null) !== $leaseOwner
            || ! is_int($workflowTaskAttempt) || $workflowTaskAttempt < 1
            || ($claim['attempt_count'] ?? null) !== $workflowTaskAttempt) {
            return null;
        }

        return $claim;
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
