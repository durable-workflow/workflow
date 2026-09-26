<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\V2\TestScheduledWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Support\ScheduleManager;
use Workflow\V2\WorkflowStub;

final class V2ScheduleTimezoneTest extends TestCase
{
    public function testScheduleFireInstantSurvivesKyivClockTransitionsAndDatabaseReload(): void
    {
        $originalAppTimezone = config('app.timezone');
        $originalPhpTimezone = date_default_timezone_get();
        config()
            ->set('app.timezone', 'Europe/Kyiv');
        date_default_timezone_set('Europe/Kyiv');

        try {
            $schedule = ScheduleManager::createFromSpec(
                scheduleId: 'kyiv-dst-round-trip',
                spec: [
                    'cron_expressions' => ['* * * * *'],
                    'timezone' => 'Europe/Kyiv',
                ],
                action: [
                    'workflow_type' => 'example.noop.v1',
                ],
            );

            $cases = [
                'ordinary_day' => '2026-09-26T14:00:00Z',
                'spring_before' => '2026-03-29T00:58:00Z',
                'spring_transition' => '2026-03-29T00:59:00Z',
                'spring_after' => '2026-03-29T01:00:00Z',
                'fall_before' => '2026-10-24T23:58:00Z',
                'fall_first_hour' => '2026-10-25T00:10:00Z',
                'fall_transition' => '2026-10-25T00:59:00Z',
                'fall_second_hour' => '2026-10-25T01:10:00Z',
                'fall_after' => '2026-10-25T02:00:00Z',
            ];
            $repeatedInstants = [];
            $storedInstants = [];

            foreach ($cases as $name => $utc) {
                $after = Carbon::parse($utc)->setTimezone('Europe/Kyiv');
                $next = $schedule->computeNextFireAt($after);
                $this->assertNotNull($next, $name);
                $this->assertSame(60, $next->getTimestamp() - $after->getTimestamp(), $name);

                $schedule->next_fire_at = $next;
                $schedule->save();
                $reloadedSchedule = $schedule->fresh();
                $reloaded = $reloadedSchedule->next_fire_at;
                $this->assertNotNull($reloaded, $name);
                $this->assertSame($next->getTimestamp(), $reloaded->getTimestamp(), $name);

                $stored = DB::table('workflow_schedules')->where('id', $schedule->id)->value('next_fire_at');
                $storedInstants[$name] = [
                    Carbon::instance($next)->utc()->format('Y-m-d H:i:s.u'),
                    Carbon::parse($stored, 'UTC')->format('Y-m-d H:i:s.u'),
                ];

                if ($name === 'fall_first_hour' || $name === 'fall_second_hour') {
                    $repeatedInstants[$name] = $reloaded->getTimestamp();
                }
            }

            $this->assertSame(3600, $repeatedInstants['fall_second_hour'] - $repeatedInstants['fall_first_hour']);
            foreach ($storedInstants as $name => [$expected, $actual]) {
                $this->assertSame($expected, $actual, $name);
            }
        } finally {
            config()->set('app.timezone', $originalAppTimezone);
            date_default_timezone_set($originalPhpTimezone);
        }
    }

    public function testLegacyLocalScheduleTimestampsAreConvertedToUtcOnUpgrade(): void
    {
        $originalTimezone = config('workflows.v2.legacy_schedule_storage_timezone');
        config()
            ->set('workflows.v2.legacy_schedule_storage_timezone', 'Europe/Kyiv');

        try {
            $schedule = ScheduleManager::createFromSpec(
                scheduleId: 'legacy-kyiv-schedule',
                spec: [
                    'cron_expressions' => ['* * * * *'],
                    'timezone' => 'Europe/Kyiv',
                ],
                action: [
                    'workflow_type' => 'example.noop.v1',
                ],
            );
            DB::table('workflow_schedules')->where('id', $schedule->id)->update([
                'next_fire_at' => '2026-09-26 17:01:00',
                'last_fired_at' => '2026-09-26 17:00:00',
                'paused_at' => '2026-09-26 16:59:00',
                'deleted_at' => '2026-09-26 16:58:00',
                'last_skipped_at' => '2026-09-26 16:57:00',
            ]);

            $migration = require __DIR__ . '/../../../src/migrations/2026_09_26_000100_normalize_workflow_schedule_timestamps.php';
            $migration->up();

            $reloaded = $schedule->fresh();
            $this->assertSame('2026-09-26T14:01:00+00:00', $reloaded->next_fire_at->format(DATE_ATOM));
            $this->assertSame('2026-09-26T14:00:00+00:00', $reloaded->last_fired_at->format(DATE_ATOM));
            $this->assertSame('2026-09-26T13:59:00+00:00', $reloaded->paused_at->format(DATE_ATOM));
            $this->assertSame('2026-09-26T13:58:00+00:00', $reloaded->deleted_at->format(DATE_ATOM));
            $this->assertSame('2026-09-26T13:57:00+00:00', $reloaded->last_skipped_at->format(DATE_ATOM));
        } finally {
            config()->set('workflows.v2.legacy_schedule_storage_timezone', $originalTimezone);
        }
    }

