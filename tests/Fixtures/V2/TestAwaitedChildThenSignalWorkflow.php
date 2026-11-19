<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('test-awaited-child-then-signal-workflow')]
#[Signal('proceed')]
final class TestAwaitedChildThenSignalWorkflow extends Workflow
{
    public function handle(): array
    {
        $child = child(TestWatchdogNoopChildWorkflow::class, 'awaited');

        return [
            'child' => $child,
            'signal' => await('proceed'),
        ];
    }
}
