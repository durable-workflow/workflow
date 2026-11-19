<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Definition;

use InvalidArgumentException;

/**
 * A forward command cap and replay-stable history admission thresholds.
 *
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
final readonly class ExecutionLimits
{
    public const MAX_COMMANDS = 4096;

    public const MAX_GROUP_DEPTH = 64;

    public function __construct(
        public int $commands,
        public ?int $historyEvents = null,
        public ?int $historyBytes = null,
        public int $groupDepth = self::MAX_GROUP_DEPTH,
    ) {
        if ($commands < 0 || $commands > self::MAX_COMMANDS
            || ($historyEvents !== null && $historyEvents < 0)
            || ($historyBytes !== null && $historyBytes < 0)
            || $groupDepth < 1 || $groupDepth > self::MAX_GROUP_DEPTH) {
            throw new InvalidArgumentException(
                'Transition budgets require bounded nonnegative command and history values.'
            );
        }
    }

    public function toArray(): array
    {
        return [
            'version' => 1,
            'commands' => $this->commands,
            'historyEvents' => $this->historyEvents,
            'historyBytes' => $this->historyBytes,
            'groupDepth' => $this->groupDepth,
        ];
    }
}
