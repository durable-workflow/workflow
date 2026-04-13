<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Contracts\YieldedCommand;

final class ChildWorkflowCall implements YieldedCommand
{
    /**
     * @param array<int, mixed> $arguments
     */
    public function __construct(
        public readonly string $workflow,
        public readonly array $arguments,
        public readonly ?ChildWorkflowOptions $options = null,
    ) {
    }
}
