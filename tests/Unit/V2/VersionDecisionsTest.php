<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use LogicException;
use PHPUnit\Framework\TestCase;
use Workflow\Exceptions\VersionNotSupportedException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Support\VersionCall;
use Workflow\V2\Support\VersionDecisions;

final class VersionDecisionsTest extends TestCase
{
    public function testFreshDecisionsAreIndependentAndReuseTheirFirstRange(): void
    {
        $decisions = new VersionDecisions([]);
        $first = $decisions->resolve(null, new VersionCall('same', -1, 1), 1);
        $repeat = $decisions->resolve(null, new VersionCall('same', -1, 5), 2);
        $other = $decisions->resolve(null, new VersionCall('other', -1, 3), 2);
        $this->assertSame(1, $first->version);
        $this->assertTrue($first->shouldRecordMarker);
        $this->assertSame(1, $repeat->version);
        $this->assertFalse($repeat->shouldRecordMarker);
        $this->assertFalse($repeat->advancesSequence);
        $this->assertSame(3, $other->version);
        $this->assertSame(5, (new VersionDecisions([]))->resolve(null, new VersionCall('same', -1, 5), 1)->version);

        $this->expectException(VersionNotSupportedException::class);
        $decisions->resolve(null, new VersionCall('same', 2, 5), 2);
    }

    public function testAppendedHistoryRetainsTheDecisionAndLegacyPhysicalMarkers(): void
    {
        $first = $this->marker(1, 1);
        $second = $this->marker(2, 1);
        $decisions = new VersionDecisions([$first]);
        $this->assertNull($decisions->duplicateAt(1));
        $this->assertSame(1, $decisions->resolve(null, VersionCall::patched('same'), 1)->version);
        $decisions->replaceMarkers([$first, $second]);
        $this->assertSame($second, $decisions->duplicateAt(2));
        $decisions->assertDuplicate(null, $second, 2);
        $this->assertFalse($decisions->resolve(null, VersionCall::deprecatePatch('same'), 3)->advancesSequence);
        $this->assertNull($decisions->markerAt(3));
        $this->assertSame(2, $second->payload['sequence']);
    }

    public function testConflictingRetainedVersionsAreRefusedBeforeReplay(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('conflicts with its original decision');
        new VersionDecisions([$this->marker(1, 1), $this->marker(2, 2)]);
    }

    public function testNewHistoryCannotReplaceAPreviouslyChosenDecision(): void
    {
        $decisions = new VersionDecisions([]);
        $decisions->resolve(null, VersionCall::patched('same'), 1);
        $this->expectException(LogicException::class);
        $decisions->replaceMarkers([$this->marker(1, 0)]);
    }

    public function testDuplicatePhysicalSequencesAreRefused(): void
    {
        $this->expectException(LogicException::class);
        new VersionDecisions([$this->marker(1, 1), $this->marker(1, 1)]);
    }

    public function testMalformedMarkerVersionIsRefused(): void
    {
        $marker = $this->marker(1, 1);
        $marker->payload = array_replace($marker->payload, [
            'version' => true,
        ]);
        $this->expectException(LogicException::class);
        new VersionDecisions([$marker]);
    }

    private function marker(int $sequence, int $version): WorkflowHistoryEvent
    {
        return new WorkflowHistoryEvent([
            'event_type' => HistoryEventType::VersionMarkerRecorded,
            'payload' => [
                'sequence' => $sequence,
                'change_id' => 'same',
                'version' => $version,
                'min_supported' => -1,
                'max_supported' => 2,
            ],
        ]);
    }
}
