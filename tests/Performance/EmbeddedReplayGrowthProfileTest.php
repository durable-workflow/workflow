<?php

declare(strict_types=1);

namespace Tests\Performance;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;
use Workflow\V2\Activity;
use function Workflow\V2\activity;
use function Workflow\V2\all;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\WorkflowFiberRunner;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EmbeddedReplayGrowthProfileTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function workloads(): iterable
    {
        foreach ([1, 9, 91] as $rounds) {
            foreach ([1, 2, 3] as $repetition) {
                yield sprintf('%d-rounds-repeat-%d', $rounds, $repetition) => [$rounds, $repetition];
            }
        }
    }

    #[DataProvider('workloads')]
    public function testCommittedHistoryGrowth(int $rounds, int $repetition): void
    {
        if (getenv('PROFILE_REPLAY_GROWTH') !== '1') {
            $this->markTestSkipped('Explicit profiling run only.');
        }

        Queue::fake();
        $this->freezeTime();
        ReplayGrowthWorkflow::$entries = 0;
        ReplayGrowthWorkflow::$roundVisits = 0;
        $effects = 0;
        WorkflowStub::mock(ReplayGrowthActivity::class, static function ($context, int $value) use (&$effects): int {
            ++$effects;

            return $value;
        });

        $progress = new ReplayProfileProgress($rounds, $repetition);
        $this->beforeApplicationDestroyed(static fn () => $progress->close());
        $report = static function (string $phase, int $iteration = 0) use ($progress, &$effects): void {
            $progress->report(
                $phase,
                $iteration,
                $effects,
                ReplayGrowthWorkflow::$entries,
                ReplayGrowthWorkflow::$roundVisits
            );
        };

        $queries = 0;
        $sqlMilliseconds = 0.0;
        DB::listen(static function (QueryExecuted $query) use (&$queries, &$sqlMilliseconds): void {
            ++$queries;
            $sqlMilliseconds += $query->time;
        });

        $workflow = WorkflowStub::make(ReplayGrowthWorkflow::class, 'replay-growth');
        $report('before_start');
        $start = $this->measure(static function () use ($workflow, $rounds): int {
            $workflow->start($rounds);

            return 1;
        }, $queries, $sqlMilliseconds);
        $report('after_start');
        $batches = [];
        for ($round = 0; $round <= $rounds; ++$round) {
            $report('before_batch', $round);
            $batches[] = $this->measure(
                static fn (): int => WorkflowStub::runReadyTasks(),
                $queries,
                $sqlMilliseconds,
            );
            $report('after_batch', $round);
            $this->travel(2)
                ->seconds();
        }

        $workflow = WorkflowStub::load('replay-growth');
        $this->assertTrue($workflow->completed());
        $this->assertSame($rounds * 3, $workflow->output());
        $this->assertSame($rounds * 3, $effects);
        $generationEntries = ReplayGrowthWorkflow::$entries;
        $generationVisits = ReplayGrowthWorkflow::$roundVisits;
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        $history = [];
        $report('history_materialization');
        $historyLoad = $this->measure(static function () use ($run, &$history): int {
            $history = self::historyFrames($run);

            return count($history);
        }, $queries, $sqlMilliseconds);
        $historyDigest = hash('sha256', json_encode($history, JSON_THROW_ON_ERROR));

        $replays = [];
        $projections = [];
        for ($sample = 0; $sample < 3; ++$sample) {
            $report('cold_bridge_replay', $sample + 1);
            $phases = [];
            $replays[] = $this->measure(function () use (
                $run,
                $history,
                $rounds,
                &$phases,
                &$queries,
                &$sqlMilliseconds
            ): int {
                $initialization = $this->measure(static fn (): WorkflowFiberRunner => WorkflowFiberRunner::forClass(
                    ReplayGrowthWorkflow::class,
                    $run->workflow_instance_id,
                    $run->id,
                    [$rounds],
                    historyEvents: $history,
                    namespace: $run->namespace,
                ), $queries, $sqlMilliseconds);
                $runner = $initialization['result'];
                $initialization['result'] = 1;
                $phases['initialization'] = $initialization;
                $phases['step'] = $this->measure(function () use ($runner, $rounds): int {
                    $step = $runner->step();
                    $this->assertTrue($step->completed);
                    $this->assertSame($rounds * 3, $step->result);

                    return count($step->commands);
                }, $queries, $sqlMilliseconds);
                $this->assertSame(0, $phases['initialization']['queries']);
                $this->assertSame(0, $phases['step']['queries']);

                return $phases['step']['result'];
            }, $queries, $sqlMilliseconds);
            $replays[array_key_last($replays)]['phases'] = $phases;
            $report('summary_projection', $sample + 1);
            $projections[] = $this->measure(static function () use ($run): int {
                RunSummaryProjector::project($run->fresh());

                return 1;
            }, $queries, $sqlMilliseconds);
        }

        $report('audit');

        $this->assertSame($rounds * 3, $effects, 'Cold replay must not repeat an activity effect.');
        $this->assertSame(count($history), $run->historyEvents()->count());
        $this->assertSame($historyDigest, hash('sha256', json_encode(self::historyFrames($run), JSON_THROW_ON_ERROR)));
        $tasks = WorkflowTask::query()->where('workflow_run_id', $run->id)->get();
        $this->assertNotEmpty($tasks);
        foreach ($tasks as $task) {
            $this->assertSame('completed', $task->status->value);
            $this->assertSame(1, $task->attempt_count);
            $this->assertSame(0, $task->repair_count);
        }

        $report('complete');
        echo json_encode([
            'profile' => 'embedded-replay-growth',
            'rounds' => $rounds,
            'repetition' => $repetition,
            'history_events' => count($history),
            'tasks' => $tasks->count(),
            'activity_effects' => $effects,
            'executor_workflow_entries' => $generationEntries,
            'executor_round_visits' => $generationVisits,
            'start_and_initial_tasks' => $start,
            'ready_batches' => $batches,
            'history_materialization' => $historyLoad,
            'cold_bridge_replays' => $replays,
            'summary_projections' => $projections,
            'process_peak_rss_kib' => getrusage()['ru_maxrss'],
            'php_peak_allocated_bytes' => memory_get_peak_usage(true),
        ], JSON_THROW_ON_ERROR) . PHP_EOL;
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return array{wall_ms: float, cpu_ms: float, queries: int, sql_ms: float, result: T}
     */
    private function measure(callable $operation, int &$queries, float &$sqlMilliseconds): array
    {
        $beforeQueries = $queries;
        $beforeSql = $sqlMilliseconds;
        $cpu = self::cpuMicroseconds();
        $started = hrtime(true);
        $result = $operation();

        return [
            'wall_ms' => (hrtime(true) - $started) / 1_000_000,
            'cpu_ms' => (self::cpuMicroseconds() - $cpu) / 1_000,
            'queries' => $queries - $beforeQueries,
            'sql_ms' => $sqlMilliseconds - $beforeSql,
            'result' => $result,
        ];
    }

    private static function cpuMicroseconds(): int
    {
        $usage = getrusage();

        return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1_000_000
            + $usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function historyFrames(WorkflowRun $run): array
    {
        return $run->historyEvents()
            ->orderBy('sequence')
            ->get()
            ->map(static fn ($event): array => [
                'id' => $event->id,
                'sequence' => (int) $event->sequence,
                'event_type' => $event->event_type->value,
                'namespace' => $run->namespace,
                'payload' => $event->payload,
                'workflow_task_id' => $event->workflow_task_id,
                'workflow_command_id' => $event->workflow_command_id,
                'recorded_at' => $event->recorded_at?->toJSON(),
            ])->all();
    }
}

final class ReplayGrowthWorkflow extends Workflow
{
    public static int $entries = 0;

    public static int $roundVisits = 0;

    public function handle(int $rounds): int
    {
        ++self::$entries;
        $result = 0;
        for ($round = 0; $round < $rounds; ++$round) {
            ++self::$roundVisits;
            $result += array_sum(all([
                static fn (): mixed => activity(ReplayGrowthActivity::class, 1),
                static fn (): mixed => activity(ReplayGrowthActivity::class, 1),
                static fn (): mixed => activity(ReplayGrowthActivity::class, 1),
            ]));
            timer(1);
        }

        return $result;
    }
}

final class ReplayGrowthActivity extends Activity
{
    public function handle(int $value): int
    {
        return $value;
    }
}
