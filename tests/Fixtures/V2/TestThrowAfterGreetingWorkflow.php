<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use RuntimeException;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-throw-after-greeting-workflow')]
final class TestThrowAfterGreetingWorkflow extends Workflow
{
    public function handle(string $name): never
    {
        activity(TestGreetingActivity::class, $name);

        throw new RuntimeException('Workflow code failed after a completed activity.');
    }
}
