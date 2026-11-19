<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\child;
use function Workflow\V2\select;
use Workflow\V2\Workflow;

/**
 * Waits for whichever of a child and a signal comes first. The child is sequenced before the signal
 * wait, yet while both members are open the child's closure is what resumes the run.
 */
#[Type('test-child-or-signal-selection-workflow')]
#[Signal('proceed')]
final class TestChildOrSignalSelectionWorkflow extends Workflow
{
    public function handle(): array
    {
        $selection = select([
            'child' => static fn () => child(TestWatchdogNoopChildWorkflow::class, 'selected'),
            'signal' => static fn () => await('proceed'),
        ]);

        return [
            'winner' => $selection->key,
            'value' => $selection->value,
        ];
    }
}
