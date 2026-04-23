<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\now;

use Workflow\V2\Workflow;

final class TestDeterministicTimeWorkflow extends Workflow
{
    public function handle(string $name): array
    {
        $timeAtStart = now();
        $greeting = activity(TestGreetingActivity::class, $name);
        $timeAfterActivity = now();

        return [
            'greeting' => $greeting,
            'time_at_start' => $timeAtStart->toIso8601String(),
            'time_after_activity' => $timeAfterActivity->toIso8601String(),
            'time_at_start_ms' => $timeAtStart->getTimestampMs(),
            'time_after_activity_ms' => $timeAfterActivity->getTimestampMs(),
        ];
    }
}
