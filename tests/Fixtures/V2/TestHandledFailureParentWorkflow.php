<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\child;
use Workflow\V2\Workflow;

final class TestHandledFailureParentWorkflow extends Workflow
{
    public function handle(): string
    {
        return child(TestHandledFailureWorkflow::class);
    }
}
