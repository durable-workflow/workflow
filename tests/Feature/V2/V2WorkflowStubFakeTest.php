<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use RuntimeException;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestParentChildWorkflow;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\Fixtures\V2\TestTimerWorkflow;
use Tests\Fixtures\V2\TestUpdateWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Testing\ActivityFakeContext;
use Workflow\V2\WorkflowStub;

final class V2WorkflowStubFakeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');
    }

    public function testFakeModeRunsActivitiesInlineOnUnsupportedSyncBackend(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        WorkflowStub::assertNothingDispatched();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'fake-inline-sync');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'greeting' => 'Hello, Taylor!',
            'workflow_id' => 'fake-inline-sync',
            'run_id' => $workflow->runId(),
        ], $workflow->output());

        WorkflowStub::assertDispatched(
            TestGreetingActivity::class,
            static fn (string $name): bool => $name === 'Taylor'
        );
        WorkflowStub::assertDispatchedTimes(TestGreetingActivity::class, 1);
        WorkflowStub::assertNotDispatched('Tests\\Fixtures\\V2\\MissingActivity');

        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $workflow->runId())
            ->firstOrFail();

        $this->assertSame(ActivityStatus::Completed, $execution->status);

        $tasks = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('created_at')
            ->get();

        $this->assertCount(3, $tasks);
        $this->assertSame([TaskType::Workflow, TaskType::Activity, TaskType::Workflow], $tasks->pluck('task_type')
            ->all());
        $this->assertTrue($tasks->every(static fn (WorkflowTask $task): bool => $task->last_dispatched_at !== null));
        $this->assertTrue($tasks->every(static fn (WorkflowTask $task): bool => $task->last_dispatch_error === null));

        $this->assertSame([
            HistoryEventType::StartAccepted->value,
            HistoryEventType::WorkflowStarted->value,
            HistoryEventType::ActivityScheduled->value,
            HistoryEventType::ActivityStarted->value,
            HistoryEventType::ActivityCompleted->value,
            HistoryEventType::WorkflowCompleted->value,
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn (HistoryEventType $eventType): string => $eventType->value)
            ->all());
    }

    public function testFakeActivityMockCallbackReceivesDurableContext(): void
    {
        WorkflowStub::fake();

        $capturedContext = null;

        WorkflowStub::mock(TestGreetingActivity::class, static function (ActivityFakeContext $context, string $name) use (
            &$capturedContext
        ): string {
            $capturedContext = $context;

            return "Hello, {$name}!";
        });

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'fake-context');
        $workflow->start('Jordan');

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertInstanceOf(ActivityFakeContext::class, $capturedContext);
        $this->assertSame('fake-context', $capturedContext->workflowId());
        $this->assertSame($workflow->runId(), $capturedContext->runId());
        $this->assertSame(TestGreetingActivity::class, $capturedContext->activity);
        $this->assertSame(1, $capturedContext->sequence);
        $this->assertSame($capturedContext->activityId(), $capturedContext->execution->id);
        $this->assertNotNull($capturedContext->taskId);
    }

    public function testFakeActivityMockFailuresStillProduceDurableFailureHistory(): void
    {
        WorkflowStub::fake();

        WorkflowStub::mock(TestGreetingActivity::class, static function (): never {
            throw new RuntimeException('mocked activity boom');
        });

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'fake-failure');
        $workflow->start('Jordan');

        $this->assertTrue($workflow->refresh()->failed());
        WorkflowStub::assertDispatchedTimes(TestGreetingActivity::class, 1);

        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow->runId())
            ->latest('created_at')
            ->firstOrFail();

        $this->assertSame('mocked activity boom', $failure->message);
        $this->assertSame([
            HistoryEventType::StartAccepted->value,
            HistoryEventType::WorkflowStarted->value,
            HistoryEventType::ActivityScheduled->value,
            HistoryEventType::ActivityStarted->value,
            HistoryEventType::ActivityFailed->value,
            HistoryEventType::WorkflowFailed->value,
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn (HistoryEventType $eventType): string => $eventType->value)
            ->all());
    }

    public function testFakeModeExecutesNestedChildWorkflowsInline(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestParentChildWorkflow::class, 'fake-inline-parent');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());
        WorkflowStub::assertDispatchedTimes(TestGreetingActivity::class, 1);

        $output = $workflow->output();

        $this->assertSame('fake-inline-parent', $output['parent_workflow_id'] ?? null);
        $this->assertSame($workflow->runId(), $output['parent_run_id'] ?? null);
        $this->assertSame('Hello, Taylor!', $output['child']['greeting'] ?? null);
        $this->assertIsString($output['child']['workflow_id'] ?? null);
        $this->assertIsString($output['child']['run_id'] ?? null);

        $runs = WorkflowRun::query()
            ->whereIn('workflow_instance_id', ['fake-inline-parent', $output['child']['workflow_id']])
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $runs);
    }

    public function testRunReadyTasksRequiresFakeMode(): void
    {
        $this->expectExceptionMessage('WorkflowStub::runReadyTasks() requires WorkflowStub::fake().');

        WorkflowStub::runReadyTasks();
    }

    public function testFakeModeCanDrainDueTimerTasksAfterTimeTravel(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'fake-inline-timer');
        $workflow->start(60);

        $this->assertSame('waiting', $workflow->refresh()->status());

        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $workflow->runId())
            ->firstOrFail();

        $this->assertSame(TimerStatus::Pending, $timer->status);

        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Timer->value)
            ->firstOrFail();

        $this->assertSame(TaskStatus::Ready, $timerTask->status);
        $this->assertTrue($timerTask->available_at?->isFuture() ?? false);
        $this->assertSame(0, WorkflowStub::runReadyTasks());
        $this->assertSame('waiting', $workflow->refresh()->status());

        $this->travel(61)
            ->seconds();

        $this->assertSame(1, WorkflowStub::runReadyTasks());

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'waited' => true,
            'workflow_id' => 'fake-inline-timer',
            'run_id' => $workflow->runId(),
        ], $workflow->output());

        $timer->refresh();
        $this->assertSame(TimerStatus::Fired, $timer->status);

        $tasks = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('created_at')
            ->get();

        $this->assertCount(3, $tasks);
        $this->assertSame([TaskType::Workflow, TaskType::Timer, TaskType::Workflow], $tasks->pluck('task_type')
            ->all());
        $this->assertTrue($tasks->every(static fn (WorkflowTask $task): bool => $task->last_dispatch_error === null));

        $this->assertSame([
            HistoryEventType::StartAccepted->value,
            HistoryEventType::WorkflowStarted->value,
            HistoryEventType::TimerScheduled->value,
            HistoryEventType::TimerFired->value,
            HistoryEventType::WorkflowCompleted->value,
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn (HistoryEventType $eventType): string => $eventType->value)
            ->all());
    }

    public function testFakeModeSignalResumesWaitingWorkflowInline(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'fake-signal');
        $workflow->start();

        $this->assertSame('waiting', $workflow->refresh()->status());

        $signal = WorkflowSignal::query()
            ->where('workflow_instance_id', 'fake-signal')
            ->first();

        $this->assertNull($signal);

        $result = $workflow->signal('name-provided', 'Taylor');

        $this->assertTrue($result->accepted());
        $this->assertTrue($workflow->refresh()->completed());

        $this->assertSame([
            'name' => 'Taylor',
            'greeting' => 'Hello, Taylor!',
            'workflow_id' => 'fake-signal',
            'run_id' => $workflow->runId(),
        ], $workflow->output());

        $signal = WorkflowSignal::query()
            ->where('workflow_instance_id', 'fake-signal')
            ->firstOrFail();

        $this->assertSame('name-provided', $signal->signal_name);

        WorkflowStub::assertSignalSent('name-provided');
        WorkflowStub::assertSignalSentTimes('name-provided', 1);
        WorkflowStub::assertSignalNotSent('other-signal');
        WorkflowStub::assertDispatched(TestGreetingActivity::class);

        $this->assertSame([
            HistoryEventType::StartAccepted->value,
            HistoryEventType::WorkflowStarted->value,
            HistoryEventType::SignalWaitOpened->value,
            HistoryEventType::SignalReceived->value,
            HistoryEventType::MessageCursorAdvanced->value,
            HistoryEventType::SignalApplied->value,
            HistoryEventType::ActivityScheduled->value,
            HistoryEventType::ActivityStarted->value,
            HistoryEventType::ActivityCompleted->value,
            HistoryEventType::WorkflowCompleted->value,
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn (HistoryEventType $eventType): string => $eventType->value)
            ->all());
    }

    public function testFakeModeSignalAssertionCallbackReceivesInstanceIdAndArguments(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'fake-signal-assert');
        $workflow->start();
        $workflow->signal('name-provided', 'Taylor');

        WorkflowStub::assertSignalSent(
            'name-provided',
            static fn (string $instanceId, string $name): bool => $instanceId === 'fake-signal-assert' && $name === 'Taylor'
        );
    }

    public function testFakeModeUpdateAppliesMutationInline(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'fake-update');
        $workflow->start();

        $this->assertSame('waiting', $workflow->refresh()->status());

        $updateResult = $workflow->attemptUpdate('approve', true, 'webhook');

        $this->assertTrue($updateResult->accepted());

        $update = WorkflowUpdate::query()
            ->where('workflow_instance_id', 'fake-update')
            ->firstOrFail();

        $this->assertSame('approve', $update->update_name);

        WorkflowStub::assertUpdateSent('approve');
        WorkflowStub::assertUpdateSentTimes('approve', 1);
        WorkflowStub::assertUpdateNotSent('explode');

        WorkflowStub::assertUpdateSent(
            'approve',
            static fn (string $instanceId, bool $approved, string $source): bool => $instanceId === 'fake-update'
                && $approved === true
                && $source === 'webhook'
        );

        $workflow->signal('name-provided', 'Taylor');

        $this->assertTrue($workflow->refresh()->completed());
        $output = $workflow->output();

        $this->assertTrue($output['approved']);
        $this->assertContains('approved:yes:webhook', $output['events']);
        $this->assertContains('signal:Taylor', $output['events']);
    }

    public function testFakeModeSignalAndUpdateAssertionsStartClean(): void
    {
        WorkflowStub::fake();

        WorkflowStub::assertSignalNotSent('name-provided');
        WorkflowStub::assertUpdateNotSent('approve');
    }

    public function testMockRejectsWorkflowSubclass(): void
    {
        WorkflowStub::fake();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not support mocking workflow classes');

        WorkflowStub::mock(TestGreetingWorkflow::class, [
            'greeting' => 'fake',
        ]);
    }

    public function testMockAcceptsActivityClass(): void
    {
        WorkflowStub::fake();

        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $this->assertTrue(WorkflowStub::hasMock(TestGreetingActivity::class));
    }

    public function testMockAcceptsUnknownStringAsActivityKey(): void
    {
        WorkflowStub::fake();

        WorkflowStub::mock('App\\Activities\\UnregisteredActivity', 'result');

        $this->assertTrue(WorkflowStub::hasMock('App\\Activities\\UnregisteredActivity'));
    }
}
