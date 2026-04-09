<?php

declare(strict_types=1);

namespace Workflow\V2\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Throwable;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityLease;
use Workflow\V2\Support\ActivityOutcomeRecorder;
use Workflow\V2\Support\ActivityRetryPolicy;
use Workflow\V2\Support\ActivitySnapshot;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\TaskBackendCapabilities;
use Workflow\V2\Support\TaskCompatibility;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\WorkerCompatibilityFleet;

final class RunActivityTask implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

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

        [$claim, $releaseIn] = $this->claimTask();

        if ($releaseIn !== null) {
            $this->release($releaseIn);

            return;
        }

        if ($claim === null) {
            return;
        }

        $activityExecutionId = $claim['activity_execution_id'];
        $attemptId = $claim['attempt_id'];
        $attemptCount = $claim['attempt_count'];

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->with('run')
            ->findOrFail($activityExecutionId);

        $activityClass = TypeRegistry::resolveActivityClass($execution->activity_class, $execution->activity_type);
        $activity = new $activityClass($execution, $execution->run, $this->taskId);
        $arguments = $activity->resolveMethodDependencies(
            $execution->activityArguments(),
            new ReflectionMethod($activity, 'execute'),
        );

        $result = null;
        $throwable = null;

        try {
            $result = $activity->execute(...$arguments);
        } catch (Throwable $error) {
            $throwable = $error;
        }

        $maxAttempts = ActivityRetryPolicy::maxAttempts($execution, $activity);
        $backoffSeconds = ActivityRetryPolicy::backoffSeconds($execution, $activity, $attemptCount);

        $outcome = ActivityOutcomeRecorder::record(
            $this->taskId,
            $attemptId,
            $attemptCount,
            $result,
            $throwable,
            $maxAttempts,
            $backoffSeconds,
        );

        $nextTask = $outcome['next_task'];

        if ($nextTask instanceof WorkflowTask) {
            TaskDispatcher::dispatch($nextTask);
        }
    }

    /**
     * @return array{0: array{activity_execution_id: string, attempt_id: string, attempt_count: int}|null, 1: int|null}
     */
    private function claimTask(): array
    {
        return DB::transaction(function (): array {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->lockForUpdate()
                ->find($this->taskId);

            if ($task === null || $task->task_type !== TaskType::Activity || $task->status !== TaskStatus::Ready) {
                return [null, null];
            }

            if ($task->available_at !== null && $task->available_at->isFuture()) {
                $remainingMilliseconds = max(1, $task->available_at->getTimestampMs() - now()->getTimestampMs());

                return [null, (int) ceil($remainingMilliseconds / 1000)];
            }

            $activityExecutionId = $task->payload['activity_execution_id'] ?? null;

            if (! is_string($activityExecutionId)) {
                return [null, null];
            }

            /** @var ActivityExecution $execution */
            $execution = ActivityExecution::query()
                ->lockForUpdate()
                ->findOrFail($activityExecutionId);

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()->findOrFail($execution->workflow_run_id);

            TaskCompatibility::sync($task, $run);

            if (TaskBackendCapabilities::recordClaimFailureIfUnsupported($task) !== null) {
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return [null, null];
            }

            if (! TaskCompatibility::supported($task, $run)) {
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return [null, null];
            }

            $task->forceFill([
                'status' => TaskStatus::Leased,
                'leased_at' => now(),
                'lease_owner' => $this->taskId,
                'lease_expires_at' => ActivityLease::expiresAt(),
                'attempt_count' => $task->attempt_count + 1,
                'last_claim_failed_at' => null,
                'last_claim_error' => null,
            ])->save();

            $attemptId = (string) Str::ulid();
            $attemptCount = $task->attempt_count;

            $execution->forceFill([
                'status' => ActivityStatus::Running,
                'attempt_count' => $attemptCount,
                'current_attempt_id' => $attemptId,
                'started_at' => now(),
                'last_heartbeat_at' => null,
            ])->save();

            ActivityAttempt::query()->create([
                'id' => $attemptId,
                'workflow_run_id' => $run->id,
                'activity_execution_id' => $execution->id,
                'workflow_task_id' => $task->id,
                'attempt_number' => $attemptCount,
                'status' => ActivityAttemptStatus::Running->value,
                'lease_owner' => $task->lease_owner,
                'started_at' => $execution->started_at ?? now(),
                'lease_expires_at' => $task->lease_expires_at,
            ]);

            $parallelMetadataPath = \Workflow\V2\Support\ParallelChildGroup::metadataPathForSequence(
                $run,
                (int) $execution->sequence,
            );
            $parallelMetadata = \Workflow\V2\Support\ParallelChildGroup::payloadForPath($parallelMetadataPath);

            WorkflowHistoryEvent::record($run, HistoryEventType::ActivityStarted, array_merge([
                'activity_execution_id' => $execution->id,
                'activity_attempt_id' => $attemptId,
                'activity_class' => $execution->activity_class,
                'activity_type' => $execution->activity_type,
                'sequence' => $execution->sequence,
                'attempt_number' => $attemptCount,
                'activity' => ActivitySnapshot::fromExecution($execution),
            ], $parallelMetadata ?? []), $task);

            RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

            return [[
                'activity_execution_id' => $activityExecutionId,
                'attempt_id' => $attemptId,
                'attempt_count' => $attemptCount,
            ], null];
        });
    }
}
