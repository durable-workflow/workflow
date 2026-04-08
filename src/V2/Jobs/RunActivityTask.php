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
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityLease;
use Workflow\V2\Support\ActivitySnapshot;
use Workflow\V2\Support\FailureFactory;
use Workflow\V2\Support\RunSummaryProjector;
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

        $claim = $this->claimTask();

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

        $resumeTask = DB::transaction(function () use (
            $execution,
            $result,
            $throwable,
            $attemptId,
            $attemptCount,
        ): ?WorkflowTask {
            /** @var WorkflowTask $task */
            $task = WorkflowTask::query()
                ->lockForUpdate()
                ->findOrFail($this->taskId);

            /** @var ActivityExecution $lockedExecution */
            $lockedExecution = ActivityExecution::query()
                ->lockForUpdate()
                ->findOrFail($execution->id);

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()
                ->lockForUpdate()
                ->findOrFail($lockedExecution->workflow_run_id);

            // Ignore late activity outcomes once the lease has been reclaimed or a newer
            // attempt has already been claimed for this execution.
            if (
                $task->status !== TaskStatus::Leased
                || $task->attempt_count !== $attemptCount
                || $lockedExecution->attempt_count !== $attemptCount
                || $lockedExecution->current_attempt_id !== $attemptId
            ) {
                self::closeAttemptIfStale($run, $attemptId);

                return null;
            }

            if (in_array($run->status, [RunStatus::Cancelled, RunStatus::Terminated], true)) {
                $lockedExecution->forceFill([
                    'status' => ActivityStatus::Cancelled,
                    'closed_at' => $lockedExecution->closed_at ?? now(),
                ])->save();

                self::closeAttempt($attemptId, ActivityAttemptStatus::Cancelled);

                $task->forceFill([
                    'status' => $task->status === TaskStatus::Cancelled ? TaskStatus::Cancelled : TaskStatus::Completed,
                    'lease_expires_at' => null,
                ])->save();

                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return null;
            }

            if (in_array($run->status, [RunStatus::Completed, RunStatus::Failed], true)) {
                $lockedExecution->forceFill([
                    'status' => $throwable === null ? ActivityStatus::Completed : ActivityStatus::Failed,
                    'result' => $throwable === null ? Serializer::serialize($result) : $lockedExecution->result,
                    'exception' => $throwable === null
                        ? $lockedExecution->exception
                        : Serializer::serialize(FailureFactory::payload($throwable)),
                    'closed_at' => $lockedExecution->closed_at ?? now(),
                ])->save();

                self::closeAttempt(
                    $attemptId,
                    $throwable === null ? ActivityAttemptStatus::Completed : ActivityAttemptStatus::Failed,
                );

                $task->forceFill([
                    'status' => TaskStatus::Completed,
                    'lease_expires_at' => null,
                ])->save();

                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return null;
            }

            $parallelMetadata = \Workflow\V2\Support\ParallelChildGroup::metadataForSequence(
                $run,
                (int) $lockedExecution->sequence,
            );

            if ($throwable === null) {
                $lockedExecution->forceFill([
                    'status' => ActivityStatus::Completed,
                    'result' => Serializer::serialize($result),
                    'closed_at' => now(),
                ])->save();

                WorkflowHistoryEvent::record($run, HistoryEventType::ActivityCompleted, array_merge([
                    'activity_execution_id' => $lockedExecution->id,
                    'activity_class' => $lockedExecution->activity_class,
                    'activity_type' => $lockedExecution->activity_type,
                    'sequence' => $lockedExecution->sequence,
                    'result' => $lockedExecution->result,
                    'activity' => ActivitySnapshot::fromExecution($lockedExecution),
                ], $parallelMetadata ?? []), $task);

                self::closeAttempt($attemptId, ActivityAttemptStatus::Completed);
            } else {
                $exceptionPayload = FailureFactory::payload($throwable);

                /** @var WorkflowFailure $failure */
                $failure = WorkflowFailure::query()->create(array_merge(
                    FailureFactory::make($throwable),
                    [
                        'workflow_run_id' => $run->id,
                        'source_kind' => 'activity_execution',
                        'source_id' => $lockedExecution->id,
                        'propagation_kind' => 'activity',
                        'handled' => false,
                    ],
                ));

                $lockedExecution->forceFill([
                    'status' => ActivityStatus::Failed,
                    'exception' => Serializer::serialize($exceptionPayload),
                    'closed_at' => now(),
                ])->save();

                WorkflowHistoryEvent::record($run, HistoryEventType::ActivityFailed, array_merge([
                    'activity_execution_id' => $lockedExecution->id,
                    'activity_class' => $lockedExecution->activity_class,
                    'activity_type' => $lockedExecution->activity_type,
                    'sequence' => $lockedExecution->sequence,
                    'failure_id' => $failure->id,
                    'exception_class' => $failure->exception_class,
                    'message' => $failure->message,
                    'code' => $throwable->getCode(),
                    'exception' => $exceptionPayload,
                    'activity' => ActivitySnapshot::fromExecution($lockedExecution),
                ], $parallelMetadata ?? []), $task);

                self::closeAttempt($attemptId, ActivityAttemptStatus::Failed);
            }

            $task->forceFill([
                'status' => TaskStatus::Completed,
                'lease_expires_at' => null,
            ])->save();

            $closedStatus = $throwable === null
                ? ActivityStatus::Completed
                : ActivityStatus::Failed;

            if (
                $parallelMetadata !== null
                && ! \Workflow\V2\Support\ParallelChildGroup::shouldWakeParentOnActivityClosure(
                    $run,
                    $parallelMetadata,
                    $closedStatus,
                )
            ) {
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return null;
            }

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

            RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

            return $resumeTask;
        });

        if ($resumeTask instanceof WorkflowTask) {
            TaskDispatcher::dispatch($resumeTask);
        }
    }

    /**
     * @return array{activity_execution_id: string, attempt_id: string, attempt_count: int}|null
     */
    private function claimTask(): ?array
    {
        return DB::transaction(function (): ?array {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->lockForUpdate()
                ->find($this->taskId);

            if ($task === null || $task->task_type !== TaskType::Activity || $task->status !== TaskStatus::Ready) {
                return null;
            }

            $activityExecutionId = $task->payload['activity_execution_id'] ?? null;

            if (! is_string($activityExecutionId)) {
                return null;
            }

            /** @var ActivityExecution $execution */
            $execution = ActivityExecution::query()
                ->lockForUpdate()
                ->findOrFail($activityExecutionId);

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()->findOrFail($execution->workflow_run_id);

            TaskCompatibility::sync($task, $run);

            if (! TaskCompatibility::supported($task, $run)) {
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return null;
            }

            $task->forceFill([
                'status' => TaskStatus::Leased,
                'leased_at' => now(),
                'lease_owner' => $this->taskId,
                'lease_expires_at' => ActivityLease::expiresAt(),
                'attempt_count' => $task->attempt_count + 1,
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

            $parallelMetadata = \Workflow\V2\Support\ParallelChildGroup::metadataForSequence(
                $run,
                (int) $execution->sequence,
            );

            WorkflowHistoryEvent::record($run, HistoryEventType::ActivityStarted, array_merge([
                'activity_execution_id' => $execution->id,
                'activity_class' => $execution->activity_class,
                'activity_type' => $execution->activity_type,
                'sequence' => $execution->sequence,
                'activity' => ActivitySnapshot::fromExecution($execution),
            ], $parallelMetadata ?? []), $task);

            RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

            return [
                'activity_execution_id' => $activityExecutionId,
                'attempt_id' => $attemptId,
                'attempt_count' => $attemptCount,
            ];
        });
    }

    private static function closeAttempt(string $attemptId, ActivityAttemptStatus $status): void
    {
        /** @var ActivityAttempt|null $attempt */
        $attempt = ActivityAttempt::query()
            ->lockForUpdate()
            ->find($attemptId);

        if (! $attempt instanceof ActivityAttempt || $attempt->status !== ActivityAttemptStatus::Running) {
            return;
        }

        $attempt->forceFill([
            'status' => $status,
            'lease_expires_at' => null,
            'closed_at' => $attempt->closed_at ?? now(),
        ])->save();
    }

    private static function closeAttemptIfStale(WorkflowRun $run, string $attemptId): void
    {
        $status = in_array($run->status, [RunStatus::Cancelled, RunStatus::Terminated], true)
            ? ActivityAttemptStatus::Cancelled
            : ActivityAttemptStatus::Expired;

        self::closeAttempt($attemptId, $status);
    }
}
