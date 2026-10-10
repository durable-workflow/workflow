<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Workflow;

final class VersionDecisionClockCompatibilityTest extends TestCase
{
    public function testRetainedRepeatedCallsPreserveBothRecordedClockBoundaries(): void
    {
        $history = [];
        foreach ([1, 2] as $sequence) {
            $history[] = [
                'sequence' => $sequence,
                'event_type' => 'VersionMarkerRecorded',
                'payload' => [
                    'sequence' => $sequence,
                    'change_id' => 'same',
                    'version' => 1,
                    'min_supported' => -1,
                    'max_supported' => 1,
                ],
                'recorded_at' => sprintf('2026-10-01T12:00:0%d+00:00', $sequence),
            ];
        }

        $step = WorkflowFiberRunner::forClass(
            VersionDecisionClockWorkflow::class,
            'clock-workflow',
            'clock-run',
            [],
            'avro',
            $history,
        )->step();

        self::assertTrue($step->completed);
        self::assertSame(['2026-10-01T12:00:01+00:00', '2026-10-01T12:00:02+00:00'], $step->result);
    }
}

final class VersionDecisionClockWorkflow extends Workflow
{
    public function handle(): array
    {
        self::patched('same');
        $first = self::now()->toIso8601String();
        self::patched('same');

        return [$first, self::now()->toIso8601String()];
    }
}
