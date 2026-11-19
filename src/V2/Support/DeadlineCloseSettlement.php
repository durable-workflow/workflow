<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use LogicException;
use Throwable;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/**
 * The cascade after a bounded deadline close's terminal event (#1116): the
 * accepted updates it fails and the children its parent-close policy acts
 * on. Each transaction takes at most one batch, updates first and then
 * children, in the order a single close takes them.
 *
 * While work is left, the run already reads Failed and its closing workflow
 * task is held: Leased by {@see self::OWNER} with no lease expiry, so no
 * worker can claim it and task repair never resets it, and its payload says
 * {@see self::PENDING}. Only this class changes a held task's status, lease
 * or owner; any other writer is refused (see {@see self::guard()}). The
 * watchdog finds due held tasks among the open (Ready/Leased) tasks, a set
 * that does not grow with retained runs, and continues the settlement. A
 * pass that fails records its error and backs the task off; it is retried,
 * never released, and counted as stuck after {@see self::STUCK_AFTER_FAILURES}
 * failed passes. When nothing is left, the task is Completed and its
 * payload says {@see self::SETTLED}.
 */
final class DeadlineCloseSettlement
{
    public const OWNER = 'deadline-close-settlement';

    public const MARKER = 'deadline_close_settlement';

    public const FAILURES = 'deadline_close_settlement_failures';

    public const PENDING = 'pending';

    public const SETTLED = 'settled';

    public const STUCK_AFTER_FAILURES = 3;

    public const BACKOFF_BASE_SECONDS = 10;

    public const BACKOFF_MAX_SECONDS = 3600;

    private static bool $writing = false;

    public static function holds(WorkflowTask $task): bool
    {
        return $task->status === TaskStatus::Leased && $task->lease_owner === self::OWNER;
    }

    public static function hold(WorkflowTask $task): void
    {
        self::write(static function () use ($task): void {
            $task->forceFill([
                'status' => TaskStatus::Leased,
                'leased_at' => $task->leased_at ?? now(),
                'lease_owner' => self::OWNER,
                'lease_expires_at' => null,
                'available_at' => now(),
                'payload' => [
                    ...(is_array($task->payload) ? $task->payload : []),
                    self::MARKER => self::PENDING,
                ],
            ])->save();
        });
    }

    /**
     * Settle at most $budget updates and children. Returns true when nothing
     * is left, completing the held task; otherwise the task stays held.
     */
    public static function settle(WorkflowRun $run, WorkflowTask $task, int $budget): bool
    {
        $budget -= PendingUpdateCloser::closeForTerminalRun($run, $task, $budget);
        $settled = false;
        if (! PendingUpdateCloser::hasAccepted($run)) {
            $childrenSettled = true;
            ParentClosePolicyEnforcer::enforce($run, max(0, $budget), $childrenSettled);
            $settled = $childrenSettled;
        }

        if (! $settled) {
            if (! self::holds($task)) {
                self::hold($task);
            }

            return false;
        }

        self::write(static function () use ($task): void {
            $task->forceFill([
                'status' => TaskStatus::Completed,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'last_error' => null,
                'payload' => [
                    ...(is_array($task->payload) ? $task->payload : []),
                    self::MARKER => self::SETTLED,
                ],
            ])->save();
        });

        return true;
    }

    /**
     * Record a failed settlement pass on the held task, in its own
     * transaction after the pass rolled back: the error, one more attempt,
     * and an exponential backoff before the watchdog takes it again.
     */
    public static function recordFailure(string $taskId, Throwable $error): void
    {
        ConfiguredV2Models::connection()->transaction(static function () use ($taskId, $error): void {
            /** @var WorkflowTask|null $task */
            $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                ->lockForUpdate()
                ->find($taskId);
            if ($task === null || ! self::holds($task)) {
                return;
            }
            $failures = self::failures($task) + 1;
            $task->forceFill([
                'attempt_count' => (int) $task->attempt_count + 1,
                'last_error' => mb_substr($error->getMessage(), 0, 4000),
                'available_at' => now()
                    ->addSeconds(self::backoffSeconds($failures)),
                'payload' => [
                    ...(is_array($task->payload) ? $task->payload : []),
                    self::FAILURES => $failures,
                ],
            ])->save();
        });
    }

