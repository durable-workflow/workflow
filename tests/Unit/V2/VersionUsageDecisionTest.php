<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\VersionUsageDecision;

final class VersionUsageDecisionTest extends TestCase
{
    public static function versions(): iterable
    {
        yield 'legacy default' => [-1, 1, 'legacy'];
        yield 'older' => [1, 2, 'legacy'];
        yield 'minimum' => [2, 2, 'current'];
        yield 'newer' => [3, 2, 'current'];
        yield 'signed minimum' => [-2147483648, -2147483648, 'current'];
        yield 'signed maximum' => [2147483647, 2147483647, 'current'];
    }

    #[DataProvider('versions')]
    public function testRecordedDecisionIsComparedWithTheProposedMinimum(
        int $version,
        int $minimum,
        string $state
    ): void {
        $decision = new VersionUsageDecision('shipping', $minimum, true);
        $decision->observe($this->marker($version));
        $this->assertSame([
            'state' => $state,
            'recorded_version' => $version,
            'unknown_reason' => null,
        ], $decision->result());
    }

    public function testAbsentAndUnavailableHistoryCannotBeClassifiedCurrent(): void
    {
        $missing = new VersionUsageDecision('shipping', 1, true);
        $missing->observe($this->marker(2) + [
            'ignored' => true,
        ]);
        $this->assertSame('history_unavailable', $missing->result()['unknown_reason']);
        $empty = new VersionUsageDecision('shipping', 1, true);
        $this->assertSame('marker_absent', $empty->result()['unknown_reason']);
        $unavailable = new VersionUsageDecision('shipping', 1, false);
        $unavailable->observe($this->marker(2));
        $this->assertSame([
            'state' => 'unknown',
            'recorded_version' => null,
            'unknown_reason' => 'history_unavailable',
        ], $unavailable->result());
    }

    public function testIdenticalHistoricalDecisionsRemainValidButConflictsCannotBeOverwritten(): void
    {
        $decision = new VersionUsageDecision('shipping', 2, true);
        $decision->observe($this->marker(1));
        $decision->observe(array_replace($this->marker(1), [
            'sequence' => 2,
        ]));
        $this->assertSame('legacy', $decision->result()['state']);
        $decision->observe(array_replace($this->marker(2), [
            'sequence' => 3,
        ]));
        $decision->observe(array_replace($this->marker(1), [
            'sequence' => 4,
        ]));
        $this->assertSame([
            'state' => 'unknown',
            'recorded_version' => null,
            'unknown_reason' => 'history_invalid',
        ], $decision->result());
    }

    public function testRepeatedVersionMayRetainDifferentIndividuallyValidRanges(): void
    {
        $decision = new VersionUsageDecision('shipping', 2, true);
        $decision->observe(array_replace($this->marker(1), [
            'min_supported' => -1,
            'max_supported' => 1,
        ]));
        $decision->observe(array_replace($this->marker(1), [
            'sequence' => 2,
            'min_supported' => 0,
            'max_supported' => 4,
        ]));
        $this->assertSame([
            'state' => 'legacy',
            'recorded_version' => 1,
            'unknown_reason' => null,
        ], $decision->result());
    }

    public static function invalidMarkers(): iterable
    {
        yield 'not data' => [null];
        yield 'missing bounds' => [[
            'change_id' => 'shipping',
            'version' => 2,
            'sequence' => 1,
        ]];
        foreach ([
            'sequence' => 0,
            'change_id' => '',
            'version' => '2',
            'min_supported' => false,
            'max_supported' => 2147483648,
        ] as $key => $value) {
            yield $key => [
                array_replace(self::validMarker(), [
                    $key => $value,
                ])];
        }
        yield 'outside recorded range' => [
            array_replace(self::validMarker(), [
                'version' => 5,
                'max_supported' => 4,
            ])];
    }

    #[DataProvider('invalidMarkers')]
    public function testInvalidHistoryRemainsUnknown(mixed $marker): void
    {
        $decision = new VersionUsageDecision('shipping', 1, true);
        $decision->observe($marker);
        $this->assertSame('history_invalid', $decision->result()['unknown_reason']);
        $this->assertNull($decision->result()['recorded_version']);
    }

