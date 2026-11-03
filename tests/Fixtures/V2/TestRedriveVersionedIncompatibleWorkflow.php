<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\getVersion;
use Workflow\V2\Workflow;

/**
 * One definition of the configured `test-redrive-versioned` workflow type. It no
 * longer supports the version the reused history recorded.
 */
final class TestRedriveVersionedIncompatibleWorkflow extends Workflow
{
    public function handle(string $name): string
    {
        getVersion('redrive-change', 2, 3);
        $greeting = activity(TestRedriveFirstActivity::class, $name);

        return activity(TestRedriveSecondActivity::class, $greeting);
    }
}
