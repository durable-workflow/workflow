<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Workflow;

/**
 * @api Stable v2 replay outcome returned by WorkflowReplayer.
 */
final class ReplayState
{
    public function __construct(
        public readonly Workflow $workflow,
        public readonly int $sequence,
        public readonly mixed $current,
    ) {
    }
}
