<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\all;
use function Workflow\V2\now;

use Workflow\V2\Workflow;

final class TestParallelDeterministicTimeWorkflow extends Workflow
{
    public function handle(string $firstName, string $secondName): array
    {
        $timeAtStart = now();

        $results = all([
            static fn () => activity(TestGreetingActivity::class, $firstName),
            static fn () => activity(TestGreetingActivity::class, $secondName),
        ]);

        $timeAfterParallel = now();

        return [
            'results' => $results,
            'time_at_start' => $timeAtStart->toIso8601String(),
            'time_after_parallel' => $timeAfterParallel->toIso8601String(),
            'time_at_start_ms' => $timeAtStart->getTimestampMs(),
            'time_after_parallel_ms' => $timeAfterParallel->getTimestampMs(),
        ];
    }
}
