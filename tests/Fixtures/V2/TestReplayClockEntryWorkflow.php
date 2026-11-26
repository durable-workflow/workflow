<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

/**
 * Reads workflow time at entry, after an activity and after a timer, at full precision.
 */
#[Type('test-replay-clock-entry-workflow')]
final class TestReplayClockEntryWorkflow extends Workflow
{
    /**
     * @return array{entry: string, after_activity: string, after_timer: string}
     */
    public function handle(): array
    {
        $entry = Workflow::now()->format('Y-m-d\TH:i:s.uP');
        Workflow::activity('demo.step');
        $afterActivity = Workflow::now()->format('Y-m-d\TH:i:s.uP');
        Workflow::timer(2);

        return [
            'entry' => $entry,
            'after_activity' => $afterActivity,
            'after_timer' => Workflow::now()->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}
