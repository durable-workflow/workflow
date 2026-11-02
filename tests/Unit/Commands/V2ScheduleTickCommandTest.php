<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testItPassesAnExplicitLimitToTheSchedulerRole(): void
    {
        $fake = $this->recordingRole();
        config()
            ->set('workflows.v2.schedules.tick_limit', 25);

        $this->artisan('workflow:v2:schedule-tick', [
            '--limit' => '7',
        ])
            ->expectsOutput('No schedules due.')
            ->assertSuccessful();

        $this->assertSame([7], $fake->limits);
    }

    #[DataProvider('configuredLimits')]
    public function testItUsesTheConfiguredTickLimit(mixed $configured, int $expected): void
    {
        $fake = $this->recordingRole();
        config()
            ->set('workflows.v2.schedules.tick_limit', $configured);

        $this->artisan('workflow:v2:schedule-tick')
            ->assertSuccessful();

        $this->assertSame([$expected], $fake->limits);
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function configuredLimits(): iterable
    {
        yield 'integer' => [250, 250];
        yield 'environment string' => ['250', 250];
        yield 'one' => [1, 1];
        yield 'unset key' => [null, 100];
    }

    #[DataProvider('invalidLimits')]
    public function testItRefusesAnInvalidLimitOptionWithoutTicking(string $limit, string $shown): void
    {
        $fake = $this->recordingRole();

        $this->artisan('workflow:v2:schedule-tick', [
            '--limit' => $limit,
        ])
            ->expectsOutputToContain(sprintf('Invalid --limit value [%s]: expected a positive integer.', $shown))
            ->assertFailed();

        $this->assertSame([], $fake->limits);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidLimits(): iterable
    {
        yield 'zero' => ['0', '0'];
        yield 'negative' => ['-5', '-5'];
        yield 'empty' => ['', ''];
        yield 'word' => ['all', 'all'];
        yield 'decimal' => ['1.5', '1.5'];
        yield 'leading zero' => ['010', '010'];
        yield 'surrounding space' => [' 10', ' 10'];
        yield 'trailing newline' => ["3\n", '3\n'];
        yield 'trailing carriage return and newline' => ["3\r\n", '3\r\n'];
        yield 'leading newline' => ["\n3", '\n3'];
        yield 'embedded newline' => ["3\n4", '3\n4'];
        yield 'console tag' => ['<info>3</info>', '<info>3</info>'];
        yield 'overflow' => ['99999999999999999999', '99999999999999999999'];
    }

    #[DataProvider('invalidConfiguredLimits')]
    public function testItRefusesAnInvalidConfiguredLimitWithoutTicking(mixed $configured, string $shown): void
    {
        $fake = $this->recordingRole();
        config()
            ->set('workflows.v2.schedules.tick_limit', $configured);

        $this->artisan('workflow:v2:schedule-tick')
            ->expectsOutputToContain(sprintf(
                'Invalid workflows.v2.schedules.tick_limit value [%s]: expected a positive integer.',
                $shown,
            ))
            ->assertFailed();

        $this->assertSame([], $fake->limits);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidConfiguredLimits(): iterable
    {
        yield 'zero' => [0, '0'];
        yield 'negative' => [-1, '-1'];
        yield 'zero string' => ['0', '0'];
        yield 'word' => ['unlimited', 'unlimited'];
        yield 'trailing newline' => ["3\n", '3\n'];
        yield 'environment trailing newline' => ["250\n", '250\n'];
        yield 'boolean' => [true, 'true'];
        yield 'float' => [12.5, '12.5'];
        yield 'empty string' => ['', ''];
        yield 'array' => [[10], 'array'];
    }

    public function testAnExplicitLimitOverridesAnInvalidConfiguredLimit(): void
    {
        $fake = $this->recordingRole();
        config()
            ->set('workflows.v2.schedules.tick_limit', 'unlimited');

        $this->artisan('workflow:v2:schedule-tick', [
            '--limit' => '3',
        ])
            ->assertSuccessful();

        $this->assertSame([3], $fake->limits);
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

    private function recordingRole(): object
    {
        $fake = new class() implements SchedulerRole {
            /**
             * @var list<int>
             */
            public array $limits = [];

            public function tick(int $limit = 100): array
            {
                $this->limits[] = $limit;

                return [];
            }
        };

        $this->app->instance(SchedulerRole::class, $fake);

        return $fake;
    }
}
