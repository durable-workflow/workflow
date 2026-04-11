<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Carbon;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

final class ActivityRecovery
{
    public static function pendingExecutionForSummary(
        WorkflowRun $run,
        WorkflowRunSummary $summary,
    ): ?ActivityExecution {
        $activityId = self::stringValue($summary->resume_source_id);

        if ($activityId !== null) {
            $execution = self::restore($run, $activityId);

            if ($execution instanceof ActivityExecution && $execution->status === ActivityStatus::Pending) {
                return $execution;
            }
        }

        foreach (RunActivityView::activitiesForRun($run) as $activity) {
            if (($activity['status'] ?? null) !== ActivityStatus::Pending->value) {
                continue;
            }

            $activityId = self::stringValue($activity['id'] ?? null);

            if ($activityId === null) {
                continue;
            }

            $execution = self::restore($run, $activityId);

            if ($execution instanceof ActivityExecution && $execution->status === ActivityStatus::Pending) {
                return $execution;
            }
        }

        return null;
    }

    public static function restore(WorkflowRun $run, string $activityId): ?ActivityExecution
    {
        /** @var ActivityExecution|null $execution */
        $execution = $run->activityExecutions
            ->first(static fn (ActivityExecution $execution): bool => $execution->id === $activityId);

        if ($execution instanceof ActivityExecution) {
            return $execution;
        }

        /** @var ActivityExecution|null $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->find($activityId);

        if ($execution instanceof ActivityExecution) {
            return $execution;
        }

        $state = self::stateFromHistory($run, $activityId);

        if ($state === null || self::stringValue($state['status'] ?? null) !== ActivityStatus::Pending->value) {
            return null;
        }

        $sequence = self::intValue($state['sequence'] ?? null);
        $activityClass = self::stringValue($state['class'] ?? null);
        $activityType = self::stringValue($state['type'] ?? null);

        if ($sequence === null || $activityClass === null || $activityType === null) {
            return null;
        }

        /** @var ActivityExecution $restored */
        $restored = ActivityExecution::query()->create([
            'id' => $activityId,
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'activity_class' => $activityClass,
            'activity_type' => $activityType,
            'status' => ActivityStatus::Pending->value,
            'arguments' => self::stringValue($state['arguments'] ?? null),
            'connection' => self::stringValue($state['connection'] ?? null) ?? $run->connection,
            'queue' => self::stringValue($state['queue'] ?? null) ?? $run->queue,
            'attempt_count' => max(0, self::intValue($state['attempt_count'] ?? null) ?? 0),
            'retry_policy' => is_array($state['retry_policy'] ?? null) ? $state['retry_policy'] : null,
            'parallel_group_path' => is_array($state['parallel_group_path'] ?? null)
                ? $state['parallel_group_path']
                : null,
            'created_at' => self::timestamp($state['created_at'] ?? null) ?? now(),
            'updated_at' => now(),
        ]);

        return $restored;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function stateFromHistory(WorkflowRun $run, string $activityId): ?array
    {
        $run->loadMissing('historyEvents');

        $state = null;

        foreach ($run->historyEvents->sortBy('sequence') as $event) {
            if (! $event instanceof WorkflowHistoryEvent || ! in_array($event->event_type, [
                HistoryEventType::ActivityScheduled,
                HistoryEventType::ActivityStarted,
                HistoryEventType::ActivityHeartbeatRecorded,
                HistoryEventType::ActivityRetryScheduled,
                HistoryEventType::ActivityCompleted,
                HistoryEventType::ActivityFailed,
                HistoryEventType::ActivityCancelled,
            ], true)) {
                continue;
            }

            $snapshot = ActivitySnapshot::fromEvent($event);

            if (! is_array($snapshot) || self::stringValue($snapshot['id'] ?? null) !== $activityId) {
                continue;
            }

            $state = ActivitySnapshot::merge($state ?? [
                'id' => $activityId,
            ], $snapshot);
        }

        return $state;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function intValue(mixed $value): ?int
    {
        return is_int($value)
            ? $value
            : null;
    }

    private static function timestamp(mixed $value): ?\Carbon\CarbonInterface
    {
        if ($value instanceof \Carbon\CarbonInterface) {
            return $value;
        }

        return is_string($value) && $value !== ''
            ? Carbon::parse($value)
            : null;
    }
}
