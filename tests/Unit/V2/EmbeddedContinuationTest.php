<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Orchestra\Testbench\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;
use Tests\Fixtures\V2\TestUpdateWorkflow;
use Tests\Support\ContinuationTaskMaintenance;
use Tests\Support\TestDatabaseServiceProvider;
use Workflow\Exceptions\NonRetryableException;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\Serializers\Serializer;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Exceptions\WorkflowEntryArgumentsMismatchException;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\ServiceOperationOptions;
use Workflow\V2\Support\ServiceOperationResult;
use Workflow\V2\Support\TaskRepairPolicy;
use Workflow\V2\Support\WorkflowContinuationCache;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EmbeddedContinuationTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'dw-continuation-');
        parent::setUp();
        Carbon::setTestNow('2026-10-17 15:15:00.123456');
        $this->artisan('migrate', [
            '--force' => true,
        ])->run();
        DB::statement('CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,
            queue TEXT NOT NULL, payload TEXT NOT NULL, attempts INTEGER NOT NULL,
            reserved_at INTEGER, available_at INTEGER NOT NULL, created_at INTEGER NOT NULL)');
        DB::statement('CREATE TABLE failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL UNIQUE, connection TEXT NOT NULL, queue TEXT NOT NULL,
            payload TEXT NOT NULL, exception TEXT NOT NULL, failed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        ContinuationWorkflow::$entries = 0;
        ContinuationWorkflow::$afterActivity = 0;
        ContinuationActivity::$calls = 0;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($this->path . $suffix)) {
                unlink($this->path . $suffix);
            }
        }
    }

    public static function profiles(): array
    {
        return [
            'ordinary cold jobs' => [false, 3, 2, 'none'],
            'retained jobs' => [true, 1, 1, 'none'],
            'lost process-local state' => [true, 2, 1, 'evict'],
            'new application build' => [true, 2, 1, 'build'],
            'reconnected database session' => [true, 2, 1, 'reconnect'],
            'changed history model' => [true, 2, 1, 'history_event_model'],
            'changed activity model' => [true, 2, 1, 'activity_execution_model'],
            'changed timer model' => [true, 2, 1, 'timer_model'],
            'changed task model' => [true, 2, 1, 'task_model'],
            'ready task transport redispatch' => [true, 1, 1, 'redispatch'],
            'duplicate completed delivery' => [true, 1, 1, 'duplicate'],
            'worker stop' => [true, 2, 1, 'stop'],
            'container binding stays cold' => [true, 3, 2, 'binding'],
        ];
    }

    #[DataProvider('profiles')]
    public function testActualDatabaseJobsKeepCommittedEffectsAndOriginalDeadlines(
        bool $enabled,
        int $entries,
        int $afterActivity,
        string $change,
    ): void {
        $maintenance = new ContinuationTaskMaintenance();
        WorkflowTask::updated($maintenance->observe(...));
        $heldDelivery = null;
        config()
            ->set('workflows.v2.live_execution', [
                'workflows' => $enabled ? [ContinuationWorkflow::class] : [],
                'build' => 'continuation-test-build',
            ]);
        if ($change === 'binding') {
            $this->app->bind(ContinuationWorkflow::class, static fn () => new ContinuationWorkflow());
        }
        $stub = WorkflowStub::make(ContinuationWorkflow::class, 'original', 'continuation-test');
        $stub->start(new StartOptions(executionTimeoutSeconds: 120, runTimeoutSeconds: 90));
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $deadlines = [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')];
        $this->workOnce(); // workflow parks on the activity
        if ($change === 'duplicate') {
            Queue::connection('database')->push(new RunWorkflowTask(
                WorkflowTask::query()->where('workflow_run_id', $run->id)->where('task_type', 'workflow')->sole()->id,
            ));
        }
        $prefix = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->get()
            ->map->getRawOriginal()
            ->all();
        $this->workOnce(); // actual activity callback
        if ($change === 'duplicate') {
            $this->workOnce();
            $this->assertSame(1, ContinuationWorkflow::$entries);
        }
        if ($change === 'evict') {
            $this->app->make(WorkflowContinuationCache::class)->clear();
        } elseif ($change === 'build') {
            config()->set('workflows.v2.live_execution.build', 'new-build');
        } elseif ($change === 'stop') {
            $this->app->make('queue.worker')
                ->stop();
        } elseif ($change === 'reconnect') {
            DB::disconnect('continuation');
        } elseif (in_array(
            $change,
            ['history_event_model', 'activity_execution_model', 'timer_model', 'task_model'],
            true
        )) {
            $model = match ($change) {
                'history_event_model' => ContinuationConfiguredHistory::class,
                'activity_execution_model' => ContinuationConfiguredActivity::class,
                'timer_model' => ContinuationConfiguredTimer::class,
                'task_model' => ContinuationConfiguredTask::class,
            };
            config()
                ->set('workflows.v2.' . $change, $model);
        } elseif ($change === 'redispatch') {
            // Reserve through the real broker, then leave the logical task unclaimed.
            $heldDelivery = Queue::connection('database')->pop('default');
            $this->assertInstanceOf(DatabaseJob::class, $heldDelivery);
            Carbon::setTestNow(now()->addSeconds(TaskRepairPolicy::redispatchAfterSeconds() + 1));
            $repair = TaskWatchdog::runPass(runIds: [$run->id]);
            $this->assertSame(1, $repair['repaired_existing_tasks'], json_encode([
                'repair' => $repair,
                'now' => now()
                    ->toJSON(),
                'tasks' => $run->tasks()
                    ->get()
                    ->map(static fn ($task): array => [
                        ...$task->only(['task_type', 'status', 'available_at', 'created_at', 'last_dispatched_at']),
                        'needs_redispatch' => TaskRepairPolicy::readyTaskNeedsRedispatch($task),
                    ])->all(),
            ], JSON_THROW_ON_ERROR));
        }
        $this->workOnce(); // workflow resumes, then parks on its timer
        if ($heldDelivery instanceof DatabaseJob) {
            $this->app->make('queue.worker')
                ->process(
                    'database',
                    $heldDelivery,
                    new WorkerOptions(sleep: 0, timeout: 30),
                ); // The held obsolete delivery observes the completed logical task.
        }
        Carbon::setTestNow(now()->addSeconds(2));
        $this->workOnce(); // durable timer fires through the ordinary queue
        $this->workOnce(); // final workflow continuation
        $this->assertSame('completed', $stub->refresh()->status(), Artisan::output());
        $this->assertSame([
            'integer' => 9007199254740993,
            'optional' => null,
            'unicode' => 'λ',
        ], $stub->output());
        $this->assertSame(1, ContinuationActivity::$calls);
        $this->assertSame($entries, ContinuationWorkflow::$entries);
        $this->assertSame($afterActivity, ContinuationWorkflow::$afterActivity);
        $this->assertSame($deadlines, [
            $run->fresh()
                ->getRawOriginal('execution_deadline_at'),
            $run->fresh()
                ->getRawOriginal('run_deadline_at'),
        ]);
        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy('sequence')->get();
        $this->assertSame($prefix, $events->take(count($prefix))->map->getRawOriginal()->all());
        foreach ([HistoryEventType::ActivityScheduled, HistoryEventType::ActivityStarted,
            HistoryEventType::ActivityCompleted, HistoryEventType::TimerScheduled,
            HistoryEventType::TimerFired, HistoryEventType::WorkflowCompleted] as $type) {
            $this->assertCount(1, $events->where('event_type', $type));
        }
        $tasks = WorkflowTask::query()->where('workflow_run_id', $run->id)->get();
        $this->assertCount(5, $tasks);
        $this->assertSame(['completed'], $tasks->pluck('status')->map->value->unique()->values()->all());
        $this->assertSame([1], $tasks->pluck('attempt_count')->unique()->values()->all());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame([
            'ready_redispatches' => $change === 'redispatch' ? 1 : 0,
            'lease_recoveries' => 0,
            'other_repairs' => 0,
        ], $maintenance->snapshot());
        $counts = app(WorkflowContinuationCache::class)->snapshot()['counts'];
        if ($enabled) {
            $this->assertSame($entries, $counts['cold_entry']);
            if ($change === 'binding') {
                $this->assertSame(0, $counts['validated_history_events'] ?? 0);
            } else {
                $this->assertGreaterThan(0, $counts['validated_history_events'] ?? 0);
            }
        } else {
            $this->assertArrayNotHasKey('cold_entry', $counts);
        }
    }

    public static function replacedClaims(): array
    {
        return [
            'different owner' => [false],
            'same owner advanced attempt' => [true],
        ];
    }

    #[DataProvider('replacedClaims')]
    public function testFreshLeaseOwnerIsCheckedBeforeAnyRetainedPrefixCanResume(bool $advancedAttempt): void
    {
        $this->enable();
        $stub = WorkflowStub::make(ContinuationWorkflow::class, 'lease-original', 'continuation-test');
        $stub->start();
        $this->workOnce();
        $this->workOnce();
        $task = WorkflowTask::query()->where('workflow_run_id', $stub->runId())
            ->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->sole();
        $writer = new PDO('sqlite:' . $this->path);
        $expectedOwner = $advancedAttempt ? $task->id : 'replacement-owner';
        $admissions = 0;
        DB::connection()->beforeExecuting(static function (string $sql) use (
            $writer,
            $task,
            $advancedAttempt,
            $expectedOwner,
            &$admissions
        ): void {
            if (str_starts_with($sql, 'update "workflow_tasks" set "id" =') && ++$admissions === 2) {
                $statement = $writer->prepare($advancedAttempt
                    ? 'UPDATE workflow_tasks SET attempt_count = attempt_count + 1, lease_owner = ? WHERE id = ?'
                    : 'UPDATE workflow_tasks SET lease_owner = ? WHERE id = ?');
                $statement->execute([$expectedOwner, $task->id]);
            }
        });
        $prefix = WorkflowHistoryEvent::query()->where('workflow_run_id', $stub->runId())
            ->orderBy('sequence')
            ->get()
            ->map->getRawOriginal()
            ->all();
        $this->workOnce();
        $this->assertSame(2, $admissions);
        $this->assertSame($expectedOwner, $task->fresh()->lease_owner);
        $this->assertSame($advancedAttempt ? 2 : 1, (int) $task->fresh()->attempt_count);
        $this->assertSame('leased', $task->fresh()->status->value);
        $this->assertSame(1, ContinuationWorkflow::$entries);
        $this->assertSame(0, ContinuationWorkflow::$afterActivity);
        $this->assertSame(0, app(WorkflowContinuationCache::class)->snapshot()['entries']);
        $this->assertSame($prefix, WorkflowHistoryEvent::query()->where('workflow_run_id', $stub->runId())
            ->orderBy('sequence')
            ->get()
            ->map->getRawOriginal()
            ->all());
    }

    public function testOnePersistentDaemonKeepsItsFrameAcrossARealOneSecondTimer(): void
    {
        $this->enable();
        Carbon::setTestNow();
        $stub = WorkflowStub::make(ContinuationWorkflow::class, 'daemon-original', 'continuation-test');
        $stub->start(new StartOptions(executionTimeoutSeconds: 120, runTimeoutSeconds: 90));
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $deadlines = [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')];
        $started = hrtime(true);
        $status = Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'default',
            '--max-jobs' => 5,
            '--max-time' => 30,
            '--sleep' => 1,
            '--tries' => 1,
            '--timeout' => 30,
        ]);
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;
        $this->assertSame(0, $status, Artisan::output());
        $this->assertSame('completed', $stub->refresh()->status(), Artisan::output());
        $this->assertSame(1, ContinuationWorkflow::$entries);
        $this->assertSame(1, ContinuationWorkflow::$afterActivity);
        $this->assertSame(1, ContinuationActivity::$calls);
        $this->assertSame(5, $run->tasks()->where('status', 'completed')->where('attempt_count', 1)->count());
        $this->assertSame($deadlines, [$run->fresh()->getRawOriginal('execution_deadline_at'),
            $run->fresh()
                ->getRawOriginal('run_deadline_at')]);
        $this->assertGreaterThanOrEqual(1, $elapsed);
        $this->assertLessThan(30, $elapsed);
        $this->assertSame(0, $this->app->make(WorkflowContinuationCache::class)->snapshot()['entries']);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function testUnsupportedMemoFallsBackBeforeItsDurableWrite(): void
    {
        config()->set('workflows.v2.live_execution', [
            'workflows' => [ContinuationMemoWorkflow::class],
            'build' => 'memo-fallback',
        ]);
        ContinuationMemoWorkflow::$entries = 0;
        $atWrite = [];
        WorkflowHistoryEvent::creating(static function (WorkflowHistoryEvent $event) use (&$atWrite): void {
            if ($event->event_type === HistoryEventType::MemoUpserted) {
                $atWrite[] = ContinuationMemoWorkflow::$entries;
            }
        });
        $stub = WorkflowStub::make(ContinuationMemoWorkflow::class, 'memo-fallback', 'continuation-test');
        $stub->start();
        $this->workOnce();
        $this->workOnce();
        $this->workOnce();
        $this->assertSame('completed', $stub->refresh()->status());
        $this->assertSame([2], $atWrite);
        $this->assertSame(1, ContinuationActivity::$calls);
        $this->assertSame(2, app(WorkflowContinuationCache::class)->snapshot()['counts']['cold_entry']);
        $this->assertSame(0, $this->app->make(WorkflowContinuationCache::class)->snapshot()['entries']);
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $stub->runId())
            ->where('event_type', HistoryEventType::MemoUpserted)->count());
    }

    public function testRunAndNamespaceStateStayBoundToTheirOwnContinuationAndEventClocks(): void
    {
        config()->set('workflows.v2.live_execution', [
            'workflows' => [ScopedContinuationWorkflow::class],
            'build' => 'namespace-test',
        ]);
        ScopedContinuationWorkflow::$entries = [];
        $stubs = [];
        foreach (['17', '017'] as $namespace) {
            $stub = WorkflowStub::make(ScopedContinuationWorkflow::class, 'scope-' . $namespace, $namespace);
            $stub->start($namespace);
            $stubs[] = $stub;
        }
        foreach ([0, 1, 2] as $phase) {
            if ($phase === 1) {
                Carbon::setTestNow(now()->addMicroseconds(13));
            }
            $this->workOnce();
            $this->workOnce();
        }
        Carbon::setTestNow(now()->addSeconds(2));
        for ($job = 0; $job < 4; ++$job) {
            $this->workOnce();
        }
        foreach ($stubs as $stub) {
            $run = WorkflowRun::query()->findOrFail($stub->runId());
            $this->assertSame('completed', $stub->refresh()->status());
            $result = $stub->output();
            $this->assertSame($run->namespace, $result['tag']);
            $this->assertSame($run->namespace, $result['namespace']);
            $this->assertSame($run->id, $result['run']);
            $events = $run->historyEvents()
                ->get();
            $timer = $events->where('event_type', HistoryEventType::TimerFired)->sole();
            $activity = $events->where('event_type', HistoryEventType::ActivityCompleted)->sole();
            $this->assertSame($timer->sequence, $result['history_at_return']);
            $this->assertSame([$run->started_at->toJSON(), $activity->recorded_at->toJSON(),
                $timer->recorded_at->toJSON()], $result['clocks']);
            $this->assertSame(1, ScopedContinuationWorkflow::$entries[$run->id]);
            $this->assertSame(5, $run->tasks()->where('status', 'completed')->where('attempt_count', 1)->count());
        }
        $this->assertSame(2, ContinuationActivity::$calls);
    }

    public static function failures(): array
    {
        return [
            'root rollback' => ['rollback'],
            'lost post-commit acknowledgement' => ['ack'],
        ];
    }

    #[DataProvider('failures')]
    public function testAdvancedFrameIsDiscardedAfterTransactionFailure(string $phase): void
    {
        $this->enable();
        $stub = WorkflowStub::make(ContinuationWorkflow::class, 'failed-original', 'continuation-test');
        $stub->start(new StartOptions(executionTimeoutSeconds: 120, runTimeoutSeconds: 90));
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $deadlines = [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')];
        $this->workOnce();
        $this->workOnce();
        $inject = true;
        WorkflowHistoryEvent::created(static function (WorkflowHistoryEvent $event) use (&$inject, $phase): void {
            if ($inject && $event->event_type === HistoryEventType::TimerScheduled) {
                $inject = false;
                $fault = static fn () => throw new RuntimeException('controlled-' . $phase);
                if ($phase === 'ack') {
                    DB::afterCommit($fault);
                } else {
                    $fault();
                }
            }
        });
        $this->workOnce();
        $this->assertFalse($inject);
        $this->assertSame(0, $this->app->make(WorkflowContinuationCache::class)->snapshot()['entries']);
        $this->assertSame(1, ContinuationWorkflow::$entries);
        $this->assertSame(1, ContinuationWorkflow::$afterActivity);
        $this->assertSame(1, ContinuationActivity::$calls);
        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $stub->runId())->orderBy('sequence')->get();
        $this->assertCount($phase === 'ack' ? 1 : 0, $events->where('event_type', HistoryEventType::TimerScheduled));
        $this->assertCount(1, $events->where('event_type', HistoryEventType::ActivityCompleted));
        $this->assertCount(0, $events->where('event_type', HistoryEventType::WorkflowCompleted));
        $failed = WorkflowTask::query()->where('workflow_run_id', $stub->runId())->where('status', 'failed')->sole();
        $this->assertSame('controlled-' . $phase, $failed->last_error);
        $failedState = $failed->getRawOriginal();
        $prefix = $events->map->getRawOriginal()
            ->all();
        $failedJobs = DB::table('failed_jobs')->count();

        $this->assertTrue($stub->refresh()->attemptRepair()->accepted());
        $this->workOnce();
        // A lost acknowledgement can precede the timer's transport dispatch.
        // Preserve the ordinary stale-dispatch policy before asking the watchdog.
        Carbon::setTestNow(now()->addSeconds(TaskRepairPolicy::redispatchAfterSeconds() + 2));
        $repair = TaskWatchdog::runPass(runIds: [$run->id]);
        for ($delivery = 0; $delivery < 10 && DB::table('jobs')->exists(); ++$delivery) {
            $this->workOnce();
        }

        $this->assertTrue($stub->refresh()->completed(), json_encode([
            'repair' => $repair,
            'status' => $run->fresh()
->status->value,
            'now' => now()
                ->toJSON(),
            'tasks' => $run->tasks()
                ->get()
                ->map(static fn ($task): array => $task->only([
                    'id', 'task_type', 'status', 'available_at', 'last_dispatched_at', 'last_error', 'repair_count',
                ]))->all(),
            'queued' => DB::table('jobs')->count(),
        ], JSON_THROW_ON_ERROR));
        $this->assertSame($failedState, $failed->fresh()->getRawOriginal());
        $this->assertSame(1, ContinuationActivity::$calls);
        // RepairRequested keeps recovery cold. Rollback also needs a workflow
        // task to reschedule the timer that never committed.
        $this->assertSame($phase === 'rollback' ? 3 : 2, ContinuationWorkflow::$entries);
        $this->assertSame($deadlines, [$run->fresh()->getRawOriginal('execution_deadline_at'),
            $run->fresh()
                ->getRawOriginal('run_deadline_at')]);
        $events = $run->historyEvents()
            ->orderBy('sequence')
            ->get();
        $this->assertCount(1, $events->where('event_type', HistoryEventType::RepairRequested));
        $this->assertSame($prefix, $events->take(count($prefix))->map->getRawOriginal()->all());
        $this->assertCount(1, $events->where('event_type', HistoryEventType::TimerScheduled));
        $this->assertCount(1, $events->where('event_type', HistoryEventType::WorkflowCompleted));
        $this->assertSame($failedJobs, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, app(WorkflowContinuationCache::class)->snapshot()['entries']);
    }

    public static function modelProfiles(): array
    {
        return [
            'ordinary cold' => [false],
            'retained' => [true],
        ];
    }

    #[DataProvider('modelProfiles')]
    public function testIncompatibleEntryAfterSuspensionPreservesTheOriginalForRepair(bool $enabled): void
    {
        $maintenance = new ContinuationTaskMaintenance();
        WorkflowTask::updated($maintenance->observe(...));
        config()
            ->set('workflows.v2.live_execution', [
                'workflows' => $enabled ? [ContinuationEntryWorkflow::class] : [],
                'build' => 'entry-binding',
            ]);
        $stub = WorkflowStub::make(ContinuationEntryWorkflow::class, 'entry-binding', 'continuation-test');
        $stub->start(new StartOptions(executionTimeoutSeconds: 120, runTimeoutSeconds: 90), 7);
        $this->workOnce();
        $cache = app(WorkflowContinuationCache::class);
        $this->assertSame($enabled ? 1 : 0, $cache->snapshot()['entries']);
        Carbon::setTestNow(now()->addSeconds(2));
        $this->workOnce();
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $original = array_intersect_key($run->getRawOriginal(), array_flip([
            'id', 'workflow_instance_id', 'workflow_class', 'workflow_type', 'arguments', 'payload_codec',
            'started_at', 'execution_deadline_at', 'run_deadline_at',
        ]));
        $prefix = $run->historyEvents()
            ->orderBy('sequence')
            ->get()
            ->map->getRawOriginal()
            ->all();
        $task = $run->tasks()
            ->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->sole();
        $this->app->bind(ContinuationEntryWorkflow::class, static fn () => new ContinuationIncompatibleEntry());
        $this->workOnce();

        $this->assertFalse($run->fresh()->status->isTerminal());
        $this->assertSame($original, array_intersect_key($run->fresh()->getRawOriginal(), $original));
        $this->assertSame($prefix, $run->historyEvents()->orderBy('sequence')->get()->map->getRawOriginal()->all());
        $this->assertSame('failed', $task->fresh()->status->value);
        $this->assertSame('workflow_entry_arguments_mismatch', $task->fresh()->payload['replay_blocked_reason']);
        $this->assertSame(0, $cache->snapshot()['entries']);
        $this->assertSame(0, ContinuationIncompatibleEntry::$entries);
        $this->assertSame(0, DB::table('failed_jobs')->count());

        $this->app->offsetUnset(ContinuationEntryWorkflow::class);
        $this->assertTrue($stub->refresh()->attemptRepair()->accepted());
        $this->workOnce();
        $this->assertTrue($stub->refresh()->completed(), Artisan::output());
        $this->assertSame(7, $stub->output());
        $this->assertSame('completed', $task->fresh()->status->value);
        $this->assertSame($original, array_intersect_key($run->fresh()->getRawOriginal(), $original));
        $this->assertSame($prefix, $run->historyEvents()->orderBy('sequence')->get()->take(count($prefix))
            ->map->getRawOriginal()
            ->all());
        $this->assertSame(1, $run->historyEvents()->where('event_type', 'TimerScheduled')->count());
        $this->assertSame(0, $run->historyEvents()->where('event_type', 'WorkflowFailed')->count());
        $this->assertSame([
            'ready_redispatches' => 0,
            'lease_recoveries' => 0,
            'other_repairs' => 1,
        ], $maintenance->snapshot());
    }

    public static function bodyFailures(): iterable
    {
        foreach ([false, true] as $enabled) {
            foreach (['type-error', 'internal-mismatch'] as $failure) {
                yield ($enabled ? 'retained-' : 'cold-') . $failure => [$enabled, $failure];
            }
        }
    }

    #[DataProvider('bodyFailures')]
    public function testAValidEntryBodyFailureAfterSuspensionRemainsAnApplicationFailure(
        bool $enabled,
        string $failure,
    ): void {
        config()->set('workflows.v2.live_execution', [
            'workflows' => $enabled ? [ContinuationEntryBodyFailure::class] : [],
            'build' => 'entry-body-failure',
        ]);
        ContinuationEntryBodyFailure::$entries = 0;
        $stub = WorkflowStub::make(ContinuationEntryBodyFailure::class, 'entry-body', 'continuation-test');
        $stub->start(new StartOptions(executionTimeoutSeconds: 120, runTimeoutSeconds: 90), $failure);
        $this->workOnce();
        $this->assertSame($enabled ? 1 : 0, app(WorkflowContinuationCache::class)->snapshot()['entries']);
        Carbon::setTestNow(now()->addSeconds(2));
        $this->workOnce();
        $this->workOnce();
        $run = WorkflowRun::query()->findOrFail($stub->runId());

        $this->assertTrue($stub->refresh()->failed(), Artisan::output());
        $this->assertSame($enabled ? 1 : 2, ContinuationEntryBodyFailure::$entries);
        $this->assertSame(1, $run->historyEvents()->where('event_type', 'WorkflowFailed')->count());
        foreach ($run->tasks()->get() as $task) {
            $this->assertFalse($task->payload['replay_blocked'] ?? false);
        }
        $this->assertSame(0, app(WorkflowContinuationCache::class)->snapshot()['entries']);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    #[DataProvider('modelProfiles')]
    public function testAConfiguredRunModelChangeUsesFreshRuntimeState(bool $enabled): void
    {
        ContinuationModelWorkflow::$entries = 0;
        config()
            ->set('workflows.v2.live_execution', [
                'workflows' => $enabled ? [ContinuationModelWorkflow::class] : [],
                'build' => 'unchanged-model-build',
            ]);
        $stub = WorkflowStub::make(ContinuationModelWorkflow::class, 'model-change', 'continuation-test');
        $stub->start();
        $this->workOnce();
        $this->assertSame($enabled ? 1 : 0, app(WorkflowContinuationCache::class)->snapshot()['entries']);
        config()
            ->set('workflows.v2.run_model', ContinuationConfiguredRun::class);
        $this->workOnce();
        $this->workOnce();
        $this->assertSame('completed', $stub->refresh()->status());
        $this->assertSame(ContinuationConfiguredRun::class, $stub->output()['model']);
        $this->assertSame(2, ContinuationModelWorkflow::$entries);
        $this->assertSame(1, ContinuationActivity::$calls);
        if ($enabled) {
            $this->assertSame(
                1,
                app(WorkflowContinuationCache::class)->snapshot()['counts']['invalidate_identity'] ?? 0
            );
        }
    }

    public static function fallbackOperations(): iterable
    {
        $operations = [
            'parallel' => [HistoryEventType::TimerScheduled, [true, true]],
            'selection' => [
                HistoryEventType::TimerScheduled, [
                    'key' => 'left',
                    'winner' => true,
                    'remaining' => true,
                ]],
            'child' => [HistoryEventType::ChildWorkflowScheduled, 'child-value'],
            'condition' => [HistoryEventType::ConditionWaitOpened, true],
            'continue' => [HistoryEventType::WorkflowContinuedAsNew, 'continued'],
            'nexus' => [HistoryEventType::ServiceCallStarted, [
                'integer' => 9007199254740993,
                'optional' => null,
                'unicode' => 'Nexus λ',
            ]],
        ];
        foreach ($operations as $operation => [$event, $result]) {
            foreach ([false, true] as $enabled) {
                yield $operation . ($enabled ? ' retained fallback' : ' cold') => [
                    $enabled,
                    $operation,
                    $event,
                    $result,
                ];
            }
        }
    }

    #[DataProvider('fallbackOperations')]
    public function testExcludedOperationsReplayBeforeTheirFirstDurableWrite(
        bool $enabled,
        string $operation,
        HistoryEventType $firstEvent,
        mixed $result,
    ): void {
        ContinuationFallbackWorkflow::$entries = [];
        config()
            ->set('workflows.v2.live_execution', [
                'workflows' => $enabled ? [ContinuationFallbackWorkflow::class] : [],
                'build' => 'excluded-operations',
            ]);
        $backendStarts = 0;
        $serviceRun = $operation === 'nexus' ? $this->registerNexusService($backendStarts) : null;
        $stub = WorkflowStub::make(ContinuationFallbackWorkflow::class, 'fallback-' . $operation, 'continuation-test');
        $stub->start(new StartOptions(executionTimeoutSeconds: 120, runTimeoutSeconds: 90), $operation);
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $deadlines = [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')];
        $atWrite = [];
        $atServiceCreate = [];
        WorkflowServiceCall::creating(static function (WorkflowServiceCall $call) use ($run, &$atServiceCreate): void {
            if ($call->caller_workflow_run_id === $run->id) {
                $atServiceCreate[] = ContinuationFallbackWorkflow::$entries[$run->id] ?? 0;
            }
        });
        WorkflowHistoryEvent::creating(static function (WorkflowHistoryEvent $event) use (
            $run,
            $firstEvent,
            &$atWrite
        ): void {
            if ($event->workflow_run_id === $run->id && $event->event_type === $firstEvent) {
                $atWrite[] = ContinuationFallbackWorkflow::$entries[$run->id] ?? 0;
            }
        });
        $this->workOnce();
        $this->assertSame($enabled ? 1 : 0, app(WorkflowContinuationCache::class)->snapshot()['entries']);
        $this->workOnce();
        $prefix = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy('sequence')
            ->get()
            ->map->getRawOriginal()
            ->all();
        $this->workOnce();
        $this->assertNotEmpty($atWrite);
        $this->assertSame([2], array_values(array_unique($atWrite)));
        $this->assertSame(0, app(WorkflowContinuationCache::class)->snapshot()['entries']);
        if ($enabled) {
            $this->assertSame(
                1,
                app(WorkflowContinuationCache::class)->snapshot()['counts']['cold_unsupported_operation'] ?? 0
            );
        }
        if ($serviceRun !== null) {
            $this->assertSame([2], $atServiceCreate);
            $this->assertSame(1, $backendStarts);
            $call = WorkflowServiceCall::query()->where('caller_workflow_run_id', $run->id)->sole();
            $this->assertSame('started', $call->status);
            WorkflowHistoryEvent::record($serviceRun, HistoryEventType::WorkflowCompleted, [
                'output' => Serializer::serializeWithCodec('avro', $result),
                'payload_codec' => 'avro',
            ]);
        }
        Carbon::setTestNow(now()->addSeconds(2));
        for ($jobs = 0; $jobs < 12 && DB::table('jobs')->exists(); ++$jobs) {
            $this->workOnce();
            Carbon::setTestNow(now()->addSeconds(2));
        }
        $this->assertSame('completed', $stub->refresh()->status(), Artisan::output());
        $this->assertSame([
            'value' => [
                'integer' => 9007199254740993,
                'optional' => null,
                'unicode' => 'λ',
            ],
            'operation' => $result,
        ], $stub->output());
        $this->assertSame(1, ContinuationActivity::$calls);
        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy('sequence')->get();
        $this->assertSame($prefix, $events->take(count($prefix))->map->getRawOriginal()->all());
        $this->assertCount(1, $events->where('event_type', HistoryEventType::ActivityCompleted));
        if ($serviceRun !== null) {
            $this->assertSame(1, $backendStarts);
            $this->assertCount(1, $events->where('event_type', HistoryEventType::ServiceCallStarted));
            $this->assertCount(1, $events->where('event_type', HistoryEventType::ServiceCallCompleted));
            $this->assertSame(
                2,
                $events->firstWhere('event_type', HistoryEventType::ServiceCallStarted)->payload['sequence']
            );
            $this->assertSame(
                2,
                $events->firstWhere('event_type', HistoryEventType::ServiceCallCompleted)->payload['sequence']
            );
            $call = WorkflowServiceCall::query()->where('caller_workflow_run_id', $run->id)->sole();
            $this->assertSame('completed', $call->status);
        }
        $this->assertSame($deadlines, [$run->fresh()->getRawOriginal('execution_deadline_at'),
            $run->fresh()
                ->getRawOriginal('run_deadline_at')]);
        $this->assertSame(
            ['completed'],
            WorkflowTask::query()->get()->pluck('status')->map->value->unique()->values()->all()
        );
        $this->assertSame([1], WorkflowTask::query()->pluck('attempt_count')->unique()->values()->all());
        $this->assertSame([0], WorkflowTask::query()->pluck('repair_count')->unique()->values()->all());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, app(WorkflowContinuationCache::class)->snapshot()['entries']);
    }

    #[DataProvider('modelProfiles')]
    public function testDeclaredQueriesUpdatesAndSignalsKeepTheirColdReplayContract(bool $enabled): void
    {
        config()->set('workflows.v2.live_execution', [
            'workflows' => $enabled ? [TestUpdateWorkflow::class] : [],
            'build' => 'interactive-cold-profile',
        ]);
        $stub = WorkflowStub::make(TestUpdateWorkflow::class, 'interactive', 'continuation-test');
        $stub->start(new StartOptions(executionTimeoutSeconds: 120, runTimeoutSeconds: 90));
        $this->workOnce();
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $deadlines = [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')];
        $this->assertSame(0, app(WorkflowContinuationCache::class)->snapshot()['entries']);
        $prefix = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy('sequence')
            ->get()
            ->map->getRawOriginal()
            ->all();
        $this->assertSame(
            [
                'stage' => 'waiting-for-name',
                'approved' => false,
                'events' => ['started'],
            ],
            $stub->query('currentState')
        );
        $this->assertSame(
            $prefix,
            WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy('sequence')
                ->get()
                ->map->getRawOriginal()
                ->all()
        );
        $update = $stub->submitUpdate('approve', true, 'cold-profile');
        $this->assertTrue($update->accepted());
        $this->workOnce();
        $this->assertTrue($stub->inspectUpdate($update->updateId())->completed());
        $this->assertSame([
            'stage' => 'waiting-for-name',
            'approved' => true,
            'events' => ['started', 'approved:yes:cold-profile'],
        ], $stub->query('currentState'));
        $this->assertTrue($stub->signal('name-provided', 'λ')->accepted());
        for ($jobs = 0; $jobs < 8 && DB::table('jobs')->exists(); ++$jobs) {
            $this->workOnce();
        }
        $this->assertSame('completed', $stub->refresh()->status(), Artisan::output());
        $this->assertTrue($stub->output()['approved']);
        $this->assertSame(['started', 'approved:yes:cold-profile', 'signal:λ'], $stub->output()['events']);
        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy('sequence')->get();
        $this->assertSame($prefix, $events->take(count($prefix))->map->getRawOriginal()->all());
        foreach ([HistoryEventType::UpdateAccepted, HistoryEventType::UpdateCompleted,
            HistoryEventType::SignalApplied, HistoryEventType::WorkflowCompleted] as $type) {
            $this->assertCount(1, $events->where('event_type', $type));
        }
        $this->assertSame($deadlines, [$run->fresh()->getRawOriginal('execution_deadline_at'),
            $run->fresh()
                ->getRawOriginal('run_deadline_at')]);
        $this->assertSame(0, app(WorkflowContinuationCache::class)->snapshot()['entries']);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public static function terminalCommands(): iterable
    {
        foreach ([
            'cancel' => 'cancelled',
            'terminate' => 'terminated',
        ] as $command => $status) {
            foreach ([false, true] as $enabled) {
                yield $command . ($enabled ? ' retained' : ' cold') => [$enabled, $command, $status];
            }
        }
    }

    #[DataProvider('terminalCommands')]
    public function testExternalTerminalCommandsNeverResumeTheRetainedFrame(
        bool $enabled,
        string $command,
        string $status,
    ): void {
        config()->set('workflows.v2.live_execution', [
            'workflows' => $enabled ? [ContinuationWorkflow::class] : [],
            'build' => 'terminal-authority',
        ]);
        $stub = WorkflowStub::make(ContinuationWorkflow::class, 'terminal-' . $command, 'continuation-test');
        $stub->start(new StartOptions(executionTimeoutSeconds: 120, runTimeoutSeconds: 90));
        $this->workOnce();
        $this->workOnce();
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $deadlines = [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')];
        $this->assertSame($enabled ? 1 : 0, app(WorkflowContinuationCache::class)->snapshot()['entries']);
        $prefix = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy('sequence')
            ->get()
            ->map->getRawOriginal()
            ->all();
        $this->assertTrue($stub->{$command}('original terminal command')->accepted());
        for ($jobs = 0; $jobs < 8 && DB::table('jobs')->exists(); ++$jobs) {
            $this->workOnce();
        }
        $this->assertSame($status, $stub->refresh()->status());
        $this->assertSame(1, ContinuationWorkflow::$entries);
        $this->assertSame(0, ContinuationWorkflow::$afterActivity);
        $this->assertSame(1, ContinuationActivity::$calls);
        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy('sequence')->get();
        $this->assertSame($prefix, $events->take(count($prefix))->map->getRawOriginal()->all());
        $this->assertCount(0, $events->where('event_type', HistoryEventType::TimerScheduled));
        $this->assertCount(0, $events->where('event_type', HistoryEventType::WorkflowCompleted));
        $this->assertSame($deadlines, [$run->fresh()->getRawOriginal('execution_deadline_at'),
            $run->fresh()
                ->getRawOriginal('run_deadline_at')]);
        $this->app->make('queue.worker')
            ->stop();
        $this->assertSame(0, app(WorkflowContinuationCache::class)->snapshot()['entries']);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public static function injectedWorkflows(): iterable
    {
        foreach ([ContinuationConstructorWorkflow::class, ContinuationMethodWorkflow::class] as $class) {
            foreach ([false, true] as $enabled) {
                yield $class . ($enabled ? ' configured' : ' cold') => [$enabled, $class];
            }
        }
    }

    /**
     * @param class-string<Workflow> $class
     */
    #[DataProvider('injectedWorkflows')]
    public function testScopedDependenciesAreRebuiltAcrossColdJobBoundaries(bool $enabled, string $class): void
    {
        config()->set('workflows.v2.live_execution', [
            'workflows' => $enabled ? [$class] : [],
            'build' => 'scoped-dependencies',
        ]);
        config()
            ->set('continuation_test.scope', 'first-job');
        $this->app->scoped(
            ContinuationScopedDependency::class,
            static fn () => new ContinuationScopedDependency(config('continuation_test.scope'))
        );
        $stub = WorkflowStub::make($class, 'scoped', 'continuation-test');
        $stub->start();
        $this->workOnce();
        $this->assertSame(0, app(WorkflowContinuationCache::class)->snapshot()['entries']);
        $this->app->forgetScopedInstances();
        config()
            ->set('continuation_test.scope', 'second-job');
        $this->workOnce();
        $this->workOnce();
        $this->assertSame('completed', $stub->refresh()->status(), Artisan::output());
        $this->assertSame([
            'scope' => 'second-job',
            'value' =>
                        [
                            'integer' => 9007199254740993,
                            'optional' => null,
                            'unicode' => 'λ',
                        ],
        ], $stub->output());
        $this->assertSame(1, ContinuationActivity::$calls);
        $this->assertSame(0, app(WorkflowContinuationCache::class)->snapshot()['entries']);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        if ($enabled) {
            $this->assertGreaterThan(
                0,
                app(WorkflowContinuationCache::class)->snapshot()['counts']['cold_profile'] ?? 0
            );
        }
    }

    #[DataProvider('modelProfiles')]
    public function testFailedActivityRedriveDoesNotRepeatTheCommittedPrefix(bool $enabled): void
    {
        ContinuationRedriveActivity::$calls = 0;
        config()
            ->set('workflows.v2.live_execution', [
                'workflows' => $enabled ? [ContinuationRedriveWorkflow::class] : [],
                'build' => 'redrive-original-prefix',
            ]);
        $stub = WorkflowStub::make(ContinuationRedriveWorkflow::class, 'redrive', 'continuation-test');
        $stub->start(new StartOptions(executionTimeoutSeconds: 120, runTimeoutSeconds: 90));
        $this->workOnce();
        $this->workOnce();
        $this->assertSame($enabled ? 1 : 0, app(WorkflowContinuationCache::class)->snapshot()['entries']);
        $this->workOnce();
        $this->workOnce();
        $this->workOnce();
        $this->assertSame('failed', $stub->refresh()->status(), Artisan::output());
        $source = WorkflowRun::query()->findOrFail($stub->runId());
        $deadlines = [$source->getRawOriginal('execution_deadline_at'), $source->getRawOriginal('run_deadline_at')];
        $prefix = WorkflowHistoryEvent::query()->where('workflow_run_id', $source->id)->orderBy('sequence')
            ->get()
            ->map->getRawOriginal()
            ->all();
        $this->assertSame(1, ContinuationActivity::$calls);
        $this->assertSame(1, ContinuationRedriveActivity::$calls);
        $this->assertSame(0, app(WorkflowContinuationCache::class)->snapshot()['entries']);
        $controlPlane = app(WorkflowControlPlane::class);
        $options = [
            'namespace' => 'continuation-test',
            'request_id' => 'redrive-once',
        ];
        $redrive = $controlPlane->redrive('redrive', $source->id, $options);
        $this->assertTrue($redrive['accepted'], (string) $redrive['reason']);
        $this->assertNotSame($source->id, $redrive['workflow_run_id']);
        $this->assertSame($source->id, $redrive['continued_from_run_id']);
        $this->assertSame(2, $redrive['resume_step_sequence']);
        $this->assertSame(
            $redrive['workflow_run_id'],
            $controlPlane->redrive('redrive', $source->id, $options)['workflow_run_id']
        );
        for ($jobs = 0; $jobs < 8 && DB::table('jobs')->exists(); ++$jobs) {
            $this->workOnce();
        }
        $this->assertSame('completed', $stub->refresh()->status(), Artisan::output());
        $this->assertSame([
            'integer' => 9007199254740993,
            'optional' => null,
            'unicode' => 'λ',
        ], $stub->output());
        $this->assertSame(1, ContinuationActivity::$calls);
        $this->assertSame(2, ContinuationRedriveActivity::$calls);
        $this->assertSame(2, WorkflowRun::query()->count());
        $this->assertSame('failed', $source->fresh()->status->value);
        $this->assertSame(
            $prefix,
            WorkflowHistoryEvent::query()->where('workflow_run_id', $source->id)->orderBy('sequence')
                ->get()
                ->map->getRawOriginal()
                ->all()
        );
        $this->assertSame($deadlines, [$source->fresh()->getRawOriginal('execution_deadline_at'),
            $source->fresh()
                ->getRawOriginal('run_deadline_at')]);
        $successor = WorkflowRun::query()->findOrFail($redrive['workflow_run_id']);
        $this->assertSame($deadlines[0], $successor->getRawOriginal('execution_deadline_at'));
        $this->assertSame([0], $successor->tasks()->pluck('repair_count')->unique()->values()->all());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, app(WorkflowContinuationCache::class)->snapshot()['entries']);
    }

    protected function getPackageProviders($app): array
    {
        return [TestDatabaseServiceProvider::class, WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'continuation');
        $app['config']->set('database.connections.continuation', [
            'driver' => 'sqlite',
            'database' => $this->path,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 1000,
        ]);
        $app['config']->set('workflows.storage.connection', 'continuation');
        $app['config']->set('workflows.v1.enabled', false);
        $app['config']->set('workflows.v2.task_dispatch_mode', 'queue');
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'continuation',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ]);
        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => 'continuation',
            'table' => 'failed_jobs',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'null');
    }

    private function enable(): void
    {
        config()->set('workflows.v2.live_execution', [
            'workflows' => [ContinuationWorkflow::class],
            'build' => 'continuation-test-build',
        ]);
    }

    private function workOnce(): void
    {
        $this->app->make('queue.worker')
            ->runNextJob('database', 'default', new WorkerOptions(sleep: 0, timeout: 30));
        $this->assertSame(0, DB::transactionLevel());
    }

    private function registerNexusService(int &$starts): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'namespace' => 'service',
            'workflow_type' => 'nexus.service',
            'workflow_class' => 'nexus.service',
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'namespace' => 'service',
            'run_number' => 1,
            'workflow_type' => 'nexus.service',
            'workflow_class' => 'nexus.service',
            'status' => 'waiting',
            'payload_codec' => 'avro',
            'arguments' => Serializer::serializeWithCodec('avro', []),
            'connection' => 'sync',
            'queue' => 'default',
            'started_at' => now(),
        ]);
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'service',
            'endpoint_name' => 'php-service',
        ]);
        $service = WorkflowService::query()->create([
            'namespace' => 'service',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'greeter',
        ]);
        WorkflowServiceOperation::query()->create([
            'namespace' => 'service',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'operation_name' => 'greet',
            'operation_mode' => 'async',
            'handler_binding_kind' => 'workflow_run',
            'handler_target_reference' => 'nexus.service',
        ]);
        $control = Mockery::mock(WorkflowControlPlane::class);
        $control->shouldReceive('start')
            ->once()
            ->andReturnUsing(function () use ($run, &$starts): array {
                $this->assertSame([2], array_values(ContinuationFallbackWorkflow::$entries));
                ++$starts;
                return [
                    'started' => true,
                    'workflow_instance_id' => $run->workflow_instance_id,
                    'workflow_run_id' => $run->id,
                ];
            });
        $this->app->instance(ServiceControlPlane::class, new DefaultServiceControlPlane($control));
        return $run;
    }
}

