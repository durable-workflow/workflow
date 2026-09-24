<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\ActivityLease;

final class ActivityLeaseTest extends TestCase
{
    public function testExpiryKeepsDefaultMutableDateClass(): void
    {
        Date::useDefault();

        try {
            $now = Carbon::parse('2026-09-24 13:00:00 UTC');
            Date::setTestNow($now);

            $expiresAt = ActivityLease::expiresAt();

            $this->assertInstanceOf(Carbon::class, $expiresAt);
            $this->assertTrue($expiresAt->equalTo($now->copy()->addMinutes(ActivityLease::DURATION_MINUTES)));
        } finally {
            Date::setTestNow();
            Date::useDefault();
        }
    }

    public function testExpiryUsesConfiguredImmutableDateClass(): void
    {
        Date::use(CarbonImmutable::class);

        try {
            $now = CarbonImmutable::parse('2026-09-24 13:00:00 UTC');
            Date::setTestNow($now);

            $expiresAt = ActivityLease::expiresAt();

            $this->assertInstanceOf(CarbonImmutable::class, $expiresAt);
            $this->assertTrue($expiresAt->equalTo($now->addMinutes(ActivityLease::DURATION_MINUTES)));
            $this->assertTrue(now()->equalTo($now));
        } finally {
            Date::setTestNow();
            Date::useDefault();
        }
    }
}
