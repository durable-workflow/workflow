<?php

declare(strict_types=1);

namespace Tests\Performance;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;
use Workflow\V2\Contracts\BufferedSignalProjectionRole;
use Workflow\V2\Contracts\HistoryProjectionMaintenanceRole;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Contracts\WorkflowTaskRepairProjectionRole;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultHistoryProjectionRole;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/EmbeddedReplayGrowthProfileTest.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProjectionRoleProbeTest extends TestCase
{
    /**
     * @return iterable<array{int}>
     */
    public static function repetitions(): iterable
    {
        yield [1];
        yield [2];
        yield [3];
    }

    #[DataProvider('repetitions')]
    public function testProjectionRoleCost(int $repetition): void
    {
        if (getenv('PROFILE_REPLAY_GROWTH') !== '1') {
            $this->markTestSkipped('Explicit profiling run only.');
        }

        Queue::fake();
        $this->freezeTime();
        $delegate = $this->app->make(HistoryProjectionRole::class);
        $this->assertInstanceOf(DefaultHistoryProjectionRole::class, $delegate);
        $role = new ProfiledHistoryRole($delegate);
        $this->app->instance(HistoryProjectionRole::class, $role);
        $effects = 0;
        WorkflowStub::mock(ReplayGrowthActivity::class, static function ($context, int $value) use (&$effects): int {
            ++$effects;

            return $value;
        });
        $workflow = WorkflowStub::make(ReplayGrowthWorkflow::class, 'projection-role-growth');
        $started = ProfiledHistoryRole::cpuMicroseconds();
        $workflow->start(8);
        for ($round = 0; $round <= 8; ++$round) {
            WorkflowStub::runReadyTasks();
            $this->travel(2)
                ->seconds();
        }
        $total = ProfiledHistoryRole::cpuMicroseconds() - $started;
        $workflow = WorkflowStub::load('projection-role-growth');
        $this->assertTrue($workflow->completed());
        $this->assertSame(24, $workflow->output());
        $this->assertSame(24, $effects);
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $this->assertSame(91, $run->historyEvents()->count());
        $tasks = WorkflowTask::query()->where('workflow_run_id', $run->id)->get();
        $this->assertCount(49, $tasks);
        foreach ($tasks as $task) {
            $this->assertSame('completed', $task->status->value);
            $this->assertSame(1, $task->attempt_count);
            $this->assertSame(0, $task->repair_count);
        }
        $this->assertGreaterThan(0, $role->calls['projectRun'] ?? 0);
        $this->assertGreaterThan(0, $role->calls['recordActivityStarted'] ?? 0);
        $this->assertGreaterThan(array_sum($role->cpuMicroseconds), $total);
        echo json_encode([
            'profile' => 'projection-role-probe',
            'repetition' => $repetition,
            'history_events' => 91,
            'tasks' => 49,
            'activity_effects' => $effects,
            'whole_cpu_us' => $total,
            'role_cpu_us' => $role->cpuMicroseconds,
            'role_calls' => $role->calls,
        ], JSON_THROW_ON_ERROR) . PHP_EOL;
    }
}

final class ProfiledHistoryRole implements HistoryProjectionMaintenanceRole, BufferedSignalProjectionRole, WorkflowTaskRepairProjectionRole
{
    /**
     * @var array<string, int>
     */
    public array $calls = [];

    /**
     * @var array<string, int>
     */
    public array $cpuMicroseconds = [];

    private int $depth = 0;

    public function __construct(
        private readonly DefaultHistoryProjectionRole $delegate
    ) {
    }

    public function projectRun(WorkflowRun $run): WorkflowRunSummary
    {
        return $this->measure('projectRun', fn () => $this->delegate->projectRun($run));
    }

    public function recordActivityStarted(
        WorkflowRun $run,
        ActivityExecution $execution,
        ActivityAttempt $attempt,
        WorkflowTask $task
    ): WorkflowRunSummary {
        return $this->measure(
            'recordActivityStarted',
            fn () => $this->delegate->recordActivityStarted($run, $execution, $attempt, $task)
        );
    }

    public function projectRepairedWorkflowTask(WorkflowRun $run, WorkflowTask $task): WorkflowRunSummary
    {
        return $this->delegate->projectRepairedWorkflowTask($run, $task);
    }

    public function bufferedSignalSummary(WorkflowRun $run): ?WorkflowRunSummary
    {
        return $this->delegate->bufferedSignalSummary($run);
    }

    public function projectBufferedSignal(
        WorkflowRun $run,
        WorkflowHistoryEvent $event,
        WorkflowRunSummary $summary
    ): WorkflowRunSummary {
        return $this->delegate->projectBufferedSignal($run, $event, $summary);
    }

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $projectionModel
     * @param list<string> $seenProjectionIds
     */
    public function pruneStaleProjectionRowsForRun(
        string $projectionModel,
        string $runId,
        array $seenProjectionIds
    ): void {
        $this->delegate->pruneStaleProjectionRowsForRun($projectionModel, $runId, $seenProjectionIds);
    }

    /**
     * @param list<string> $runIds
     * @return array{
     *     run_summaries: int,
     *     run_waits: int,
     *     run_timeline_entries: int,
     *     run_timer_entries: int,
     *     run_lineage_entries: int
     * }
     */
    public function pruneStaleProjections(array $runIds = [], ?string $instanceId = null, bool $dryRun = false): array
    {
        return $this->delegate->pruneStaleProjections($runIds, $instanceId, $dryRun);
    }

    public static function cpuMicroseconds(): int
    {
        $usage = getrusage();

        return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1_000_000
            + $usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec'];
    }

    /**
     * @param callable(): WorkflowRunSummary $operation
     */
    private function measure(string $name, callable $operation): WorkflowRunSummary
    {
        $this->calls[$name] = ($this->calls[$name] ?? 0) + 1;
        $outer = $this->depth++ === 0;
        $started = self::cpuMicroseconds();
        try {
            return $operation();
        } finally {
            --$this->depth;
            if ($outer) {
                $this->cpuMicroseconds[$name] = ($this->cpuMicroseconds[$name] ?? 0) + self::cpuMicroseconds() - $started;
            }
        }
    }
}