#[Type('continuation-workflow')]
final class ContinuationWorkflow extends Workflow
{
    public static int $entries = 0;

    public static int $afterActivity = 0;

    public function handle(): array
    {
        ++self::$entries;
        $value = Workflow::activity(ContinuationActivity::class);
        ++self::$afterActivity;
        Workflow::timer(1);

        return $value;
    }
}

#[Type('continuation-activity')]
final class ContinuationActivity extends Activity
{
    public static int $calls = 0;

    public function handle(): array
    {
        ++self::$calls;

        return [
            'integer' => 9007199254740993,
            'optional' => null,
            'unicode' => 'λ',
        ];
    }
}

final class ContinuationEntryWorkflow extends Workflow
{
    public function handle(int $amount): int
    {
        Workflow::timer(1);

        return $amount;
    }
}

final class ContinuationIncompatibleEntry extends Workflow
{
    public static int $entries = 0;

    public function handle(string $amount): string
    {
        ++self::$entries;
        Workflow::timer(1);

        return $amount;
    }
}

final class ContinuationEntryBodyFailure extends Workflow
{
    public static int $entries = 0;

    public function handle(string $failure): never
    {
        ++self::$entries;
        Workflow::timer(1);

        if ($failure === 'internal-mismatch') {
            throw new WorkflowEntryArgumentsMismatchException(self::class, 'handle', 'application_body_reason');
        }

        throw new \TypeError('Application body failure.');
    }
}

