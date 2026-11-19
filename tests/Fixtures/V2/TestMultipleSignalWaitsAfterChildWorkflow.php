<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\all;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\select;
use function Workflow\V2\startChild;
use Workflow\V2\Workflow;

/**
 * Leaves a losing selection wait open, then waits for two signals together, all after starting a child
 * it never awaits.
 */
#[Type('test-multiple-signal-waits-after-child-workflow')]
#[Signal('first')]
#[Signal('second')]
#[Signal('left')]
#[Signal('right')]
final class TestMultipleSignalWaitsAfterChildWorkflow extends Workflow
{
    public function handle(): array
    {
        startChild(TestWatchdogNoopChildWorkflow::class, 'unawaited');
        $selection = select([
            'first' => static fn () => await('first'),
            'second' => static fn () => await('second'),
        ]);

        return [
            'winner' => $selection->key,
            'pair' => all([
                static fn () => await('left'),
                static fn () => await('right'),
            ]),
        ];
    }
}
