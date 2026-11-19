<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Workflow;

final class TestRedriveRetryProbeWorkflow extends Workflow
{
    public function handle(string $counter): string
    {
        $first = self::activity(
            TestRedriveRetryProbeActivity::class,
            new ActivityOptions(maxAttempts: 2),
            'first',
            'value',
            $counter,
        );
        return self::activity(
            TestRedriveRetryProbeActivity::class,
            new ActivityOptions(maxAttempts: 2),
            'second',
            $first,
            $counter,
        );
    }
}
