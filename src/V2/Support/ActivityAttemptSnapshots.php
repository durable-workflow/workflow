<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class ActivityAttemptSnapshots
{
    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public static function forRun(WorkflowRun $run): array
    {
        $run->loadMissing(['activityExecutions.attempts', 'historyEvents']);

        $states = [];

        foreach (self::attemptEvents($run) as $event) {
            $snapshot = self::fromEvent($event);
            $activityId = is_array($snapshot) ? self::stringValue($snapshot['activity_execution_id'] ?? null) : null;
            $attemptId = is_array($snapshot) ? self::stringValue($snapshot['id'] ?? null) : null;

            if ($activityId === null || $attemptId === null) {
                continue;
            }

            $states[$activityId][$attemptId] = self::merge($states[$activityId][$attemptId] ?? [], $snapshot);
        }

        foreach ($run->activityExecutions as $execution) {
            if (! $execution instanceof ActivityExecution) {
                continue;
            }

            foreach ($execution->attempts as $attempt) {
                if (! $attempt instanceof ActivityAttempt) {
                    continue;
                }

                $snapshot = self::fromAttempt($attempt);
                $states[$execution->id][$attempt->id] = isset($states[$execution->id][$attempt->id])
                    ? self::mergeMissing($states[$execution->id][$attempt->id], $snapshot)
                    : $snapshot;
            }
        }

        foreach ($states as $activityId => $attempts) {
            $attempts = array_values($attempts);

            usort($attempts, static function (array $left, array $right): int {
                $leftAttemptNumber = self::intValue($left['attempt_number'] ?? null) ?? PHP_INT_MAX;
                $rightAttemptNumber = self::intValue($right['attempt_number'] ?? null) ?? PHP_INT_MAX;

                if ($leftAttemptNumber !== $rightAttemptNumber) {
                    return $leftAttemptNumber <=> $rightAttemptNumber;
                }

                $leftStartedAt = self::timestampToMilliseconds($left['started_at'] ?? null);
                $rightStartedAt = self::timestampToMilliseconds($right['started_at'] ?? null);

                if ($leftStartedAt !== $rightStartedAt) {
                    return $leftStartedAt <=> $rightStartedAt;
                }

                return (string) ($left['id'] ?? '') <=> (string) ($right['id'] ?? '');
            });

            $states[$activityId] = $attempts;
        }

        return $states;
    }

    /**
     * @return iterable<WorkflowHistoryEvent>
     */
    private static function attemptEvents(WorkflowRun $run): iterable
    {
        return $run->historyEvents
            ->filter(static fn (WorkflowHistoryEvent $event): bool => in_array($event->event_type, [
                HistoryEventType::ActivityStarted,
                HistoryEventType::ActivityHeartbeatRecorded,
                HistoryEventType::ActivityRetryScheduled,
                HistoryEventType::ActivityCompleted,
                HistoryEventType::ActivityFailed,
                HistoryEventType::ActivityCancelled,
            ], true))
            ->sortBy('sequence');
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fromEvent(WorkflowHistoryEvent $event): ?array
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($event->payload) ? $event->payload : [];
        $activity = is_array($payload['activity'] ?? null) ? $payload['activity'] : [];
        $attempt = is_array($payload['activity_attempt'] ?? null) ? $payload['activity_attempt'] : [];
        $task = is_array($payload['task'] ?? null) ? $payload['task'] : [];
        $activityId = self::stringValue($payload['activity_execution_id'] ?? null)
            ?? self::stringValue($activity['id'] ?? null)
            ?? self::stringValue($attempt['activity_execution_id'] ?? null);
        $attemptId = self::stringValue($attempt['id'] ?? null)
            ?? self::stringValue($payload['activity_attempt_id'] ?? null)
            ?? self::stringValue($activity['attempt_id'] ?? null);

        if ($activityId === null || $attemptId === null) {
            return null;
        }

        $eventTime = self::timestamp($event->recorded_at ?? $event->created_at);
        $terminalEvent = in_array($event->event_type, [
            HistoryEventType::ActivityRetryScheduled,
            HistoryEventType::ActivityCompleted,
            HistoryEventType::ActivityFailed,
            HistoryEventType::ActivityCancelled,
        ], true);

        $snapshot = array_filter([
            'id' => $attemptId,
            'activity_execution_id' => $activityId,
            'workflow_task_id' => self::stringValue($attempt['task_id'] ?? null)
                ?? self::stringValue($event->workflow_task_id)
                ?? self::stringValue($task['id'] ?? null),
            'attempt_number' => self::intValue($attempt['attempt_number'] ?? null)
                ?? self::intValue($payload['attempt_number'] ?? null)
                ?? self::intValue($payload['retry_after_attempt'] ?? null)
                ?? self::intValue($activity['attempt_count'] ?? null)
                ?? self::intValue($task['attempt_count'] ?? null),
            'status' => self::statusForEvent($event->event_type)
                ?? self::stringValue($attempt['status'] ?? null),
            'lease_owner' => self::stringValue($attempt['lease_owner'] ?? null)
                ?? self::stringValue($task['lease_owner'] ?? null),
            'started_at' => self::timestamp($attempt['started_at'] ?? null)
                ?? self::timestamp($activity['started_at'] ?? null)
                ?? ($event->event_type === HistoryEventType::ActivityStarted ? $eventTime : null),
            'last_heartbeat_at' => self::timestamp($payload['heartbeat_at'] ?? null)
                ?? self::timestamp($attempt['last_heartbeat_at'] ?? null)
                ?? self::timestamp($activity['last_heartbeat_at'] ?? null),
            'last_heartbeat_progress' => HeartbeatProgress::fromStored($payload['progress'] ?? null)
                ?? HeartbeatProgress::fromStored($attempt['last_heartbeat_progress'] ?? null),
            'lease_expires_at' => $terminalEvent
                ? null
                : (self::timestamp($payload['lease_expires_at'] ?? null)
                    ?? self::timestamp($attempt['lease_expires_at'] ?? null)
                    ?? self::timestamp($task['lease_expires_at'] ?? null)),
            'closed_at' => self::timestamp($attempt['closed_at'] ?? null)
                ?? self::timestamp($activity['closed_at'] ?? null)
                ?? ($terminalEvent ? $eventTime : null),
        ], static fn (mixed $value): bool => $value !== null);

        if ($terminalEvent) {
            $snapshot['lease_expires_at'] = null;
        }

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fromAttempt(ActivityAttempt $attempt): array
    {
        return array_filter([
            'id' => $attempt->id,
            'activity_execution_id' => $attempt->activity_execution_id,
            'workflow_task_id' => $attempt->workflow_task_id,
            'attempt_number' => $attempt->attempt_number,
            'status' => $attempt->status?->value,
            'lease_owner' => $attempt->lease_owner,
            'started_at' => self::timestamp($attempt->started_at),
            'last_heartbeat_at' => self::timestamp($attempt->last_heartbeat_at),
            'lease_expires_at' => self::timestamp($attempt->lease_expires_at),
            'closed_at' => self::timestamp($attempt->closed_at),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private static function merge(array $state, array $snapshot): array
    {
        foreach ($snapshot as $key => $value) {
            $state[$key] = $value;
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private static function mergeMissing(array $state, array $snapshot): array
    {
        foreach ($snapshot as $key => $value) {
            if ($value !== null && ! array_key_exists($key, $state)) {
                $state[$key] = $value;
            }
        }

        return $state;
    }

    private static function statusForEvent(HistoryEventType $eventType): ?string
    {
        return match ($eventType) {
            HistoryEventType::ActivityStarted,
            HistoryEventType::ActivityHeartbeatRecorded => ActivityAttemptStatus::Running->value,
            HistoryEventType::ActivityRetryScheduled,
            HistoryEventType::ActivityFailed => ActivityAttemptStatus::Failed->value,
            HistoryEventType::ActivityCompleted => ActivityAttemptStatus::Completed->value,
            HistoryEventType::ActivityCancelled => ActivityAttemptStatus::Cancelled->value,
            default => null,
        };
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value)
            ? (int) $value
            : null;
    }

    private static function timestamp(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toJSON();
        }

        return self::stringValue($value);
    }

    private static function timestampToMilliseconds(mixed $timestamp): int
    {
        if ($timestamp instanceof CarbonInterface) {
            return $timestamp->getTimestampMs();
        }

        if (is_string($timestamp) && $timestamp !== '') {
            return Carbon::parse($timestamp)->getTimestampMs();
        }

        return PHP_INT_MAX;
    }
}
