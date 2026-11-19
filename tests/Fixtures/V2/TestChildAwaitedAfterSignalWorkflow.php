<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\startChild;
use Workflow\V2\Workflow;

/**
 * Starts a child, waits for a signal, then awaits the child through its handle.
 */
#[Type('test-child-awaited-after-signal-workflow')]
#[Signal('proceed')]
final class TestChildAwaitedAfterSignalWorkflow extends Workflow
{
    public function handle(): array
    {
        $handle = startChild(TestWatchdogNoopChildWorkflow::class, 'later');
        $signal = await('proceed');

        return [
            'signal' => $signal,
            'child' => $handle->await(),
        ];
    }
}
