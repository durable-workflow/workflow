<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('test-mixed-entry-child-parent-workflow')]
final class TestMixedEntryChildParentWorkflow extends Workflow
{
    public function handle(string $name): array
    {
        return [
            'child' => child(TestMixedEntryChildWorkflow::class, $name),
        ];
    }
}
