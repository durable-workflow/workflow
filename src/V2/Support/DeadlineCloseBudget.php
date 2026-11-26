<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * The history events and rows one transaction of a bounded deadline close
 * may write (#1116, #1290): the completion budget's event limit and its row
 * limit, each 0 for no bound.
 *
 * Every kind of work the close does is charged what it writes, with its
 * projections: a cancelled activity or timer, an accepted update failed with
 * its cursor event, and a child's whole policy outcome, which is that
 * child's own terminate with its open work, its accepted updates and its own
 * children. The fixed writes of the close or of a pass are reserved first.
 *
 * Work is taken while it fits. A pass that must make progress takes its
 * first item even when that item alone is larger than the budget, so every
 * pass removes at least one item and the close always finishes.
 */
final class DeadlineCloseBudget
{
    /**
     * A cancelled activity: its execution and task, ActivityCancelled with the
     * run position it advances, its timeline entry, and its wait's status and
     * task status.
     *
     * @var array{int, int}
     */
    public const ACTIVITY = [1, 7];

    /**
     * A cancelled timer: the timer, TimerCancelled with the run position, its
     * timeline entry, its wait and its timer entry.
     *
     * @var array{int, int}
     */
    public const TIMER = [1, 6];

    /**
     * Another open task cancelled, with its wait's task status.
     *
     * @var array{int, int}
     */
    public const TASK = [0, 2];

    /**
     * A failed accepted update: its failure, UpdateCompleted and the
     * MessageCursorAdvanced it may need, each with its run position and
     * timeline entry, and the update, command and cursor rows.
     *
     * @var array{int, int}
     */
    public const UPDATE = [2, 11];

    /**
     * A child closed by terminate or request_cancel, before its own work: its
     * command, requested and terminal events with their run positions, its
     * failure, run, summary, lineage and timeline rows, and the parent's
     * ParentClosePolicyApplied.
     *
     * @var array{int, int}
     */
    public const CHILD = [3, 19];

    /**
     * A child asked for cooperative cancellation (request_cancellation): the
     * request's command and event, its resume task and projections, the
     * parent's ParentClosePolicyApplied, and the parent's cancellation origin,
     * which only the first request writes but each is charged. The child
     * stays open, so nothing cascades.
     *
     * @var array{int, int}
     */
    public const CANCELLATION_REQUEST = [3, 17];

    /**
     * The close's own writes: its failure, the run, WorkflowTimedOut with the
     * run position, the closing task and the run's projections.
     *
     * @var array{int, int}
     */
    public const CLOSE = [1, 10];

    /**
     * A pass that does not close the run: its task, the run and the run's
     * projections.
     *
     * @var array{int, int}
     */
    public const PASS = [0, 6];

    private bool $taken = false;

    private bool $progress = false;

    private function __construct(
        private ?int $events,
        private ?int $rows,
    ) {
    }

    /**
     * The budget of one close or settlement transaction, or null when both
     * limits are disabled and the close takes everything at once.
     */
    public static function forTransaction(): ?self
    {
        $events = StructuralLimits::historyTransactionSizeLimit();
        $rows = StructuralLimits::completionRowsLimit();
        if ($events <= 0 && $rows <= 0) {
            return null;
        }

        return new self($events > 0 ? $events : null, $rows > 0 ? $rows : null);
    }

    /**
     * No bound: everything fits.
     */
    public static function unlimited(): self
    {
        return new self(null, null);
    }

    public function unbounded(): bool
    {
        return $this->events === null && $this->rows === null;
    }

    /**
     * Make this a pass that must make progress: its first item is taken even
     * when it alone does not fit.
     */
    public function progressing(): self
    {
        $this->progress = true;

        return $this;
    }

    /**
     * Charge fixed writes the transaction makes whatever it takes.
     *
     * @param array{int, int} $cost
     */
    public function reserve(array $cost, int $count = 1): void
    {
        if ($this->events !== null) {
            $this->events -= $cost[0] * $count;
        }
        if ($this->rows !== null) {
            $this->rows -= $cost[1] * $count;
        }
    }

    /**
     * @param array{int, int} $cost
     */
    public function fits(array $cost, int $count = 1): bool
    {
        return ($this->events === null || $this->events >= $cost[0] * $count)
            && ($this->rows === null || $this->rows >= $cost[1] * $count);
    }

    /**
     * Charge one item if it fits, or if it is the first item of a pass that
     * must make progress.
     *
     * @param array{int, int} $cost
     */
    public function take(array $cost): bool
    {
        if (! $this->fits($cost) && ! ($this->progress && ! $this->taken)) {
            return false;
        }
        $this->reserve($cost);
        $this->taken = true;

        return true;
    }

    /**
     * How many items of one cost fit, at least one for the first item of a
     * pass that must make progress. Nothing is charged; see {@see self::charge()}.
     *
     * @param array{int, int} $cost
     */
    public function room(array $cost): int
    {
        $room = PHP_INT_MAX;
        foreach ([[$this->events, $cost[0]], [$this->rows, $cost[1]]] as [$left, $each]) {
            if ($left !== null && $each > 0) {
                $room = min($room, intdiv(max(0, $left), $each));
            }
        }

        return $this->progress && ! $this->taken ? max(1, $room) : $room;
    }

    /**
     * Charge items taken after {@see self::room()}.
     *
     * @param array{int, int} $cost
     */
    public function charge(array $cost, int $count): void
    {
        if ($count <= 0) {
            return;
        }
        $this->reserve($cost, $count);
        $this->taken = true;
    }

    /**
     * Add two costs.
     *
     * @param array{int, int} $cost
     * @param array{int, int} $other
     * @return array{int, int}
     */
    public static function plus(array $cost, array $other, int $count = 1): array
    {
        return [$cost[0] + $other[0] * $count, $cost[1] + $other[1] * $count];
    }
}
