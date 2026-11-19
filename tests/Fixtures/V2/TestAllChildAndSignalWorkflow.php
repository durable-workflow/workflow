<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\all;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

/**
 * Waits for a child and a signal together. Once the child completed, only the signal can complete the
 * group (#1229 review).
 */
#[Type('test-all-child-and-signal-workflow')]
#[Signal('proceed')]
final class TestAllChildAndSignalWorkflow extends Workflow
{
    public function handle(): array
    {
        return all([
            static fn () => child(TestWatchdogNoopChildWorkflow::class, 'all'),
            static fn () => await('proceed'),
        ]);
    }
}
