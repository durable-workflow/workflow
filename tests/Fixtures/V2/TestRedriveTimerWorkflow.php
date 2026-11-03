<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\now;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

#[Type('test-redrive-timer-workflow')]
final class TestRedriveTimerWorkflow extends Workflow
{
    private ?string $timeAfterTimer = null;

    /**
     * @return array{result: string, time_after_timer: string}
     */
    public function handle(string $name, int $seconds): array
    {
        $greeting = activity(TestRedriveFirstActivity::class, $name);
        timer($seconds);
        $this->timeAfterTimer = now()->toIso8601String();

        return [
            'result' => activity(TestRedriveSecondActivity::class, $greeting),
            'time_after_timer' => $this->timeAfterTimer,
        ];
    }

    /**
     * @return array{time_after_timer: string|null}
     */
    #[QueryMethod]
    public function replayTimes(): array
    {
        return [
            'time_after_timer' => $this->timeAfterTimer,
        ];
    }
}
