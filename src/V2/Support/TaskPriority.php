<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;

/**
 * Priority scale for workflow and activity tasks.
 *
 * Lower numbers are more urgent. The default is {@see self::DEFAULT}.
 * Priority 0 is reserved for high-urgency control-plane work; user
 * code should typically use values in the {@see self::MIN_USER}..{@see self::MAX} range.
 *
 * Priority is one half of the dispatch ordering surface — the other half
 * is fairness across workload classes (see {@see TaskFairnessKey}). Within a
 * priority tier, dispatch is rebalanced across distinct fairness keys so a
 * single noisy class cannot starve the others.
 */
final class TaskPriority
{
    public const MIN = 0;
    public const MIN_USER = 1;
    public const MAX = 9;
    public const DEFAULT = 5;

    public static function normalize(mixed $value): int
    {
        if ($value === null) {
            return self::DEFAULT;
        }

        if (! is_int($value)) {
            throw new LogicException('Task priority must be an integer in the range 0..9.');
        }

        if ($value < self::MIN || $value > self::MAX) {
            throw new LogicException(sprintf(
                'Task priority must be in the range %d..%d (got %d).',
                self::MIN,
                self::MAX,
                $value,
            ));
        }

        return $value;
    }

    public static function isDefault(int $priority): bool
    {
        return $priority === self::DEFAULT;
    }
}
