<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Fixtures\V2\TestCooperativeActivityCleanupWorkflow;
use Tests\Fixtures\V2\TestFinallyCleanupWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

final class V2FinallyCleanupTest extends TestCase
{
    public function testQueuedFinallyCleanupSurvivesTaskDisposalAndColdReplay(): void
    {
        foreach ([false, true] as $fail) {
            $workflow = WorkflowStub::make(TestFinallyCleanupWorkflow::class);
            $workflow->start($fail);
            $this->waitForWorkflow(
                $workflow,
                static fn (WorkflowStub $workflow): bool => $workflow->refresh()
                    ->completed(),
                'completed'
            );

            $this->assertSame($fail ? 'handled' : 'success', $workflow->output());
            $this->assertSame([
                'cleanup' => 'Hello, cleanup!',
            ], $workflow->memo());
            $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $workflow->runId())
                ->orderBy('sequence')
                ->get();
            $types = $events->map(static fn (WorkflowHistoryEvent $event): string => $event->event_type->value)
                ->all();
            $this->assertSame([
                'StartAccepted',
                'WorkflowStarted',
                'ActivityScheduled',
                'ActivityStarted',
                $fail ? 'ActivityFailed' : 'ActivityCompleted',
                ...($fail ? ['FailureHandled'] : []),
                'ActivityScheduled',
                'ActivityStarted',
                'ActivityCompleted',
                'TimerScheduled',
                'TimerFired',
                'MemoUpserted',
                'WorkflowCompleted',
            ], $types);

            $database = config('database.connections.' . config('database.default'));
            $this->assertIsArray($database);
            $replay = new Process([PHP_BINARY, __DIR__ . '/../../Fixtures/V2/finally_cold_replay.php'], env: [
                'FINALLY_DB_CONFIG' => json_encode($database, JSON_THROW_ON_ERROR),
                'FINALLY_RUN_ID' => $workflow->runId(),
                'FINALLY_FAIL' => $fail ? '1' : '0',
            ]);
            $replay->mustRun();

            $this->assertSame([
                'completed' => true,
                'result' => $workflow->output(),
                'history_events' => count($types),
            ], json_decode($replay->getOutput(), true, flags: JSON_THROW_ON_ERROR));
            $this->assertSame(count($types), WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $workflow->runId())
                ->count());
        }
    }

    public function testRunCancellationAndTerminationDoNotExecuteFinallyCleanup(): void
    {
        foreach (['cancel', 'terminate'] as $command) {
            $workflow = WorkflowStub::make(TestFinallyCleanupWorkflow::class);
            $workflow->start(false, 3600);
            $this->waitForWorkflow(
                $workflow,
                static fn (WorkflowStub $workflow): bool => $workflow->refresh()
                    ->summary()?->wait_kind === 'timer',
                'waiting on timer'
            );

            $this->assertTrue($workflow->{$command}()->accepted());
            $this->assertSame($command === 'cancel' ? 'cancelled' : 'terminated', $workflow->refresh()->status());
            $this->assertSame([], $workflow->memo());
            $this->assertDatabaseMissing('workflow_history_events', [
                'workflow_run_id' => $workflow->runId(),
                'event_type' => 'ActivityScheduled',
            ]);
            $this->assertDatabaseMissing('workflow_history_events', [
                'workflow_run_id' => $workflow->runId(),
                'event_type' => 'MemoUpserted',
            ]);
        }
    }

    public function testCooperativeCancellationRequestIsDurableAndIdempotent(): void
    {
        $workflow = WorkflowStub::make(TestFinallyCleanupWorkflow::class);
        $workflow->start(false, 3600);
        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->refresh()
                ->summary()?->wait_kind === 'timer',
            'waiting on timer'
        );

        $first = $workflow->requestCancellation('maintenance', 60);
        $second = $workflow->requestCancellation('ignored duplicate', 120);

        $this->assertTrue($first->accepted());
        $this->assertSame($first->commandId(), $second->commandId());
        $this->assertSame(CommandType::RequestCancellation->value, $first->type());
        $this->assertSame(CommandOutcome::CancellationRequested->value, $first->outcome());
        $this->assertNotSame('cancelled', $workflow->refresh()->status());
        $this->assertSame(1, WorkflowCommand::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('command_type', CommandType::RequestCancellation->value)
            ->count());
        $this->assertSame(1, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::CooperativeCancellationRequested->value)
            ->count());

        $command = WorkflowCommand::query()->findOrFail($first->commandId());
        $this->assertSame($command->id, $workflow->run()?->cancellation_request_command_id);
        $this->assertEquals(60, $workflow->run()?->cancellation_requested_at
            ?->diffInSeconds($workflow->run()?->cancellation_deadline_at));
    }

    public function testCooperativeCancellationRunsDurableFinallyCleanupAfterTimerWait(): void
    {
        $workflow = WorkflowStub::make(TestFinallyCleanupWorkflow::class);
        $workflow->start(false, 3600);
        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->refresh()
                ->summary()?->wait_kind === 'timer',
            'waiting on timer'
        );

        $this->assertTrue($workflow->requestCancellation('stop', 60)->accepted());
        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->refresh()
                ->cancelled(),
            'cancelled after cleanup'
        );

        $this->assertSame([
            'cleanup' => 'Hello, cleanup!',
        ], $workflow->memo());
        $types = WorkflowHistoryEvent::query()->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): string => $event->event_type->value)
            ->all();
        $this->assertContains(HistoryEventType::CooperativeCancellationRequested->value, $types);
        $this->assertContains(HistoryEventType::CooperativeCancellationDelivered->value, $types);
        $this->assertContains(HistoryEventType::TimerCancelled->value, $types);
        $this->assertContains(HistoryEventType::MemoUpserted->value, $types);
        $this->assertContains(HistoryEventType::WorkflowCancelled->value, $types);
    }

    public function testCooperativeCancellationInterruptsActivityWaitAndCompletesCleanup(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestCooperativeActivityCleanupWorkflow::class);
        $workflow->start();
        $runId = $workflow->runId();
        $this->assertIsString($runId);
        $this->runReadyTask($runId, TaskType::Workflow);

        $this->assertTrue($workflow->requestCancellation('stop', 60)->accepted());
        $this->runReadyTask($runId, TaskType::Workflow);

        $this->assertSame(1, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ActivityCancelled->value)
            ->count());
        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Activity->value)
            ->where('status', TaskStatus::Cancelled->value)
            ->count());

        $this->runReadyTask($runId, TaskType::Activity);
        $this->runReadyTask($runId, TaskType::Workflow);

        $this->assertTrue($workflow->refresh()->cancelled());
        $this->assertSame([
            'cleanup' => 'Hello, cleanup!',
        ], $workflow->memo());
    }

    public function testExpiredCleanupDeadlineFallsBackToTerminalCancellation(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestFinallyCleanupWorkflow::class);
        $workflow->start(false, 3600);
        $runId = $workflow->runId();
        $this->assertIsString($runId);
        $this->runReadyTask($runId, TaskType::Workflow);
        $workflow->requestCancellation('stop', 1);

        try {
            Carbon::setTestNow($workflow->run()?->cancellation_deadline_at?->copy()->addSecond());
            $this->runReadyTask($runId, TaskType::Workflow);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertTrue($workflow->refresh()->cancelled());
        $this->assertSame([], $workflow->memo());
        $this->assertDatabaseMissing('workflow_history_events', [
            'workflow_run_id' => $runId,
            'event_type' => HistoryEventType::CooperativeCancellationDelivered->value,
        ]);
    }

    public function testWatchdogEnforcesDeadlineWhileCleanupTimerIsPending(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestFinallyCleanupWorkflow::class);
        $workflow->start(false, 3600);
        $runId = $workflow->runId();
        $this->assertIsString($runId);
        $this->runReadyTask($runId, TaskType::Workflow);
        $workflow->requestCancellation('stop', 60);
        $this->runReadyTask($runId, TaskType::Workflow);
        $this->runReadyTask($runId, TaskType::Activity);
        $this->runReadyTask($runId, TaskType::Workflow);

        try {
            Carbon::setTestNow($workflow->run()?->cancellation_deadline_at?->copy()->addSecond());
            $report = TaskWatchdog::runPass(respectThrottle: false, runIds: [$runId]);
            $this->assertSame(1, $report['deadline_expired_candidates']);
            $this->assertSame(1, $report['deadline_expired_tasks_created']);
            $this->runReadyTask($runId, TaskType::Workflow);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertTrue($workflow->refresh()->cancelled());
        $this->assertSame([], $workflow->memo());
    }

    private function runReadyTask(string $runId, TaskType $type): void
    {
        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', $type->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->first();

        if (! $task instanceof WorkflowTask) {
            $this->fail(sprintf('Expected ready %s task for run %s.', $type->value, $runId));
        }

        $job = match ($type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            default => throw new \LogicException('Unexpected test task type.'),
        };
        $this->app->call([$job, 'handle']);
    }
}
