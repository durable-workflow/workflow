<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\DefaultOperatorObservabilityRepository;
use Workflow\V2\Support\OperatorDashboardSummary;

final class OperatorDashboardReadBoundsTest extends TestCase
{
    private int $nextId = 0;

    public function testMaintenanceVolumeDoesNotMaterializeEveryRunForDashboardCharts(): void
    {
        $now = Carbon::parse('2026-10-06T12:34:56Z');
        $this->seedRuns(range(1, 400), $now->copy()->subMinutes(30));
        $this->seedRuns(array_fill(0, 100, null), $now->copy()->subHours(2), status: 'failed');
        $this->seedRuns([1000, 1001, 1002, 1003, 1004], $now->copy()->subDays(8));
        $this->seedRuns([2000, 2001], $now->copy()->subMinutes(30), namespace: 'another-namespace');
        WorkflowHistoryEvent::create([
            'id' => 'dashboard-history',
            'workflow_run_id' => 'dashboard-00001',
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowStarted,
            'payload' => Serializer::serialize([
                'workflow_type' => 'maintenance.scan',
            ]),
            'recorded_at' => $now,
        ]);

        $retrieved = 0;
        $historyRetrieved = 0;
        WorkflowRunSummary::retrieved(static function () use (&$retrieved): void {
            ++$retrieved;
        });
        WorkflowHistoryEvent::retrieved(static function () use (&$historyRetrieved): void {
            ++$historyRetrieved;
        });

        $dashboard = (new DefaultOperatorObservabilityRepository())->boundedDashboardSummary($now, 'bounded-dashboard');

        $this->assertSame(505, $dashboard['flows']);
        $this->assertSame(500, (int) $dashboard['workflow_type_health'][0]['total_runs']);
        $this->assertSame(201, (int) $dashboard['workflow_type_health'][0]['median_duration_ms']);
        $this->assertSame(80.0, $dashboard['workflow_type_health'][0]['pass_rate']);
        $this->assertCount(169, $dashboard['fleet_trends_series']['timestamps']);
        $this->assertSame(400, array_sum($dashboard['fleet_trends_series']['completed']));
        $this->assertSame(100, array_sum($dashboard['fleet_trends_series']['failed']));
        $this->assertLessThan(10, $retrieved, 'Dashboard charts must not hydrate every matching run.');
        $this->assertSame('not_requested', $dashboard['operator_metrics']['history_audit_evaluation']);
        $this->assertNull($dashboard['operator_metrics']['projections']['run_waits']['needs_rebuild']);
        $this->assertNull($dashboard['operator_metrics']['projections']['run_lineage_entries']['stale_projected_runs']);
        $this->assertNull($dashboard['operator_metrics']['command_contracts']['backfill_needed_runs']);
        $this->assertSame(0, $historyRetrieved, 'A dashboard read must not decode complete run histories.');

        $retrieved = 0;
        $global = (new DefaultOperatorObservabilityRepository())->boundedDashboardSummary($now);
        $this->assertSame(507, $global['flows']);
        $this->assertLessThan(10, $retrieved, 'The global dashboard must keep the same read bound.');
        $this->assertSame(0, $historyRetrieved);
    }

