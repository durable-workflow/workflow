<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\select;
use function Workflow\V2\startChild;
use Workflow\V2\Workflow;

#[Type('rv-stale-signal-handle-workflow')]
#[Signal('proceed')]
#[Signal('other')]
final class RvStaleSignalHandleWorkflow extends Workflow
{
    public function handle(): array
    {
        $handle = startChild(TestWatchdogNoopChildWorkflow::class, 'handle');
        $sel = select([
            'proceed' => static fn () => await('proceed'),
            'other' => static fn () => await('other'),
        ]);

        return ['winner' => $sel->key, 'child' => $handle->await()];
    }
}
