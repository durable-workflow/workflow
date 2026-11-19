<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestCancellationAfterSignalTimeoutWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\WorkflowStub;

final class SignalTimeoutCancellationReplayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-01-01T00:00:00Z');
        config()
            ->set('queue.default', 'database');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function resolvedTimeouts(): iterable
    {
        yield 'immediate' => [0, false];
        yield 'scheduled' => [1, false];
        yield 'immediate, history without timer projection' => [0, true];
        yield 'scheduled, history without timer projection' => [1, true];
    }

    #[DataProvider('resolvedTimeouts')]
    public function testLaterCancellationCannotReplaceAnAlreadyCompletedSignalTimeout(
        int $timeout,
        bool $removeTimer
    ): void {
        $workflow = WorkflowStub::make(TestCancellationAfterSignalTimeoutWorkflow::class);
        $workflow->start($timeout);
        $runId = $workflow->runId();
        $this->assertIsString($runId);
        $this->runTask($runId, TaskType::Workflow);
        if ($timeout > 0) {
            Carbon::setTestNow(Carbon::now()->addSeconds($timeout));
            $this->runTask($runId, TaskType::Timer);
            $this->runTask($runId, TaskType::Workflow);
        }
        $this->runTask($runId, TaskType::Activity);
        $this->runTask($runId, TaskType::Workflow);
        $timeoutEvent = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::TimerFired)->sole();
        $this->assertSame(1, $timeoutEvent->payload['sequence']);
        $prefix = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderBy('sequence')->get()
            ->map(static fn (WorkflowHistoryEvent $event): array => $event->getRawOriginal())
            ->all();
        if ($removeTimer) {
            WorkflowTimer::query()->where('workflow_run_id', $runId)->delete();
        }
        $request = $workflow->requestCancellation('after original signal timeout', 30);
        $this->runTask($runId, TaskType::Workflow);
        $delivery = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::CooperativeCancellationDelivered)->sole();
        $this->assertSame(3, $delivery->payload['sequence'], 'Cancellation must stay at the later unresolved wait.');
        $this->assertSame('signal', $delivery->payload['call_kind']);
        $this->assertSame($request->commandId(), $delivery->workflow_command_id);
        $this->runTask($runId, TaskType::Activity);
        $this->runTask($runId, TaskType::Workflow);
        $this->assertTrue($workflow->refresh()->cancelled());
        $cleanup = ActivityExecution::query()->where('workflow_run_id', $runId)->where('sequence', 4)->sole();
        $this->assertSame('Hello, cleanup!', $cleanup->activityResult());
        $this->assertSame($prefix, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('sequence', '<=', count($prefix))
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): array => $event->getRawOriginal())
            ->all());
        $this->assertSame(2, ActivityExecution::query()->where('workflow_run_id', $runId)->count());
        $this->assertSame(
            0,
            WorkflowTask::query()->where('workflow_run_id', $runId)->where('status', TaskStatus::Failed)->count()
        );
    }

    public static function pendingTimeouts(): iterable
    {
        yield 'timeout still pending' => [false];
        yield 'timeout fires after the request' => [true];
    }

    #[DataProvider('pendingTimeouts')]
    public function testCancellationRequestedBeforeTimeoutStillOwnsTheOriginalWait(bool $fireAfterRequest): void
    {
        $workflow = WorkflowStub::make(TestCancellationAfterSignalTimeoutWorkflow::class);
        $workflow->start(1);
        $runId = $workflow->runId();
        $this->assertIsString($runId);
        $this->runTask($runId, TaskType::Workflow);
        $request = $workflow->requestCancellation('before original timeout', 30);
        if ($fireAfterRequest) {
            Carbon::setTestNow(Carbon::now()->addSecond());
            $this->runTask($runId, TaskType::Timer);
        }
        $this->runTask($runId, TaskType::Workflow);
        $delivery = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::CooperativeCancellationDelivered)->sole();
        $this->assertSame(1, $delivery->payload['sequence']);
        $this->assertSame($request->commandId(), $delivery->workflow_command_id);
        $this->runTask($runId, TaskType::Activity);
        $this->runTask($runId, TaskType::Workflow);
        $this->assertTrue($workflow->refresh()->cancelled());
        $cleanup = ActivityExecution::query()->where('workflow_run_id', $runId)->sole();
        $this->assertSame(2, $cleanup->sequence);
        $this->assertSame('Hello, cleanup!', $cleanup->activityResult());
        $this->assertSame(
            0,
            WorkflowTask::query()->where('workflow_run_id', $runId)->where('status', TaskStatus::Failed)->count()
        );
    }

    private function runTask(string $runId, TaskType $type): void
    {
        $task = WorkflowTask::query()->where('workflow_run_id', $runId)->where('task_type', $type)
            ->where('status', TaskStatus::Ready)->orderBy('created_at')->firstOrFail();
        $job = match ($type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            TaskType::Timer => new RunTimerTask($task->id),
            default => throw new \LogicException('The regression only drives its original recorded tasks.'),
        };
        $this->app->call([$job, 'handle']);
    }
}