    public function testChangeIdsAreExactAndDuplicateLogicalPositionsAreInvalid(): void
    {
        $decision = new VersionUsageDecision('shipping', 1, true);
        $decision->observe(array_replace($this->marker(2), [
            'change_id' => 'Shipping',
        ]));
        $this->assertSame('marker_absent', $decision->result()['unknown_reason']);
        $decision->observe($this->marker(2));
        $this->assertSame('history_invalid', $decision->result()['unknown_reason']);
    }

    public function testTaskSnapshotIsKnownButOtherMarkerExtensionsRemainUnavailable(): void
    {
        $decision = new VersionUsageDecision('shipping', 2, true);
        $decision->observe($this->marker(2) + [
            'task' => [
                'id' => 'task-1',
                'status' => 'leased',
            ],
        ]);
        $this->assertSame('current', $decision->result()['state']);
        foreach ([[
            'task' => 'bad',
        ], [
            'task' => ['list'],
        ], [
            'future_format' => 2,
        ]] as $extension) {
            $other = new VersionUsageDecision('shipping', 2, true);
            $other->observe($this->marker(2) + $extension);
            $this->assertSame('history_unavailable', $other->result()['unknown_reason']);
        }
    }

    public function testInspectionBudgetCannotReturnAnOptimisticPartialDecision(): void
    {
        $decision = new VersionUsageDecision('shipping', 1, true);
        for ($sequence = 1; $sequence <= 1001; ++$sequence) {
            $decision->observe(array_replace($this->marker(2), [
                'sequence' => $sequence,
            ]));
        }
        $this->assertSame([
            'state' => 'unknown',
            'recorded_version' => null,
            'unknown_reason' => 'history_unavailable',
        ], $decision->result());
    }

    public static function invalidReuse(): iterable
    {
        yield 'source only' => [[
            'reused_from_run_id' => 'original-run',
        ]];
        yield 'clock only' => [[
            'reused_recorded_at' => '2026-11-14T01:02:03+00:00',
        ]];
        foreach ([[], null, '', ' '] as $index => $source) {
            yield 'source value ' . $index => [[
                'reused_from_run_id' => $source,
                'reused_recorded_at' => '2026-11-14T01:02:03+00:00',
            ]];
        }
        foreach ([[], null, '', ' ', 'tomorrow', 'not-a-clock', '2026-02-30T01:02:03+00:00',
            '2026-11-14T25:02:03+00:00', '2026-11-14T01:02:03+25:00',
            '2026-11-14T01:02:03.1234567+00:00'] as $index => $clock) {
            yield 'clock value ' . $index => [[
                'reused_from_run_id' => 'original-run',
                'reused_recorded_at' => $clock,
            ]];
        }
    }

    #[DataProvider('invalidReuse')]
    public function testMalformedReuseProvenanceCannotBecomeCurrent(array $reuse): void
    {
        $decision = new VersionUsageDecision('shipping', 2, true);
        $decision->observe($this->marker(2) + $reuse);
        $this->assertSame([
            'state' => 'unknown',
            'recorded_version' => null,
            'unknown_reason' => 'history_invalid',
        ], $decision->result());
    }

    public function testValidRetainedClocksKeepTheirOriginalVersionWithoutRewritingStrings(): void
    {
        foreach (['2026-11-14T01:02:03+00:00', '2026-11-14T01:02:03.123456+00:00',
            '2026-11-14T01:02:03.123Z', '2026-11-14T01:02:03Z', '2026-11-14T01:02:03-05:30'] as $clock) {
            $decision = new VersionUsageDecision('shipping', 2, true);
            $marker = $this->marker(2) + [
                'reused_from_run_id' => 'original-run',
                'reused_recorded_at' => $clock,
            ];
            $original = $marker;
            $decision->observe($marker);
            $this->assertSame('current', $decision->result()['state']);
            $this->assertSame($original, $marker);
        }
    }

    private function marker(int $version): array
    {
        return array_replace(self::validMarker(), [
            'version' => $version,
        ]);
    }

    private static function validMarker(): array
    {
        return [
            'sequence' => 1,
            'change_id' => 'shipping',
            'version' => 2,
            'min_supported' => -2147483648,
            'max_supported' => 2147483647,
        ];
    }
}
