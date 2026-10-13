<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Workflow;

final class TestAuthoredRetryReplayWorkflow extends Workflow
{
    public static ?ActivityOptions $overrides = null;

    public function handle(int $lastInterval = 120, string $mode = 'direct'): mixed
    {
        $options = self::$overrides ?? new ActivityOptions(
            maxAttempts: 1,
            backoff: [1, 2, 5, 10, 15, 30, 60, $lastInterval],
            startToCloseTimeout: 120,
            scheduleToCloseTimeout: 600,
        );
        $call = static fn (): mixed => Workflow::activity(TestGreetingActivity::class, $options, 'Ada');

        if ($mode === 'all') {
            return Workflow::all([
                'greeting' => $call,
            ]);
        }
        if ($mode === 'select') {
            return Workflow::select([
                'greeting' => $call,
                'timer' => static fn (): mixed => Workflow::timer(60),
            ])->result();
        }

        return $call();
    }
}