final class ContinuationRedriveWorkflow extends Workflow
{
    public function handle(): array
    {
        $value = Workflow::activity(ContinuationActivity::class);

        return Workflow::activity(ContinuationRedriveActivity::class, $value);
    }
}

final class ContinuationRedriveActivity extends Activity
{
    public static int $calls = 0;

    public function handle(array $value): array
    {
        if (++self::$calls === 1) {
            throw new NonRetryableException('Original redrive failure.');
        }

        return $value;
    }
}

final class ContinuationScopedDependency
{
    public function __construct(
        public readonly string $scope
    ) {
    }
}

final class ContinuationConstructorWorkflow extends Workflow
{
    public function __construct(
        private readonly ContinuationScopedDependency $dependency
    ) {
    }

    public function handle(): array
    {
        $value = Workflow::activity(ContinuationActivity::class);

        return [
            'scope' => $this->dependency->scope,
            'value' => $value,
        ];
    }
}

final class ContinuationMethodWorkflow extends Workflow
{
    public function handle(ContinuationScopedDependency $dependency): array
    {
        $value = Workflow::activity(ContinuationActivity::class);

        return [
            'scope' => $dependency->scope,
            'value' => $value,
        ];
    }
}

final class ContinuationConfiguredRun extends WorkflowRun
{
}

