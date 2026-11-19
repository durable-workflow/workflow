<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Closure;

/**
 * Values remembered only while one queued task runs (#1203).
 *
 * A workflow task that starts a fan-out repeats the same immutable lookups for
 * every member. Remembering them for the task's duration, and never longer,
 * keeps each lookup once per task without trusting a value across tasks.
 * Outside a task scope every call computes its value.
 *
 * @internal
 */
final class TaskScopeMemo
{
    private const MAX_VALUES = 1024;

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $values = null;

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public static function scope(callable $operation): mixed
    {
        if (self::$values !== null) {
            return $operation();
        }

        self::$values = [];

        try {
            return $operation();
        } finally {
            self::$values = null;
        }
    }

    /**
     * Remember a non-null value for the active task scope.
     *
     * @template T
     * @param Closure(): T $resolve
     * @return T
     */
    public static function remember(string $key, Closure $resolve): mixed
    {
        if (self::$values === null) {
            return $resolve();
        }

        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }

        $value = $resolve();

        if ($value !== null && count(self::$values) < self::MAX_VALUES) {
            self::$values[$key] = $value;
        }

        return $value;
    }
}
