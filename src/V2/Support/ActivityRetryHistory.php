<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;

/** @internal Validates recorded retry lineage before reusing or resuming an activity. */
final class ActivityRetryHistory
{
    /**
     * @param array<WorkflowHistoryEvent> $events
     */
    public static function valid(array $events): bool
    {
        $hasRetries = false;
        foreach ($events as $event) {
            $hasRetries = $hasRetries || $event->event_type === HistoryEventType::ActivityRetryScheduled;
        }
        if (! $hasRetries) {
            return true;
        }
        $phase = 'schedule';
        $execution = null;
        $attempt = null;
        $task = null;
        $number = 0;
        $nextTask = null;
        $seenAttempts = [];
        $seenTasks = [];
        foreach ($events as $event) {
            $payload = $event->payload;
            $id = $payload['activity_execution_id'] ?? null;
            if (
                ! is_string($id)
                || $id === ''
                || ($execution !== null && $id !== $execution)
                || $phase === 'terminal'
            ) {
                return false;
            }
            $execution = $id;
            if ($event->event_type === HistoryEventType::ActivityScheduled) {
                if ($phase !== 'schedule') {
                    return false;
                }
                $phase = 'start';
            } elseif ($event->event_type === HistoryEventType::ActivityStarted) {
                $currentAttempt = $payload['activity_attempt_id'] ?? null;
                $currentTask = $event->workflow_task_id ?? ($payload['workflow_task_id'] ?? null);
                if (! in_array($phase, ['start', 'retry'], true)
                    || ! is_string($currentAttempt) || $currentAttempt === '' || isset($seenAttempts[$currentAttempt])
                    || ! is_string($currentTask) || $currentTask === '' || isset($seenTasks[$currentTask])
                    || ($payload['attempt_number'] ?? null) !== $number + 1
                    || ($number > 0 && $nextTask !== $currentTask)
                    || ! self::matchesTask($event, $currentTask)) {
                    return false;
                }
                $attempt = $currentAttempt;
                $task = $currentTask;
                $seenAttempts[$attempt] = true;
                $seenTasks[$task] = true;
                ++$number;
                $nextTask = null;
                $phase = 'active';
            } elseif ($event->event_type === HistoryEventType::ActivityRetryScheduled) {
                $retry = $payload['retry_task_id'] ?? null;
                $maximum = $payload['max_attempts'] ?? null;
                if ($phase !== 'active'
                    || ($payload['activity_attempt_id'] ?? null) !== $attempt
                    || ($payload['retry_after_attempt_id'] ?? null) !== $attempt
                    || ($payload['retry_after_attempt'] ?? null) !== $number
                    || ($payload['retry_of_task_id'] ?? null) !== $task
                    || ! is_string($retry) || $retry === '' || isset($seenTasks[$retry])
                    || ($maximum !== null && (! is_int($maximum) || $maximum <= $number))
                    || ! self::matchesTask($event, $task)) {
                    return false;
                }
                $nextTask = $retry;
                $phase = 'retry';
            } elseif (in_array($event->event_type, [
                HistoryEventType::ActivityHeartbeatRecorded,
                HistoryEventType::ActivityCompleted,
                HistoryEventType::ActivityFailed,
            ], true)) {
                if ($phase !== 'active' || ($payload['activity_attempt_id'] ?? null) !== $attempt
                    || ($payload['attempt_number'] ?? null) !== $number
                    || ! self::matchesTask($event, $task)) {
                    return false;
                }
                if ($event->event_type !== HistoryEventType::ActivityHeartbeatRecorded) {
                    $phase = 'terminal';
                }
            } else {
                return false;
            }
            if (in_array($phase, ['active', 'retry', 'terminal'], true)
                && ! self::matchesAttemptSnapshot($payload, $execution, $attempt, $number, $task)) {
                return false;
            }
        }
        return $phase === 'terminal';
    }

    private static function matchesTask(WorkflowHistoryEvent $event, string $task): bool
    {
        $payload = $event->payload;
        return ($event->workflow_task_id === null || $event->workflow_task_id === $task)
            && (! array_key_exists('workflow_task_id', $payload) || $payload['workflow_task_id'] === $task);
    }

    private static function matchesAttemptSnapshot(
        array $payload,
        string $execution,
        string $attempt,
        int $number,
        string $task,
    ): bool {
        if (! array_key_exists('activity_attempt', $payload)) {
            return true;
        }
        $snapshot = $payload['activity_attempt'];
        if (! is_array($snapshot)) {
            return false;
        }
        foreach ([
            'id' => $attempt,
            'activity_execution_id' => $execution,
            'attempt_number' => $number,
            'task_id' => $task,
        ] as $field => $expected) {
            if (array_key_exists($field, $snapshot) && $snapshot[$field] !== $expected) {
                return false;
            }
        }
        return true;
    }
}
