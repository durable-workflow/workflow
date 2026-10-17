<?php

declare(strict_types=1);

namespace Tests\Performance;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Process\Process;
use Tests\Fixtures\V2\TestContinuationGrowthWorkflow;
use Tests\Support\ContinuationGrowthEnvironment;
use Throwable;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EmbeddedContinuationGrowthProfileTest extends TestCase
{
    private string $directory;

    /**
     * @var list<Process>
     */
    private array $workers = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/dw-growth-' . bin2hex(random_bytes(8));
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
        DB::statement('CREATE TABLE continuation_effects (id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id TEXT NOT NULL, activity_id TEXT NOT NULL, round INTEGER NOT NULL)');
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

    public static function workloads(): iterable
    {
        $setup = getenv('PROFILE_EMBEDDED_CONTINUATION') === 'setup';
        foreach ($setup ? [1] : [1, 19, 199] as $rounds) {
            foreach ([1, 4] as $workers) {
                foreach ([1, 8] as $concurrent) {
                    foreach ($setup ? [1] : [1, 2, 3] as $repetition) {
                        foreach ($repetition % 2 === 0 ? [true, false] : [false, true] as $retained) {
                            yield sprintf(
                                '%d-events_%d-workers_%d-runs_%s_repeat-%d',
                                5 * $rounds + 5,
                                $workers,
                                $concurrent,
                                $retained ? 'retained' : 'replay',
                                $repetition
                            )
                                => [$rounds, $workers, $concurrent, $retained, $repetition];
                        }
                    }
                }
            }
        }
    }

    #[DataProvider('workloads')]
    public function testActualQueueGrowth(
        int $rounds,
        int $workerCount,
        int $concurrent,
        bool $retained,
        int $repetition,
    ): void {
        $mode = getenv('PROFILE_EMBEDDED_CONTINUATION');
        if (! in_array($mode, ['setup', 'measure'], true)) {
            $this->markTestSkipped('Explicit isolated growing-history qualification only.');
        }
        $budget = $mode === 'setup' ? ($concurrent === 1 ? 30 : 120) : 3600;
        $limits = [
            'cpu_max' => trim((string) @file_get_contents('/sys/fs/cgroup/cpu.max')),
            'memory_max' => trim((string) @file_get_contents('/sys/fs/cgroup/memory.max')),
            'swap_max' => trim((string) @file_get_contents('/sys/fs/cgroup/memory.swap.max')),
        ];
        if ($mode === 'measure') {
            $this->assertSame('1073741824', $limits['memory_max']);
            $this->assertSame('0', $limits['swap_max']);
            $this->assertSame('100000 100000', $limits['cpu_max']);
            $this->assertMatchesRegularExpression(
                '/^[0-9]+$/D',
                trim((string) @file_get_contents('/sys/fs/cgroup/memory.peak')),
                'Qualified aggregate peak memory requires the cgroup peak counter.'
            );
        }
        $boot = hrtime(true);
        for ($index = 0; $index < $workerCount; ++$index) {
            $this->workers[] = $this->startWorker($index, $retained, $workerCount, $budget);
        }
        $startupDeadline = hrtime(true) + 30_000_000_000;
        while (count(glob($this->directory . '/worker-*.ready')) !== $workerCount) {
            $this->assertWorkersRunning();
            $this->assertLessThan($startupDeadline, hrtime(true), 'Original 30-second startup bound elapsed.');
            usleep(50_000);
        }
        $startupWall = hrtime(true) - $boot;
        $ready = [];
        foreach ($this->workers as $index => $worker) {
            $ready[] = $this->readReport('/worker-' . $index . '.ready');
            $this->assertSame($worker->getPid(), $ready[$index]['pid']);
            $this->assertSame(ContinuationGrowthEnvironment::loadedSources(), $ready[$index]['loaded_sources']);
            $this->assertSame(
                hash_file('sha256', dirname(__DIR__, 2) . '/composer.lock'),
                $ready[$index]['lock_sha256']
            );
        }
        $deadline = hrtime(true) + $budget * 1_000_000_000;
        $originals = [];
        for ($index = 0; $index < $concurrent; ++$index) {
            $stub = WorkflowStub::make(TestContinuationGrowthWorkflow::class, 'growth-' . $index, 'growth');
            $stub->start(new StartOptions(executionTimeoutSeconds: $budget, runTimeoutSeconds: $budget), $rounds);
            $run = WorkflowRun::query()->findOrFail($stub->runId());
            $originals[] = [
                'stub' => $stub,
                'run' => $run,
                'deadlines' => [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')],
                'prefix' => $run->historyEvents()
                    ->orderBy('sequence')
                    ->get()
                    ->map->getRawOriginal()
                    ->all(),
            ];
        }
        $started = hrtime(true);
        $cgroupCpu = $this->cgroupCpu();
        touch($this->directory . '/start');
        try {
            while (count(glob($this->directory . '/completed-*')) !== $concurrent) {
                $this->assertWorkersRunning();
                $this->assertLessThan($deadline, hrtime(true), 'Original cohort observation budget elapsed.');
                usleep(50_000);
            }
            touch($this->directory . '/stop');
            $shutdownDeadline = hrtime(true) + 15_000_000_000;
            while (array_filter($this->workers, static fn (Process $worker): bool => $worker->isRunning()) !== []) {
                foreach ($this->workers as $worker) {
                    $worker->checkTimeout();
                }
                $this->assertLessThan(
                    $shutdownDeadline,
                    hrtime(true),
                    'Original 15-second graceful drain bound elapsed.'
                );
                usleep(50_000);
            }
        } catch (Throwable $failure) {
            echo 'CONTINUATION_GROWTH_FAILED=' . json_encode([
                'rounds' => $rounds,
                'workers' => $workerCount,
                'runs' => $concurrent,
                'retained' => $retained,
                'completed_markers' => count(glob($this->directory . '/completed-*')),
                'unfinished' => $concurrent - count(glob($this->directory . '/completed-*')),
                'elapsed_ns' => hrtime(true) - $started,
                'message' => $failure->getMessage(),
            ], JSON_THROW_ON_ERROR) . PHP_EOL;
            throw $failure;
        }
        $elapsed = hrtime(true) - $started;
        $cgroupCpu = $this->cgroupCpu() - $cgroupCpu;
        $reports = [];
        foreach ($this->workers as $index => $worker) {
            $this->assertSame(0, $worker->getExitCode(), $this->diagnostic($worker));
            $reports[] = $this->readReport('/worker-' . $index . '.json');
            $this->assertSame(0, $reports[$index]['exit']);
            $this->assertSame(0, $reports[$index]['cache']['entries']);
            $this->assertLessThanOrEqual(4, $reports[$index]['observed_peak_entries']);
        }
        $audit = [];
        foreach ($originals as $original) {
            $run = $original['run']->fresh();
            $stub = $original['stub']->refresh();
            $this->assertSame('completed', $stub->status());
            $output = $stub->output();
            $this->assertSame($rounds, $output['rounds']);
            $this->assertSame([
                'integer' => 9007199254740993,
                'optional' => null,
                'unicode' => 'λ',
            ], $output['value']);
            $events = $run->historyEvents()
                ->orderBy('sequence')
                ->get();
            $this->assertCount(5 * $rounds + 5, $events);
            $this->assertSame(range(1, count($events)), $events->pluck('sequence')->all());
            $this->assertSame(
                $original['prefix'],
                $events->take(count($original['prefix']))->map->getRawOriginal()->all()
            );
            $this->assertSame($original['deadlines'], [$run->getRawOriginal('execution_deadline_at'),
                $run->getRawOriginal('run_deadline_at')]);
            $clocks = [$run->started_at->toJSON(), ...$events->whereIn('event_type', [
                HistoryEventType::ActivityCompleted, HistoryEventType::TimerFired,
            ])->map->recorded_at->map->toJSON()
                ->values()
                ->all()];
            $this->assertSame($clocks, $output['clocks']);
            $effects = DB::table('continuation_effects')->where('run_id', $run->id)->orderBy('round')->get();
            $this->assertCount($rounds, $effects);
            $this->assertSame(range(1, $rounds), $effects->pluck('round')->all());
            $this->assertCount($rounds, $effects->pluck('activity_id')->unique());
            $this->assertSame(ActivityExecution::query()->where('workflow_run_id', $run->id)
                ->orderBy('sequence')
                ->pluck('id')
                ->all(), $effects->pluck('activity_id')
                ->all());
            $this->assertSame(4 * $rounds + 3, $run->tasks()->where('status', 'completed')->count());
            $this->assertSame([0], $run->tasks()->pluck('repair_count')->unique()->values()->all());
            $this->assertSame([1], ActivityExecution::query()->where('workflow_run_id', $run->id)
                ->pluck('attempt_count')
                ->unique()
                ->values()
                ->all());
            $audit[] = [
                'run_id' => $run->id,
                'events' => count($events),
                'effects' => count($effects),
                'output_sha256' => hash('sha256', json_encode($output, JSON_THROW_ON_ERROR)),
                'deadlines' => $original['deadlines'],
            ];
        }
        $tasks = WorkflowTask::query()->get();
        $this->assertSame(['completed'], $tasks->pluck('status')->map->value->unique()->values()->all());
        $this->assertSame(
            (int) $tasks->sum('attempt_count'),
            array_sum(array_column(array_column($reports, 'task_queue_delay'), 'count'))
        );
        $this->assertSame(
            ($rounds + 1) * $concurrent,
            array_sum(array_column(array_column($reports, 'timer_lateness'), 'count'))
        );
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        if (! $retained) {
            $this->assertSame((2 * $rounds + 2) * $concurrent, array_sum(array_column($reports, 'entries')));
        }
        $peakMemory = @file_get_contents('/sys/fs/cgroup/memory.peak');
        echo 'CONTINUATION_GROWTH_REPORT=' . json_encode([
            'schema' => 'embedded-continuation-growth-v1',
            'mode' => $mode,
            'events_per_run' => 5 * $rounds + 5,
            'rounds' => $rounds,
            'worker_count' => $workerCount,
            'concurrent_runs' => $concurrent,
            'retained' => $retained,
            'repetition' => $repetition,
            'original_budget_seconds' => $budget,
            'limits' => $limits,
            'startup_wall_ns' => $startupWall,
            'worker_ready' => $ready,
            'workload_wall_ns' => $elapsed,
            'cgroup_workload_cpu_us' => $cgroupCpu,
            'cgroup_peak_memory_bytes' => $peakMemory === false ? null : (int) trim($peakMemory),
            'worker_reports' => $reports,
            'audit' => $audit,
            'errors' => 0,
            'unfinished' => 0,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    protected function getPackageProviders($app): array
    {
        return ContinuationGrowthEnvironment::providers();
    }

    protected function defineEnvironment($app): void
    {
        ContinuationGrowthEnvironment::configure($app, $this->directory, false);
    }

    private function startWorker(int $index, bool $retained, int $workers, int $budget): Process
    {
        $worker = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/vendor/bin/phpunit',
            dirname(__DIR__) . '/Support/ContinuationGrowthWorkerTest.php',
            '--filter', 'testDaemon',
            '--cache-directory', $this->directory . '/phpunit-' . $index], dirname(__DIR__, 2), [
                'DWF_GROWTH_DIRECTORY' => $this->directory,
                'DWF_GROWTH_WORKER' => (string) $index,
                'DWF_GROWTH_RETAINED' => $retained ? '1' : '0',
                'DWF_GROWTH_BUDGET' => (string) $budget,
                'DWF_GROWTH_MEMORY' => (string) intdiv(1024, $workers),
                'XDEBUG_MODE' => 'off',
                'APP_SERVICES_CACHE' => $this->directory . '/services-' . $index . '.php',
                'APP_PACKAGES_CACHE' => $this->directory . '/packages-' . $index . '.php',
            ]);
        $worker->setTimeout($budget + 45);
        $worker->start();

        return $worker;
    }

    private function assertWorkersRunning(): void
    {
        foreach ($this->workers as $worker) {
            $worker->checkTimeout();
            $this->assertTrue($worker->isRunning(), $this->diagnostic($worker));
        }
    }

    private function diagnostic(Process $worker): string
    {
        return substr($worker->getOutput(), -4096) . substr($worker->getErrorOutput(), -4096);
    }

    private function readReport(string $suffix): array
    {
        return json_decode(file_get_contents($this->directory . $suffix), true, flags: JSON_THROW_ON_ERROR);
    }

    private function cgroupCpu(): int
    {
        $stat = (string) @file_get_contents('/sys/fs/cgroup/cpu.stat');

        return preg_match('/^usage_usec ([0-9]+)$/m', $stat, $matches) === 1 ? (int) $matches[1] : 0;
    }
}
