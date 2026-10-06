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
    ): void {
        $instances = [];
        $runs = [];
        $summaries = [];
        foreach ($durations as $duration) {
            $id = sprintf('dashboard-%05d', ++$this->nextId);
            $identity = [
                'id' => $id,
                'namespace' => $namespace,
                'workflow_type' => 'maintenance.scan',
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
