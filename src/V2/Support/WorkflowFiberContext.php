<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Fiber;
use LogicException;

final class WorkflowFiberContext
{
    /**
     * @var array<int, true>
     */
    private static array $activeFibers = [];

    /**
     * Deterministic workflow time per fiber, set by the executor from the
     * latest history event recorded_at before resuming the workflow.
     *
     * @var array<int, CarbonInterface>
     */
    private static array $workflowTime = [];

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
        unset(self::$workflowTime[spl_object_id($fiber)]);
    }

    public static function active(): bool
    {
        $fiber = Fiber::getCurrent();

        if (! $fiber instanceof Fiber) {
            return false;
        }

        return isset(self::$activeFibers[spl_object_id($fiber)]);
    }

    /**
     * Set the deterministic workflow time for the current fiber.
     */
    public static function setTime(CarbonInterface $time): void
    {
        $fiber = Fiber::getCurrent();

        if ($fiber instanceof Fiber) {
            self::$workflowTime[spl_object_id($fiber)] = $time;
        }
    }

    /**
     * Read the deterministic workflow time for the current fiber.
     *
     * Returns the timestamp of the last history event the executor replayed
     * before resuming this fiber. Outside a workflow context, falls back to
     * wall-clock time via now().
     */
    public static function getTime(): CarbonInterface
    {
        $fiber = Fiber::getCurrent();

        if ($fiber instanceof Fiber && isset(self::$workflowTime[spl_object_id($fiber)])) {
            return self::$workflowTime[spl_object_id($fiber)];
        }

        return now();
    }

    public static function suspend(mixed $call): mixed
    {
        if (! self::active()) {
            return $call;
        }

        return Fiber::suspend($call);
    }

    public static function whileInactive(callable $callback): mixed
    {
        $fiber = Fiber::getCurrent();

        if (! $fiber instanceof Fiber) {
            return $callback();
        }

        $fiberId = spl_object_id($fiber);
        $wasActive = isset(self::$activeFibers[$fiberId]);

        unset(self::$activeFibers[$fiberId]);

        try {
            return $callback();
        } finally {
            if ($wasActive) {
                self::$activeFibers[$fiberId] = true;
            }
        }
    }
}
