<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Contracts\YieldedCommand;
use Workflow\V2\StartedChildWorkflow;

final readonly class AwaitStartedChildWorkflowCall implements YieldedCommand
{
    public function __construct(
        public StartedChildWorkflow $child
    ) {
    }
}