    public function testWorkflowTypeScopeFiltersEveryVolumeSurfaceWithoutReadingMaintenanceHistories(): void
    {
        $now = Carbon::parse('2026-10-06T12:34:56Z');
        $this->seedRuns(range(1, 500), $now->copy()->subMinute());
        $this->seedRuns([2000], $now->copy()->subMinute(), status: 'failed');
        $this->seedRuns([5, 7], $now->copy()->subMinute(), workflowType: 'orders.import');
        $this->seedRuns([11], $now->copy()->subMinute(), status: 'failed', workflowType: 'orders.import');
        $this->seedRuns([99], $now->copy()->subMinute(), namespace: 'outside', workflowType: 'orders.import');
        foreach (['dashboard-00501', 'dashboard-00504', 'dashboard-00505'] as $id) {
            DB::table('workflow_failures')->insert([
                'id' => 'failure-' . $id,
                'workflow_run_id' => $id,
                'source_kind' => 'workflow',
                'source_id' => $id,
                'propagation_kind' => 'direct',
                'exception_class' => \RuntimeException::class,
                'message' => 'Import failed',
                'file' => __FILE__,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('workflow_run_summaries')->where('id', $id)->update([
                'exception_count' => 1,
            ]);
        }

        $retrieved = 0;
        $historyRetrieved = 0;
        WorkflowRunSummary::retrieved(static function () use (&$retrieved): void {
            ++$retrieved;
        });
        WorkflowHistoryEvent::retrieved(static function () use (&$historyRetrieved): void {
            ++$historyRetrieved;
        });
        $dashboard = (new DefaultOperatorObservabilityRepository())->workflowTypeDashboardSummary(
            ['orders.import'],
            $now,
            'bounded-dashboard',
        );

        $this->assertSame(3, $dashboard['flows']);
        $this->assertSame(3, $dashboard['flows_past_hour']);
        $this->assertSame(1, $dashboard['exceptions_past_hour']);
        $this->assertSame(1, $dashboard['failed_flows_past_week']);
        $this->assertSame(2, (int) $dashboard['fleet_overview']['trends']['hour']['completed']);
        $this->assertSame(1, (int) $dashboard['fleet_overview']['trends']['hour']['failed']);
        $this->assertSame(2, array_sum($dashboard['fleet_trends_series']['completed']));
        $this->assertSame(1, array_sum($dashboard['fleet_trends_series']['failed']));
        $this->assertSame(['orders.import'], array_column($dashboard['workflow_type_health'], 'workflow_type'));
        $this->assertSame(3, (int) $dashboard['workflow_type_health'][0]['total_runs']);
        $this->assertSame('orders.import', $dashboard['max_duration_workflow']['workflow_type']);
        $this->assertSame('orders.import', $dashboard['max_exceptions_workflow']['workflow_type']);
        $this->assertSame(['orders.import'], $dashboard['workflow_scope']['workflow_types']);
        $this->assertSame('bounded-dashboard', $dashboard['workflow_scope']['namespace']);
        $this->assertFalse($dashboard['workflow_scope']['all_workflow_types']);
        $this->assertNull($dashboard['operator_metrics_scope']['workflow_types']);
        $this->assertSame('all_retained_runs', $dashboard['time_windows']['total_runs']);
        $this->assertLessThan(10, $retrieved);
        $this->assertSame(0, $historyRetrieved);

        $global = OperatorDashboardSummary::snapshot(
            $now,
            includeHistoryAudits: false,
            workflowTypes: ['orders.import']
        );
        $this->assertSame(4, $global['flows']);
        $all = OperatorDashboardSummary::snapshot($now, 'bounded-dashboard', includeHistoryAudits: false);
        $this->assertSame(504, $all['flows']);
        $this->assertTrue($all['workflow_scope']['all_workflow_types']);
    }

    public function testEmptyWorkflowTypeScopeDoesNotBroadenToEveryWorkflow(): void
    {
        $now = Carbon::parse('2026-10-06T12:34:56Z');
        $this->seedRuns([100], $now->copy()->subMinute());

        $dashboard = (new DefaultOperatorObservabilityRepository())->workflowTypeDashboardSummary([], $now);

        $this->assertSame(0, $dashboard['flows']);
        $this->assertSame(0, $dashboard['flows_past_hour']);
        $this->assertSame(0, $dashboard['exceptions_past_hour']);
        $this->assertSame(0, $dashboard['failed_flows_past_week']);
        $this->assertNull($dashboard['max_duration_workflow']);
        $this->assertSame([], $dashboard['workflow_type_health']);
        $this->assertSame(0, array_sum($dashboard['fleet_trends_series']['completed']));
        $this->assertSame([], $dashboard['workflow_scope']['workflow_types']);
        $this->assertFalse($dashboard['workflow_scope']['all_workflow_types']);
    }

    public function testWorkerAlertsKeepNamespaceScopeIndependentlyOfWorkflowTypeSelection(): void
    {
        $now = Carbon::parse('2026-10-06T12:34:56Z');
        foreach (['bounded-dashboard', 'outside'] as $namespace) {
            DB::table('workflow_worker_compatibility_heartbeats')->insert([
                'worker_id' => 'worker-' . $namespace,
                'scope_key' => $namespace,
                'namespace' => $namespace,
                'supported' => '[]',
                'recorded_at' => $now->copy()
                    ->subMinutes(10),
                'expires_at' => $now->copy()
                    ->subMinutes(5),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $scoped = OperatorDashboardSummary::snapshot(
            $now,
            'bounded-dashboard',
            includeHistoryAudits: false,
            workflowTypes: [],
        );
        $alert = collect($scoped['needs_attention']['alerts'])->firstWhere('type', 'stuck_workers');
        $this->assertSame(1, $alert['count']);
        $this->assertSame('operator_workers', $alert['scope']);
        $this->assertSame(0, $scoped['flows']);
        $global = OperatorDashboardSummary::snapshot(
            $now,
            includeHistoryAudits: false,
            workflowTypes: []
        );
        $this->assertSame(2, collect($global['needs_attention']['alerts'])->firstWhere('type', 'stuck_workers')['count']);
    }

    /**
     * @param list<int|null> $durations
     */
    #[DataProvider('medianCases')]
    public function testMedianKeepsTheExistingUpperMiddleContract(array $durations, ?int $expected): void
    {
        $now = Carbon::parse('2026-10-06T12:34:56Z');
        $this->seedRuns($durations, $now->copy()->subMinute());

        $dashboard = OperatorDashboardSummary::snapshot($now, 'bounded-dashboard');

        $median = $dashboard['workflow_type_health'][0]['median_duration_ms'];
        $this->assertSame($expected, $median === null ? null : (int) $median);
        $this->assertArrayNotHasKey('history_audit_evaluation', $dashboard['operator_metrics']);
        $this->assertIsInt($dashboard['operator_metrics']['projections']['run_waits']['needs_rebuild']);
    }

    /**
     * @return array<string, array{list<int|null>, int|null}>
     */
    public static function medianCases(): array
    {
        return [
            'odd, unsorted' => [[7, 2, 9], 7],
            'even, unsorted' => [[7, 2, 9, 3], 7],
            'one duration' => [[5], 5],
            'missing duration' => [[null], null],
        ];
    }

    /**
     * @param array<string, mixed> $wait
     */
    #[DataProvider('waitAttentionCases')]
    public function testWaitAttentionUsesScheduledWorkAndDeadlinesInsteadOfElapsedAge(array $wait, int $expected): void
    {
        $now = Carbon::parse('2026-10-06T12:00:00Z');
        $this->seedRuns([null], $now->copy()->subHours(2), status: 'waiting');
        DB::table('workflow_run_summaries')->where('id', 'dashboard-00001')->update([
            'status_bucket' => 'running',
            'closed_at' => null,
            'wait_kind' => 'signal',
            'wait_started_at' => '2026-10-06 10:00:00',
            ...$wait,
        ]);
        $this->seedRuns([null], $now->copy()->subHours(2), namespace: 'outside-scope', status: 'waiting');
        DB::table('workflow_run_summaries')->where('id', 'dashboard-00002')->update([
            'status_bucket' => 'running',
            'closed_at' => null,
            'wait_started_at' => '2026-10-06 10:00:00',
            'task_problem' => true,
        ]);
        $dashboard = OperatorDashboardSummary::snapshot($now, 'bounded-dashboard', includeHistoryAudits: false);
        $alerts = collect($dashboard['needs_attention']['alerts'])->where('type', 'long_waits');
        $this->assertSame($expected, $alerts->sum('count'));
        $global = OperatorDashboardSummary::snapshot($now, includeHistoryAudits: false);
        $this->assertSame(
            $expected + 1,
            collect($global['needs_attention']['alerts'])->where('type', 'long_waits')->sum('count')
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, int}>
     */
    public static function waitAttentionCases(): array
    {
        return [
            'future activity retry' => [[
                'wait_kind' => 'activity',
                'next_task_at' => '2026-10-06 13:00:00',
                'next_task_status' => 'ready',
            ], 0],
            'future timer' => [[
                'wait_kind' => 'timer',
                'wait_deadline_at' => '2026-10-06 13:00:00',
            ], 0],
            'indefinite signal' => [[], 0],
            'future condition deadline' => [[
                'wait_kind' => 'condition',
                'wait_deadline_at' => '2026-10-06 13:00:00',
            ], 0],
            'eligible timer within dispatch grace' => [[
                'wait_kind' => 'timer',
                'wait_deadline_at' => '2026-10-06 11:59:00',
            ], 0],
            'task currently leased' => [[
                'wait_kind' => 'activity',
                'next_task_at' => '2026-10-06 10:30:00',
                'next_task_status' => 'leased',
            ], 0],
            'timer resume currently leased' => [[
                'wait_kind' => 'timer',
                'wait_deadline_at' => '2026-10-06 10:30:00',
                'next_task_status' => 'leased',
            ], 0],
            'overdue ready resume' => [[
                'wait_kind' => 'activity',
                'next_task_at' => '2026-10-06 11:29:00',
                'next_task_status' => 'ready',
            ], 1],
            'overdue timer delivery' => [[
                'wait_kind' => 'timer',
                'wait_deadline_at' => '2026-10-06 11:29:00',
            ], 1],
            'elapsed condition deadline' => [[
                'wait_kind' => 'condition',
                'wait_deadline_at' => '2026-10-06 11:59:00',
            ], 1],
            'recorded task problem' => [[
                'task_problem' => true,
            ], 1],
            'recorded repair need' => [[
                'repair_attention' => true,
            ], 1],
        ];
    }

    public function testTrendBucketsKeepHourBoundariesAndZeroFill(): void
    {
        $now = Carbon::parse('2026-10-06T12:34:56Z');
        $this->seedRuns([1], $now->copy()->subWeek());
        $this->seedRuns([1], $now->copy()->subWeek()->subSecond());
        $this->seedRuns([1], $now->copy()->startOfHour()->subSecond());
        $this->seedRuns([1], $now->copy()->startOfHour(), status: 'failed');
        $this->seedRuns([1], $now->copy()->addDay());

        $series = OperatorDashboardSummary::fleetTrendsSeries($now, 'bounded-dashboard');

        $this->assertCount(169, $series['timestamps']);
        $this->assertSame(1, $series['completed'][0]);
        $this->assertSame(1, $series['completed'][167]);
        $this->assertSame(1, $series['failed'][168]);
        $this->assertSame(2, array_sum($series['completed']));
        $this->assertSame(1, array_sum($series['failed']));
        $this->assertSame(0, $series['completed'][100]);
    }

    /**
     * @param list<int|null> $durations
     */
    private function seedRuns(
        array $durations,
        CarbonInterface $closedAt,
        string $namespace = 'bounded-dashboard',
        string $status = 'completed',
        string $workflowType = 'maintenance.scan',
    ): void {
        $instances = [];
        $runs = [];
        $summaries = [];
        foreach ($durations as $duration) {
            $id = sprintf('dashboard-%05d', ++$this->nextId);
            $identity = [
                'id' => $id,
                'namespace' => $namespace,
                'workflow_type' => $workflowType,
                'created_at' => $closedAt->copy()
                    ->subMinute()
                    ->format('Y-m-d H:i:s.u'),
                'updated_at' => $closedAt->format('Y-m-d H:i:s.u'),
            ];
            $instances[] = [
                ...$identity,
                'workflow_class' => 'MaintenanceScan',
                'run_count' => 1,
            ];
            $runs[] = [
                ...$identity,
                'workflow_instance_id' => $id,
                'workflow_class' => 'MaintenanceScan',
                'run_number' => 1,
                'status' => $status,
                'closed_at' => $closedAt->format('Y-m-d H:i:s.u'),
            ];
            $summaries[] = [
                ...$identity,
                'workflow_instance_id' => $id,
                'class' => 'MaintenanceScan',
                'run_number' => 1,
                'status' => $status,
                'status_bucket' => $status,
                'closed_at' => $closedAt->format('Y-m-d H:i:s.u'),
                'duration_ms' => $duration,
            ];
        }
        foreach (array_chunk($instances, 50) as $chunk) {
            DB::table('workflow_instances')->insert($chunk);
        }
        foreach (array_chunk($runs, 50) as $chunk) {
            DB::table('workflow_runs')->insert($chunk);
        }
        foreach (array_chunk($summaries, 50) as $chunk) {
            DB::table('workflow_run_summaries')->insert($chunk);
        }
    }
}
