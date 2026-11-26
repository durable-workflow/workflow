<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

/**
 * Replay corpus workflow: returns the replay state it observed before each
 * step, so a cold replay proves where the committed boundary lies.
 */
#[Type('test-replay-state-observing-workflow')]
final class TestReplayStateObservingWorkflow extends Workflow
{
    public function handle(): array
    {
        $states = [$this->replayState()->value];
        $first = activity(TestGreetingActivity::class, 'first');
        $states[] = $this->replayState()->value;
        $second = activity(TestGreetingActivity::class, 'second');
        $states[] = $this->replayState()->value;

        return [
            'results' => [$first, $second],
            'states' => $states,
        ];
    }
}
