<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Contracts\YieldedCommand;

final class DurableOperationHandle implements YieldedCommand
{
    public function __construct(
        public readonly int|string $key,
        public readonly int $index,
        public readonly string $kind,
        public readonly string $identity,
        public readonly int $baseSequence,
        public readonly int $size,
        public readonly string $selectionGroupId,
        public readonly ActivityCall|ChildWorkflowCall|TimerCall|SignalCall|AwaitCall|AwaitWithTimeoutCall|AllCall $call,
    ) {
    }

    public function await(): mixed
    {
        return WorkflowFiberContext::suspend($this);
    }

    public function cancel(): void
    {
        WorkflowFiberContext::suspend(new CancelDurableOperationCall($this));
    }
}
