<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\all;
use function Workflow\V2\await;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('rv-all-child-signal-workflow')]
#[Signal('proceed')]
final class RvAllChildSignalWorkflow extends Workflow
{
    public function handle(): array
    {
        return all([
            static fn () => child(TestWatchdogNoopChildWorkflow::class, 'all'),
            static fn () => await('proceed'),
        ]);
    }
}
