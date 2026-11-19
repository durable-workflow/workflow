<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Workflow;

final class TestRedriveRetryWorkflow extends Workflow
{
    public function handle(string $name, bool $withChild = false): string
    {
        if ($withChild) {
            self::child(TestRedriveGreetingChildWorkflow::class, $name);
        }
        $greeting = self::activity(TestRedriveFirstActivity::class, new ActivityOptions(maxAttempts: 2), $name);
        return self::activity(TestRedriveSecondActivity::class, new ActivityOptions(maxAttempts: 2), $greeting);
    }
}
