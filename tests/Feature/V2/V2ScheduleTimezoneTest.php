<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Workflow\V2\Support\ScheduleManager;

final class V2ScheduleTimezoneTest extends TestCase
{
    public function testScheduleFireInstantSurvivesKyivClockTransitionsAndDatabaseReload(): void
    {
        $originalAppTimezone = config('app.timezone');
        $originalPhpTimezone = date_default_timezone_get();
        config()->set('app.timezone', 'Europe/Kyiv');
        date_default_timezone_set('Europe/Kyiv');

        try {
            $schedule = ScheduleManager::createFromSpec(
                scheduleId: 'kyiv-dst-round-trip',
                spec: ['cron_expressions' => ['* * * * *'], 'timezone' => 'Europe/Kyiv'],
                action: ['workflow_type' => 'example.noop.v1'],
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
                $reloaded = $schedule->fresh()->next_fire_at;
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
}
