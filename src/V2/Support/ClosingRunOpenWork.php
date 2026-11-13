<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\CancellationPolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;

/**
 * Settles the activities and timers a run still has open when its own code
 * closes it by completing, failing or continuing as new (#1117).
 *
 * A `select()` loser, or any other operation the workflow never awaited,
 * otherwise kept running after the run closed and appended history after the
 * terminal event. Each open activity is cancelled and each pending timer is
 * cancelled, recorded in the closing transaction before the terminal event,
 * so nothing is appended after it. An activity scheduled with the abandon
 * cancellation policy is left running; the caller lists it on the terminal
 * event and its later outcome is never appended to the closed run.
 *
 * Cancel, terminate, timeout and cooperative cancellation already settle open
 * work in their own close paths. Child workflows follow their parent-close
 * policy and continue-as-new hand-over instead (#868).
 */
final class ClosingRunOpenWork
{
    /**
     * Terminal event payload key that lists activities left running at close.
     */
    public const ABANDONED_ACTIVITIES = 'abandoned_activities';

    /**
     * `cancellation_reason` of an activity cancelled because its run closed.
     * Such a cancellation never decides a group or selection outcome: the
     * workflow had already moved past the operation when it closed.
     */
    public const CANCELLATION_REASON = 'run_closed';

    /**
     * @return list<array{activity_execution_id: string, sequence: int}> activities left running
     */
    public static function settle(WorkflowRun $run, WorkflowTask $closingTask): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, ActivityExecution> $executions */
        $executions = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->whereIn('status', [ActivityStatus::Pending->value, ActivityStatus::Running->value])
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
        /** @var \Illuminate\Database\Eloquent\Collection<int, WorkflowTimer> $timers */
        $timers = WorkflowTimer::query()
            ->where('workflow_run_id', $run->id)
            ->where('status', TimerStatus::Pending->value)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        if ($executions->isEmpty() && $timers->isEmpty()) {
            return [];
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, WorkflowTask> $openTasks */
        $openTasks = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->whereKeyNot($closingTask->id)
            ->whereIn('task_type', [TaskType::Activity->value, TaskType::Timer->value])
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->orderBy('id')
            ->get();

        $abandoned = [];

        foreach ($executions as $execution) {
            if (self::abandonsAtClose($execution)) {
                $abandoned[] = [
                    'activity_execution_id' => (string) $execution->id,
                    'sequence' => (int) $execution->sequence,
                ];

                continue;
            }

            $activityTask = $openTasks->first(
                static fn (WorkflowTask $task): bool => $task->task_type === TaskType::Activity
                    && ($task->payload['activity_execution_id'] ?? null) === $execution->id
            );

            ActivityCancellation::record(
                $run,
                $execution,
                $activityTask,
                null,
                cancellationReason: self::CANCELLATION_REASON,
            );
        }

        foreach ($timers as $timer) {
            $timer->forceFill([
                'status' => TimerStatus::Cancelled,
            ])->save();

            $timerTask = $openTasks->first(
                static fn (WorkflowTask $task): bool => $task->task_type === TaskType::Timer
                    && ($task->payload['timer_id'] ?? null) === $timer->id
            );

            if ($timerTask instanceof WorkflowTask) {
                $timerTask->forceFill([
                    'status' => TaskStatus::Cancelled,
                    'lease_expires_at' => null,
                    'last_error' => null,
                ])->save();
            }

            TimerCancellation::record($run, $timer, $closingTask);
        }

        return $abandoned;
    }

    /**
     * Whether a closed run left this activity running under the abandon policy
     * when its own code closed it. Its outcome is never appended to the run.
     */
    public static function abandonedAtClose(WorkflowRun $run, ActivityExecution $execution): bool
    {
        if (! $run->status->isTerminal() || $execution->workflow_run_id !== $run->id
            || ! self::abandonsAtClose($execution)) {
            return false;
        }

        /** @var WorkflowHistoryEvent|null $terminal */
        $terminal = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->whereIn('event_type', [
                HistoryEventType::WorkflowCompleted->value,
                HistoryEventType::WorkflowFailed->value,
                HistoryEventType::WorkflowContinuedAsNew->value,
                HistoryEventType::WorkflowTimedOut->value,
            ])
            ->orderByDesc('sequence')
            ->first();
        $listed = $terminal?->payload[self::ABANDONED_ACTIVITIES] ?? null;

        if (! is_array($listed)) {
            return false;
        }

        foreach ($listed as $entry) {
            if (is_array($entry) && ($entry['activity_execution_id'] ?? null) === $execution->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this history event is an activity cancellation its run's close
     * recorded, rather than an outcome the workflow could have observed.
     */
    public static function settledAtClose(WorkflowHistoryEvent $event): bool
    {
        return $event->event_type === HistoryEventType::ActivityCancelled
            && ($event->payload['cancellation_reason'] ?? null) === self::CANCELLATION_REASON;
    }

    private static function abandonsAtClose(ActivityExecution $execution): bool
    {
        return ($execution->activity_options['cancellation_policy'] ?? null) === CancellationPolicy::Abandon->value
            && ! LocalActivityRuntime::isExecution($execution);
    }
}
