<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-redrive-greeting-child-workflow')]
final class TestRedriveGreetingChildWorkflow extends Workflow
{
    public function handle(string $name): string
    {
        return activity(TestRedriveFirstActivity::class, $name);
    }
}
