<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Tests\TestCase;
use Workflow\V2\Support\TimerTransportChunker;

final class TimerTransportChunkerTest extends TestCase
{
    public function testMaxDispatchDelayReturnsNullForNonSqsDriver(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');

        $this->assertNull(TimerTransportChunker::maxDispatchDelaySeconds());
    }

    public function testMaxDispatchDelayReturns900ForSqsDriver(): void
    {
        config()->set('queue.default', 'sqs');
        config()
            ->set('queue.connections.sqs.driver', 'sqs');

        $this->assertSame(900, TimerTransportChunker::maxDispatchDelaySeconds());
    }

    public function testMaxReleaseDelayReturnsNullForNonSqsDriver(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');

        $this->assertNull(TimerTransportChunker::maxReleaseDelaySeconds());
    }

    public function testMaxReleaseDelayReturns43200ForSqsDriver(): void
    {
        config()->set('queue.default', 'sqs');
        config()
            ->set('queue.connections.sqs.driver', 'sqs');

        $this->assertSame(43200, TimerTransportChunker::maxReleaseDelaySeconds());
    }

    public function testCappedDispatchDelayReturnsOriginalWhenWithinLimit(): void
    {
        config()->set('queue.default', 'sqs');
        config()
            ->set('queue.connections.sqs.driver', 'sqs');

        $now = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($now);

        $availableAt = $now->copy()
            ->addSeconds(600);
        $result = TimerTransportChunker::cappedDispatchDelay($availableAt);

        $this->assertEquals($availableAt->toIso8601String(), $result->toIso8601String());

        Carbon::setTestNow();
    }

    public function testCappedDispatchDelayCapsToMaxForSqs(): void
    {
        config()->set('queue.default', 'sqs');
        config()
            ->set('queue.connections.sqs.driver', 'sqs');

        $now = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($now);

        $availableAt = $now->copy()
            ->addSeconds(7200); // 2 hours — exceeds SQS 900s
        $result = TimerTransportChunker::cappedDispatchDelay($availableAt);

        $expectedMax = $now->copy()
            ->addSeconds(900);
        $this->assertLessThanOrEqual(900, $result->diffInSeconds($now));

        Carbon::setTestNow();
    }

    public function testCappedDispatchDelayDoesNotCapForRedis(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');

        $now = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($now);

        $availableAt = $now->copy()
            ->addSeconds(7200);
        $result = TimerTransportChunker::cappedDispatchDelay($availableAt);

        $this->assertEquals($availableAt->toIso8601String(), $result->toIso8601String());

        Carbon::setTestNow();
    }

    public function testCappedReleaseDelayCapsForSqs(): void
    {
        config()->set('queue.default', 'sqs');
        config()
            ->set('queue.connections.sqs.driver', 'sqs');

        $this->assertSame(43200, TimerTransportChunker::cappedReleaseDelay(86400));
        $this->assertSame(3600, TimerTransportChunker::cappedReleaseDelay(3600));
    }

    public function testCappedReleaseDelayDoesNotCapForRedis(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');

        $this->assertSame(86400, TimerTransportChunker::cappedReleaseDelay(86400));
    }
}
