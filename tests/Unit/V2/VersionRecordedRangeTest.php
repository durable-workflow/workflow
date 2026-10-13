<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\VersionCall;
use Workflow\V2\Support\VersionDecisions;
use Workflow\V2\Support\VersionResolver;

final class VersionRecordedRangeTest extends TestCase
{
    /**
     * @param array<string, mixed> $bounds
     */
    #[DataProvider('invalidBounds')]
    public function testResolverRejectsMalformedRecordedBounds(array $bounds): void
    {
        $this->expectException(LogicException::class);
        VersionResolver::resolve(new WorkflowRun(), $this->marker(1, $bounds), VersionCall::patched('same'), 1);
    }

    /**
     * @param array<string, mixed> $bounds
     */
    #[DataProvider('invalidBounds')]
    public function testHistoryIndexRejectsMalformedDuplicateBeforeSkippingIt(array $bounds): void
    {
        $this->expectException(LogicException::class);
        new VersionDecisions([$this->marker(1), $this->marker(2, $bounds)]);
    }

    public function testValidHistoricalRangesMayDifferAndCurrentRangeMayWiden(): void
    {
        $first = $this->marker(1);
        $second = $this->marker(2, [
            'min_supported' => 0,
            'max_supported' => 5,
        ]);
        $decisions = new VersionDecisions([$first, $second]);
        $this->assertSame(1, $decisions->resolve(null, VersionCall::patched('same'), 1)->version);
        $this->assertSame($second, $decisions->duplicateAt(2));
        $decisions->assertDuplicate(null, $second, 2);
        $this->assertSame(1, $decisions->resolve(null, new VersionCall('same', 0, 10), 2)->version);
        $this->assertSame(5, $second->payload['max_supported']);
    }

    public function testCompatibleHelpersShareTheNumericDecision(): void
    {
        $decisions = new VersionDecisions([]);
        $first = $decisions->resolve(null, new VersionCall('same', -1, 1), 1);
        $patched = $decisions->resolve(null, VersionCall::patched('same'), 2);
        $deprecated = $decisions->resolve(null, VersionCall::deprecatePatch('same'), 2);
        $widened = $decisions->resolve(null, new VersionCall('same', -1, 10), 2);
        $this->assertSame([1, 1, 1, 1], [$first->version, $patched->version, $deprecated->version, $widened->version]);
        $this->assertTrue($first->shouldRecordMarker);
        $this->assertFalse($patched->advancesSequence);
        $this->assertFalse($deprecated->advancesSequence);
        $this->assertFalse($widened->advancesSequence);
        $this->assertTrue(VersionCall::patched('same')->resolveValue($patched->version));
        $this->assertNull(VersionCall::deprecatePatch('same')->resolveValue($deprecated->version));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidBounds(): array
    {
        return [
            'inverted' => [[
                'min_supported' => 3,
                'max_supported' => 2,
            ]],
            'below minimum' => [[
                'min_supported' => 2,
                'max_supported' => 5,
            ]],
            'above maximum' => [[
                'min_supported' => -1,
                'max_supported' => 0,
            ]],
            'string minimum' => [[
                'min_supported' => '-1',
                'max_supported' => 1,
            ]],
            'boolean minimum' => [[
                'min_supported' => false,
                'max_supported' => 1,
            ]],
            'string maximum' => [[
                'min_supported' => -1,
                'max_supported' => '1',
            ]],
            'null maximum' => [[
                'min_supported' => -1,
                'max_supported' => null,
            ]],
            'missing maximum' => [[
                'min_supported' => -1,
            ]],
            'missing minimum' => [[
                'max_supported' => 1,
            ]],
            'missing both canonical bounds' => [[]],
        ];
    }

    /**
     * @param array<string, mixed> $bounds
     */
    private function marker(int $sequence, array $bounds = [
        'min_supported' => -1,
        'max_supported' => 1,
    ]): WorkflowHistoryEvent
    {
        return new WorkflowHistoryEvent([
            'event_type' => HistoryEventType::VersionMarkerRecorded,
            'payload' => [
                'sequence' => $sequence,
                'change_id' => 'same',
                'version' => 1,
            ] + $bounds,
        ]);
    }
}
