<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Execution;

use InvalidArgumentException;
use LogicException;

/**
 * A configured boundary was exhausted; it is not evidence of business cancellation.
 *
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
final class ResourceLimitExceeded extends LogicException
{
    public function __construct(
        public readonly string $dimension,
        public readonly int $observed,
        public readonly int $limit,
    ) {
        if (! in_array($dimension, ['states', 'transitions', 'child_slots', 'rule_nodes',
            'commands', 'history_events', 'history_bytes', 'command_depth', 'child_depth'], true)
            || $observed < 0 || $limit < 0) {
            throw new InvalidArgumentException('Invalid state-machine resource refusal.');
        }
        parent::__construct(sprintf(
            'State-machine resource budget [%s] is exhausted (observed %d, limit %d).',
            $dimension,
            $observed,
            $limit
        ));
    }

    public function reason(): string
    {
        return 'state_machine_' . $this->dimension . '_limit_exceeded';
    }
}
