<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-redrive-workflow')]
final class TestRedriveWorkflow extends Workflow
{
    public function handle(string $name): string
    {
        $greeting = activity(TestRedriveFirstActivity::class, $name);

        return activity(TestRedriveSecondActivity::class, $greeting);
    }
}