    public function testLegacyAmbiguousLocalMinuteKeepsItsPreviouslyHydratedInstant(): void
    {
        $originalTimezone = config('workflows.v2.legacy_schedule_storage_timezone');
        config()
            ->set('workflows.v2.legacy_schedule_storage_timezone', 'Europe/Kyiv');

        try {
            $schedule = ScheduleManager::createFromSpec(
                scheduleId: 'legacy-overlap-schedule',
                spec: [
                    'cron_expressions' => ['* * * * *'],
                    'timezone' => 'Europe/Kyiv',
                ],
                action: [
                    'workflow_type' => 'example.noop.v1',
                ],
            );
            DB::table('workflow_schedules')->where('id', $schedule->id)
                ->update([
                    'next_fire_at' => '2026-10-25 03:11:00',
                ]);

            $migration = require __DIR__ . '/../../../src/migrations/2026_09_26_000100_normalize_workflow_schedule_timestamps.php';
            $migration->up();

            $this->assertSame(
                Carbon::parse('2026-10-25 03:11:00', 'Europe/Kyiv')->getTimestamp(),
                $schedule->fresh()
                    ->next_fire_at->getTimestamp(),
            );
            $this->assertSame('2026-10-25T01:11:00+00:00', $schedule->fresh()->next_fire_at->format(DATE_ATOM));
        } finally {
            config()->set('workflows.v2.legacy_schedule_storage_timezone', $originalTimezone);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function tickTransitionCases(): array
    {
        return [
            'spring_first_valid_minute' => ['spring_first_valid_minute', '2026-03-29T01:00:00Z'],
            'fall_first_hour' => ['fall_first_hour', '2026-10-25T00:11:00Z'],
            'fall_second_hour' => ['fall_second_hour', '2026-10-25T01:11:00Z'],
        ];
    }

    #[DataProvider('tickTransitionCases')]
    public function testSchedulerTickUsesReloadedUtcInstantsAcrossBothClockTransitions(string $case, string $utc): void
    {
        $originalAppTimezone = config('app.timezone');
        $originalPhpTimezone = date_default_timezone_get();
        config()
            ->set('app.timezone', 'Europe/Kyiv');
        date_default_timezone_set('Europe/Kyiv');
        WorkflowStub::fake();

        try {
            $schedule = ScheduleManager::create(
                scheduleId: 'kyiv-dst-tick',
                workflowClass: TestScheduledWorkflow::class,
                cronExpression: '* * * * *',
                timezone: 'Europe/Kyiv',
                overlapPolicy: ScheduleOverlapPolicy::AllowAll,
            );

            $dueAt = Carbon::parse($utc)->setTimezone('UTC');
            $schedule->next_fire_at = $dueAt;
            $schedule->save();
            $schedule = $schedule->fresh();
            $this->assertSame($dueAt->getTimestamp(), $schedule->next_fire_at->getTimestamp(), $case);

            $connection = $schedule->getConnection();
            $database = $connection->getConfig();
            $coldReadback = new Process([
                PHP_BINARY,
                __DIR__ . '/../../Fixtures/V2/schedule_cold_readback.php',
            ], env: [
                'SCHEDULE_DB_DRIVER' => (string) ($database['driver'] ?? ''),
                'SCHEDULE_DB_DATABASE' => (string) ($database['database'] ?? ''),
                'SCHEDULE_DB_HOST' => (string) ($database['host'] ?? ''),
                'SCHEDULE_DB_PORT' => (string) ($database['port'] ?? ''),
                'SCHEDULE_DB_USERNAME' => (string) ($database['username'] ?? ''),
                'SCHEDULE_DB_PASSWORD' => (string) ($database['password'] ?? ''),
                'SCHEDULE_ID' => (string) $schedule->id,
                'SCHEDULE_DUE_AT' => $dueAt->format('Y-m-d H:i:s.u'),
            ]);
            $coldReadback->mustRun();
            $coldResult = json_decode($coldReadback->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($coldResult);
            $this->assertSame($dueAt->getTimestamp(), $coldResult['timestamp'], $case . ' cold readback');
            $this->assertSame(1, $coldResult['due_count'], $case . ' cold due query');

            // Carbon's named-zone test clock loses the first fold offset when
            // constructing now(); freeze the absolute UTC instant instead.
            Carbon::setTestNowAndTimezone($dueAt->copy()->subSecond(), 'UTC');
            $this->assertSame($dueAt->getTimestamp() - 1, now()->getTimestamp(), $case . ' frozen clock');
            $this->assertSame([], ScheduleManager::tick(), $case);

            Carbon::setTestNowAndTimezone($dueAt, 'UTC');
            $results = ScheduleManager::tick();
            $this->assertCount(1, $results, $case);
            $this->assertSame('triggered', $results[0]['outcome'], $case);
            $this->assertSame(
                $dueAt->getTimestamp(),
                Carbon::parse($results[0]['occurrence_time'])->getTimestamp(),
                $case
            );
            $this->assertSame([], ScheduleManager::tick(), $case);
        } finally {
            Carbon::setTestNow();
            config()
                ->set('app.timezone', $originalAppTimezone);
            date_default_timezone_set($originalPhpTimezone);
        }
    }

    public function testRepeatedLocalMinuteCanFireTwiceAsDistinctUtcOccurrences(): void
    {
        $originalAppTimezone = config('app.timezone');
        $originalPhpTimezone = date_default_timezone_get();
        config()
            ->set('app.timezone', 'Europe/Kyiv');
        date_default_timezone_set('Europe/Kyiv');
        WorkflowStub::fake();

        try {
            $schedule = ScheduleManager::create(
                scheduleId: 'kyiv-repeated-minute',
                workflowClass: TestScheduledWorkflow::class,
                cronExpression: '* * * * *',
                timezone: 'Europe/Kyiv',
                overlapPolicy: ScheduleOverlapPolicy::AllowAll,
            );

            $occurrences = [];
            foreach (['2026-10-25T00:11:00Z', '2026-10-25T01:11:00Z'] as $utc) {
                $dueAt = Carbon::parse($utc)->setTimezone('UTC');
                $computed = $schedule->computeNextFireAt($dueAt->copy()->subMinute()->setTimezone('Europe/Kyiv'));
                $this->assertNotNull($computed);
                $this->assertSame($dueAt->getTimestamp(), $computed->getTimestamp());

                $schedule->next_fire_at = $computed;
                $schedule->save();
                $schedule = $schedule->fresh();
                $this->assertSame($dueAt->getTimestamp(), $schedule->next_fire_at->getTimestamp());

                Carbon::setTestNowAndTimezone($dueAt, 'UTC');
                $results = ScheduleManager::tick();
                $this->assertCount(1, $results);
                $this->assertSame('triggered', $results[0]['outcome']);
                $occurrences[] = Carbon::parse($results[0]['occurrence_time'])->getTimestamp();
                $this->assertSame([], ScheduleManager::tick());
                $schedule = $schedule->fresh();
            }

            $this->assertSame(3600, $occurrences[1] - $occurrences[0]);
            $this->assertSame(2, (int) $schedule->fires_count);
        } finally {
            Carbon::setTestNow();
            config()
                ->set('app.timezone', $originalAppTimezone);
            date_default_timezone_set($originalPhpTimezone);
        }
    }
}
