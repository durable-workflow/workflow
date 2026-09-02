<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\sideEffect;
use Workflow\V2\Workflow;

#[Type('test-many-side-effects-workflow')]
final class TestManySideEffectsWorkflow extends Workflow
{
    public function handle(int $count): array
    {
        $results = [];

        for ($i = 0; $i < $count; $i++) {
            $results[] = sideEffect(static fn (): int => $i);
        }

        return $results;
    }
}
