<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Tests\Fixtures\V2\LeaseBlockingActivity;
use Tests\Fixtures\V2\LeaseBlockingWorkflow;
use Tests\Support\EmbeddedActivityLeaseEnvironment;
use Tests\Support\TestDatabaseServiceProvider;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class EmbeddedActivityLeaseMaintenanceTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'dw-embedded-lease-');
        parent::setUp();
        $this->artisan('migrate:fresh', [
            '--force' => true,
        ])->run();
        Schema::create('jobs', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')
                ->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('uuid')
                ->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')
                ->useCurrent();
        });
        LeaseBlockingActivity::$invocations = 0;
        LeaseBlockingActivity::$callbackPid = null;
        LeaseBlockingActivity::$recordProgress = false;
        LeaseBlockingActivity::$beforeWait = null;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($this->path . $suffix)) {
                unlink($this->path . $suffix);
            }
        }
    }

    public function testRuntimeMaintenanceDoesNotExtendTheRecordedActivityDeadline(): void
    {
        [$stub, $task, $execution] = $this->pendingActivity('embedded-business-deadline');
        $execution->forceFill([
            'retry_policy' => [
                'maximum_attempts' => 1,
                'start_to_close_timeout' => 2,
            ],
        ])->save();
        $this->workOnce();

        self::assertSame(1, LeaseBlockingActivity::$invocations);
        self::assertSame(TaskStatus::Leased, $task->fresh()->status);
        self::assertTrue($execution->fresh()->close_deadline_at->isPast());
        $this->assertNoPublishedOutcome($stub->runId());
    }

    public function testRuntimeMaintenanceDoesNotSupplyMissingApplicationHeartbeats(): void
    {
        [$stub, $task, $execution] = $this->pendingActivity('embedded-missing-progress');
        $execution->forceFill([
            'retry_policy' => [
                'maximum_attempts' => 1,
                'heartbeat_timeout' => 2,
            ],
        ])->save();
        $this->workOnce();

        self::assertSame(1, LeaseBlockingActivity::$invocations);
        self::assertSame(TaskStatus::Leased, $task->fresh()->status);
        self::assertNull($execution->fresh()->last_heartbeat_at);
        self::assertTrue($execution->fresh()->heartbeat_deadline_at->isPast());
        $this->assertNoPublishedOutcome($stub->runId());
    }

    public function testRealUserProgressCanContinueAlongsideRuntimeMaintenance(): void
    {
        [$stub, $task, $execution] = $this->pendingActivity('embedded-genuine-progress');
        $execution->forceFill([
            'retry_policy' => [
                'maximum_attempts' => 1,
                'heartbeat_timeout' => 2,
            ],
        ])->save();
        LeaseBlockingActivity::$recordProgress = true;
        $this->workOnce();
        $this->workOnce();

        self::assertSame(getmypid(), LeaseBlockingActivity::$callbackPid);
        self::assertSame(1, LeaseBlockingActivity::$invocations);
        self::assertSame(TaskStatus::Completed, $task->fresh()->status);
        self::assertSame([], WorkflowHistoryEvent::query()->where('workflow_run_id', $stub->runId())
            ->where('event_type', HistoryEventType::ActivityFailed)->pluck('payload')
            ->map(static fn (array $payload): array => [
                'type' => $payload['exception_class'] ?? null,
                'message' => $payload['message'] ?? null,
            ])->all());
        self::assertSame('completed', $stub->refresh()->status());
        self::assertSame(4, WorkflowHistoryEvent::query()->where('workflow_run_id', $stub->runId())
            ->where('event_type', HistoryEventType::ActivityHeartbeatRecorded)->count());
        self::assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $stub->runId())
            ->where('event_type', HistoryEventType::ActivityCompleted)->count());
        self::assertSame(0, DB::table('failed_jobs')->count());
    }

    public function testAStoppedMetadataProcessCannotAuthorizeTheInlineResult(): void
    {
        if (! function_exists('posix_kill') || ! is_file('/proc/self/task/' . getmypid() . '/children')) {
            self::markTestSkipped('This process-loss control observes its own Linux child process.');
        }
        [$stub, $task] = $this->pendingActivity('embedded-maintenance-lost', 0.1);
        $killed = false;
        LeaseBlockingActivity::$beforeWait = static function () use (&$killed): void {
            $children = preg_split('/\s+/', trim(file_get_contents('/proc/self/task/' . getmypid() . '/children')));
            foreach ($children as $child) {
                $command = @file_get_contents('/proc/' . $child . '/cmdline');
                if (is_string($command) && str_contains($command, '/embedded-activity-lease.php')) {
                    $killed = posix_kill((int) $child, 9);
                }
            }
        };
        $this->workOnce();

        self::assertTrue($killed);
        self::assertSame(1, LeaseBlockingActivity::$invocations);
        self::assertSame(TaskStatus::Leased, $task->fresh()->status);
        $this->assertNoPublishedOutcome($stub->runId());
    }

    public function testBriefMetadataContentionDoesNotLoseTheOriginalActivity(): void
    {
        [$stub, $task] = $this->pendingActivity('embedded-brief-writer');
        $checkpoint = $this->path . '.writer';
        $log = $this->path . '.writer.log';
        $locker = null;
        $environment = [
            'DW_EMBEDDED_LEASE_OWNER_DATABASE' => $this->path,
            'DW_EMBEDDED_LEASE_OWNER_CHECKPOINT' => $checkpoint,
            'DW_EMBEDDED_LEASE_LOCK_TASK' => $task->id,
        ];
        foreach (['DRIVER', 'HOST', 'PORT'] as $key) {
            $value = getenv('DW_EMBEDDED_LEASE_TEST_' . $key);
            if (is_string($value)) {
                $environment['DW_EMBEDDED_LEASE_TEST_' . $key] = $value;
            }
        }
        LeaseBlockingActivity::$beforeWait = static function () use (&$locker, $checkpoint, $log, $environment): void {
            $locker = proc_open(
                [PHP_BINARY, dirname(__DIR__, 3) . '/vendor/bin/phpunit',
                    dirname(__DIR__, 2) . '/Support/EmbeddedActivityLeaseWorkerTest.php', '--filter', 'testBriefWriter'],
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', $log, 'w'],
                    2 => ['file', $log, 'a'],
                ],
                $pipes,
                env_vars: $environment
            );
            $deadline = hrtime(true) + 5_000_000_000;
            while (! is_file($checkpoint) && hrtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($checkpoint, substr((string) file_get_contents($log), -2048));
        };
        try {
            $this->workOnce();
            if (is_resource($locker)) {
                self::assertSame(0, proc_close($locker));
                $locker = null;
            }
            self::assertSame(1, LeaseBlockingActivity::$invocations);
            self::assertSame(TaskStatus::Completed, $task->fresh()->status);
            self::assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $stub->runId())
                ->where('event_type', HistoryEventType::ActivityCompleted)->count());
            self::assertSame(0, DB::table('failed_jobs')->count());
        } finally {
            if (is_resource($locker)) {
                proc_terminate($locker, 9);
                proc_close($locker);
            }
            foreach ([$checkpoint, $log] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function testMaintenanceStopsWhenTheActualQueueWorkerIsKilled(): void
    {
        if (! function_exists('posix_kill') || ! is_file('/proc/self/task/' . getmypid() . '/children')) {
            self::markTestSkipped('This owner-death control observes its own Linux processes.');
        }
        [$stub, $task] = $this->pendingActivity('embedded-owner-killed');
        $checkpoint = $this->path . '.owner.json';
        $log = $this->path . '.owner.log';
        $environment = [
            'DW_EMBEDDED_LEASE_OWNER_DATABASE' => $this->path,
            'DW_EMBEDDED_LEASE_OWNER_CHECKPOINT' => $checkpoint,
        ];
        foreach (['DRIVER', 'HOST', 'PORT'] as $key) {
            $value = getenv('DW_EMBEDDED_LEASE_TEST_' . $key);
            if (is_string($value)) {
                $environment['DW_EMBEDDED_LEASE_TEST_' . $key] = $value;
            }
        }
        $owner = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/vendor/bin/phpunit',
                dirname(__DIR__, 2) . '/Support/EmbeddedActivityLeaseWorkerTest.php', '--filter', 'testOwner'],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $log, 'w'],
                2 => ['file', $log, 'a'],
            ],
            $pipes,
            env_vars: $environment
        );
        self::assertIsResource($owner);
        $helper = null;
        try {
            $deadline = hrtime(true) + 10_000_000_000;
            while (! is_file($checkpoint) && hrtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($checkpoint, substr((string) file_get_contents($log), -2048));
            $ready = json_decode(file_get_contents($checkpoint), true, flags: JSON_THROW_ON_ERROR);
            $helper = $ready['helper'];
            self::assertSame(proc_get_status($owner)['pid'], $ready['owner']);
            self::assertIsInt($helper);
            self::assertTrue(posix_kill($helper, 0));
            proc_terminate($owner, 9);
            proc_close($owner);
            $owner = null;
            $deadline = hrtime(true) + 3_000_000_000;
            while (posix_kill($helper, 0) && hrtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFalse(posix_kill($helper, 0), 'Metadata maintenance must stop with its actual callback owner.');
            $expiry = $task->fresh()
                ->getRawOriginal('lease_expires_at');
            usleep(1100000);
            self::assertSame($expiry, $task->fresh()->getRawOriginal('lease_expires_at'));
            self::assertSame(1, $task->fresh()->attempt_count);
            $this->assertNoPublishedOutcome($stub->runId());
        } finally {
            if (is_resource($owner)) {
                proc_terminate($owner, 9);
                proc_close($owner);
            }
            if (is_int($helper) && posix_kill($helper, 0)) {
                posix_kill($helper, 9);
            }
            foreach ([$checkpoint, $log] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function testOrdinaryInlineActivityOutlastsItsLeaseAndCompletesExactlyOnce(): void
    {
        $stub = WorkflowStub::make(LeaseBlockingWorkflow::class, 'embedded-long-activity', 'lease-tenant');
        $stub->start(4.2);
        $this->workOnce();
        $task = WorkflowTask::query()->where('workflow_run_id', $stub->runId())
            ->where('task_type', 'activity')
            ->sole();
        $prefix = WorkflowHistoryEvent::query()->where('workflow_run_id', $stub->runId())
            ->orderBy('sequence')
            ->get()
            ->map->getAttributes()
            ->all();
        $this->workOnce();

        self::assertSame(getmypid(), LeaseBlockingActivity::$callbackPid);
        self::assertSame(1, LeaseBlockingActivity::$invocations);
        self::assertSame(
            TaskStatus::Completed,
            $task->fresh()
->status,
            'The original callback must publish once after exceeding its runtime lease.'
        );
        $this->workOnce();
        self::assertSame('completed', $stub->refresh()->status());
        self::assertSame([
            'integer' => 9007199254740993,
            'optional' => null,
            'unicode' => 'λ',
        ], $stub->output());
        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $stub->runId())->orderBy('sequence')->get();
        self::assertSame($prefix, $events->take(count($prefix))->map->getAttributes()->all());
        self::assertSame(1, $events->where('event_type', HistoryEventType::ActivityStarted)->count());
        self::assertSame(1, $events->where('event_type', HistoryEventType::ActivityCompleted)->count());
        self::assertSame(0, $events->where('event_type', HistoryEventType::ActivityHeartbeatRecorded)->count());
        self::assertSame(0, WorkflowTask::query()->where('workflow_run_id', $stub->runId())->sum('repair_count'));
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, DB::table('failed_jobs')->count());
    }

    protected function getPackageProviders($app): array
    {
        return [TestDatabaseServiceProvider::class, WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        EmbeddedActivityLeaseEnvironment::configure($app, $this->path);
    }

    /**
     * @return array{WorkflowStub, WorkflowTask, ActivityExecution}
     */
    private function pendingActivity(string $id, float $seconds = 4.2): array
    {
        $stub = WorkflowStub::make(LeaseBlockingWorkflow::class, $id, 'lease-tenant');
        $stub->start($seconds);
        $this->workOnce();
        $task = WorkflowTask::query()->where('workflow_run_id', $stub->runId())
            ->where('task_type', 'activity')
            ->sole();
        $execution = ActivityExecution::query()->where('workflow_run_id', $stub->runId())->sole();

        return [$stub, $task, $execution];
    }

    private function assertNoPublishedOutcome(string $runId): void
    {
        self::assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->whereIn('event_type', [HistoryEventType::ActivityCompleted, HistoryEventType::ActivityFailed])->count());
        self::assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ActivityHeartbeatRecorded)->count());
        self::assertSame(0, WorkflowTask::query()->where('workflow_run_id', $runId)->sum('repair_count'));
    }

    private function workOnce(): void
    {
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'default',
            '--once' => true,
            '--tries' => 1,
            '--timeout' => 30,
        ]);
    }
}
