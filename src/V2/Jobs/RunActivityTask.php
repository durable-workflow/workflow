<?php

declare(strict_types=1);

namespace Workflow\V2\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\FailureFactory;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\Support\TypeRegistry;

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
        $activityExecutionId = $this->claimTask();

        if ($activityExecutionId === null) {
            return;
        }

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->with('run')
            ->findOrFail($activityExecutionId);

        $activityClass = TypeRegistry::resolveActivityClass($execution->activity_class, $execution->activity_type);
        $activity = new $activityClass($execution, $execution->run);
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

        $resumeTask = DB::transaction(function () use ($execution, $result, $throwable): ?WorkflowTask {
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

            if (in_array($run->status, [RunStatus::Cancelled, RunStatus::Terminated], true)) {
                $lockedExecution->forceFill([
                    'status' => ActivityStatus::Cancelled,
                    'closed_at' => $lockedExecution->closed_at ?? now(),
                ])->save();

                $task->forceFill([
                    'status' => $task->status === TaskStatus::Cancelled ? TaskStatus::Cancelled : TaskStatus::Completed,
                    'lease_expires_at' => null,
                ])->save();

                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return null;
            }

            if ($throwable === null) {
                $lockedExecution->forceFill([
                    'status' => ActivityStatus::Completed,
                    'result' => Serializer::serialize($result),
                    'closed_at' => now(),
                ])->save();

                WorkflowHistoryEvent::record($run, HistoryEventType::ActivityCompleted, [
                    'activity_execution_id' => $lockedExecution->id,
                    'activity_class' => $lockedExecution->activity_class,
                    'activity_type' => $lockedExecution->activity_type,
                    'result' => $lockedExecution->result,
                ], $task->id);
            } else {
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
                    'exception' => Serializer::serialize([
                        'class' => $throwable::class,
                        'message' => $throwable->getMessage(),
                        'code' => $throwable->getCode(),
                    ]),
                    'closed_at' => now(),
                ])->save();

                WorkflowHistoryEvent::record($run, HistoryEventType::ActivityFailed, [
                    'activity_execution_id' => $lockedExecution->id,
                    'activity_class' => $lockedExecution->activity_class,
                    'activity_type' => $lockedExecution->activity_type,
                    'failure_id' => $failure->id,
                    'exception_class' => $failure->exception_class,
                    'message' => $failure->message,
                ], $task->id);
            }

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
            ]);

            RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

            return $resumeTask;
        });

        if ($resumeTask instanceof WorkflowTask) {
            TaskDispatcher::dispatch($resumeTask);
        }
    }

    private function claimTask(): ?string
    {
        return DB::transaction(function (): ?string {
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

            $task->forceFill([
                'status' => TaskStatus::Leased,
                'leased_at' => now(),
                'lease_owner' => $this->taskId,
                'lease_expires_at' => now()
                    ->addMinutes(5),
                'attempt_count' => $task->attempt_count + 1,
            ])->save();

            $execution->forceFill([
                'status' => ActivityStatus::Running,
                'started_at' => $execution->started_at ?? now(),
            ])->save();

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()->findOrFail($execution->workflow_run_id);
            RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

            return $activityExecutionId;
        });
    }
}
