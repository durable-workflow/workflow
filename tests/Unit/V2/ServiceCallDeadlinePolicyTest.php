<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\ServiceCallDeadlinePolicy;

final class ServiceCallDeadlinePolicyTest extends TestCase
{
    public function testRelativeDeadlineRetainsOriginalAcceptancePrecisionAndTimezone(): void
    {
        $accepted = CarbonImmutable::parse('2026-10-11T12:30:00.123456+02:00');
        $deadline = ServiceCallDeadlinePolicy::resolve([
            'timeout_seconds' => 1,
        ], $accepted);
        self::assertSame('2026-10-11T10:30:01.123456Z', $deadline?->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame('2026-10-11T12:30:00.123456+02:00', $accepted->format('Y-m-d\TH:i:s.uP'));
        self::assertNull(ServiceCallDeadlinePolicy::resolve(null, $accepted));
    }

    public function testAbsoluteDeadlineRetainsItsInstantAndAcceptsAnAlreadyExpiredPolicy(): void
    {
        $accepted = CarbonImmutable::parse('2026-10-11T12:30:00Z');
        foreach (['2026-10-10T14:30:00.25+02:00', '2026-10-10T12:30:00.250000Z'] as $value) {
            self::assertSame(
                '2026-10-10T12:30:00.250000Z',
                ServiceCallDeadlinePolicy::resolve([
                    'deadline_at' => $value,
                ], $accepted)?->format('Y-m-d\TH:i:s.u\Z')
            );
        }
        self::assertSame(
            '2026-10-10T12:30:00.000000Z',
            ServiceCallDeadlinePolicy::resolve([
                'deadline_at' => '2026-10-10T12:30:00Z',
            ], $accepted)?->format('Y-m-d\TH:i:s.u\Z')
        );
    }

    public function testInvalidPoliciesCannotBecomeAcceptedDeadlines(): void
    {
        $accepted = CarbonImmutable::parse('2026-10-11T12:30:00Z');
        $invalid = [[], [
            'other' => 1,
        ], [
            'timeout_seconds' => 0,
        ], [
            'timeout_seconds' => -1,
        ],
            [
                'timeout_seconds' => 1.0,
            ], [
                'timeout_seconds' => '1',
            ], [
                'timeout_seconds' => true,
            ],
            [
                'timeout_seconds' => 2_147_483_648,
            ], [
                'timeout_seconds' => 1,
                'deadline_at' => '2026-10-11T12:30:00Z',
            ],
            [
                'deadline_at' => '2026-10-11T12:30:00',
            ], [
                'deadline_at' => '2026-02-30T12:30:00Z',
            ],
            [
                'deadline_at' => '2026-10-11T12:30:60Z',
            ], [
                'deadline_at' => '2026-10-11T12:30:00.1234567Z',
            ],
            [
                'deadline_at' => '2026-10-11T12:30:00+24:00',
            ], [
                'deadline_at' => 1,
            ],
            [
                'deadline_at' => '1969-12-31T23:59:59Z',
            ], [
                'deadline_at' => '9999-12-31T23:59:59-01:00',
            ],
        ];
        foreach ($invalid as $policy) {
            try {
                ServiceCallDeadlinePolicy::resolve($policy, $accepted);
                self::fail('Invalid deadline policy was accepted: ' . json_encode($policy));
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }
}
