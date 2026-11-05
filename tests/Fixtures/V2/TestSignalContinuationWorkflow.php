<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use function Workflow\V2\continueAsNew;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;

#[Signal('resume')]
final class TestSignalContinuationWorkflow extends Workflow
{
    public function handle(int $round = 0): mixed
    {
        signal('resume');

        return continueAsNew($round + 1);
    }
}
