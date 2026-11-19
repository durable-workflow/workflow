<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\continueAsNew;
use function Workflow\V2\startChild;
use Workflow\V2\Workflow;

/**
 * The first run starts a child it never awaits and continues as new while the child is open, handing
 * it to the continued run. The continued run starts its own unawaited child and waits for a signal.
 */
#[Type('test-continue-as-new-child-signal-workflow')]
#[Signal('proceed')]
final class TestContinueAsNewChildSignalWorkflow extends Workflow
{
    public function handle(int $generation = 0): array
    {
        startChild(TestWatchdogNoopChildWorkflow::class, sprintf('generation-%d', $generation));

        if ($generation === 0) {
            return continueAsNew(1);
        }

        return [
            'generation' => $generation,
            'signal' => await('proceed'),
        ];
    }
}
