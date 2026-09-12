<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestDeterministicDeadlineWorkflow;
use Tests\Fixtures\V2\TestReplayDeterministicTimeWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

/**
 * Pins the Phase 3 exit criterion: replay never depends on ambient
 * Carbon::now(). The test runs a workflow to completion at one frozen
 * clock, then replays it under a wildly different ambient clock and
 * asserts that `Workflow::now()` (Workflow\V2\now()) reads the
 * deterministic event time the executor seeded — not the ambient
 * Carbon::setTestNow() value.
 */
final class V2DeterministicTimeReplayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('workflows.v2.task_dispatch_mode', 'poll');
        Queue::fake();
    }

    public function testReplayReadsSeededEventTimeAndIgnoresAmbientWallClock(): void
    {
        $startedAt = Carbon::parse('2026-02-01T12:00:00Z');
        Carbon::setTestNow($startedAt);

        $workflow = WorkflowStub::make(TestReplayDeterministicTimeWorkflow::class, 'replay-deterministic-time-1');
        $workflow->start('Taylor');

        $this->runReadyTaskOfType(TaskType::Workflow);

        $activityRecordedAt = $startedAt->copy()
            ->addMinutes(5);
        Carbon::setTestNow($activityRecordedAt);

        $this->runReadyTaskOfType(TaskType::Activity);
        $this->runReadyTaskOfType(TaskType::Workflow);

        Carbon::setTestNow(null);

        $this->assertTrue($workflow->refresh()->completed());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()
            ->findOrFail($workflow->runId());

        /** @var WorkflowHistoryEvent $activityCompleted */
        $activityCompleted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityCompleted->value)
            ->orderBy('sequence')
            ->firstOrFail();

        $expectedStartMs = $run->started_at?->getTimestampMs();
        $expectedAfterActivityMs = $activityCompleted->recorded_at?->getTimestampMs();

        $this->assertNotNull($expectedStartMs);
        $this->assertNotNull($expectedAfterActivityMs);

        $ambientFuture = Carbon::parse('2099-12-31T23:59:59Z');
        Carbon::setTestNow($ambientFuture);

        try {
            $state = (new WorkflowReplayer())->replay($run);
        } finally {
            Carbon::setTestNow(null);
        }

        $this->assertInstanceOf(TestReplayDeterministicTimeWorkflow::class, $state->workflow);

        $this->assertSame(
            $expectedStartMs,
            $state->workflow->observedStartMs,
            'Replay must read the run started_at from history when sampling Workflow::now() at handle entry, even when Carbon::setTestNow() is set far in the future.',
        );

        $this->assertSame(
            $expectedAfterActivityMs,
            $state->workflow->observedAfterActivityMs,
            'Replay must read the ActivityCompleted recorded_at from history when sampling Workflow::now() after the activity, even when Carbon::setTestNow() is set far in the future.',
        );

        $this->assertNotSame(
            $ambientFuture->getTimestampMs(),
            $state->workflow->observedStartMs,
            'Replay must not read ambient wall-clock time at handle entry.',
        );

        $this->assertNotSame(
            $ambientFuture->getTimestampMs(),
            $state->workflow->observedAfterActivityMs,
            'Replay must not read ambient wall-clock time after an activity completes.',
        );
    }

    public function testDerivedDeadlineWaitsForDurableTimeAcrossFreshDatabaseReplays(): void
    {
        $startedAt = Carbon::parse('2026-02-01T12:00:00Z');
        Carbon::setTestNow($startedAt);

        try {
            $workflow = WorkflowStub::make(TestDeterministicDeadlineWorkflow::class, 'deterministic-deadline');
            $workflow->start();
            $this->runReadyTaskOfType(TaskType::Workflow);

            $this->assertSame('waiting', $workflow->refresh()->status());
            $this->assertDatabaseMissing('workflow_history_events', [
                'workflow_run_id' => $workflow->runId(),
                'event_type' => HistoryEventType::WorkflowCompleted->value,
            ]);

            Carbon::setTestNow(Carbon::parse('2099-12-31T23:59:59Z'));
            $replay = (new WorkflowReplayer())->replay(WorkflowRun::query()->findOrFail($workflow->runId()));
            $this->assertInstanceOf(TestDeterministicDeadlineWorkflow::class, $replay->workflow);
            $this->assertSame($startedAt->getTimestampMs(), $replay->workflow->startedAtMs);
            $this->assertSame($startedAt->copy()->addHour()->getTimestampMs(), $replay->workflow->deadlineMs);
            $this->assertNull($replay->workflow->completedAtMs);
            unset($replay);

            Carbon::setTestNow($startedAt->copy()->addMinutes(30));
            $this->runReadyTaskOfType(TaskType::Timer);
            $this->runReadyTaskOfType(TaskType::Workflow);
            $this->assertSame('waiting', $workflow->refresh()->status());

            Carbon::setTestNow($startedAt->copy()->addHour());
            $this->runReadyTaskOfType(TaskType::Timer);
            $this->runReadyTaskOfType(TaskType::Workflow);
            $this->assertTrue($workflow->refresh()->completed());
            $this->assertSame([
                'started_at_ms' => $startedAt->getTimestampMs(),
                'deadline_ms' => $startedAt->copy()
                    ->addHour()
                    ->getTimestampMs(),
                'completed_at_ms' => $startedAt->copy()
                    ->addHour()
                    ->getTimestampMs(),
            ], $workflow->output());

            $this->assertSame(2, WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $workflow->runId())
                ->where('event_type', HistoryEventType::TimerFired->value)
                ->count());

            Carbon::setTestNow(Carbon::parse('2099-12-31T23:59:59Z'));
            $replay = (new WorkflowReplayer())->replay(WorkflowRun::query()->findOrFail($workflow->runId()));
            $this->assertInstanceOf(TestDeterministicDeadlineWorkflow::class, $replay->workflow);
            $this->assertSame($workflow->output()['completed_at_ms'], $replay->workflow->completedAtMs);
        } finally {
            Carbon::setTestNow(null);
        }
    }

    private function runReadyTaskOfType(TaskType $taskType): void
    {
        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->where('status', TaskStatus::Ready->value)
            ->where('task_type', $taskType->value)
            ->orderBy('created_at')
            ->first();

        if (! $task instanceof WorkflowTask) {
            $this->fail("Expected a ready task of type {$taskType->value}.");
        }

        $job = match ($task->task_type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            TaskType::Timer => new RunTimerTask($task->id),
            default => $this->fail("Unsupported task type {$task->task_type->value}."),
        };

        $this->app->call([$job, 'handle']);
    }
}
