<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Carbon\CarbonImmutable;
use Fiber;
use Illuminate\Support\Carbon;
use Tests\NonDatabaseTestCase;
use function Workflow\V2\now;
use Workflow\V2\Support\WorkflowFiberContext;

final class WorkflowFiberContextTimeTest extends NonDatabaseTestCase
{
    public function testDeadlineArithmeticDoesNotAdvanceTheWorkflowClock(): void
    {
        $fiber = new Fiber(function (): void {
            WorkflowFiberContext::enter();

            try {
                WorkflowFiberContext::setTime(Carbon::parse('2026-01-01T00:00:00.123456+05:30'));
                $first = now();
                $second = now();

                $this->assertNotSame($first, $second);
                $this->assertSame($first->format('Y-m-d H:i:s.uP'), $second->format('Y-m-d H:i:s.uP'));

                $deadline = $first->addHour();

                $this->assertSame('2026-01-01 00:00:00.123456+05:30', now()->format('Y-m-d H:i:s.uP'));
                $this->assertSame('2026-01-01 00:00:00.123456+05:30', $second->format('Y-m-d H:i:s.uP'));
                $this->assertFalse(now()->greaterThanOrEqualTo($deadline));
            } finally {
                WorkflowFiberContext::leave();
            }
        });

        $fiber->start();
    }

    public function testSetTimeDoesNotRetainTheCallersMutableObject(): void
    {
        $fiber = new Fiber(function (): void {
            WorkflowFiberContext::enter();

            try {
                $event = Carbon::parse('2026-01-01T00:00:00.123456Z');
                WorkflowFiberContext::setTime($event);
                $event->addHour();

                $this->assertSame('2026-01-01 00:00:00.123456', now()->format('Y-m-d H:i:s.u'));
            } finally {
                WorkflowFiberContext::leave();
            }
        });

        $fiber->start();
    }

    public function testExplicitFiberClocksRemainIndependentWhenCallerAndReadValuesChange(): void
    {
        $event = Carbon::parse('2026-01-01T00:00:00Z');
        $first = new Fiber(static function (): void {
            WorkflowFiberContext::enter();

            try {
                now()->addDay();
            } finally {
                WorkflowFiberContext::leave();
            }
        });
        $second = new Fiber(function (): void {
            WorkflowFiberContext::enter();

            try {
                $this->assertSame('2026-01-01T00:00:00+00:00', now()->toIso8601String());
                Fiber::suspend();
                $this->assertSame('2026-01-01T01:00:00+00:00', now()->toIso8601String());
            } finally {
                WorkflowFiberContext::leave();
            }
        });

        WorkflowFiberContext::setTime($event, $first);
        WorkflowFiberContext::setTime($event, $second);
        $event->addWeek();
        $first->start();
        $second->start();

        $nextEvent = Carbon::parse('2026-01-01T01:00:00Z');
        WorkflowFiberContext::setTime($nextEvent, $second);
        $nextEvent->addDay();
        $second->resume();
    }

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

        $fiber = new Fiber(static function () use (&$observed): void {
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

        $this->assertInstanceOf(CarbonImmutable::class, $observed);
        $this->assertNotSame($event, $observed);
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

        $fiber = new Fiber(static function () use (&$afterLeave): void {
            WorkflowFiberContext::enter();
            WorkflowFiberContext::leave();
            $afterLeave = WorkflowFiberContext::getTime();
        });

        $fiber->start();

        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $afterLeave);
    }
}
