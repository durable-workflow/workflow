<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\localActivity;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\LocalActivityOptions;
use Workflow\V2\Workflow;

/**
 * Schedules one activity with a per-call attempt budget (#860).
 */
#[Type('test-activity-attempt-budget-workflow')]
final class TestActivityAttemptBudgetWorkflow extends Workflow
{
    public function handle(int $maxAttempts, bool $local = false): string
    {
        return $local
            ? localActivity(TestGreetingActivity::class, new LocalActivityOptions(maxAttempts: $maxAttempts), 'budget')
            : activity(TestGreetingActivity::class, new ActivityOptions(maxAttempts: $maxAttempts), 'budget');
    }
}
