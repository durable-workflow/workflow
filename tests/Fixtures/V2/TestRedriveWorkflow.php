<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\now;
use Workflow\V2\Workflow;

#[Type('test-redrive-workflow')]
final class TestRedriveWorkflow extends Workflow
{
    /**
     * @return array{result: string, time_at_start: string, time_after_first_activity: string}
     */
    public function handle(string $name): array
    {
        $timeAtStart = now();
        $greeting = activity(TestRedriveFirstActivity::class, $name);
        $timeAfterFirstActivity = now();

        return [
            'result' => activity(TestRedriveSecondActivity::class, $greeting),
            'time_at_start' => $timeAtStart->toIso8601String(),
            'time_after_first_activity' => $timeAfterFirstActivity->toIso8601String(),
        ];
    }
}
