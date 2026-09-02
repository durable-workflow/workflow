<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Tests\TestCase;
use Workflow\V2\Contracts\SchedulerRole;

final class V2ScheduleTickCommandTest extends TestCase
{
    public function testItUsesTheSchedulerRoleBindingForScheduleTicks(): void
    {
        $fake = new class() implements SchedulerRole {
            public ?int $lastTickLimit = null;

            public function tick(int $limit = 100): array
            {
                $this->lastTickLimit = $limit;

                return [[
                    'schedule_id' => 'billing-report',
                    'instance_id' => 'wf-billing-report',
                    'outcome' => 'scheduled',
                ]];
            }
        };

        $this->app->instance(SchedulerRole::class, $fake);

        $expected = [[
            'schedule_id' => 'billing-report',
            'instance_id' => 'wf-billing-report',
            'outcome' => 'scheduled',
        ]];

        $this->artisan('workflow:v2:schedule-tick', [
            '--json' => true,
        ])
            ->expectsOutput(json_encode($expected, JSON_UNESCAPED_SLASHES))
            ->assertSuccessful();

        $this->assertSame(100, $fake->lastTickLimit);
    }

    public function testItReportsHumanScheduleTickOutputFromTheBoundRole(): void
    {
        $fake = new class() implements SchedulerRole {
            public function tick(int $limit = 100): array
            {
                return [[
                    'schedule_id' => 'nightly-rebuild',
                    'instance_id' => 'wf-nightly-rebuild',
                    'outcome' => 'scheduled',
                ]];
            }
        };

        $this->app->instance(SchedulerRole::class, $fake);

        $this->artisan('workflow:v2:schedule-tick')
            ->expectsOutput('[nightly-rebuild] → wf-nightly-rebuild')
            ->expectsOutput('Processed 1 schedule(s).')
            ->assertSuccessful();
    }

    public function testItReportsWhenNoSchedulesAreDue(): void
    {
        $fake = new class() implements SchedulerRole {
            public function tick(int $limit = 100): array
            {
                return [];
            }
        };

        $this->app->instance(SchedulerRole::class, $fake);

        $this->artisan('workflow:v2:schedule-tick')
            ->expectsOutput('No schedules due.')
            ->assertSuccessful();
    }
}