final class ContinuationFallbackWorkflow extends Workflow
{
    /**
     * @var array<string, int>
     */
    public static array $entries = [];

    public function handle(string $operation, ?array $continued = null): array
    {
        self::$entries[$this->run->id] = (self::$entries[$this->run->id] ?? 0) + 1;
        if ($continued !== null) {
            return $continued;
        }
        $value = Workflow::activity(ContinuationActivity::class);
        $result = match ($operation) {
            'parallel' => Workflow::all([
                static fn () => Workflow::timer(1),
                static fn () => Workflow::timer(1),
            ]),
            'selection' => $this->selection(),
            'child' => Workflow::child(ContinuationFallbackChild::class),
            'condition' => Workflow::await(static fn (): bool => true, 1, 'fallback-ready'),
            'continue' => Workflow::continueAsNew($operation, [
                'value' => $value,
                'operation' => 'continued',
            ]),
            'nexus' => $this->nexus(),
        };

        return [
            'value' => $value,
            'operation' => $result,
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function nexus(): array
    {
        $result = Workflow::serviceOperation('php-service', 'greeter', 'greet', ['world'], new ServiceOperationOptions(
            targetNamespace: 'service',
            modeOverride: 'sync',
            waitFor: 'completed',
        ));
        if (! $result instanceof ServiceOperationResult || ! is_array($result->responsePayload)) {
            throw new RuntimeException('Expected a completed Nexus response.');
        }
        return $result->responsePayload;
    }

    private function selection(): array
    {
        $selected = Workflow::select([
            'left' => static fn () => Workflow::timer(1),
            'right' => static fn () => Workflow::timer(1),
        ]);

        return [
            'key' => $selected->key,
            'winner' => $selected->result(),
            'remaining' => $selected->handles['right']->await(),
        ];
    }
}

final class ContinuationFallbackChild extends Workflow
{
    public function handle(): string
    {
        return 'child-value';
    }
}

final class ContinuationModelWorkflow extends Workflow
{
    public static int $entries = 0;

    public function handle(): array
    {
        ++self::$entries;
        Workflow::activity(ContinuationActivity::class);

        return [
            'model' => get_class($this->run),
        ];
    }
}

final class ContinuationMemoWorkflow extends Workflow
{
    public static int $entries = 0;

    public function handle(): array
    {
        ++self::$entries;
        $value = Workflow::activity(ContinuationActivity::class);
        Workflow::upsertMemo([
            'phase' => 'completed',
        ]);

        return $value;
    }
}

final class ScopedContinuationWorkflow extends Workflow
{
    /**
     * @var array<string, int>
     */
    public static array $entries = [];

    public function handle(string $tag): array
    {
        $bound = $this->run;
        self::$entries[$bound->id] = (self::$entries[$bound->id] ?? 0) + 1;
        $clocks = [Workflow::now()->toJSON()];
        Workflow::activity(ContinuationActivity::class);
        $clocks[] = Workflow::now()->toJSON();
        Workflow::timer(1);
        $clocks[] = Workflow::now()->toJSON();

        return [
            'tag' => $tag,
            'namespace' => $bound->namespace,
            'run' => $bound->id,
            'history_at_return' => $bound->last_history_sequence,
            'clocks' => $clocks,
        ];
    }
}

final class ContinuationConfiguredHistory extends WorkflowHistoryEvent
{
}

final class ContinuationConfiguredActivity extends \Workflow\V2\Models\ActivityExecution
{
}

final class ContinuationConfiguredTimer extends \Workflow\V2\Models\WorkflowTimer
{
}

final class ContinuationConfiguredTask extends WorkflowTask
{
}
