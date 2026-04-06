<?php

declare(strict_types=1);

namespace Workflow\V2\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\Support\WorkerCompatibility;

final class RunTimerTask implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly string $taskId,
    ) {
        $this->afterCommit();
    }

    public function handle(): void
    {
        [$timerId, $releaseIn] = $this->claimTask();

        if ($releaseIn !== null) {
            $this->release($releaseIn);

            return;
        }

        if ($timerId === null) {
            return;
        }

        $resumeTask = DB::transaction(function () use ($timerId): ?WorkflowTask {
            /** @var WorkflowTask $task */
            $task = WorkflowTask::query()
                ->lockForUpdate()
                ->findOrFail($this->taskId);

            /** @var WorkflowTimer $timer */
            $timer = WorkflowTimer::query()
                ->lockForUpdate()
                ->findOrFail($timerId);

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()
                ->lockForUpdate()
                ->findOrFail($timer->workflow_run_id);

            if (
                in_array($run->status, [RunStatus::Cancelled, RunStatus::Terminated], true)
                || $timer->status === TimerStatus::Cancelled
            ) {
                $task->forceFill([
                    'status' => $task->status === TaskStatus::Cancelled ? TaskStatus::Cancelled : TaskStatus::Completed,
                    'lease_expires_at' => null,
                ])->save();

                if ($timer->status !== TimerStatus::Cancelled) {
                    $timer->forceFill([
                        'status' => TimerStatus::Cancelled,
                    ])->save();
                }

                RunSummaryProjector::project(
                    $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures'])
                );

                return null;
            }

            $timer->forceFill([
                'status' => TimerStatus::Fired,
                'fired_at' => now(),
            ])->save();

            WorkflowHistoryEvent::record($run, HistoryEventType::TimerFired, [
                'timer_id' => $timer->id,
                'sequence' => $timer->sequence,
                'delay_seconds' => $timer->delay_seconds,
                'fired_at' => $timer->fired_at?->toJSON(),
            ], $task->id);

            $task->forceFill([
                'status' => TaskStatus::Completed,
                'lease_expires_at' => null,
            ])->save();

            /** @var WorkflowTask $resumeTask */
            $resumeTask = WorkflowTask::query()->create([
                'workflow_run_id' => $run->id,
                'task_type' => TaskType::Workflow->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => now(),
                'payload' => [],
                'connection' => $run->connection,
                'queue' => $run->queue,
                'compatibility' => $run->compatibility,
            ]);

            RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures'])
            );

            return $resumeTask;
        });

        if ($resumeTask instanceof WorkflowTask) {
            TaskDispatcher::dispatch($resumeTask);
        }
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function claimTask(): array
    {
        return DB::transaction(function (): array {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->lockForUpdate()
                ->find($this->taskId);

            if ($task === null || $task->task_type !== TaskType::Timer || $task->status !== TaskStatus::Ready) {
                return [null, null];
            }

            if (! WorkerCompatibility::supports($task->compatibility)) {
                return [null, null];
            }

            if ($task->available_at !== null && $task->available_at->isFuture()) {
                $remainingMilliseconds = max(1, $task->available_at->getTimestampMs() - now()->getTimestampMs());

                return [null, (int) ceil($remainingMilliseconds / 1000)];
            }

            $timerId = $task->payload['timer_id'] ?? null;

            if (! is_string($timerId)) {
                return [null, null];
            }

            $task->forceFill([
                'status' => TaskStatus::Leased,
                'leased_at' => now(),
                'lease_owner' => $this->taskId,
                'lease_expires_at' => now()
                    ->addMinutes(5),
                'attempt_count' => $task->attempt_count + 1,
            ])->save();

            /** @var WorkflowTimer $timer */
            $timer = WorkflowTimer::query()->findOrFail($timerId);
            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()->findOrFail($timer->workflow_run_id);
            RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures'])
            );

            return [$timerId, null];
        });
    }
}
