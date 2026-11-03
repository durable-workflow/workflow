<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Workflow;

/**
 * One definition of the configured `test-redrive-correctable` workflow type
 * used to redrive a workflow-code failure onto corrected code.
 */
final class TestRedriveIncompatibleWorkflow extends Workflow
{
    public function handle(string $name): string
    {
        // Takes a different first step than the history it would reuse.
        $greeting = activity(TestRedriveSecondActivity::class, $name);

        return activity(TestRedriveFirstActivity::class, $greeting);
    }
}