    public static function backoffSeconds(int $failures): int
    {
        return (int) min(self::BACKOFF_MAX_SECONDS, self::BACKOFF_BASE_SECONDS * (2 ** min(30, max(0, $failures - 1))));
    }

    public static function failures(WorkflowTask $task): int
    {
        $failures = is_array($task->payload) ? ($task->payload[self::FAILURES] ?? 0) : 0;

        return is_int($failures) ? $failures : 0;
    }

    /**
     * Held tasks: the open-task set, by status and owner.
     *
     * @return Builder<WorkflowTask>
     */
    public static function heldQuery(): Builder
    {
        /** @var Builder<WorkflowTask> $query */
        $query = ConfiguredV2Models::query('task_model', WorkflowTask::class)
            ->where('status', TaskStatus::Leased->value)
            ->where('task_type', TaskType::Workflow->value)
            ->where('lease_owner', self::OWNER);

        return $query;
    }

    /**
     * Refuse a write that would release or re-lease a held task from
     * anywhere but this class: a held task carries correctness-critical
     * work, and only its settlement may end the hold.
     */
    public static function guard(WorkflowTask $task, bool $deleting = false): void
    {
        if (self::$writing) {
            return;
        }
        $wasHeld = $task->getOriginal('lease_owner') === self::OWNER
            && self::statusValue($task->getOriginal('status')) === TaskStatus::Leased->value;
        if (! $wasHeld) {
            return;
        }
        if ($deleting || $task->isDirty(['status', 'lease_owner', 'lease_expires_at'])) {
            throw new LogicException(sprintf(
                'Workflow task [%s] is held for a deadline close settlement; only the settlement may release it.',
                $task->getKey(),
            ));
        }
    }

    /**
     * The settlement state shown by describe, or null when the run has none.
     *
     * @return array{state: string, held: bool, failed_attempts: int, last_error: string|null, next_attempt_at: string|null}|null
     */
    public static function describe(WorkflowRun $run): ?array
    {
        /** @var WorkflowTask|null $task */
        $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->get()
            ->filter(static fn (WorkflowTask $task): bool => is_array($task->payload)
                && is_string($task->payload[self::MARKER] ?? null))
            ->sortBy(static fn (WorkflowTask $task): int => self::holds($task) ? 0 : 1)
            ->first();
        if ($task === null) {
            return null;
        }
        $held = self::holds($task);

        return [
            'state' => $held ? self::PENDING : (string) $task->payload[self::MARKER],
            'held' => $held,
            'failed_attempts' => self::failures($task),
            'last_error' => $held ? $task->last_error : null,
            'next_attempt_at' => $held ? $task->available_at?->toJSON() : null,
        ];
    }

    /**
     * Pending settlements for operator metrics and queue visibility: how many
     * are held, how many are stuck, and since when the oldest has waited.
     *
     * @param (callable(Builder<WorkflowTask>): void)|null $scope
     * @return array{held: int, stuck: int, oldest_held_since: string|null, stuck_after_failures: int}
     */
    public static function metrics(?callable $scope = null): array
    {
        $query = self::heldQuery();
        if ($scope !== null) {
            $scope($query);
        }
        $held = $query->get(['id', 'payload', 'leased_at', 'created_at']);
        $oldest = $held->map(static fn (WorkflowTask $task): ?CarbonInterface => $task->leased_at ?? $task->created_at)
            ->filter()
            ->sort()
            ->first();

        return [
            'held' => $held->count(),
            'stuck' => $held->filter(
                static fn (WorkflowTask $task): bool => self::failures($task) >= self::STUCK_AFTER_FAILURES
            )
                ->count(),
            'oldest_held_since' => $oldest?->toJSON(),
            'stuck_after_failures' => self::STUCK_AFTER_FAILURES,
        ];
    }

    private static function statusValue(mixed $status): ?string
    {
        return $status instanceof TaskStatus ? $status->value : (is_string($status) ? $status : null);
    }

    private static function write(callable $write): void
    {
        $previous = self::$writing;
        self::$writing = true;
        try {
            $write();
        } finally {
            self::$writing = $previous;
        }
    }
}
