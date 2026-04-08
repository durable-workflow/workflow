<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Carbon;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
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
        $run->loadMissing(['activityExecutions', 'historyEvents']);

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

        $activities = array_values(array_map(
            static fn (array $state): array => self::presentActivity($state),
            $states,
        ));

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
                HistoryEventType::ActivityCompleted,
                HistoryEventType::ActivityFailed,
            ], true))
            ->sortBy('sequence');
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function presentActivity(array $state): array
    {
        return [
            'id' => $state['id'] ?? null,
            'sequence' => $state['sequence'] ?? null,
            'type' => $state['type'] ?? null,
            'class' => $state['class'] ?? null,
            'status' => $state['status'] ?? 'pending',
            'attempt_count' => is_int($state['attempt_count'] ?? null) ? $state['attempt_count'] : 0,
            'connection' => $state['connection'] ?? null,
            'queue' => $state['queue'] ?? null,
            'last_heartbeat_at' => $state['last_heartbeat_at'] ?? null,
            'created_at' => $state['created_at'] ?? null,
            'started_at' => $state['started_at'] ?? null,
            'closed_at' => $state['closed_at'] ?? null,
            'arguments' => self::publicSerializedValue($state['arguments'] ?? null, []),
            'result' => self::publicSerializedValue($state['result'] ?? null, null),
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
}
