<?php

declare(strict_types=1);

namespace Tests\Performance;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FanOutWidthProbe;
use Tests\TestCase;

/**
 * The documented fan-out limit, literally: one all() of 1,000 activities or
 * 1,000 child workflows with 10 KB arguments (#1203, #1231).
 *
 * Each of the run's workflow tasks must finish inside Laravel's default
 * 60-second queue job timeout, and must cost roughly ten times the 100-member
 * run's. Run explicitly; the fan-out scaling CI job does:
 *
 *     FANOUT_LITERAL=1 vendor/bin/phpunit tests/Performance/FanOutWidthLiteralTest.php
 */
final class FanOutWidthLiteralTest extends TestCase
{
    private const REFERENCE_WIDTH = 100;

    private const LITERAL_WIDTH = 1_000;

    private const PAYLOAD_BYTES = 10_240;

    private const MAX_GROWTH = 25.0;

    private const NOISE_FLOOR_SECONDS = 0.25;

    /**
     * Seconds any one workflow task may take on a hosted runner: inside the
     * default 60-second job timeout with headroom. Starting 1,000 children,
     * the slowest task, takes about 33 s on MySQL and 39 s on SQLite locally.
     */
    private const MAX_WORKFLOW_TASK_SECONDS = 50.0;

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
    public function testLiteralThousandMemberFanOut(string $kind): void
    {
        if (getenv('FANOUT_LITERAL') !== '1') {
            $this->markTestSkipped('The literal 1,000-member fan-out runs in the slow fan-out scaling job.');
        }

        Queue::fake();
        $probe = new FanOutWidthProbe($this->app);
        $probe->run($kind, 3, self::PAYLOAD_BYTES, "literal-warmup-{$kind}", refreshMemberProjections: false);
        $reference = $probe->run(
            $kind,
            self::REFERENCE_WIDTH,
            self::PAYLOAD_BYTES,
            "literal-reference-{$kind}",
            refreshMemberProjections: false
        );
        $literal = $probe->run(
            $kind,
            self::LITERAL_WIDTH,
            self::PAYLOAD_BYTES,
            "literal-{$kind}",
            refreshMemberProjections: false
        );
        $report = json_encode([
            'kind' => $kind,
            'reference' => $reference,
            'literal' => $literal,
        ], JSON_PRETTY_PRINT);
        fwrite(STDOUT, "\n{$report}\n");

        $this->assertSame([
            'members' => self::LITERAL_WIDTH,
            'total_bytes' => self::LITERAL_WIDTH * self::PAYLOAD_BYTES,
        ], $literal['output']);

        foreach (['scheduling', 'final'] as $phase) {
            $this->assertLessThan(
                self::MAX_WORKFLOW_TASK_SECONDS,
                $literal[$phase]['seconds'],
                "The {$phase} workflow task would outlast the default queue job timeout.\n{$report}",
            );
            $this->assertLessThanOrEqual(
                self::MAX_GROWTH * max($reference[$phase]['seconds'], self::NOISE_FLOOR_SECONDS),
                $literal[$phase]['seconds'],
                "The {$phase} workflow task grew faster than linearly with fan-out width.\n{$report}",
            );
        }
    }

    protected function startQueueWorkersAutomatically(): bool
    {
        return false;
    }
}
