<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Exceptions\ActivityTimeoutException;
use function Workflow\V2\localActivity;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Workflow;

final class TestActivityTimeoutCleanupWorkflow extends Workflow
{
    public function handle(bool $local = false, bool $recover = true): string
    {
        try {
            $options = new ActivityOptions(maxAttempts: 1, scheduleToCloseTimeout: 30);
            if ($local) {
                localActivity(TestClockAdvancingActivity::class, $options);
            } else {
                activity(TestGreetingActivity::class, $options, 'Ada');
            }
        } catch (ActivityTimeoutException $failure) {
            if (! $recover) {
                throw $failure;
            }
        } finally {
            activity(TestGreetingActivity::class, 'Cleanup');
        }

        return 'recovered';
    }
}
