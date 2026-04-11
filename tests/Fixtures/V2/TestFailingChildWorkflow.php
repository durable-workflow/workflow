<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-failing-child-workflow')]
final class TestFailingChildWorkflow extends Workflow
{
    public function handle(): string
    {
        activity(TestFailingActivity::class);

        return 'never';
    }
}
