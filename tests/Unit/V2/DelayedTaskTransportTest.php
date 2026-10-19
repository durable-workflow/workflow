<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Workflow\V2\Activity;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class DelayedTaskTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 12:00:00.123456 UTC');
        config([
            'workflows.v2.task_dispatch_mode' => 'queue',
            'workflows.v2.connection' => 'chunk-queue',
            'workflows.v2.queue' => 'delayed-contract',
            'queue.default' => 'chunk-queue',
            'cache.default' => 'array',
        ]);
        $this->driver('sqs');
        Bus::fake([RunWorkflowTask::class, RunActivityTask::class, RunTimerTask::class]);
        LongRetryActivity::$calls = 0;
        ChunkedChildWorkflow::$calls = 0;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function taskDrivers(): iterable
    {
        foreach (['sqs', 'redis', 'database'] as $driver) {
            foreach (TaskType::cases() as $type) {
                yield $driver . '-' . $type->value => [$driver, $type];
            }
        }
    }

    #[DataProvider('taskDrivers')]
    public function testTransportDelayRespectsDriverWithoutMovingTheDurableDueTime(string $driver, TaskType $type): void
    {
        $this->driver($driver);
        $class = match ($type) {
            TaskType::Workflow => ChunkedChildWorkflow::class,
            TaskType::Activity => LongRetryWorkflow::class,
            TaskType::Timer => ChunkedTimerWorkflow::class,
        };
        $stub = $this->start($class);
        if ($type !== TaskType::Workflow) {
            $this->runWorkflow($this->readyTask($stub->runId(), TaskType::Workflow));
        }
        $task = $this->readyTask($stub->runId(), $type);
        $task->forceFill(['available_at' => now()->addDay()])->save();
        $due = $task->getRawOriginal('available_at');
        $history = $this->history($stub->runId());

        TaskDispatcher::dispatch($task);

        $jobClass = match ($type) {
            TaskType::Workflow => RunWorkflowTask::class,
            TaskType::Activity => RunActivityTask::class,
            TaskType::Timer => RunTimerTask::class,
        };
        $job = Bus::dispatched($jobClass)->last();
        $expected = $driver === 'sqs' ? now()->addSeconds(900) : $task->available_at->copy()->ceilSecond();
        $this->assertSame($task->id, $job->taskId);
        $this->assertSame($expected->format('Y-m-d H:i:s.u'), $job->delay->format('Y-m-d H:i:s.u'));
        $this->assertSame('chunk-queue', $job->connection);
        $this->assertSame('delayed-contract', $job->queue);
        $this->assertSame($due, $task->fresh()->getRawOriginal('available_at'));
        $this->assertSame(0, $task->fresh()->attempt_count);
        $this->assertNull($task->fresh()->last_dispatch_error);
        $this->assertSame($history, $this->history($stub->runId()));
    }

    public function testGenuineDayLongActivityRetryRelaysEarlyWakeupsWithoutConsumingAttempts(): void
    {
        $stub = $this->start(LongRetryWorkflow::class);
        $this->runWorkflow($this->readyTask($stub->runId(), TaskType::Workflow));
        (new RunActivityTask($this->readyTask($stub->runId(), TaskType::Activity)->id))->handle();
        $retry = $this->readyTask($stub->runId(), TaskType::Activity);
        $due = $retry->getRawOriginal('available_at');
        $history = $this->history($stub->runId());
        $deadlines = $this->deadlines($stub->runId());
        $scheduledRetry = WorkflowHistoryEvent::query()->where('event_type', HistoryEventType::ActivityRetryScheduled)
            ->sole()->getRawOriginal();
        $this->assertSame(now()->addDay()->toISOString(), $retry->available_at->toISOString());
        $this->assertSame(1, LongRetryActivity::$calls);
        $this->assertSame(1, ActivityAttempt::query()->count());
        $inheritedAttempts = $retry->attempt_count;
        $this->assertSame(1, $inheritedAttempts);
        $this->assertCappedActivityJob();

        foreach ([900, 1800] as $seconds) {
            Carbon::setTestNow($retry->available_at->copy()->subDay()->addSeconds($seconds));
            (new RunActivityTask($retry->id))->handle();
            $this->assertCappedActivityJob();
            $this->assertSame($due, $retry->fresh()->getRawOriginal('available_at'));
            $this->assertSame(TaskStatus::Ready, $retry->fresh()->status);
            $this->assertSame($inheritedAttempts, $retry->fresh()->attempt_count);
            $this->assertSame(1, ActivityAttempt::query()->count());
            $this->assertSame(1, LongRetryActivity::$calls);
            $this->assertSame($history, $this->history($stub->runId()));
            $this->assertSame($deadlines, $this->deadlines($stub->runId()));
        }

        Carbon::setTestNow($retry->available_at);
        (new RunActivityTask($retry->id))->handle();
        (new RunActivityTask($retry->id))->handle();
        $this->assertSame(2, LongRetryActivity::$calls);
        $this->assertSame(2, ActivityAttempt::query()->count());
        $this->assertSame(1, ActivityExecution::query()->count());
        $this->runWorkflow($this->readyTask($stub->runId(), TaskType::Workflow));
        $this->assertSame([9223372036854775807, null, '雪'], $stub->refresh()->output());
        $this->assertSame($deadlines, $this->deadlines($stub->runId()));
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('event_type', HistoryEventType::ActivityCompleted)->count());
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('event_type', HistoryEventType::WorkflowCompleted)->count());
        $this->assertSame($scheduledRetry, WorkflowHistoryEvent::query()->where('event_type', HistoryEventType::ActivityRetryScheduled)
            ->sole()->getRawOriginal());
    }

    public function testDelayedChildWorkflowCannotExecuteOnAnEarlyTransportDelivery(): void
    {
        $parent = $this->start(ChunkedParentWorkflow::class);
        $this->runWorkflow($this->readyTask($parent->runId(), TaskType::Workflow));
        $call = WorkflowChildCall::query()->where('parent_workflow_run_id', $parent->runId())->sole();
        $childId = $call->resolved_child_run_id;
        $task = $this->readyTask($childId, TaskType::Workflow);
        $task->forceFill(['available_at' => now()->addHour()])->save();
        $due = $task->getRawOriginal('available_at');
        $parentHistory = $this->history($parent->runId());
        $childHistory = $this->history($childId);
        $deadlines = $this->deadlines($childId);

        $queued = Bus::dispatched(RunWorkflowTask::class)->count();
        DB::beginTransaction();
        try {
            $this->runWorkflow($task);
            $this->assertSame(0, ChunkedChildWorkflow::$calls);
            $this->assertSame($queued, Bus::dispatched(RunWorkflowTask::class)->count());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($queued, Bus::dispatched(RunWorkflowTask::class)->count());
        $this->runWorkflow($task);

        $this->assertSame(0, ChunkedChildWorkflow::$calls);
        $this->assertSame(TaskStatus::Ready, $task->fresh()->status);
        $this->assertSame(0, $task->fresh()->attempt_count);
        $this->assertNull($task->fresh()->lease_owner);
        $this->assertSame($due, $task->fresh()->getRawOriginal('available_at'));
        $this->assertSame($parentHistory, $this->history($parent->runId()));
        $this->assertSame($childHistory, $this->history($childId));
        $this->assertSame($deadlines, $this->deadlines($childId));
        $this->assertSame(now()->addSeconds(900)->toISOString(), Bus::dispatched(RunWorkflowTask::class)->last()->delay->toISOString());

        Carbon::setTestNow($task->available_at);
        $this->runWorkflow($task);
        $this->runWorkflow($task);
        $this->assertSame(1, ChunkedChildWorkflow::$calls);
        $this->runWorkflow($this->readyTask($parent->runId(), TaskType::Workflow));
        $this->assertSame([9223372036854775807, null, '雪'], $parent->refresh()->output());
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $parent->runId())
            ->where('event_type', HistoryEventType::ChildRunCompleted)->count());
    }

    private function driver(string $driver): void
    {
        config(['queue.connections.chunk-queue' => ['driver' => $driver, 'queue' => 'delayed-contract']]);
    }

    private function start(string $class): WorkflowStub
    {
        $stub = WorkflowStub::make($class);
        $stub->start(new StartOptions(executionTimeoutSeconds: 259200, runTimeoutSeconds: 172800));
        return $stub;
    }

    private function readyTask(string $runId, TaskType $type): WorkflowTask
    {
        return WorkflowTask::query()->where('workflow_run_id', $runId)->where('task_type', $type)
            ->where('status', TaskStatus::Ready)->sole();
    }

    private function runWorkflow(WorkflowTask $task): void
    {
        app()->call([new RunWorkflowTask($task->id), 'handle']);
    }

    private function history(string $runId): array
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderBy('sequence')->get()->toArray();
    }

    private function deadlines(string $runId): array
    {
        $run = WorkflowRun::query()->findOrFail($runId);
        return [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')];
    }

    private function assertCappedActivityJob(): void
    {
        $job = Bus::dispatched(RunActivityTask::class)->last();
        $this->assertSame(now()->addSeconds(900)->toISOString(), $job->delay->toISOString());
        $this->assertNull(WorkflowTask::query()->findOrFail($job->taskId)->last_dispatch_error);
    }
}

final class LongRetryWorkflow extends Workflow
{
    public function handle(): array
    {
        return self::activity(LongRetryActivity::class, new ActivityOptions(
            maxAttempts: 2, backoff: [86400], startToCloseTimeout: 30, scheduleToCloseTimeout: 172800,
        ));
    }
}

final class LongRetryActivity extends Activity
{
    public static int $calls = 0;

    public function handle(): array
    {
        ++self::$calls;
        if ($this->attemptCount() === 1) {
            throw new RuntimeException('Retry this original once.');
        }
        return [9223372036854775807, null, '雪'];
    }
}

final class ChunkedParentWorkflow extends Workflow
{
    public function handle(): array
    {
        return self::child(ChunkedChildWorkflow::class);
    }
}

final class ChunkedChildWorkflow extends Workflow
{
    public static int $calls = 0;

    public function handle(): array
    {
        ++self::$calls;
        return [9223372036854775807, null, '雪'];
    }
}

final class ChunkedTimerWorkflow extends Workflow
{
    public function handle(): string
    {
        self::timer(86400);
        return 'timer completed';
    }
}
