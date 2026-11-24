<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FanOutWidthProbe;
use Tests\TestCase;

/**
 * A fan-out's workflow tasks and member projections stay within the same
 * growth bound as width increases (#1203, #1231, #1240).
 *
 * The scheduling task used to rebuild the run summary once per published
 * member, and the final task rescanned the whole history for every member,
 * so both grew with the square of the width and a valid 300-member all()
 * outlasted the default 60-second queue job timeout. Ten times the members
 * must now cost about ten times as much: the bound allows 2.5 times linear
 * growth, far below the hundredfold growth of a quadratic step.
 *
 * The literal 1,000-member cases run in the slow fan-out scaling job
 * (tests/Performance/FanOutWidthLiteralTest.php).
 */
final class V2FanOutWidthScalingTest extends TestCase
{
    private const SMALL = 30;

    private const LARGE = 300;

    private const PAYLOAD_BYTES = 1024;

    /**
     * Ten times the members may cost at most this many times as much.
     */
    private const MAX_GROWTH = 25.0;

    /**
     * Seconds of timer and scheduler noise allowed on the small run.
     */
    private const NOISE_FLOOR_SECONDS = 0.25;

    /**
     * @return iterable<string, array{'activity'|'child'}>
     */
    public static function kinds(): iterable
    {
        yield 'activities' => ['activity'];
        yield 'children' => ['child'];
    }

    /**
     * @param 'activity'|'child' $kind
     */
    #[DataProvider('kinds')]
    public function testFanOutTasksAndMemberProjectionGrowth(string $kind): void
    {
        Queue::fake();
        // A frozen clock also keeps activity lease maintenance in process.
        $this->freezeTime();
        $probe = new FanOutWidthProbe($this->app);

        // Warm the autoloader, container and query compilation first.
        $probe->run($kind, 3, self::PAYLOAD_BYTES, "fan-out-warmup-{$kind}", refreshMemberProjections: true);
        $small = $probe->run(
            $kind,
            self::SMALL,
            self::PAYLOAD_BYTES,
            "fan-out-small-{$kind}",
            refreshMemberProjections: true
        );
        $large = $probe->run(
            $kind,
            self::LARGE,
            self::PAYLOAD_BYTES,
            "fan-out-large-{$kind}",
            refreshMemberProjections: true
        );

        foreach ([$small, $large] as $result) {
            $this->assertSame([
                'members' => $result['width'],
                'total_bytes' => $result['width'] * self::PAYLOAD_BYTES,
            ], $result['output']);
        }

        $report = json_encode([
            'kind' => $kind,
            'small' => $small,
            'large' => $large,
        ], JSON_PRETTY_PRINT);

        if (getenv('FANOUT_REPORT') === '1') {
            fwrite(STDOUT, "\n{$report}\n");
        }

        $this->assertLessThanOrEqual(
            self::MAX_GROWTH * $small['workflow_task_queries'],
            $large['workflow_task_queries'],
            "Workflow-task queries grew faster than linearly with fan-out width.\n{$report}",
        );

        $this->assertLessThanOrEqual(
            self::MAX_GROWTH * $small['members']['queries'],
            $large['members']['queries'],
            "Member queries exceeded the fan-out growth allowance.\n{$report}",
        );

        foreach (['scheduling', 'members', 'final'] as $phase) {
            $this->assertLessThanOrEqual(
                self::MAX_GROWTH * max($small[$phase]['seconds'], self::NOISE_FLOOR_SECONDS),
                $large[$phase]['seconds'],
                "The {$phase} phase exceeded the fan-out growth allowance.\n{$report}",
            );
        }
    }

    public function testChildFanOutCompletesWhenTheStructuralWarningSinkFails(): void
    {
        Queue::fake();
        config([
            'workflows.v2.structural_limits.pending_child_count' => 10,
            'workflows.v2.structural_limits.warning_threshold_percent' => 80,
        ]);
        Log::partialMock()->shouldReceive('warning')->withArgs(
            static fn (string $message, array $context): bool => ($context['limit_kind'] ?? null) === 'pending_child_count',
        )->atLeast()
            ->once()
            ->andThrow(new RuntimeException('The warning sink is unavailable.'));

        $result = (new FanOutWidthProbe($this->app))->run('child', 10, self::PAYLOAD_BYTES, 'fan-out-warning');

        $this->assertSame([
            'members' => 10,
            'total_bytes' => 10 * self::PAYLOAD_BYTES,
        ], $result['output']);
        $this->assertSame(10, $result['members']['tasks']);
    }

    protected function startQueueWorkersAutomatically(): bool
    {
        return false;
    }
}
