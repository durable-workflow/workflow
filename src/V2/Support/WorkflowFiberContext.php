<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Fiber;
use LogicException;

final class WorkflowFiberContext
{
    /**
     * @var array<int, true>
     */
    private static array $activeFibers = [];

    public static function enter(): void
    {
        $fiber = Fiber::getCurrent();

        if (! $fiber instanceof Fiber) {
            throw new LogicException('Workflow fiber context can only be entered from inside a Fiber.');
        }

        self::$activeFibers[spl_object_id($fiber)] = true;
    }

    public static function leave(): void
    {
        $fiber = Fiber::getCurrent();

        if (! $fiber instanceof Fiber) {
            return;
        }

        unset(self::$activeFibers[spl_object_id($fiber)]);
    }

    public static function active(): bool
    {
        $fiber = Fiber::getCurrent();

        if (! $fiber instanceof Fiber) {
            return false;
        }

        return isset(self::$activeFibers[spl_object_id($fiber)]);
    }

    public static function suspend(mixed $call): mixed
    {
        if (! self::active()) {
            return $call;
        }

        return Fiber::suspend($call);
    }
}
