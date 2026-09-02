<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Throwable;
use function Workflow\V2\activity;
use Workflow\V2\Workflow;

final class TestHandledFailureWorkflow extends Workflow
{
    public function handle(): string
    {
        try {
            activity(TestFailingActivity::class);
        } catch (Throwable) {
            // Continue after an activity failure to prove the run can recover.
        }

        return activity(TestGreetingActivity::class, 'Recovered');
    }
}
