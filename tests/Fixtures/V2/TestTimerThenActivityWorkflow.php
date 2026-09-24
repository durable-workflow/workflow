<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

final class TestTimerThenActivityWorkflow extends Workflow
{
    public function handle(string $name): string
    {
        timer(60);

        return activity(TestGreetingActivity::class, $name);
    }
}
