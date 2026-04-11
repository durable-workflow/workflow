<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

abstract class TestMixedEntryChildWorkflowParent extends Workflow
{
    public function handle(string $name): array
    {
        return [
            'greeting' => "Hello, {$name}!",
        ];
    }
}

#[Type('test-mixed-entry-child-workflow')]
final class TestMixedEntryChildWorkflow extends TestMixedEntryChildWorkflowParent
{
    public function execute(string $name): array
    {
        return [
            'greeting' => "Hello, {$name}!",
        ];
    }
}
