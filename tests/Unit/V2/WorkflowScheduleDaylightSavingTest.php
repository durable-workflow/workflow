<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowSchedule;

/**
 * Daylight saving rules for cron schedules with a named timezone (#882):
 * a repeated wall-clock time fires once unless the hour field is `*`, and a
 * skipped wall-clock time fires once, moved forward by the gap.
 */
final class WorkflowScheduleDaylightSavingTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, list<string>}>
     */
    public static function occurrenceCases(): array
    {
        return [
            'new york daily time repeated when clocks go back' => [
                '30 1 * * *',
                'America/New_York',
                '2026-10-31T12:00:00Z',
                ['2026-11-01T05:30:00Z', '2026-11-02T06:30:00Z', '2026-11-03T06:30:00Z'],
            ],
            'new york daily time just after the repeated hour' => [
                '30 2 * * *',
                'America/New_York',
                '2026-10-31T12:00:00Z',
                ['2026-11-01T07:30:00Z', '2026-11-02T07:30:00Z'],
            ],
            'new york daily time skipped when clocks go forward' => [
                '30 2 * * *',
                'America/New_York',
                '2026-03-07T12:00:00Z',
                ['2026-03-08T07:30:00Z', '2026-03-09T06:30:00Z'],
            ],
            'new york daily time before the gap' => [
                '30 1 * * *',
                'America/New_York',
                '2026-03-07T12:00:00Z',
                ['2026-03-08T06:30:00Z', '2026-03-09T05:30:00Z'],
            ],
            'kyiv daily time repeated when clocks go back' => [
                '30 3 * * *',
                'Europe/Kyiv',
                '2026-10-24T12:00:00Z',
                ['2026-10-25T00:30:00Z', '2026-10-26T01:30:00Z'],
            ],
            'kyiv daily time skipped when clocks go forward' => [
                '30 3 * * *',
                'Europe/Kyiv',
                '2026-03-28T12:00:00Z',
                ['2026-03-29T01:30:00Z', '2026-03-30T00:30:00Z'],
            ],
            'sub-hourly fixed hour fires once per wall-clock minute' => [
                '*/15 1 * * *',
                'America/New_York',
                '2026-11-01T05:10:00Z',
                ['2026-11-01T05:15:00Z', '2026-11-01T05:30:00Z', '2026-11-01T05:45:00Z', '2026-11-02T06:00:00Z'],
            ],
            'sub-hourly wildcard hour keeps elapsed time through the repeated hour' => [
                '*/15 * * * *',
                'America/New_York',
                '2026-11-01T05:40:00Z',
                [
                    '2026-11-01T05:45:00Z',
                    '2026-11-01T06:00:00Z',
                    '2026-11-01T06:15:00Z',
                    '2026-11-01T06:30:00Z',
                    '2026-11-01T06:45:00Z',
                    '2026-11-01T07:00:00Z',
                ],
            ],
            'every-hour step keeps elapsed time like a wildcard' => [
                '30 */1 * * *',
                'America/New_York',
                '2026-11-01T05:30:00Z',
                ['2026-11-01T06:30:00Z', '2026-11-01T07:30:00Z'],
            ],
            'full hour range keeps elapsed time like a wildcard' => [
                '30 0-23 * * *',
                'America/New_York',
                '2026-11-01T05:30:00Z',
                ['2026-11-01T06:30:00Z', '2026-11-01T07:30:00Z'],
            ],
            'positive fixed offset' => [
                '0 9 * * *',
                '+02:00',
                '2026-11-03T00:00:00Z',
                ['2026-11-03T07:00:00Z', '2026-11-04T07:00:00Z'],
            ],
            'negative fixed offset' => [
                '0 9 * * *',
                '-05:00',
                '2026-11-03T00:00:00Z',
                ['2026-11-03T14:00:00Z', '2026-11-04T14:00:00Z'],
            ],
            'utc is unaffected' => [
                '30 1 * * *',
                'UTC',
                '2026-10-31T12:00:00Z',
                ['2026-11-01T01:30:00Z', '2026-11-02T01:30:00Z'],
            ],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('occurrenceCases')]
    public function testCronOccurrencesFollowDaylightSavingRules(
        string $expression,
        string $timezone,
        string $startUtc,
        array $expected,
    ): void {
        $schedule = new WorkflowSchedule();
        $schedule->spec = [
            'cron_expressions' => [$expression],
            'timezone' => $timezone,
        ];

        $this->assertSame($expected, $this->occurrences(
            static fn (DateTimeInterface $after): ?DateTimeInterface => $schedule->computeNextFireAt($after),
            $startUtc,
            count($expected),
        ));
    }

    public function testRepeatedWindowEndIsReportedOnlyForTheSecondPass(): void
    {
        $newYork = new DateTimeZone('America/New_York');

        $this->assertNull(
            WorkflowSchedule::repeatedWallClockWindowEnd($this->timestamp('2026-11-01T05:30:00Z'), $newYork)
        );
        $this->assertSame(
            $this->timestamp('2026-11-01T07:00:00Z'),
            WorkflowSchedule::repeatedWallClockWindowEnd($this->timestamp('2026-11-01T06:00:00Z'), $newYork),
        );
        $this->assertSame(
            $this->timestamp('2026-11-01T07:00:00Z'),
            WorkflowSchedule::repeatedWallClockWindowEnd($this->timestamp('2026-11-01T06:59:00Z'), $newYork),
        );
        $this->assertNull(
            WorkflowSchedule::repeatedWallClockWindowEnd($this->timestamp('2026-11-01T07:00:00Z'), $newYork)
        );
        $this->assertNull(
            WorkflowSchedule::repeatedWallClockWindowEnd($this->timestamp('2026-03-08T07:30:00Z'), $newYork)
        );
        $this->assertNull(
            WorkflowSchedule::repeatedWallClockWindowEnd(
                $this->timestamp('2026-11-01T06:30:00Z'),
                new DateTimeZone('UTC')
            )
        );
    }

    /**
     * The fallback scanner, used without dragonmantank/cron-expression, visits
     * absolute minutes: a repeated hour is seen twice (the caller drops the
     * second pass) and a skipped time is moved forward by the gap.
     *
     * @return array<string, array{string, string, string, list<string>}>
     */
    public static function scannerCases(): array
    {
        return [
            'repeated hour is visited twice' => [
                '30 1 * * *',
                'America/New_York',
                '2026-11-01T05:00:00Z',
                ['2026-11-01T05:30:00Z', '2026-11-01T06:30:00Z', '2026-11-02T06:30:00Z'],
            ],
            'skipped time moves forward by the gap' => [
                '30 2 * * *',
                'America/New_York',
                '2026-03-07T12:00:00Z',
                ['2026-03-08T07:30:00Z', '2026-03-09T06:30:00Z'],
            ],
            'kyiv skipped time moves forward by the gap' => [
                '30 3 * * *',
                'Europe/Kyiv',
                '2026-03-28T12:00:00Z',
                ['2026-03-29T01:30:00Z', '2026-03-30T00:30:00Z'],
            ],
            'positive fixed offset' => [
                '0 9 * * *',
                '+02:00',
                '2026-11-03T00:00:00Z',
                ['2026-11-03T07:00:00Z', '2026-11-04T07:00:00Z'],
            ],
            'negative fixed offset' => [
                '0 9 * * *',
                '-05:00',
                '2026-11-03T00:00:00Z',
                ['2026-11-03T14:00:00Z', '2026-11-04T14:00:00Z'],
            ],
            'every-hour step matches every hour' => [
                '30 0/1 * * *',
                'UTC',
                '2026-11-03T00:00:00Z',
                ['2026-11-03T00:30:00Z', '2026-11-03T01:30:00Z'],
            ],
            'wildcard hour keeps elapsed time' => [
                '*/30 * * * *',
                'America/New_York',
                '2026-11-01T05:10:00Z',
                ['2026-11-01T05:30:00Z', '2026-11-01T06:00:00Z', '2026-11-01T06:30:00Z', '2026-11-01T07:00:00Z'],
            ],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('scannerCases')]
    public function testFallbackScannerVisitsAbsoluteMinutes(
        string $expression,
        string $timezone,
        string $startUtc,
        array $expected,
    ): void {
        $scanner = new ReflectionMethod(WorkflowSchedule::class, 'scanNextCronOccurrence');
        $tz = new DateTimeZone($timezone);

        $this->assertSame($expected, $this->occurrences(
            static fn (DateTimeInterface $after): ?DateTimeInterface => $scanner->invoke(
                null,
                $expression,
                $after,
                $tz
            ),
            $startUtc,
            count($expected),
        ));
    }

    /**
     * @param  callable(DateTimeInterface): ?DateTimeInterface  $next
     * @return list<string>
     */
    private function occurrences(callable $next, string $startUtc, int $count): array
    {
        $after = Carbon::parse($startUtc);
        $occurrences = [];

        for ($index = 0; $index < $count; $index++) {
            $occurrence = $next($after);
            $this->assertNotNull($occurrence);
            $occurrences[] = Carbon::createFromTimestamp($occurrence->getTimestamp(), 'UTC')->format('Y-m-d\TH:i:s\Z');
            $after = $occurrence;
        }

        return $occurrences;
    }

    private function timestamp(string $utc): int
    {
        return Carbon::parse($utc)->getTimestamp();
    }
}
