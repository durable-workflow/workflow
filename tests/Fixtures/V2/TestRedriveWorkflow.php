<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\now;
use Workflow\V2\Workflow;

#[Type('test-redrive-workflow')]
final class TestRedriveWorkflow extends Workflow
{
    private ?string $timeAtStart = null;

    private ?string $timeAfterFirstActivity = null;

    /**
     * @return array{result: string, time_at_start: string, time_after_first_activity: string}
     */
    public function handle(string $name): array
    {
        $timeAtStart = now();
        $this->timeAtStart = $timeAtStart->toIso8601String();
        $greeting = activity(TestRedriveFirstActivity::class, $name);
        $timeAfterFirstActivity = now();
        $this->timeAfterFirstActivity = $timeAfterFirstActivity->toIso8601String();

        return [
            'result' => activity(TestRedriveSecondActivity::class, $greeting),
            'time_at_start' => $timeAtStart->toIso8601String(),
            'time_after_first_activity' => $timeAfterFirstActivity->toIso8601String(),
        ];
    }

    /**
     * @return array{time_at_start: string|null, time_after_first_activity: string|null}
     */
    #[QueryMethod]
    public function replayTimes(): array
    {
        return [
            'time_at_start' => $this->timeAtStart,
            'time_after_first_activity' => $this->timeAfterFirstActivity,
        ];
    }
}
