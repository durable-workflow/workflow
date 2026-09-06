<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;

use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Workflow;

final class TestFractionalRetryWorkflow extends Workflow
{
    public function handle(int $failures = 1): string
    {
        return activity(
            TestFractionalRetryActivity::class,
            new ActivityOptions(maxAttempts: 2, backoff: [1]),
            $failures,
        );
    }
}
