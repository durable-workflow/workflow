<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\sideEffect;
use Workflow\V2\Workflow;

#[Type('test-large-side-effect-workflow')]
final class TestLargeSideEffectWorkflow extends Workflow
{
    public function handle(int $size): array
    {
        $value = sideEffect(static fn (): string => str_repeat('x', $size));

        return [
            'length' => strlen($value),
            'value' => $value,
        ];
    }
}
