<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Process\Process;
use Tests\Fixtures\V2\TestContinuationProcessWorkflow;
use Tests\Support\ContinuationProcessEnvironment;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EmbeddedContinuationProcessTest extends TestCase
{
    private string $directory;

    private int $deadline;

    /**
     * @var list<Process>
     */
    private array $workers = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/dw-process-loss-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        touch($this->directory . '/database.sqlite');
        parent::setUp();
        $this->artisan('migrate', [
            '--force' => true,
        ])->run();
        DB::statement('CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,
            queue TEXT NOT NULL, payload TEXT NOT NULL, attempts INTEGER NOT NULL,
            reserved_at INTEGER, available_at INTEGER NOT NULL, created_at INTEGER NOT NULL)');
        DB::statement('CREATE TABLE failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL UNIQUE, connection TEXT NOT NULL, queue TEXT NOT NULL,
            payload TEXT NOT NULL, exception TEXT NOT NULL, failed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(0, 9);
            }
        }
        parent::tearDown();
        (new Filesystem())->deleteDirectory($this->directory);
    }

    public static function profiles(): array
    {
        return [
            'ordinary cold worker' => [false, 2, 3],
            'retained worker' => [true, 1, 2],
        ];
    }

    #[DataProvider('profiles')]
    public function testKilledWorkerRecoversTheSameClaimWithoutRepeatingCommittedEffects(
        bool $enabled,
        int $entriesBeforeKill,
        int $entriesAfterRepair,
    ): void {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('This process-loss control requires Linux SIGKILL.');
        }
        ContinuationProcessEnvironment::configure($this->app, $this->directory . '/database.sqlite', $enabled);
        $this->deadline = hrtime(true) + 30_000_000_000;
        $stub = WorkflowStub::make(TestContinuationProcessWorkflow::class, 'process-loss', 'process-loss');
        $stub->start(new StartOptions(executionTimeoutSeconds: 120, runTimeoutSeconds: 90), $this->directory);
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $deadlines = [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')];
        $checkpoint = $this->directory . '/checkpoint';
        $original = $this->worker($enabled, $checkpoint, 5);
        while (! is_file($checkpoint) && $original->isRunning()) {
            $this->remaining();
            $original->checkTimeout();
            usleep(10_000);
        }
        $this->assertFileExists($checkpoint, $this->diagnostic($original));
        $claim = json_decode(file_get_contents($checkpoint), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($original->getPid(), $claim['pid']);
        $this->assertSame(0, $claim['transaction_level']);
        $this->assertFalse($claim['pdo_transaction']);
        $this->assertSame($enabled ? 1 : 0, $claim['cache']['entries']);
        if ($enabled) {
            $this->assertGreaterThanOrEqual(1, $claim['cache']['counts']['hit'] ?? 0);
        }
        $this->assertCount($entriesBeforeKill, file($this->directory . '/entries'));
        $this->assertCount(1, file($this->directory . '/effects'));
        $task = WorkflowTask::query()->findOrFail($claim['task_id']);
        $this->assertSame('leased', $task->status->value);
        $this->assertSame(1, $task->attempt_count);
        $this->assertSame($claim['lease'], $task->getRawOriginal('lease_expires_at'));
        $prefix = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->get()
            ->map->getRawOriginal()
            ->all();

        $original->signal(9);
        while ($original->isRunning()) {
            $this->remaining();
            usleep(10_000);
        }
        $this->assertSame(9, $original->getTermSignal());
        while (now()->lt($task->lease_expires_at)) {
            $this->remaining();
            usleep(10_000);
        }
        $this->assertSame($claim['lease'], $task->fresh()->getRawOriginal('lease_expires_at'));
        do {
            $this->remaining();
            $repair = TaskWatchdog::runPass(runIds: [$run->id]);
            $this->assertSame([], $repair['existing_task_failures']);
            if ($repair['repaired_existing_tasks'] === 0) {
                usleep(100_000);
            }
        } while ($repair['repaired_existing_tasks'] === 0);
        $this->assertSame(1, $repair['repaired_existing_tasks'], json_encode([
            'report' => $repair,
            'task' => $task->fresh()
                ->getAttributes(),
            'now' => now()
                ->format('Y-m-d H:i:s.u'),
            'jobs' => DB::table('jobs')->get()->map(static fn ($job): array => [
                'id' => $job->id,
                'attempts' => $job->attempts,
                'reserved_at' => $job->reserved_at,
                'available_at' => $job->available_at,
            ])->all(),
        ], JSON_THROW_ON_ERROR));
        $this->assertSame(1, $task->fresh()->repair_count);
        $successor = $this->worker($enabled, '', 2);
        $successor->wait();
        $this->assertSame(0, $successor->getExitCode(), $this->diagnostic($successor));
        $this->assertSame('completed', $stub->refresh()->status());
        $this->assertSame([
            'integer' => 9007199254740993,
            'optional' => null,
            'unicode' => 'λ',
        ], $stub->output());
        $this->assertCount($entriesAfterRepair, file($this->directory . '/entries'));
        $this->assertCount(1, file($this->directory . '/effects'));
        $this->assertSame($deadlines, [
            $run->fresh()
                ->getRawOriginal('execution_deadline_at'),
            $run->fresh()
                ->getRawOriginal('run_deadline_at'),
        ]);
        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy('sequence')->get();
        $this->assertSame($prefix, $events->take(count($prefix))->map->getRawOriginal()->all());
        $this->assertCount(1, $events->where('event_type', HistoryEventType::ActivityCompleted));
        $this->assertCount(1, $events->where('event_type', HistoryEventType::WorkflowCompleted));
        $tasks = WorkflowTask::query()->where('workflow_run_id', $run->id)->get();
        $this->assertCount(5, $tasks);
        $this->assertSame(['completed'], $tasks->pluck('status')->map->value->unique()->values()->all());
        $this->assertSame(2, $task->fresh()->attempt_count);
        $this->assertSame(1, $tasks->sum('repair_count'));
        $this->assertSame(0, DB::table('jobs')->count());
        // The killed delivery's declared tries=1 refuses its broker retry after recovery.
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertStringContainsString($task->id, DB::table('failed_jobs')->sole()->payload);
        $this->assertStringContainsString('MaxAttemptsExceededException', DB::table('failed_jobs')->sole()->exception);
    }

    protected function getPackageProviders($app): array
    {
        return ContinuationProcessEnvironment::providers();
    }

    protected function defineEnvironment($app): void
    {
        ContinuationProcessEnvironment::configure($app, $this->directory . '/database.sqlite', false);
    }

    private function worker(bool $enabled, string $checkpoint, int $jobs): Process
    {
        $index = count($this->workers);
        $worker = new Process([
            PHP_BINARY, dirname(__DIR__, 3) . '/vendor/bin/phpunit',
            dirname(__DIR__, 2) . '/Support/ContinuationProcessWorkerTest.php', '--colors=never',
        ], dirname(__DIR__, 3), [
            'DWF_PROCESS_DATABASE' => $this->directory . '/database.sqlite',
            'DWF_PROCESS_ENABLED' => $enabled ? '1' : '0',
            'DWF_PROCESS_CHECKPOINT' => $checkpoint,
            'DWF_PROCESS_MAX_JOBS' => (string) $jobs,
            'APP_SERVICES_CACHE' => $this->directory . '/services-' . $index . '.php',
            'APP_PACKAGES_CACHE' => $this->directory . '/packages-' . $index . '.php',
            'XDEBUG_MODE' => 'off',
        ], timeout: $this->remaining());
        $this->workers[] = $worker;
        $worker->start();

        return $worker;
    }

    private function remaining(): float
    {
        $remaining = ($this->deadline - hrtime(true)) / 1_000_000_000;
        $this->assertGreaterThan(0, $remaining, 'The original 30-second process-loss observation expired.');

        return $remaining;
    }

    private function diagnostic(Process $worker): string
    {
        return substr($worker->getOutput() . $worker->getErrorOutput(), -8192);
    }
}
