<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/**
 * Bounds the run history that activity heartbeats write (#870).
 *
 * Every heartbeat still renews the lease and stores the latest progress on
 * the attempt row. An ActivityHeartbeatRecorded event is written only when
 * that progress has changed since the last recorded event and at least
 * max(heartbeat timeout, 60 seconds) has passed since that event. A
 * heartbeat without progress only proves liveness. When the attempt closes,
 * progress that was never recorded is recorded once.
 *
 * @internal
 */
final class ActivityHeartbeatHistory
{
    public const MIN_RECORD_INTERVAL_SECONDS = 60;

    /**
     * Store the heartbeat's progress on the attempt and decide whether this
     * heartbeat writes a history event. Saves the attempt when it changed.
     *
     * @param array<string, mixed>|null $progress
     */
    public static function observe(
        ActivityAttempt $attempt,
        ActivityExecution $execution,
        ?array $progress,
        CarbonInterface $at,
    ): bool {
        if ($progress !== null && $attempt->last_heartbeat_progress !== $progress) {
            $attempt->last_heartbeat_progress = $progress;
            $attempt->last_heartbeat_progress_at = $at;
        }

        $record = self::hasUnrecordedProgress($attempt)
            && ($attempt->last_heartbeat_recorded_at === null
                || $attempt->last_heartbeat_recorded_at->copy()
                    ->addSeconds(self::recordIntervalSeconds($execution))
                    ->lte($at));

        if ($record) {
            $attempt->last_heartbeat_recorded_at = $at;
        }

        if ($attempt->isDirty()) {
            $attempt->save();
        }

        return $record;
    }

    /**
     * Whether the attempt holds progress that no history event records yet.
     */
    public static function hasUnrecordedProgress(ActivityAttempt $attempt): bool
    {
        return $attempt->last_heartbeat_progress !== null
            && $attempt->last_heartbeat_progress_at !== null
            && ($attempt->last_heartbeat_recorded_at === null
                || $attempt->last_heartbeat_progress_at->gt($attempt->last_heartbeat_recorded_at));
    }

    public static function recordIntervalSeconds(ActivityExecution $execution): int
    {
        $retryPolicy = is_array($execution->retry_policy) ? $execution->retry_policy : [];
        $heartbeatTimeout = is_int($retryPolicy['heartbeat_timeout'] ?? null)
            ? $retryPolicy['heartbeat_timeout']
            : 0;

        return max($heartbeatTimeout, self::MIN_RECORD_INTERVAL_SECONDS);
    }

    /**
     * Record progress that was never recorded, once, before the attempt's
     * closing event, so history keeps its final state.
     */
    public static function recordPendingBeforeClose(
        WorkflowRun $run,
        ActivityExecution $execution,
        ?ActivityAttempt $attempt,
        ?WorkflowTask $task,
    ): void {
        if (! $attempt instanceof ActivityAttempt || ! self::hasUnrecordedProgress($attempt)) {
            return;
        }

        $attempt->forceFill([
            'last_heartbeat_recorded_at' => now(),
        ])->save();

        self::record(
            $run,
            $execution,
            $attempt,
            $task,
            $attempt->last_heartbeat_at ?? now(),
            $attempt->lease_expires_at,
        );
    }

    /**
     * Write an ActivityHeartbeatRecorded event carrying the attempt's latest
     * progress.
     */
    public static function record(
        WorkflowRun $run,
        ActivityExecution $execution,
        ActivityAttempt $attempt,
        ?WorkflowTask $task,
        mixed $heartbeatAt,
        mixed $leaseExpiresAt,
    ): void {
        if (ClosingRunOpenWork::abandonedAtClose($run, $execution)) {
            // Its run closed and left it running; nothing is appended after
            // the run's terminal event (#1117).
            return;
        }

        $payload = [
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $attempt->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'attempt_number' => $attempt->attempt_number,
            'heartbeat_at' => self::timestamp($heartbeatAt),
            'lease_expires_at' => self::timestamp($leaseExpiresAt),
            'activity' => ActivitySnapshot::fromExecution($execution),
            'activity_attempt' => self::attemptSnapshot($attempt),
        ];

        if (LocalActivityRuntime::isExecution($execution)) {
            $payload = LocalActivityRuntime::eventPayload($payload);
            $payload['workflow_task_id'] = $task?->id;
        }

        if (is_array($attempt->last_heartbeat_progress)) {
            $payload['progress'] = $attempt->last_heartbeat_progress;
        }

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityHeartbeatRecorded, $payload, $task);
    }

    /**
     * @return array<string, mixed>
     */
    private static function attemptSnapshot(ActivityAttempt $attempt): array
    {
        return array_filter([
            'id' => $attempt->id,
            'attempt_number' => $attempt->attempt_number,
            'status' => $attempt->status?->value,
            'task_id' => $attempt->workflow_task_id,
            'lease_owner' => $attempt->lease_owner,
            'started_at' => self::timestamp($attempt->started_at),
            'last_heartbeat_at' => self::timestamp($attempt->last_heartbeat_at),
            'lease_expires_at' => self::timestamp($attempt->lease_expires_at),
            'closed_at' => self::timestamp($attempt->closed_at),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function timestamp(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toJSON();
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
