<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestFailingWorkflow;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Events\ActivityCompleted as LegacyActivityCompleted;
use Workflow\Events\ActivityFailed as LegacyActivityFailed;
use Workflow\Events\ActivityStarted as LegacyActivityStarted;
use Workflow\Events\StateChanged;
use Workflow\Events\WorkflowCompleted as LegacyWorkflowCompleted;
use Workflow\Events\WorkflowFailed as LegacyWorkflowFailed;
use Workflow\Events\WorkflowStarted as LegacyWorkflowStarted;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowFailedStatus;
use Workflow\States\WorkflowRunningStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Events\WorkflowCompleted;
use Workflow\V2\Events\WorkflowStarted;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

/**
 * Verifies that V2 lifecycle events also dispatch legacy V1 event classes
 * alongside V2 events, so apps migrating from V1 to V2 continue to receive
 * the events they listen for.
 */
final class V2LegacyEventCompatibilityTest extends TestCase
{
    public function testWorkflowStartedDispatchesLegacyEvent(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        Event::fake([
            WorkflowStarted::class,
            LegacyWorkflowStarted::class,
            StateChanged::class,
        ]);

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'legacy-compat-start');
        $workflow->start('Taylor');

        Event::assertDispatched(WorkflowStarted::class, 1);
        Event::assertDispatched(LegacyWorkflowStarted::class, 1);

        Event::assertDispatched(LegacyWorkflowStarted::class, function (LegacyWorkflowStarted $event) use ($workflow): bool {
            return $event->workflowId === $workflow->id()
                && str_contains($event->class, 'TestGreetingWorkflow')
                && $event->arguments === '[]'
                && $event->timestamp !== '';
        });

        Event::assertDispatched(StateChanged::class, function (StateChanged $event): bool {
            return $event->initialState === null
                && $event->finalState instanceof WorkflowRunningStatus
                && $event->field === 'status';
        });
    }

    public function testSuccessfulWorkflowDispatchesFullLegacyLifecycle(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        Event::fake([
            WorkflowStarted::class,
            WorkflowCompleted::class,
            LegacyWorkflowStarted::class,
            LegacyWorkflowCompleted::class,
            LegacyActivityStarted::class,
            LegacyActivityCompleted::class,
            StateChanged::class,
        ]);

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'legacy-compat-full');
        $workflow->start('Taylor');

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());

        // V1 legacy events dispatched alongside V2 events.
        Event::assertDispatched(LegacyWorkflowStarted::class, 1);
        Event::assertDispatched(LegacyActivityStarted::class, 1);
        Event::assertDispatched(LegacyActivityCompleted::class, 1);
        Event::assertDispatched(LegacyWorkflowCompleted::class, 1);

        Event::assertDispatched(LegacyActivityStarted::class, function (LegacyActivityStarted $event) use ($workflow): bool {
            return $event->workflowId === $workflow->id()
                && $event->activityId !== ''
                && $event->index >= 1;
        });

        Event::assertDispatched(LegacyActivityCompleted::class, function (LegacyActivityCompleted $event) use ($workflow): bool {
            return $event->workflowId === $workflow->id()
                && $event->activityId !== '';
        });

        Event::assertDispatched(LegacyWorkflowCompleted::class, function (LegacyWorkflowCompleted $event) use ($workflow): bool {
            return $event->workflowId === $workflow->id()
                && $event->timestamp !== '';
        });

        // StateChanged fires for start (null→running) and complete (running→completed).
        Event::assertDispatched(StateChanged::class, function (StateChanged $event): bool {
            return $event->initialState === null
                && $event->finalState instanceof WorkflowRunningStatus;
        });
        Event::assertDispatched(StateChanged::class, function (StateChanged $event): bool {
            return $event->initialState instanceof WorkflowRunningStatus
                && $event->finalState instanceof WorkflowCompletedStatus;
        });
    }

    public function testFailedWorkflowDispatchesLegacyFailureEvents(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        Event::fake([
            LegacyWorkflowStarted::class,
            LegacyWorkflowFailed::class,
            LegacyActivityStarted::class,
            LegacyActivityFailed::class,
            StateChanged::class,
        ]);

        $workflow = WorkflowStub::make(TestFailingWorkflow::class, 'legacy-compat-fail');
        $workflow->start();

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->failed());

        Event::assertDispatched(LegacyWorkflowStarted::class, 1);
        Event::assertDispatched(LegacyActivityStarted::class, 1);
        Event::assertDispatched(LegacyActivityFailed::class, 1);
        Event::assertDispatched(LegacyWorkflowFailed::class, 1);

        Event::assertDispatched(LegacyActivityFailed::class, function (LegacyActivityFailed $event) use ($workflow): bool {
            return $event->workflowId === $workflow->id()
                && str_contains($event->output, 'RuntimeException')
                && str_contains($event->output, 'boom');
        });

        Event::assertDispatched(LegacyWorkflowFailed::class, function (LegacyWorkflowFailed $event) use ($workflow): bool {
            return $event->workflowId === $workflow->id();
        });

        // StateChanged fires for start (null→running) and fail (running→failed).
        Event::assertDispatched(StateChanged::class, function (StateChanged $event): bool {
            return $event->finalState instanceof WorkflowRunningStatus;
        });
        Event::assertDispatched(StateChanged::class, function (StateChanged $event): bool {
            return $event->initialState instanceof WorkflowRunningStatus
                && $event->finalState instanceof WorkflowFailedStatus;
        });
    }

    public function testNoLegacyEventsDispatchedDuringReplay(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'legacy-compat-no-replay');
        $workflow->start('Taylor');

        // Run workflow task (schedules activity) — no event fake yet.
        $this->runNextReadyTask();

        // Run the activity task — still no event fake.
        $this->runNextReadyTask();

        // Now fake events and run the final workflow task (replay + completion).
        Event::fake([
            LegacyWorkflowStarted::class,
            LegacyActivityStarted::class,
            StateChanged::class,
        ]);

        $this->drainReadyTasks();
        $this->assertTrue($workflow->refresh()->completed());

        // Legacy events for start and activity-start should NOT fire during replay.
        Event::assertNotDispatched(LegacyWorkflowStarted::class);
        Event::assertNotDispatched(LegacyActivityStarted::class);
    }

    public function testStateChangedCarriesRunModelReference(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        Event::fake([StateChanged::class]);

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'legacy-compat-model-ref');
        $workflow->start('Taylor');

        Event::assertDispatched(StateChanged::class, function (StateChanged $event) use ($workflow): bool {
            return $event->model !== null
                && $event->model->id === $workflow->runId()
                && $event->field === 'status';
        });
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
                TaskType::Timer => new RunTimerTask($task->id),
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
            TaskType::Timer => new RunTimerTask($task->id),
        };

        $this->app->call([$job, 'handle']);
    }
}
