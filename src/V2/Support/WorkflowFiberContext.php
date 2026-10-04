<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Fiber;
use FiberError;
use LogicException;
use Workflow\V2\Exceptions\WorkflowFiberDiscardedException;

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

    /**
     * @var array<int, CarbonInterface>
     */
    private static array $cancellationTime = [];

    /**
     * @var array<int, bool>
     */
    private static array $cancellationTimeAvailable = [];

    /**
     * @var array<int, int>
     */
    private static array $cancellationShields = [];

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
        unset(self::$cancellationTime[spl_object_id($fiber)]);
        unset(self::$cancellationTimeAvailable[spl_object_id($fiber)]);
        unset(self::$cancellationShields[spl_object_id($fiber)]);
    }

    public static function active(): bool
    {
        $fiber = Fiber::getCurrent();

        if (! $fiber instanceof Fiber) {
            return false;
        }

        return isset(self::$activeFibers[spl_object_id($fiber)]);
    }

    public static function cancellationShield(callable $callback): mixed
    {
        $fiber = Fiber::getCurrent();

        if (! $fiber instanceof Fiber || ! self::active()) {
            throw new LogicException('Cancellation shields can only run inside a workflow Fiber.');
        }

        $fiberId = spl_object_id($fiber);
        self::$cancellationShields[$fiberId] = (self::$cancellationShields[$fiberId] ?? 0) + 1;

        try {
            return $callback();
        } finally {
            --self::$cancellationShields[$fiberId];

            if (self::$cancellationShields[$fiberId] === 0) {
                unset(self::$cancellationShields[$fiberId]);
            }
        }
    }

    public static function cancellationShielded(?Fiber $fiber): bool
    {
        return $fiber instanceof Fiber
            && (self::$cancellationShields[spl_object_id($fiber)] ?? 0) > 0;
    }

    /**
     * Set the deterministic workflow time.
     *
     * When called from inside a workflow fiber, stores the time for that
     * fiber. When called from the executor (outside the fiber), the fiber
     * reference must be supplied so the executor can seed the time before
     * resuming the workflow.
     */
    public static function setTime(
        CarbonInterface $time,
        ?Fiber $fiber = null,
        bool $advanceCancellationTime = true,
    ): void {
        $fiber ??= Fiber::getCurrent();

        if ($fiber instanceof Fiber) {
            self::$workflowTime[spl_object_id($fiber)] = $time->copy();
            if ($advanceCancellationTime && array_key_exists(spl_object_id($fiber), self::$cancellationTimeAvailable)) {
                self::observeCancellationTime($time, $fiber);
            }
        }
    }

    public static function observeCancellationTime(?CarbonInterface $time, ?Fiber $fiber = null): void
    {
        $fiber ??= Fiber::getCurrent();
        if (! $fiber instanceof Fiber) {
            return;
        }

        $fiberId = spl_object_id($fiber);
        if (! array_key_exists($fiberId, self::$cancellationTimeAvailable)) {
            return;
        }
        self::$cancellationTimeAvailable[$fiberId] = $time !== null;
        if ($time !== null && (! isset(self::$cancellationTime[$fiberId]) || $time->greaterThan(
            self::$cancellationTime[$fiberId]
        ))) {
            self::$cancellationTime[$fiberId] = $time->copy();
        }
    }

    public static function startCancellationTime(?CarbonInterface $time, Fiber $fiber): void
    {
        self::$cancellationTimeAvailable[spl_object_id($fiber)] = false;
        self::observeCancellationTime($time, $fiber);
    }

    public static function getCancellationTime(): CarbonInterface
    {
        $fiber = Fiber::getCurrent();
        if (! self::active() || ! $fiber instanceof Fiber
            || ! (self::$cancellationTimeAvailable[spl_object_id($fiber)] ?? false)
            || ! isset(self::$cancellationTime[spl_object_id($fiber)])) {
            throw new LogicException('Cancellation remaining() requires a recorded blocking boundary timestamp.');
        }

        return self::$cancellationTime[spl_object_id($fiber)]->copy();
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
            return self::$workflowTime[spl_object_id($fiber)]->copy();
        }

        return now();
    }

    public static function getRecordedTime(): CarbonInterface
    {
        $fiber = Fiber::getCurrent();
        if (! self::active() || ! $fiber instanceof Fiber || ! isset(self::$workflowTime[spl_object_id($fiber)])) {
            throw new LogicException('Cancellation remaining() requires recorded workflow time.');
        }

        return self::$workflowTime[spl_object_id($fiber)]->copy();
    }

    public static function suspend(mixed $call): mixed
    {
        if (! self::active()) {
            return $call;
        }

        try {
            return Fiber::suspend($call);
        } catch (FiberError $error) {
            // PHP unwinds finally blocks when discarding suspended Fibers. It
            // exposes no closing-state query; only this native error identifies
            // a durable call that cannot be emitted during that teardown.
            $origin = $error->getTrace()[0] ?? [];
            if (
                $error->getMessage() !== 'Cannot suspend in a force-closed fiber'
                || ($origin['class'] ?? null) !== Fiber::class
                || ($origin['function'] ?? null) !== 'suspend'
                || ($origin['file'] ?? null) !== __FILE__
            ) {
                throw $error;
            }

            throw new WorkflowFiberDiscardedException(previous: $error);
        }
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
