<?php

declare(strict_types=1);

namespace Workflow\V2\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityOutcomeRecorder;
use Workflow\V2\Support\ActivityRetryPolicy;
use Workflow\V2\Support\ActivityTaskClaim;
use Workflow\V2\Support\ActivityTaskClaimer;
use Workflow\V2\Support\EntryMethod;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\Testing\ActivityFakeContext;
use Workflow\V2\WorkflowStub;

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

        $attemptId = $claim->attemptId();
        $attemptCount = $claim->attemptNumber();

        /** @var ActivityExecution $execution */
        $execution = $claim->execution;
        $execution->setRelation('run', $claim->run);

        $activityClass = TypeRegistry::resolveActivityClass($execution->activity_class, $execution->activity_type);
        $activity = new $activityClass($execution, $execution->run, $this->taskId);
        $entryMethod = EntryMethod::forActivity($activity);
        $arguments = $activity->resolveMethodDependencies($execution->activityArguments(), $entryMethod);
        $activityArguments = $execution->activityArguments();

        $result = null;
        $throwable = null;

        if (WorkflowStub::faked()) {
            WorkflowStub::recordDispatched($execution->activity_class, $activityArguments);
        }

        if (WorkflowStub::hasMock($execution->activity_class)) {
            $mocked = WorkflowStub::mockedResult(
                $execution->activity_class,
                new ActivityFakeContext(
                    run: $execution->run,
                    execution: $execution,
                    taskId: $this->taskId,
                    sequence: (int) $execution->sequence,
                    activity: $execution->activity_class,
                ),
                $activityArguments,
            );

            $result = $mocked['result'];
            $throwable = $mocked['throwable'];
        } else {
            try {
                $result = $activity->{$entryMethod->getName()}(...$arguments);
            } catch (Throwable $error) {
                $throwable = $error;
            }
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
     * @return array{0: ActivityTaskClaim|null, 1: int|null}
     */
    private function claimTask(): array
    {
        return ActivityTaskClaimer::claim($this->taskId, $this->taskId, releaseFutureTasks: true);
    }
}
