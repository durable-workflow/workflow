<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Workflow\V2\CancellationContext;
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
use Workflow\V2\ScopedCancellationContext;

/**
 * @internal Supervisor control for an already prepared portable callback.
 * Poll independently of application heartbeats. A stop instruction fences
 * publication but is not proof that the callback has stopped and been joined.
 */
final class PortableLocalActivityControl
{
    /**
     * @return array<string, mixed>
     */
    public static function poll(
        string $attemptId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        bool $renewLease = false,
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array {
        return self::observe($attemptId, $leaseOwner, $workflowTaskAttempt, $renewLease, false, null, $protocolVersion);
    }

    /** @param array<string, mixed> $progress
     * @return array<string, mixed>
     */
    public static function heartbeat(
        string $attemptId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        array $progress = [],
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array {
        try {
            $normalized = HeartbeatProgress::normalizeForWrite($progress);
        } catch (LogicException) {
            return self::response('invalid_local_activity_heartbeat');
        }

        return self::observe($attemptId, $leaseOwner, $workflowTaskAttempt, false, true, $normalized, $protocolVersion);
    }

    /** @param array<string, mixed>|null $progress
     * @return array<string, mixed>
     */
    private static function observe(
        string $attemptId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        bool $renewLease,
        bool $applicationHeartbeat,
        ?array $progress,
        string $protocolVersion,
    ): array {
        if (preg_match('/^[0-9]+\.[0-9]+$/D', $protocolVersion) !== 1
            || version_compare($protocolVersion, PortableLocalActivityPreparation::MINIMUM_PROTOCOL_VERSION, '<')) {
            return self::response('local_activity_control_requires_protocol_1_20');
        }
        if ($attemptId === '' || $leaseOwner === '' || $workflowTaskAttempt < 1) {
            return self::response('invalid_local_activity_control');
        }

        return DB::transaction(static function () use (
            $attemptId,
            $leaseOwner,
            $workflowTaskAttempt,
            $renewLease,
            $applicationHeartbeat,
            $progress,
        ): array {
            $rows = ActivityRowLockOrder::lockForAttempt($attemptId);
            $attempt = $rows['attempt'];
            $execution = $rows['execution'];
            if (! $attempt instanceof ActivityAttempt || ! $execution instanceof ActivityExecution) {
                return self::response('activity_attempt_not_found');
            }
            /** @var WorkflowRun|null $run */
            $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->lockForUpdate()
                ->find($execution->workflow_run_id);
            /** @var WorkflowTask|null $task */
            $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                ->lockForUpdate()
                ->find($attempt->workflow_task_id);
            if ($run === null || $task === null || $task->workflow_run_id !== $run->id) {
                return self::response('workflow_claim_not_found');
            }
            $started = PortableLocalActivityPreparation::originalStart(
                $run,
                $execution,
                $attempt,
                $leaseOwner,
                $workflowTaskAttempt,
            );
            if ($started === null) {
                return self::response('local_activity_preparation_mismatch');
            }
            $reply = static fn (?string $reason, bool $renewed = false): array => self::response(
                $reason,
                $run,
                $task,
                $execution,
                $attempt,
                $workflowTaskAttempt,
                $renewed,
            );
            if ($execution->current_attempt_id !== $attemptId || $execution->attempt_count !== $attempt->attempt_number) {
                return $reply('stale_activity_attempt');
            }
            // Preserve an already accepted scope fence even if a later run
            // request or replacement claim exists. Reading this fact cannot
            // renew the hosting claim or prove that the callback has stopped.
            $scopeFence = $execution->status === ActivityStatus::Cancelled && $attempt->status === ActivityAttemptStatus::Cancelled
                ? $run->historyEvents()
                    ->where('event_type', HistoryEventType::ActivityCancelled)
                    ->where('payload->activity_attempt_id', $attemptId)
                    ->first()
                : null;
            if ($scopeFence !== null && array_key_exists('cancellation_scope', $scopeFence->payload)) {
                $context = ActivityCancellationContext::forEvent($run, $scopeFence);
                if (! $context instanceof ScopedCancellationContext) {
                    return $reply('cancellation_scope_context_not_recorded');
                }
                $metadata = $scopeFence->payload['cancellation_scope'];
                return [
                    ...$reply(now()->gte(\Carbon\CarbonImmutable::parse(
                        $metadata['authority_deadline_at']
                    )) ? 'cancellation_scope_deadline_expired' : 'cancellation_scope_requested'),
                    'cancellation_scope' => $metadata,
                    'fenced' => true,
                    'cancellation_history_event_id' => $scopeFence->id,
                ];
            }
            // The original callback supervisor must see accepted cancellation
            // even after takeover. This never renews the replacement claim.
            $cleanup = PortableLocalActivityCleanup::isExecution($run, $execution, $started);
            if ($run->cancellation_request_command_id !== null
                && (! $cleanup || now()->gte($run->cancellation_deadline_at))) {
                // A supervisor needs one request, not the run's entire history.
                $requested = $run->historyEvents()
                    ->where('event_type', HistoryEventType::CooperativeCancellationRequested)
                    ->where('workflow_command_id', $run->cancellation_request_command_id)
                    ->first();
                $snapshot = $requested?->payload['cancellation'] ?? null;
                if (! is_array($snapshot)) {
                    return $reply('cancellation_context_not_recorded');
                }
                try {
                    $context = CancellationContext::fromArray($snapshot);
                } catch (InvalidArgumentException) {
                    return $reply('cancellation_context_not_recorded');
                }
                if ($context->requestId !== $run->cancellation_request_command_id
                    || ! $requested instanceof WorkflowHistoryEvent) {
                    return $reply('cancellation_context_not_recorded');
                }
                if (! $cleanup && ($started->sequence >= $requested->sequence
                    || $started->recorded_at->gt($requested->recorded_at))) {
                    return $reply('local_activity_preparation_mismatch');
                }
                $cancelled = $run->historyEvents()
                    ->where('event_type', HistoryEventType::ActivityCancelled)
                    ->where('payload->activity_attempt_id', $attemptId)
                    ->where('workflow_command_id', $context->requestId)
                    ->first();
                if (! $cancelled instanceof WorkflowHistoryEvent
                    && $execution->status === ActivityStatus::Running && $attempt->status === ActivityAttemptStatus::Running) {
                    // No hosting task argument. Its cleanup claim stays intact.
                    $cancelled = ActivityCancellation::record($run, $execution, command: $context->requestId);
                }
                return [
                    ...$reply(now()->gte(
                        $context->deadline()
                    ) ? 'cancellation_deadline_expired' : 'cancellation_requested'),
                    'cancellation_request' => $context->toArray(),
                    'fenced' => $cancelled instanceof WorkflowHistoryEvent,
                    'cancellation_history_event_id' => $cancelled?->id,
                ];
            }
            if ($run->status->isTerminal()) {
                return $reply('run_closed');
            }
            if (($run->execution_deadline_at !== null && now()->gte($run->execution_deadline_at))
                || ($run->run_deadline_at !== null && now()->gte($run->run_deadline_at))) {
                return $reply('run_deadline_expired');
            }
            if ($attempt->status !== ActivityAttemptStatus::Running || $execution->status !== ActivityStatus::Running) {
                return $reply('stale_activity_attempt');
            }
            if ($task->task_type !== TaskType::Workflow || $task->status !== TaskStatus::Leased
                || $task->lease_owner !== $leaseOwner || $task->attempt_count !== $workflowTaskAttempt) {
                return $reply('workflow_claim_mismatch');
            }
            if ($task->lease_expires_at === null || now()->gte($task->lease_expires_at)) {
                return $reply('workflow_claim_expired');
            }
            if ($attempt->lease_expires_at === null || now()->gte($attempt->lease_expires_at)) {
                return $reply('local_activity_lease_expired');
            }
            foreach ([
                $execution->close_deadline_at,
                $execution->schedule_to_close_deadline_at,
                $execution->heartbeat_deadline_at,
            ] as $deadline) {
                if ($deadline !== null && now()->gte($deadline)) {
                    return $reply('local_activity_deadline_expired');
                }
            }
            if ($renewLease) {
                // Both rows and the task summary commit in one transaction.
                // No application heartbeat or recorded execution deadline moves.
                $expiry = LocalActivityRuntime::renewWorkflowTask(
                    $task,
                    PortableLocalActivityCleanup::deadline($execution)
                );
                $attempt->forceFill([
                    'lease_expires_at' => $expiry,
                ])->save();
            }
            $heartbeatEvent = null;
            if ($applicationHeartbeat) {
                $heartbeatAt = now();
                $timeout = $execution->retry_policy['heartbeat_timeout'] ?? null;
                $execution->forceFill([
                    'last_heartbeat_at' => $heartbeatAt,
                    'heartbeat_deadline_at' => PortableLocalActivityCleanup::bound(
                        $execution,
                        is_int($timeout) && $timeout > 0 ? $heartbeatAt->copy()
                            ->addSeconds($timeout) : null,
                    ),
                ])->save();
                $attempt->forceFill([
                    'last_heartbeat_at' => $heartbeatAt,
                ])->save();
                $heartbeatEvent = WorkflowHistoryEvent::record(
                    $run,
                    HistoryEventType::ActivityHeartbeatRecorded,
                    LocalActivityRuntime::eventPayload([
                        'activity_execution_id' => $execution->id,
                        'activity_attempt_id' => $attempt->id,
                        'activity_class' => $execution->activity_class,
                        'activity_type' => $execution->activity_type,
                        'sequence' => $execution->sequence,
                        'attempt_number' => $attempt->attempt_number,
                        'heartbeat_at' => $heartbeatAt->toISOString(),
                        'activity' => ActivitySnapshot::fromExecution($execution),
                        'activity_attempt' => [
                            'id' => $attempt->id,
                            'activity_execution_id' => $execution->id,
                            'task_id' => $task->id,
                            'attempt_number' => $attempt->attempt_number,
                            'status' => $attempt->status->value,
                            'lease_owner' => $attempt->lease_owner,
                            'worker_attempt_id' => $attempt->worker_attempt_id,
                            'started_at' => $attempt->started_at?->toISOString(),
                            'last_heartbeat_at' => $heartbeatAt->toISOString(),
                            'lease_expires_at' => $attempt->lease_expires_at?->toISOString(),
                        ],
                        ...($progress === null ? [] : [
                            'progress' => $progress,
                        ]),
                    ]),
                    $task
                );
                app(\Workflow\V2\Contracts\HistoryProjectionRole::class)->projectRun(
                    $run->fresh(['instance', 'tasks', 'activityExecutions', 'failures', 'historyEvents']) ?? $run
                );
            }
            return [
                ...$reply(null, $renewLease),
                'heartbeat_recorded' => $heartbeatEvent !== null,
                'heartbeat_history_event_id' => $heartbeatEvent?->id,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function response(
        ?string $reason,
        ?WorkflowRun $run = null,
        ?WorkflowTask $task = null,
        ?ActivityExecution $execution = null,
        ?ActivityAttempt $attempt = null,
        ?int $workflowTaskAttempt = null,
        bool $renewed = false,
    ): array {
        return [
            'active' => $reason === null,
            'renewed' => $renewed,
            'stop_required' => $reason !== null,
            'reason' => $reason,
            'activity_execution_id' => $execution?->id,
            'activity_attempt_id' => $attempt?->id,
            'workflow_task_id' => $attempt?->workflow_task_id,
            'workflow_task_attempt' => $workflowTaskAttempt,
            'lease_owner' => $attempt?->lease_owner,
            'lease_expires_at' => $attempt?->lease_expires_at?->toISOString(),
            'workflow_lease_expires_at' => $task?->lease_expires_at?->toISOString(),
            'start_to_close_deadline_at' => $execution?->close_deadline_at?->toISOString(),
            'schedule_to_close_deadline_at' => $execution?->schedule_to_close_deadline_at?->toISOString(),
            'heartbeat_deadline_at' => $execution?->heartbeat_deadline_at?->toISOString(),
            'cancellation_cleanup' => $execution?->activity_options['cancellation_cleanup'] ?? null,
            'heartbeat_recorded' => false,
            'heartbeat_history_event_id' => null,
            'run_status' => $run?->status->value,
            'task_status' => $task?->status->value,
            'server_time' => now()
                ->toISOString(),
        ];
    }
}
