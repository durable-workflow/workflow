<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Support\ActivityTimeoutEnforcer;
use Workflow\V2\TaskWatchdog;

final class V2MaintenanceScanIndexesTest extends TestCase
{
    public function testMaintenanceSeeksOpenWorkAmongRetainedClosedRows(): void
    {
        $runId = self::id(10_001);
        $deadline = now()
            ->subDay();

        foreach (array_chunk(range(1, 10_002), 100) as $batch) {
            $runs = [];
            $activities = [];

            foreach ($batch as $number) {
                $open = $number >= 10_001;
                $id = self::id($number);
                $runs[] = [
                    'id' => $id,
                    'workflow_instance_id' => 'maintenance-index',
                    'run_number' => $number,
                    'workflow_class' => 'fixture',
                    'workflow_type' => 'fixture',
                    'namespace' => $number === 10_002 ? 'outside-scope' : null,
                    'status' => $open ? RunStatus::Waiting->value : RunStatus::Completed->value,
                    'execution_deadline_at' => $deadline,
                ];
                $activities[] = [
                    'id' => $id,
                    'workflow_run_id' => $id,
                    'sequence' => 1,
                    'activity_class' => 'fixture',
                    'activity_type' => 'fixture',
                    'status' => $open ? ActivityStatus::Pending->value : ActivityStatus::Completed->value,
                    'schedule_deadline_at' => $deadline,
                ];
            }

            DB::table('workflow_runs')->insert($runs);
            DB::table('activity_executions')->insert($activities);
        }

        $expiredRuns = new ReflectionMethod(TaskWatchdog::class, 'deadlineExpiredRunIds');
        $findRuns = static fn () => $expiredRuns->invoke(null);
        $findActivities = static fn () => ActivityTimeoutEnforcer::expiredExecutionIds();

        $this->assertSame([$runId], $findRuns());
        $this->assertSame([$runId], $findActivities());
        $this->assertIndexSeek('workflow_runs', 'dw_workflow_runs_status_deadline', $findRuns);
        $this->assertIndexSeek('activity_executions', 'dw_activity_executions_status', $findActivities);
    }

    private function assertIndexSeek(string $table, string $index, callable $query): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::select('ANALYZE TABLE ' . $table);
        } else {
            DB::statement('ANALYZE ' . $table);
        }

        $queries = $connection->pretend($query);
        $this->assertCount(1, $queries);
        $command = match ($driver) {
            'sqlite' => 'EXPLAIN QUERY PLAN ',
            'pgsql' => 'EXPLAIN (FORMAT JSON) ',
            'mysql' => $connection instanceof MySqlConnection && ! $connection->isMaria()
                ? 'EXPLAIN FORMAT=TRADITIONAL '
                : 'EXPLAIN ',
            default => 'EXPLAIN ',
        };
        $plan = DB::select($command . $queries[0]['query']);
        $evidence = json_encode($plan, JSON_THROW_ON_ERROR);

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $access = collect($plan)
                ->first(static fn (object $step): bool => ($step->table ?? null) === $table);
            $this->assertNotNull($access, $evidence);
            $this->assertSame($index, $access->key, $evidence);
            $this->assertContains($access->type, ['range', 'ref'], $evidence);
            $this->assertLessThan(64, (int) $access->rows, $evidence);
        } elseif ($driver === 'pgsql') {
            $this->assertStringContainsString($index, $evidence);
            $this->assertStringContainsString('Index Cond', $evidence);
        } else {
            $this->assertStringContainsString('SEARCH ' . $table . ' USING INDEX ' . $index . ' (status=?)', $evidence);
            $this->assertStringNotContainsString('SCAN workflow_runs', $evidence);
        }
    }

    private static function id(int $number): string
    {
        return str_pad((string) $number, 26, '0', STR_PAD_LEFT);
    }
}
