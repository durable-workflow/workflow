<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\all;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\signal;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

#[Type('test-leased-signal-delivery-workflow')]
#[Signal('advance')]
#[Signal('finish')]
final class TestLeasedSignalDeliveryWorkflow extends Workflow
{
    public function handle(): array
    {
        return all([
            static fn () => signal('advance'),
            static fn () => signal('finish'),
            static fn () => timer(60),
        ]);
    }
}
