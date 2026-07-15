<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PDOException;
use Tests\Fixtures\V2\TestFailingWorkflow;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Events\StateChanged;
use Workflow\Events\WorkflowStarted as LegacyWorkflowStarted;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Events\ActivityCompleted;
use Workflow\V2\Events\ActivityFailed;
use Workflow\V2\Events\ActivityStarted;
use Workflow\V2\Events\FailureRecorded;
use Workflow\V2\Events\WorkflowCompleted;
use Workflow\V2\Events\WorkflowFailed;
use Workflow\V2\Events\WorkflowStarted;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultHistoryProjectionRole;
use Workflow\V2\WorkflowStub;

final class V2LifecycleEventTest extends TestCase
{
    public function testWorkflowStartedEventIsDispatched(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        Event::fake([WorkflowStarted::class]);

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'lifecycle-start');
        $workflow->start('Taylor');

        Event::assertDispatched(WorkflowStarted::class, static function (WorkflowStarted $event) use ($workflow): bool {
            return $event->instanceId === $workflow->id()
                && $event->runId === $workflow->runId()
                && $event->workflowType === 'test-greeting-workflow'
                && $event->workflowClass !== ''
                && $event->committedAt !== '';
        });
    }

    public function testWorkflowStartedLifecycleEventsWaitForRetriedStartTransactionCommit(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('workflows.storage.transaction_attempts', 2);
        Queue::fake();

        $customRole = new class(new DefaultHistoryProjectionRole()) implements HistoryProjectionRole {
            public int $projectRunCalls = 0;

            public function __construct(
                private readonly DefaultHistoryProjectionRole $delegate,
            ) {
            }

            public function projectRun(WorkflowRun $run): WorkflowRunSummary
            {
                $this->projectRunCalls++;

                if ($this->projectRunCalls === 1) {
                    throw new QueryException(
                        'sqlite',
                        'insert into workflow_run_summaries',
                        [],
                        new class('SQLSTATE[HY000]: General error: 5 database is locked') extends PDOException {
                            public function __construct(string $message)
                            {
                                parent::__construct($message, 5);
                                $this->errorInfo = ['HY000', 5, 'database is locked'];
                            }
                        },
                    );
                }

                return $this->delegate->projectRun($run);
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                ActivityExecution $execution,
                ActivityAttempt $attempt,
                WorkflowTask $task,
            ): WorkflowRunSummary {
                return $this->delegate->recordActivityStarted($run, $execution, $attempt, $task);
            }
        };

        $this->app->instance(HistoryProjectionRole::class, $customRole);

        Event::fake([WorkflowStarted::class, LegacyWorkflowStarted::class, StateChanged::class]);

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'lifecycle-start-retry');
        $workflow->start('Taylor');

        // The failed transaction, committed transaction, and post-dispatch refresh
        // each project once; lifecycle events still publish only after commit.
        $this->assertSame(3, $customRole->projectRunCalls);
        Event::assertDispatched(WorkflowStarted::class, 1);
        Event::assertDispatched(LegacyWorkflowStarted::class, 1);
        Event::assertDispatched(StateChanged::class, 1);
    }

    public function testSuccessfulWorkflowDispatchesFullLifecycle(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        Event::fake([
            WorkflowStarted::class,
            WorkflowCompleted::class,
            ActivityStarted::class,
            ActivityCompleted::class,
        ]);

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'lifecycle-full');
        $workflow->start('Taylor');

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());

        Event::assertDispatched(WorkflowStarted::class, 1);
        Event::assertDispatched(ActivityStarted::class, 1);
        Event::assertDispatched(ActivityCompleted::class, 1);
        Event::assertDispatched(WorkflowCompleted::class, 1);

        Event::assertDispatched(ActivityStarted::class, static function (ActivityStarted $event) use ($workflow): bool {
            return $event->instanceId === $workflow->id()
                && $event->runId === $workflow->runId()
                && $event->activityClass !== ''
                && $event->sequence >= 1
                && $event->attemptNumber >= 1;
        });

        Event::assertDispatched(ActivityCompleted::class, static function (ActivityCompleted $event) use (
            $workflow
        ): bool {
            return $event->instanceId === $workflow->id()
                && $event->runId === $workflow->runId()
                && $event->activityExecutionId !== '';
        });

        Event::assertDispatched(WorkflowCompleted::class, static function (WorkflowCompleted $event) use (
            $workflow
        ): bool {
            return $event->instanceId === $workflow->id()
                && $event->runId === $workflow->runId()
                && $event->workflowType === 'test-greeting-workflow';
        });
    }

    public function testFailedWorkflowDispatchesFailureEvents(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        Event::fake([
            WorkflowStarted::class,
            WorkflowFailed::class,
            ActivityStarted::class,
            ActivityFailed::class,
            FailureRecorded::class,
        ]);

        $workflow = WorkflowStub::make(TestFailingWorkflow::class, 'lifecycle-fail');
        $workflow->start();

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->failed());

        Event::assertDispatched(WorkflowStarted::class, 1);
        Event::assertDispatched(ActivityStarted::class, 1);
        Event::assertDispatched(ActivityFailed::class, 1);
        Event::assertDispatched(WorkflowFailed::class, 1);

        Event::assertDispatched(ActivityFailed::class, static function (ActivityFailed $event) use ($workflow): bool {
            return $event->instanceId === $workflow->id()
                && $event->runId === $workflow->runId()
                && $event->exceptionClass === 'RuntimeException'
                && $event->message === 'boom';
        });

        Event::assertDispatched(WorkflowFailed::class, static function (WorkflowFailed $event) use ($workflow): bool {
            return $event->instanceId === $workflow->id()
                && $event->runId === $workflow->runId();
        });

        // FailureRecorded should fire for both the activity failure and the workflow failure.
        Event::assertDispatched(FailureRecorded::class, static function (FailureRecorded $event): bool {
            return $event->sourceKind === 'activity_execution'
                && $event->failureId !== ''
                && $event->exceptionClass === 'RuntimeException'
                && $event->message === 'boom';
        });

        Event::assertDispatched(FailureRecorded::class, static function (FailureRecorded $event): bool {
            return $event->sourceKind === 'workflow_run'
                && $event->failureId !== '';
        });
    }

    public function testNoEventsDispatchedDuringReplay(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'lifecycle-no-replay');
        $workflow->start('Taylor');

        // Run workflow task (schedules activity) — no event fake yet.
        $this->runNextReadyTask();

        // Run the activity task — still no event fake.
        $this->runNextReadyTask();

        // Now fake events and run the final workflow task (replay + completion).
        Event::fake([WorkflowStarted::class, ActivityStarted::class]);

        $this->drainReadyTasks();
        $this->assertTrue($workflow->refresh()->completed());

        // WorkflowStarted and ActivityStarted should NOT fire during replay —
        // they are only dispatched from the original start/claim commit points.
        Event::assertNotDispatched(WorkflowStarted::class);
        Event::assertNotDispatched(ActivityStarted::class);
    }

    private function drainReadyTasks(): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->where('status', TaskStatus::Ready->value)
                ->orderBy('created_at')
                ->first();

            if ($task === null) {
                return;
            }

            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new \Workflow\V2\Jobs\RunTimerTask($task->id),
            };

            $this->app->call([$job, 'handle']);
        }

        $this->fail('Timed out draining ready workflow tasks.');
    }

    private function runNextReadyTask(): void
    {
        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->first();

        if ($task === null) {
            $this->fail('Expected a ready workflow task.');
        }

        $job = match ($task->task_type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            TaskType::Timer => new \Workflow\V2\Jobs\RunTimerTask($task->id),
        };

        $this->app->call([$job, 'handle']);
    }
}
