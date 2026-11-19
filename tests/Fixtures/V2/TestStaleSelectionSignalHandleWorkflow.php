<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\select;
use function Workflow\V2\startChild;
use Workflow\V2\Workflow;

/**
 * The losing signal wait of the selection stays open after the workflow moves on to await the child
 * through its handle. That stale wait must not hide the awaited child (#1229 review).
 */
#[Type('test-stale-selection-signal-handle-workflow')]
#[Signal('proceed')]
#[Signal('other')]
final class TestStaleSelectionSignalHandleWorkflow extends Workflow
{
    public function handle(): array
    {
        $handle = startChild(TestWatchdogNoopChildWorkflow::class, 'handle');
        $selection = select([
            'proceed' => static fn () => await('proceed'),
            'other' => static fn () => await('other'),
        ]);

        return [
            'winner' => $selection->key,
            'child' => $handle->await(),
        ];
    }
}
