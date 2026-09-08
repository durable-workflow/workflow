<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\all;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('test-parallel-greeting-children-workflow')]
final class TestParallelGreetingChildrenWorkflow extends Workflow
{
    public function handle(int $count): array
    {
        $calls = [];
        for ($index = 0; $index < $count; $index++) {
            $calls[] = static fn () => child(TestChildGreetingWorkflow::class, (string) $index);
        }

        return all($calls);
    }
}
