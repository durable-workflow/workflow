<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\startChild;
use Workflow\V2\Workflow;

/**
 * Starts a child it never awaits, then waits for a signal (#1219). The child's closure must not
 * make the signal wait look stalled.
 */
#[Type('test-never-awaited-child-signal-workflow')]
#[Signal('proceed')]
final class TestNeverAwaitedChildSignalWorkflow extends Workflow
{
    public function handle(int $timeoutSeconds = 0): array
    {
        startChild(TestWatchdogNoopChildWorkflow::class, 'unawaited');

        return [
            'signal' => $timeoutSeconds > 0 ? await('proceed', $timeoutSeconds) : await('proceed'),
        ];
    }
}
