<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Workflow;

/**
 * One definition of the configured `test-redrive-correctable` workflow type.
 * It returns without the step the reused history recorded, so it can't
 * replay that prefix.
 */
final class TestRedriveFinishesEarlyWorkflow extends Workflow
{
    public function handle(string $name): string
    {
        return "Skipped {$name}";
    }
}
