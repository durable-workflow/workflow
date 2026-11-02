<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Tests\Fixtures\V2\TestContinuationGrowthWorkflow;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\DefaultHistoryProjectionRole;
use Workflow\V2\Support\WorkflowContinuationCache;
use Workflow\V2\Support\WorkflowExecutionProfile;

/** Actual worker application; it opens the parent's existing database without migrating it. */
final class ContinuationGrowthWorkerTest extends TestCase
{
    public function testDaemon(): void
    {
        $directory = getenv('DWF_GROWTH_DIRECTORY');
        if (! is_string($directory) || $directory === '') {
            $this->markTestSkipped('Invoked by EmbeddedContinuationGrowthProfileTest.');
        }
        $reportPath = $directory . '/worker-' . (int) getenv('DWF_GROWTH_WORKER');
        $loaded = ContinuationGrowthEnvironment::loadedSources();
        $sqlite = DB::selectOne('SELECT sqlite_version() AS version')->version;
        $async = function_exists('pcntl_async_signals') && function_exists('pcntl_signal');
        $observers = getenv('DWF_GROWTH_OBSERVERS') !== 'off';
        if (getenv('PROFILE_EMBEDDED_CONTINUATION') === 'measure') {
            $this->assertTrue($async, 'Qualified measurements require the actual worker timeout/signal path.');
        }
        $startupCpu = ContinuationGrowthEnvironment::cpuMicroseconds();
        $maintenance = new ContinuationTaskMaintenance();
        WorkflowTask::updated($maintenance->observe(...));
        $profile = $observers ? new WorkflowExecutionProfile() : null;
        if ($profile !== null) {
            $delegate = app(HistoryProjectionRole::class);
            $this->assertInstanceOf(DefaultHistoryProjectionRole::class, $delegate);
            $this->app->instance(HistoryProjectionRole::class, new ContinuationHistoryProfile($delegate));
        }
        ContinuationGrowthEnvironment::writeReport($reportPath . '.ready', [
            'pid' => getmypid(),
            'startup_cpu_us' => $startupCpu,
            'loaded_sources' => $loaded,
            'async_signals' => $async,
            'observers_enabled' => $observers,
            'php' => PHP_VERSION,
            'laravel' => $this->app->version(),
            'sqlite' => $sqlite,
            'lock_sha256' => hash_file('sha256', dirname(__DIR__, 2) . '/composer.lock'),
        ]);
        while (! is_file($directory . '/start')) {
            usleep(10_000);
        }
        $peakEntries = $observers ? 0 : null;
        $this->app['events']->listen(Looping::class, function () use ($directory, $observers, &$peakEntries): void {
            if ($observers) {
                $peakEntries = max($peakEntries, app(WorkflowContinuationCache::class)->snapshot()['entries']);
            }
            if (is_file($directory . '/stop')) {
                $worker = $this->app->make('queue.worker');
                $worker->shouldQuit = true;
            }
        });
        WorkflowRun::updated(static function (WorkflowRun $run) use ($directory): void {
            if ($run->status === RunStatus::Completed && $run->wasChanged('status')) {
                $run->getConnection()
                    ->afterCommit(static fn () => touch($directory . '/completed-' . $run->id));
            }
        });
        $claims = [];
        $timers = [];
        $queries = 0;
        $queryMilliseconds = 0.0;
        $historySelects = 0;
        $historyMilliseconds = 0.0;
        if ($observers) {
            $historyTable = (new WorkflowHistoryEvent())->getTable();
            WorkflowTask::updated(static function (WorkflowTask $task) use (&$claims): void {
                if ($task->status === TaskStatus::Leased && $task->wasChanged('status')
                    && $task->available_at !== null && $task->leased_at !== null) {
                    $claims[$task->id . ':' . $task->attempt_count] = $task->available_at->diffInMicroseconds(
                        $task->leased_at,
                        false
                    );
                }
            });
            WorkflowTimer::updated(static function (WorkflowTimer $timer) use (&$timers): void {
                if ($timer->status === TimerStatus::Fired && $timer->wasChanged('status')) {
                    $timers[$timer->id] = $timer->fire_at->diffInMicroseconds($timer->fired_at, false);
                }
            });
            DB::listen(static function (QueryExecuted $query) use (
                &$queries,
                &$queryMilliseconds,
                &$historySelects,
                &$historyMilliseconds,
                $historyTable
            ): void {
                ++$queries;
                $queryMilliseconds += $query->time;
                if (strncasecmp(ltrim($query->sql), 'select', 6) === 0
                    && str_contains($query->sql, $historyTable)) {
                    ++$historySelects;
                    $historyMilliseconds += $query->time;
                }
            });
        }
        TestContinuationGrowthWorkflow::$entries = 0;
        TestContinuationGrowthWorkflow::$roundVisits = 0;
        $started = hrtime(true);
        $cpu = ContinuationGrowthEnvironment::cpuMicroseconds();
        $work = static fn (): int => Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'default',
            '--sleep' => 1,
            '--max-time' => (int) getenv('DWF_GROWTH_BUDGET'),
            '--timeout' => 300,
            '--tries' => 1,
            '--memory' => (int) getenv('DWF_GROWTH_MEMORY'),
        ]);
        $exit = $profile === null ? $work() : $profile->collect($work);
        $elapsed = hrtime(true) - $started;
        $cpu = ContinuationGrowthEnvironment::cpuMicroseconds() - $cpu;
        ContinuationGrowthEnvironment::writeReport($reportPath . '.json', [
            'pid' => getmypid(),
            'exit' => $exit,
            'observers_enabled' => $observers,
            'queue_cpu_us' => $cpu,
            'queue_wall_ns' => $elapsed,
            'startup_cpu_us' => $startupCpu,
            'peak_rss_kib' => getrusage()['ru_maxrss'],
            'peak_allocated_bytes' => memory_get_peak_usage(true),
            'queries' => $observers ? $queries : null,
            'query_elapsed_ms' => $observers ? $queryMilliseconds : null,
            'history_selects' => $observers ? $historySelects : null,
            'history_select_elapsed_ms' => $observers ? $historyMilliseconds : null,
            'entries' => TestContinuationGrowthWorkflow::$entries,
            'round_visits' => TestContinuationGrowthWorkflow::$roundVisits,
            'cache' => app(WorkflowContinuationCache::class)->snapshot(),
            'task_maintenance' => $maintenance->snapshot(),
            'execution_profile' => $profile?->snapshot(),
            'observed_peak_entries' => $peakEntries,
            'task_queue_delay' => $observers ? ContinuationGrowthEnvironment::distribution($claims) : null,
            'timer_lateness' => $observers ? ContinuationGrowthEnvironment::distribution($timers) : null,
        ]);
        $this->assertSame(0, $exit, Artisan::output());
    }

    protected function getPackageProviders($app): array
    {
        return ContinuationGrowthEnvironment::providers();
    }

    protected function defineEnvironment($app): void
    {
        $directory = getenv('DWF_GROWTH_DIRECTORY');
        if (is_string($directory) && $directory !== '') {
            ContinuationGrowthEnvironment::configure($app, $directory, getenv('DWF_GROWTH_RETAINED') === '1');
        }
    }
}
