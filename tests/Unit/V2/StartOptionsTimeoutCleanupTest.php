<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\TimeoutCleanup;

/**
 * Admission rules for deadline-triggered cleanup (#863).
 */
final class StartOptionsTimeoutCleanupTest extends TestCase
{
    public function testTheAllowanceReservesTheEndOfEveryHardTimeout(): void
    {
        $options = (new StartOptions(executionTimeoutSeconds: 70, runTimeoutSeconds: 40))->withTimeoutCleanup(10);

        $this->assertSame(10, $options->timeoutCleanupSeconds);
        $this->assertSame(70, $options->executionTimeoutSeconds);
        $this->assertSame(40, $options->runTimeoutSeconds);

        $start = Carbon::parse('2026-11-13T00:00:00Z');
        $this->assertSame([
            'allowance_seconds' => 10,
            'work_deadlines' => [
                'execution' => '2026-11-13T00:01:00+00:00',
                'run' => '2026-11-13T00:00:30+00:00',
            ],
        ], TimeoutCleanup::startedPayload(10, $start->copy()->addSeconds(70), $start->copy()->addSeconds(40)));
        $this->assertNull(TimeoutCleanup::startedPayload(null, $start->copy()->addSeconds(70), null));
    }

    public function testWithoutTheOptionNothingChanges(): void
    {
        $this->assertNull((new StartOptions(executionTimeoutSeconds: 70))->timeoutCleanupSeconds);
    }

    /**
     * @return iterable<string, array{?int, ?int, int}>
     */
    public static function refusals(): iterable
    {
        yield 'no hard timeout' => [null, null, 10];
        yield 'not shorter than the execution timeout' => [10, null, 10];
        yield 'not shorter than the run timeout' => [70, 10, 10];
        yield 'zero' => [70, null, 0];
        yield 'over a day' => [100000, null, 86401];
    }

    #[DataProvider('refusals')]
    public function testAnAllowanceThatLeavesNoWorkTimeIsRefusedAtAdmission(
        ?int $execution,
        ?int $run,
        int $cleanup,
    ): void {
        $this->expectException(LogicException::class);

        new StartOptions(
            executionTimeoutSeconds: $execution,
            runTimeoutSeconds: $run,
            timeoutCleanupSeconds: $cleanup,
        );
    }
}
