<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Support\DefaultOperatorObservabilityRepository;
use Workflow\V2\Support\RunSummarySortKey;
use Workflow\V2\Support\WorkerCompatibilityFleet;

final class OperatorDashboardObservationTest extends TestCase
{
    #[DataProvider('timezoneCases')]
    public function testHourlyVolumeUsesUtcSortTimestamps(string $timezone, string $instant): void
    {
        $originalTimezone = date_default_timezone_get();
        config([
            'app.timezone' => $timezone,
        ]);
        date_default_timezone_set($timezone);
        $now = Carbon::parse($instant)->setTimezone($timezone);
        $cutoff = $now->copy()
            ->utc()
            ->subHour();

        try {
            foreach ([-1, 0, 1] as $seconds) {
                $this->seedSummary('sorted-' . $seconds, $cutoff->copy()->addSeconds($seconds)->setTimezone($timezone));
            }
            $this->seedSummary('outside-namespace', $now, namespace: 'outside');
            $this->seedSummary('other-type', $now, workflowType: 'other');
            $repository = new DefaultOperatorObservabilityRepository();
            $dashboard = $repository->boundedDashboardSummary($now, 'dashboard-observation');
            $this->assertSame(3, $dashboard['flows_past_hour']);
            $this->assertSame(3 / 60, $dashboard['flows_per_minute']);
            $typed = $repository->workflowTypeDashboardSummary(['observation'], $now, 'dashboard-observation');
            $this->assertSame(2, $typed['flows_past_hour']);
            $this->assertSame($instant, $now->copy()->utc()->format('Y-m-d\TH:i:s\Z'));
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function timezoneCases(): array
    {
        return [
            'UTC' => ['UTC', '2026-10-07T07:42:00Z'],
            'positive offset' => ['Europe/Kyiv', '2026-10-07T07:42:00Z'],
            'negative offset' => ['America/New_York', '2026-10-07T07:42:00Z'],
            'fractional offset' => ['Asia/Kolkata', '2026-10-07T07:42:00Z'],
            'Kyiv spring gap' => ['Europe/Kyiv', '2026-03-29T01:30:00Z'],
            'Kyiv autumn fold' => ['Europe/Kyiv', '2026-10-25T01:30:00Z'],
            'New York spring gap' => ['America/New_York', '2026-03-08T07:30:00Z'],
            'New York autumn fold' => ['America/New_York', '2026-11-01T06:30:00Z'],
        ];
    }

    #[DataProvider('legacyTimezoneCases')]
    public function testLegacyVolumeUsesApplicationTimestampBasis(string $timezone): void
    {
        $originalTimezone = date_default_timezone_get();
        config([
            'app.timezone' => $timezone,
        ]);
        date_default_timezone_set($timezone);
        // Callers may supply UTC even when the application stores local dates.
        $now = Carbon::parse('2026-10-07T07:42:00Z');
        $cutoff = $now->copy()
            ->subHour()
            ->setTimezone($timezone);

        try {
            foreach ([-1, 0, 1] as $seconds) {
                $this->seedSummary('legacy-' . $seconds, $cutoff->copy()->addSeconds($seconds), projected: false);
            }
            $this->assertSame(2, (new DefaultOperatorObservabilityRepository())
                ->boundedDashboardSummary($now, 'dashboard-observation')['flows_past_hour']);
            $this->assertSame(3, DB::table('workflow_run_summaries')->whereNull('sort_timestamp')->count());
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function legacyTimezoneCases(): array
    {
        return [
            'UTC' => ['UTC'],
            'positive offset' => ['Europe/Kyiv'],
            'negative offset' => ['America/New_York'],
        ];
    }

    /**
     * @param list<int> $expiryOffsets
     */
    #[DataProvider('heartbeatCases')]
    public function testDashboardAndFleetReadsDoNotWrite(array $expiryOffsets, int $active): void
    {
        WorkerCompatibilityFleet::clear();
        $now = Carbon::parse('2026-10-07T12:00:00Z');
        $this->travelTo($now);
        foreach ($expiryOffsets as $index => $offset) {
            $this->seedHeartbeat('worker-' . $index, $now->copy()->addSeconds($offset));
        }
        $this->seedHeartbeat('outside-worker', $now->copy()->addMinute(), 'outside');
        $connection = DB::connection();
        $connection->beginTransaction();
        $connection->enableQueryLog();
        $connection->flushQueryLog();

        try {
            if ($connection->getDriverName() === 'pgsql') {
                $connection->statement('SET TRANSACTION READ ONLY');
            } elseif ($connection->getDriverName() === 'sqlite') {
                $connection->statement('PRAGMA query_only = ON');
            }

            (new DefaultOperatorObservabilityRepository())->boundedDashboardSummary($now, 'dashboard-observation');
            $summary = WorkerCompatibilityFleet::summaryForNamespace('dashboard-observation');
            $this->assertSame($active, $summary['active_workers']);
            $this->assertSame(
                count($expiryOffsets) + 1,
                DB::table('workflow_worker_compatibility_heartbeats')->count()
            );
            $writes = array_filter($connection->getQueryLog(), static fn (array $query): bool =>
                preg_match('/^\s*(insert|update|delete|replace)\b/i', $query['query']) === 1);
            $this->assertSame([], $writes);
        } finally {
            $connection->rollBack();
            if ($connection->getDriverName() === 'sqlite') {
                $connection->statement('PRAGMA query_only = OFF');
            }
            $connection->disableQueryLog();
            $this->travelBack();
        }
    }

    /**
     * @return array<string, array{list<int>, int}>
     */
    public static function heartbeatCases(): array
    {
        return [
            'empty scope' => [[], 0],
            'expired only' => [[-1, -60], 0],
            'active only' => [[0, 60], 2],
            'mixed' => [[-1, 0, 60], 2],
        ];
    }

    public function testHeartbeatWriterPrunesExpiredRecords(): void
    {
        WorkerCompatibilityFleet::clear();
        $this->seedHeartbeat('expired', now()->subMinute());
        $this->seedHeartbeat('active', now()->addMinute());

        WorkerCompatibilityFleet::recordForNamespace(
            'dashboard-observation',
            ['build'],
            queue: 'default',
            workerId: 'new'
        );

        $this->assertFalse(
            DB::table('workflow_worker_compatibility_heartbeats')->where('worker_id', 'expired')->exists()
        );
        $this->assertTrue(
            DB::table('workflow_worker_compatibility_heartbeats')->where('worker_id', 'active')->exists()
        );
        $this->assertSame(2, WorkerCompatibilityFleet::summaryForNamespace('dashboard-observation')['active_workers']);
    }

    private function seedSummary(
        string $id,
        Carbon $startedAt,
        string $namespace = 'dashboard-observation',
        string $workflowType = 'observation',
        bool $projected = true,
    ): void {
        $identity = [
            'id' => $id,
            'namespace' => $namespace,
            'workflow_type' => $workflowType,
            'created_at' => $startedAt->format('Y-m-d H:i:s.u'),
            'updated_at' => $startedAt->format('Y-m-d H:i:s.u'),
        ];
        DB::table('workflow_instances')->insert([
            ...$identity,
            'workflow_class' => 'Observation',
            'run_count' => 1,
        ]);
        DB::table('workflow_runs')->insert([
            ...$identity,
            'workflow_instance_id' => $id,
            'workflow_class' => 'Observation',
            'run_number' => 1,
            'status' => 'completed',
            'started_at' => $startedAt,
            'closed_at' => $startedAt,
        ]);
        DB::table('workflow_run_summaries')->insert([
            ...$identity,
            'workflow_instance_id' => $id,
            'class' => 'Observation',
            'run_number' => 1,
            'status' => 'completed',
            'status_bucket' => 'completed',
            'started_at' => $startedAt,
            'closed_at' => $startedAt,
            'sort_timestamp' => $projected ? RunSummarySortKey::timestamp($startedAt) : null,
        ]);
    }

    private function seedHeartbeat(
        string $workerId,
        Carbon $expiresAt,
        string $namespace = 'dashboard-observation'
    ): void {
        DB::table('workflow_worker_compatibility_heartbeats')->insert([
            'worker_id' => $workerId,
            'scope_key' => $workerId,
            'namespace' => $namespace,
            'supported' => '["build"]',
            'queue' => 'default',
            'recorded_at' => now(),
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
