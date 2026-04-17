<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Carbon\CarbonImmutable;
use Fiber;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Workflow\V2\Support\WorkflowFiberContext;

final class WorkflowFiberContextTimeTest extends TestCase
{
    public function testGetTimeFallsBackToWallClockOutsideFiber(): void
    {
        $frozen = Carbon::parse('2026-01-01T12:00:00Z');
        Carbon::setTestNow($frozen);

        try {
            $time = WorkflowFiberContext::getTime();

            $this->assertSame($frozen->getTimestampMs(), $time->getTimestampMs());
        } finally {
            Carbon::setTestNow(null);
        }
    }

    public function testSetTimeWithFiberArgumentStoresPerFiberTime(): void
    {
        $event = CarbonImmutable::parse('2026-03-14T15:09:26Z');
        $observed = null;

        $fiber = new Fiber(function () use (&$observed): void {
            WorkflowFiberContext::enter();

            try {
                Fiber::suspend();

                $observed = WorkflowFiberContext::getTime();
            } finally {
                WorkflowFiberContext::leave();
            }
        });

        $fiber->start();

        WorkflowFiberContext::setTime($event, $fiber);

        $fiber->resume();

        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $observed);
        $this->assertSame(
            $event->getTimestampMs(),
            $observed->getTimestampMs(),
            'setTime with an explicit fiber must be observable from that fiber on resume.'
        );
    }

    public function testLeaveClearsStoredTime(): void
    {
        $event = CarbonImmutable::parse('2026-03-14T15:09:26Z');
        $afterLeave = null;

        $fiber = new Fiber(function () use (&$afterLeave): void {
            WorkflowFiberContext::enter();
            WorkflowFiberContext::leave();
            $afterLeave = WorkflowFiberContext::getTime();
        });

        $fiber->start();

        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $afterLeave);
    }
}
