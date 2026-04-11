<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('test-parent-failing-child-workflow')]
final class TestParentFailingChildWorkflow extends Workflow
{
    public function execute(): string
    {
        child(TestFailingChildWorkflow::class);

        return 'never';
    }
}
