<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;

/** Validates retry lineage before redrive reuses a completed activity or resumes a failed one. */
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
        $execution = null;
        $attempt = null;
        $task = null;
        $number = 0;
        $nextTask = null;
        $terminal = false;
        $seenAttempts = [];
        $seenTasks = [];
        foreach ($events as $event) {
            $payload = $event->payload;
            $id = $payload['activity_execution_id'] ?? null;
            if (! is_string($id) || $id === '' || ($execution !== null && $id !== $execution) || $terminal) {
                return false;
            }
            $execution = $id;
            if ($event->event_type === HistoryEventType::ActivityStarted) {
                $currentAttempt = $payload['activity_attempt_id'] ?? null;
                $currentTask = $event->workflow_task_id ?? ($payload['workflow_task_id'] ?? null);
                if (isset($event->workflow_task_id, $payload['workflow_task_id']) && $event->workflow_task_id !== $payload['workflow_task_id']) {
                    return false;
                }
                if (! is_string($currentAttempt) || $currentAttempt === '' || isset($seenAttempts[$currentAttempt])
                    || ! is_string($currentTask) || $currentTask === '' || isset($seenTasks[$currentTask])
                    || ($payload['attempt_number'] ?? null) !== $number + 1
                    || ($number > 0 && $nextTask !== $currentTask)) {
                    return false;
                }
                $attempt = $currentAttempt;
                $task = $currentTask;
                $seenAttempts[$attempt] = true;
                $seenTasks[$task] = true;
                ++$number;
                $nextTask = null;
            } elseif ($event->event_type === HistoryEventType::ActivityRetryScheduled) {
                $retry = $payload['retry_task_id'] ?? null;
                $maximum = $payload['max_attempts'] ?? null;
                if ($attempt === null || $nextTask !== null
                    || ($payload['activity_attempt_id'] ?? null) !== $attempt
                    || ($payload['retry_after_attempt_id'] ?? null) !== $attempt
                    || ($payload['retry_after_attempt'] ?? null) !== $number
                    || ($payload['retry_of_task_id'] ?? null) !== $task
                    || ! is_string($retry) || $retry === '' || isset($seenTasks[$retry])
                    || ($maximum !== null && (! is_int($maximum) || $maximum <= $number))) {
                    return false;
                }
                $nextTask = $retry;
            } elseif (in_array(
                $event->event_type,
                [HistoryEventType::ActivityCompleted, HistoryEventType::ActivityFailed],
                true
            )) {
                if ($nextTask !== null || $attempt === null || ($payload['activity_attempt_id'] ?? null) !== $attempt
                    || ($payload['attempt_number'] ?? null) !== $number) {
                    return false;
                }
                $terminal = true;
            }
        }
        return $terminal;
    }
}
