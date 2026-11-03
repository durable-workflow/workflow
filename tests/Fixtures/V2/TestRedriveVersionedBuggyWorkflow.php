<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use RuntimeException;
use function Workflow\V2\activity;
use function Workflow\V2\getVersion;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

/**
 * One definition of the configured `test-redrive-versioned` workflow type. It
 * records a version marker, completes an activity, then fails in workflow code.
 */
final class TestRedriveVersionedBuggyWorkflow extends Workflow
{
    public function handle(string $name): string
    {
        getVersion('redrive-change', WorkflowStub::DEFAULT_VERSION, 1);
        $greeting = activity(TestRedriveFirstActivity::class, $name);

        throw new RuntimeException("Unhandled greeting: {$greeting}");
    }
}
