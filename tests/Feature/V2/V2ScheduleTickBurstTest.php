<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\V2\TestScheduledWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Support\ScheduleManager;
use Workflow\V2\WorkflowStub;

/**
 * More schedules fall due together than one `workflow:v2:schedule-tick`
 * pass starts. Each pass starts at most its limit, shared fairly across
 * namespaces, and successive passes fire every remaining schedule exactly
 * once, so a burst of N needs ceil(N / limit) passes (#875).
 */
final class V2ScheduleTickBurstTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');

        WorkflowStub::fake();
        Carbon::setTestNowAndTimezone(Carbon::parse('2026-11-02 12:00:30', 'UTC'), 'UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testSuccessiveLimitedPassesFireABurstOnceEachAndShareEachPassAcrossNamespaces(): void
    {
        foreach ([10, 9, 8, 7, 6] as $index => $minutesAgo) {
            $this->dueSchedule('noisy-' . ($index + 1), 'noisy', $minutesAgo);
        }
        $this->dueSchedule('quiet-1', 'quiet', 3);
        $this->dueSchedule('quiet-2', 'quiet', 2);

        $passes = [];
        do {
            $pass = $this->tick([
                '--limit' => '3',
            ]);
            $passes[] = array_column($pass, 'schedule_id');
            $this->assertLessThanOrEqual(3, count($pass));
            foreach ($pass as $row) {
                $this->assertSame('triggered', $row['outcome'], json_encode($row, JSON_THROW_ON_ERROR));
                $this->assertNotNull($row['instance_id']);
            }
        } while ($pass !== [] && count($passes) < 10);

        $this->assertSame([
            // Each pass takes one schedule per namespace in turn, starting
            // with the namespace whose oldest due schedule is oldest.
            ['noisy-1', 'quiet-1', 'noisy-2'],
            ['noisy-3', 'quiet-2', 'noisy-4'],
            ['noisy-5'],
            [],
        ], $passes);

        $this->assertEveryScheduleFiredOnce(7);
    }

    public function testTheDefaultPassStartsOneHundredDueSchedulesAndTheNextPassStartsTheRest(): void
    {
        for ($index = 1; $index <= 101; $index++) {
            $this->dueSchedule(sprintf('bulk-%03d', $index), 'bulk', 5);
        }

        $first = $this->tick();
        $second = $this->tick();
        $third = $this->tick();

        $this->assertCount(100, $first);
        $this->assertCount(1, $second);
        $this->assertSame([], $third);
        $this->assertSame(
            WorkflowSchedule::query()->pluck('schedule_id')->sort()->values()->all(),
            collect(array_merge($first, $second))
                ->pluck('schedule_id')
                ->sort()
                ->values()
                ->all(),
        );
        $this->assertEveryScheduleFiredOnce(101);
    }

    public function testTheConfiguredTickLimitLetsOnePassFireTheWholeBurst(): void
    {
        config()
            ->set('workflows.v2.schedules.tick_limit', '6');

        foreach (['alpha', 'beta'] as $namespace) {
            for ($index = 1; $index <= 3; $index++) {
                $this->dueSchedule("{$namespace}-{$index}", $namespace, $index);
            }
        }

        $this->assertCount(6, $this->tick());
        $this->assertSame([], $this->tick());
        $this->assertEveryScheduleFiredOnce(6);
    }

    public function testBufferedActionsDrainAtTheLimitPerPassAlongsideDueSchedules(): void
    {
        foreach ([
            'alpha-1' => 40,
            'alpha-2' => 30,
            'alpha-3' => 20,
        ] as $scheduleId => $secondsAgo) {
            $this->bufferedSchedule($scheduleId, 'alpha', $secondsAgo);
        }
        $this->bufferedSchedule('beta-buffered', 'beta', 10);
        foreach ([
            'beta-due-1' => 3,
            'beta-due-2' => 2,
            'beta-due-3' => 1,
        ] as $scheduleId => $minutesAgo) {
            $this->dueSchedule($scheduleId, 'beta', $minutesAgo);
        }

        $passes = [];
        do {
            $pass = $this->tick([
                '--limit' => '2',
            ]);
            $passes[] = array_map(
                static fn (array $row): string => $row['schedule_id'] . ':' . $row['outcome'],
                $pass,
            );
            foreach ($pass as $row) {
                $this->assertNotNull($row['instance_id'], json_encode($row, JSON_THROW_ON_ERROR));
            }
        } while ($pass !== [] && count($passes) < 10);

        $this->assertSame([
            ['alpha-1:drained', 'beta-buffered:drained', 'beta-due-1:triggered', 'beta-due-2:triggered'],
            ['alpha-2:drained', 'alpha-3:drained', 'beta-due-3:triggered'],
            [],
        ], $passes);

        foreach (WorkflowSchedule::query()->get() as $schedule) {
            $this->assertFalse($schedule->hasBufferedActions(), $schedule->schedule_id);
        }
        $this->assertEveryScheduleFiredOnce(7);
    }

    /**
     * @param array<string, string> $options
     * @return list<array<string, mixed>>
     */
    private function tick(array $options = []): array
    {
        $exitCode = Artisan::call('workflow:v2:schedule-tick', [
            '--json' => true,
        ] + $options);
        $output = trim(Artisan::output());

        $this->assertSame(0, $exitCode, $output);

        $results = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($results);

        return $results;
    }

    private function dueSchedule(string $scheduleId, string $namespace, int $minutesAgo): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: $scheduleId,
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
            namespace: $namespace,
        );
        $schedule->forceFill([
            'next_fire_at' => now()
                ->subMinutes($minutesAgo),
        ])->save();
    }

    private function bufferedSchedule(string $scheduleId, string $namespace, int $secondsAgo): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: $scheduleId,
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 0 1 1 *',
            overlapPolicy: ScheduleOverlapPolicy::BufferOne,
            namespace: $namespace,
        );
        $schedule->bufferAction(now()->subSeconds($secondsAgo));
        $schedule->forceFill([
            'updated_at' => now()
                ->subSeconds($secondsAgo),
        ])->save();
    }

    private function assertEveryScheduleFiredOnce(int $expected): void
    {
        $schedules = WorkflowSchedule::query()->get();

        $this->assertCount($expected, $schedules);
        foreach ($schedules as $schedule) {
            $this->assertSame(1, (int) $schedule->fires_count, $schedule->schedule_id);
            $this->assertSame(0, (int) $schedule->failures_count, $schedule->schedule_id);
            $this->assertTrue($schedule->next_fire_at->greaterThan(now()), $schedule->schedule_id);
        }
    }
}
