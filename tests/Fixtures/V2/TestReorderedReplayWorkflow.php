<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

final class TestReorderedReplayWorkflow extends Workflow
{
    public static bool $timerFirst = true;

    public function handle(): string
    {
        if (self::$timerFirst) {
            timer(15);
            return $this->greet();
        }

        $greeting = $this->greet();
        timer(15);

        return $greeting;
    }

    private function greet(): string
    {
        return activity(TestGreetingActivity::class, 'Ada');
    }
}
