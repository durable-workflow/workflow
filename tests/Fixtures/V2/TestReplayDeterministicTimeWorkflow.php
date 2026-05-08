<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\now;

use Workflow\V2\Workflow;

/**
 * Fixture used by V2DeterministicTimeReplayTest to verify that
 * Workflow::now() during replay returns the deterministic event time
 * seeded by the executor — not ambient Carbon::now() / wall-clock.
 *
 * The observed reads are stored on instance properties so the test can
 * inspect them on the workflow returned from WorkflowReplayer::replay().
 */
final class TestReplayDeterministicTimeWorkflow extends Workflow
{
    public ?int $observedStartMs = null;

    public ?int $observedAfterActivityMs = null;

    public function handle(string $name): array
    {
        $timeAtStart = now();
        $this->observedStartMs = $timeAtStart->getTimestampMs();

        $greeting = activity(TestGreetingActivity::class, $name);

        $timeAfterActivity = now();
        $this->observedAfterActivityMs = $timeAfterActivity->getTimestampMs();

        return [
            'greeting' => $greeting,
            'time_at_start_ms' => $timeAtStart->getTimestampMs(),
            'time_after_activity_ms' => $timeAfterActivity->getTimestampMs(),
        ];
    }
}
