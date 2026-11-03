<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use RuntimeException;
use function Workflow\V2\activity;
use Workflow\V2\Workflow;

/**
 * One definition of the configured `test-redrive-correctable` workflow type
 * used to redrive a workflow-code failure onto corrected code.
 */
final class TestRedriveBuggyWorkflow extends Workflow
{
    public function handle(string $name): string
    {
        $greeting = activity(TestRedriveFirstActivity::class, $name);

        // The defect redrive corrects: the workflow fails before its next step.
        throw new RuntimeException("Unhandled greeting: {$greeting}");
    }
}
