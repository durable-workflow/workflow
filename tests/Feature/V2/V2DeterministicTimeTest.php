<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestDeterministicTimeWorkflow;
use Tests\Fixtures\V2\TestParallelDeterministicTimeWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class V2DeterministicTimeTest extends TestCase
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

    public function testNowReturnsWallClockOutsideWorkflowContext(): void
    {
        $before = now();
        $time = Workflow::now();
        $after = now();

        $this->assertGreaterThanOrEqual($before->getTimestampMs(), $time->getTimestampMs());
        $this->assertLessThanOrEqual($after->getTimestampMs(), $time->getTimestampMs());
    }

    public function testNowAdvancesFromStartedAtToActivityCompletionDuringReplay(): void
    {
        $frozen = Carbon::parse('2026-01-01T00:00:00Z');
        Carbon::setTestNow($frozen);

        $workflow = WorkflowStub::make(TestDeterministicTimeWorkflow::class, 'deterministic-time-1');
        $workflow->start('Taylor');

        $this->runReadyTaskOfType(TaskType::Workflow);

        Carbon::setTestNow($frozen->copy()->addMinutes(3));

        $this->runReadyTaskOfType(TaskType::Activity);
        $this->runReadyTaskOfType(TaskType::Workflow);

        Carbon::setTestNow(null);

        $this->assertTrue($workflow->refresh()->completed());
        $output = $workflow->output();

        $this->assertSame('Hello, Taylor!', $output['greeting']);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        /** @var WorkflowHistoryEvent $activityCompleted */
        $activityCompleted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityCompleted->value)
            ->orderBy('sequence')
            ->firstOrFail();

        $this->assertSame(
            $run->started_at?->getTimestampMs(),
            $output['time_at_start_ms'],
            'Workflow::now() at the start of a run should equal the run started_at.'
        );

        $this->assertSame(
            $activityCompleted->recorded_at?->getTimestampMs(),
            $output['time_after_activity_ms'],
            'Workflow::now() after an activity completes should equal the ActivityCompleted recorded_at.'
        );

        $this->assertGreaterThan(
            $output['time_at_start_ms'],
            $output['time_after_activity_ms'],
            'Workflow::now() must advance as replay progresses past history events.'
        );
    }

    public function testNowAdvancesToLatestParallelCompletionDuringReplay(): void
    {
        $frozen = Carbon::parse('2026-01-02T00:00:00Z');
        Carbon::setTestNow($frozen);

        try {
            $workflow = WorkflowStub::make(TestParallelDeterministicTimeWorkflow::class, 'deterministic-time-parallel');
            $workflow->start('Taylor', 'Abigail');

            $this->runReadyTaskOfType(TaskType::Workflow);

            Carbon::setTestNow($frozen->copy()->addMinutes(2));
            $this->runReadyTaskOfType(TaskType::Activity);

            Carbon::setTestNow($frozen->copy()->addMinutes(7));
            $this->runReadyTaskOfType(TaskType::Activity);
            $this->runReadyTaskOfType(TaskType::Workflow);
        } finally {
            Carbon::setTestNow(null);
        }

        $this->assertTrue($workflow->refresh()->completed());
        $output = $workflow->output();

        $this->assertSame(['Hello, Taylor!', 'Hello, Abigail!'], $output['results']);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        $latestActivityCompletionMs = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityCompleted->value)
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): ?int => $event->recorded_at?->getTimestampMs())
            ->filter(static fn (?int $timestamp): bool => $timestamp !== null)
            ->max();

        $this->assertSame(
            $run->started_at?->getTimestampMs(),
            $output['time_at_start_ms'],
            'Workflow::now() at the start of a parallel run should equal the run started_at.'
        );

        $this->assertSame(
            $latestActivityCompletionMs,
            $output['time_after_parallel_ms'],
            'Workflow::now() after a successful parallel group should equal the latest completed leaf event.'
        );

        $this->assertGreaterThan(
            $output['time_at_start_ms'],
            $output['time_after_parallel_ms'],
            'Workflow::now() must advance as replay progresses through successful parallel groups.'
        );
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
            default => $this->fail("Unsupported task type {$task->task_type->value}."),
        };

        $this->app->call([$job, 'handle']);
    }
}
