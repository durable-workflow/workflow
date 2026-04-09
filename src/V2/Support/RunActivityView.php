<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Carbon;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class RunActivityView
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function activitiesForRun(WorkflowRun $run): array
    {
        return self::activityStates($run);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function logsForRun(WorkflowRun $run): array
    {
        return self::logsFromActivities(self::activityStates($run));
    }

    /**
     * @param list<array<string, mixed>> $activities
     * @return list<array<string, mixed>>
     */
    public static function logsFromActivities(array $activities): array
    {
        return array_map(static fn (array $activity): array => [
            'id' => $activity['id'],
            'index' => max(($activity['sequence'] ?? 1) - 1, 0),
            'now' => $activity['started_at'] ?? $activity['created_at'],
            'class' => $activity['class'],
            'result' => $activity['result'],
            'created_at' => $activity['closed_at']
                ?? $activity['last_heartbeat_at']
                ?? $activity['started_at']
                ?? $activity['created_at'],
        ], $activities);
    }

    /**
     * @return array<string, string>
     */
    public static function classesForRun(WorkflowRun $run): array
    {
        return self::classesFromActivities(self::activityStates($run));
    }

    /**
     * @param list<array<string, mixed>> $activities
     * @return array<string, string>
     */
    public static function classesFromActivities(array $activities): array
    {
        $classes = [];

        foreach ($activities as $activity) {
            if (! is_string($activity['id'] ?? null) || ! is_string($activity['class'] ?? null)) {
                continue;
            }

            $classes[$activity['id']] = $activity['class'];
        }

        return $classes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function activityStates(WorkflowRun $run): array
    {
        $run->loadMissing(['activityExecutions.attempts', 'historyEvents']);

        $states = [];
        $executions = $run->activityExecutions->keyBy('id');

        foreach (self::activityEvents($run) as $event) {
            $snapshot = ActivitySnapshot::fromEvent($event);
            $activityId = is_array($snapshot) && is_string($snapshot['id'] ?? null)
                ? $snapshot['id']
                : null;

            if ($activityId === null) {
                continue;
            }

            /** @var ActivityExecution|null $execution */
            $execution = $executions->get($activityId);
            $state = $states[$activityId]
                ?? ($execution instanceof ActivityExecution ? ActivitySnapshot::fromExecution($execution) : ['id' => $activityId]);

            $states[$activityId] = ActivitySnapshot::merge($state, $snapshot);
        }

        foreach ($run->activityExecutions as $execution) {
            if (! $execution instanceof ActivityExecution || array_key_exists($execution->id, $states)) {
                continue;
            }

            $states[$execution->id] = ActivitySnapshot::fromExecution($execution);
        }

        $activities = [];

        foreach ($states as $activityId => $state) {
            /** @var ActivityExecution|null $execution */
            $execution = $executions->get($activityId);
            $activities[] = self::presentActivity($state, $execution);
        }

        usort($activities, static function (array $left, array $right): int {
            $leftSequence = is_int($left['sequence'] ?? null) ? $left['sequence'] : PHP_INT_MAX;
            $rightSequence = is_int($right['sequence'] ?? null) ? $right['sequence'] : PHP_INT_MAX;

            if ($leftSequence !== $rightSequence) {
                return $leftSequence <=> $rightSequence;
            }

            $leftCreatedAt = self::timestampToMilliseconds($left['created_at'] ?? null);
            $rightCreatedAt = self::timestampToMilliseconds($right['created_at'] ?? null);

            if ($leftCreatedAt !== $rightCreatedAt) {
                return $leftCreatedAt <=> $rightCreatedAt;
            }

            return ($left['id'] ?? '') <=> ($right['id'] ?? '');
        });

        return $activities;
    }

    /**
     * @return iterable<WorkflowHistoryEvent>
     */
    private static function activityEvents(WorkflowRun $run): iterable
    {
        return $run->historyEvents
            ->filter(static fn (WorkflowHistoryEvent $event): bool => in_array($event->event_type, [
                HistoryEventType::ActivityScheduled,
                HistoryEventType::ActivityStarted,
                HistoryEventType::ActivityHeartbeatRecorded,
                HistoryEventType::ActivityRetryScheduled,
                HistoryEventType::ActivityCompleted,
                HistoryEventType::ActivityFailed,
            ], true))
            ->sortBy('sequence');
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function presentActivity(array $state, ?ActivityExecution $execution = null): array
    {
        $attempts = self::presentAttempts($state, $execution);
        $latestAttempt = $attempts === []
            ? null
            : $attempts[array_key_last($attempts)];
        $attemptCount = is_int($state['attempt_count'] ?? null)
            ? $state['attempt_count']
            : (is_int($latestAttempt['attempt_number'] ?? null) ? $latestAttempt['attempt_number'] : 0);

        return [
            'id' => $state['id'] ?? null,
            'sequence' => $state['sequence'] ?? null,
            'type' => $state['type'] ?? null,
            'class' => $state['class'] ?? null,
            'parallel_group_kind' => $state['parallel_group_kind'] ?? null,
            'parallel_group_id' => $state['parallel_group_id'] ?? null,
            'parallel_group_base_sequence' => $state['parallel_group_base_sequence'] ?? null,
            'parallel_group_size' => $state['parallel_group_size'] ?? null,
            'parallel_group_index' => $state['parallel_group_index'] ?? null,
            'parallel_group_path' => $state['parallel_group_path'] ?? [],
            'attempt_id' => $state['attempt_id'] ?? ($latestAttempt['id'] ?? null),
            'status' => $state['status'] ?? 'pending',
            'attempt_count' => $attemptCount,
            'connection' => $state['connection'] ?? null,
            'queue' => $state['queue'] ?? null,
            'last_heartbeat_at' => $state['last_heartbeat_at'] ?? ($latestAttempt['last_heartbeat_at'] ?? null),
            'created_at' => $state['created_at'] ?? null,
            'started_at' => $state['started_at'] ?? ($latestAttempt['started_at'] ?? null),
            'closed_at' => $state['closed_at'] ?? ($latestAttempt['closed_at'] ?? null),
            'arguments' => self::publicSerializedValue($state['arguments'] ?? null, []),
            'result' => self::publicSerializedValue($state['result'] ?? null, null),
            'attempts' => $attempts,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return list<array<string, mixed>>
     */
    private static function presentAttempts(array $state, ?ActivityExecution $execution): array
    {
        $attempts = [];

        if ($execution instanceof ActivityExecution) {
            foreach ($execution->attempts as $attempt) {
                if (! $attempt instanceof ActivityAttempt) {
                    continue;
                }

                $attempts[] = [
                    'id' => $attempt->id,
                    'attempt_number' => $attempt->attempt_number,
                    'status' => $attempt->status?->value,
                    'task_id' => $attempt->workflow_task_id,
                    'lease_owner' => $attempt->lease_owner,
                    'lease_expires_at' => $attempt->lease_expires_at,
                    'started_at' => $attempt->started_at,
                    'last_heartbeat_at' => $attempt->last_heartbeat_at,
                    'closed_at' => $attempt->closed_at,
                ];
            }
        }

        $syntheticAttempt = self::syntheticAttempt($state, $execution);

        if (
            $syntheticAttempt !== null
            && ! collect($attempts)->contains(static fn (array $attempt): bool => ($attempt['id'] ?? null) === $syntheticAttempt['id'])
        ) {
            $attempts[] = $syntheticAttempt;
        }

        usort($attempts, static function (array $left, array $right): int {
            $leftAttemptNumber = is_int($left['attempt_number'] ?? null) ? $left['attempt_number'] : PHP_INT_MAX;
            $rightAttemptNumber = is_int($right['attempt_number'] ?? null) ? $right['attempt_number'] : PHP_INT_MAX;

            if ($leftAttemptNumber !== $rightAttemptNumber) {
                return $leftAttemptNumber <=> $rightAttemptNumber;
            }

            $leftStartedAt = self::timestampToMilliseconds($left['started_at'] ?? null);
            $rightStartedAt = self::timestampToMilliseconds($right['started_at'] ?? null);

            if ($leftStartedAt !== $rightStartedAt) {
                return $leftStartedAt <=> $rightStartedAt;
            }

            return ($left['id'] ?? '') <=> ($right['id'] ?? '');
        });

        return array_values($attempts);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    private static function syntheticAttempt(array $state, ?ActivityExecution $execution): ?array
    {
        $attemptId = self::stringValue($state['attempt_id'] ?? null)
            ?? self::stringValue($execution?->current_attempt_id);
        $attemptNumber = is_int($state['attempt_count'] ?? null)
            ? $state['attempt_count']
            : self::executionAttemptCount($execution);

        if ($attemptId === null || $attemptNumber === null || $attemptNumber <= 0) {
            return null;
        }

        return [
            'id' => $attemptId,
            'attempt_number' => $attemptNumber,
            'status' => $state['status'] ?? $execution?->status?->value ?? null,
            'task_id' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'started_at' => $state['started_at'] ?? $execution?->started_at,
            'last_heartbeat_at' => $state['last_heartbeat_at'] ?? $execution?->last_heartbeat_at,
            'closed_at' => $state['closed_at'] ?? $execution?->closed_at,
        ];
    }

    private static function publicSerializedValue(mixed $value, mixed $default): string
    {
        if (! is_string($value) || $value === '') {
            return serialize($default);
        }

        try {
            return serialize(Serializer::unserialize($value));
        } catch (Throwable) {
            return serialize($default);
        }
    }

    private static function timestampToMilliseconds(mixed $timestamp): int
    {
        if ($timestamp instanceof \Carbon\CarbonInterface) {
            return $timestamp->getTimestampMs();
        }

        if (is_string($timestamp) && $timestamp !== '') {
            return Carbon::parse($timestamp)->getTimestampMs();
        }

        return PHP_INT_MAX;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function executionAttemptCount(?ActivityExecution $execution): ?int
    {
        $attemptCount = is_int($execution?->attempt_count) ? $execution->attempt_count : 0;

        return $attemptCount > 0 ? $attemptCount : null;
    }
}
