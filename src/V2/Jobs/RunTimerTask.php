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
use Workflow\V2\Support\TaskBackendCapabilities;
use Workflow\V2\Support\TaskCompatibility;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\Support\TimerRecovery;
use Workflow\V2\Support\TimerTransportChunker;
use Workflow\V2\Support\WorkerCompatibilityFleet;

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
        WorkerCompatibilityFleet::heartbeat(
            is_string($this->connection ?? null) ? $this->connection : null,
            is_string($this->queue ?? null) ? $this->queue : null,
        );

        [$timerId, $releaseIn] = $this->claimTask();

        if ($releaseIn !== null) {
            $this->release(TimerTransportChunker::cappedReleaseDelay(
                $releaseIn,
                is_string($this->connection ?? null) ? $this->connection : null,
            ));

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

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()
                ->lockForUpdate()
                ->findOrFail($task->workflow_run_id);
            $timer = TimerRecovery::restore($run, $timerId);

            if (! $timer instanceof WorkflowTimer) {
                $task->forceFill([
                    'status' => TaskStatus::Completed,
                    'lease_expires_at' => null,
                    'last_error' => sprintf(
                        'Timer %s could not be restored from durable history for timer task %s.',
                        $timerId,
                        $task->id,
                    ),
                ])->save();

                RunSummaryProjector::project(
                    $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
                );

                return null;
            }

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
            $conditionWaitId = is_string($task->payload['condition_wait_id'] ?? null)
                ? $task->payload['condition_wait_id']
                : null;
            $conditionKey = is_string($task->payload['condition_key'] ?? null)
                ? $task->payload['condition_key']
                : null;
            $conditionDefinitionFingerprint = is_string($task->payload['condition_definition_fingerprint'] ?? null)
                ? $task->payload['condition_definition_fingerprint']
                : null;

            WorkflowHistoryEvent::record($run, HistoryEventType::TimerFired, array_filter([
                'timer_id' => $timer->id,
                'sequence' => $timer->sequence,
                'delay_seconds' => $timer->delay_seconds,
                'fire_at' => $timer->fire_at?->toJSON(),
                'fired_at' => $timer->fired_at?->toJSON(),
                'timer_kind' => $conditionWaitId === null ? null : 'condition_timeout',
                'condition_wait_id' => $conditionWaitId,
                'condition_key' => $conditionKey,
                'condition_definition_fingerprint' => $conditionDefinitionFingerprint,
            ], static fn (mixed $value): bool => $value !== null), $task);

            $task->forceFill([
                'status' => TaskStatus::Completed,
                'lease_expires_at' => null,
            ])->save();

            /** @var WorkflowTask $resumeTask */
            $resumeTask = WorkflowTask::query()->create([
                'workflow_run_id' => $run->id,
                'namespace' => $run->namespace,
                'task_type' => TaskType::Workflow->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => now(),
                'payload' => array_filter([
                    'workflow_wait_kind' => $conditionWaitId === null ? null : 'condition',
                    'open_wait_id' => $conditionWaitId,
                    'resume_source_kind' => $conditionWaitId === null ? null : 'timer',
                    'resume_source_id' => $conditionWaitId === null ? null : $timer->id,
                    'timer_id' => $conditionWaitId === null ? null : $timer->id,
                    'condition_wait_id' => $conditionWaitId,
                    'condition_key' => $conditionKey,
                    'condition_definition_fingerprint' => $conditionDefinitionFingerprint,
                    'workflow_sequence' => $conditionWaitId === null ? null : $timer->sequence,
                ], static fn (mixed $value): bool => $value !== null),
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

            if ($task->available_at !== null && $task->available_at->isFuture()) {
                $remainingMilliseconds = max(1, $task->available_at->getTimestampMs() - now()->getTimestampMs());

                return [null, (int) ceil($remainingMilliseconds / 1000)];
            }

            $timerId = $task->payload['timer_id'] ?? null;

            if (! is_string($timerId)) {
                return [null, null];
            }

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()->findOrFail($task->workflow_run_id);
            $timer = TimerRecovery::restore($run, $timerId);

            if (! $timer instanceof WorkflowTimer) {
                $task->forceFill([
                    'last_claim_failed_at' => now(),
                    'last_claim_error' => sprintf(
                        'Timer %s could not be restored from durable history.',
                        $timerId,
                    ),
                ])->save();

                RunSummaryProjector::project(
                    $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
                );

                return [null, null];
            }

            TaskCompatibility::sync($task, $run);

            if (TaskBackendCapabilities::recordClaimFailureIfUnsupported($task) !== null) {
                RunSummaryProjector::project(
                    $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures'])
                );

                return [null, null];
            }

            if (! TaskCompatibility::supported($task, $run)) {
                RunSummaryProjector::project(
                    $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures'])
                );

                return [null, null];
            }

            $task->forceFill([
                'status' => TaskStatus::Leased,
                'leased_at' => now(),
                'lease_owner' => $this->taskId,
                'lease_expires_at' => now()
                    ->addMinutes(5),
                'attempt_count' => $task->attempt_count + 1,
                'last_claim_failed_at' => null,
                'last_claim_error' => null,
            ])->save();

            RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures'])
            );

            return [$timerId, null];
        });
    }
}
