<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Connection;
use Throwable;
use Workflow\Queue\DatabaseTaskDelivery;
use Workflow\Support\AfterCommit;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Contracts\WorkflowTaskRepairProjectionRole;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\UnsupportedBackendCapabilitiesException;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class TaskDispatcher
{
    /**
     * Rows a coalescing scope writes per transaction. The scope commits its
     * deferred publishes and summary refreshes in bounded batches instead of
     * one autocommit transaction per row (#1231).
     */
    private const COALESCED_BATCH_SIZE = 50;

    /**
     * Publishes and post-commit writes registered inside the active coalescing
     * scope, in commit order.
     *
     * @var list<array{task_id: string, queue_timer_wakeup: bool, bounded: bool}|\Closure(): void>|null
     */
    private static ?array $deferredPublishes = null;

    /**
     * Run-summary refreshes owed by tasks published inside the active
     * coalescing scope, keyed by run id (#1231).
     *
     * @var array<string, list<array{task_id: string, bounded: bool}>>|null
     */
    private static ?array $deferredRunSummaries = null;

    private static bool $flushingPublishes = false;

    /**
     * Publish the tasks committed inside $operation after it returns, and
     * refresh each affected run's summary once instead of once per task.
     *
     * A workflow task that schedules a fan-out commits one task per member.
     * Each publish used to rebuild the run's whole summary in its own
     * transaction, so the step cost grew with the square of the fan-out width.
     * The deferred refresh reads the same committed state the last per-task
     * refresh read. Publishing still happens only after the owning commit.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public static function coalescingRunSummaries(callable $operation): mixed
    {
        if (self::$deferredRunSummaries !== null) {
            return $operation();
        }

        self::$deferredPublishes = [];
        self::$deferredRunSummaries = [];

        try {
            $result = $operation();
        } catch (Throwable $throwable) {
            try {
                self::flushCoalesced();
            } catch (Throwable) {
                // The operation's own failure is the one to report.
            }

            throw $throwable;
        }

        self::flushCoalesced();

        return $result;
    }

    public static function dispatch(
        WorkflowTask $task,
        bool $queueTimerWakeup = false,
        bool $boundedWorkflowTaskProjection = false,
    ): void {
        $connection = $task->getConnection();
        if ($connection->transactionLevel() > 0) {
            $taskId = (string) $task->id;
            AfterCommit::on(
                $connection,
                static fn () => self::publishOrDefer($taskId, $queueTimerWakeup, $boundedWorkflowTaskProjection)
            );

            return;
        }

        self::publish($task->id, $queueTimerWakeup, $boundedWorkflowTaskProjection);
    }

    /**
     * @internal Poll repair metadata joins the caller's SQLite writer transaction.
     */
    public static function dispatchRepairedPollTask(
        WorkflowTask $task,
        bool $boundedWorkflowTaskProjection = false,
    ): void {
        if (! self::isPollMode() || WorkflowStub::faked()) {
            throw new \LogicException('Atomic poll repair cannot publish physical or inline work.');
        }
        $connection = $task->getConnection();
        if ($connection->getDriverName() !== 'sqlite' || $connection->transactionLevel() === 0
            || ! $connection->getPdo()
                ->inTransaction()) {
            throw new \LogicException('Atomic poll repair requires the SQLite writer transaction.');
        }

        // Poll mode only updates metadata and projections. It publishes no job
        // and executes no workflow/activity body before this transaction commits.
        self::publish($task->id, false, $boundedWorkflowTaskProjection);
    }

    /**
     * Run a best-effort projection write after the connection's current
     * commit, reporting rather than throwing its failure. Inside a coalescing
     * scope the write joins the scope's batched post-commit work, ahead of
     * the tasks published after it, instead of committing alone (#1203: one
     * child-call row per started child).
     *
     * @param \Closure(): void $write
     */
    public static function afterCommitWrite(Connection $connection, \Closure $write): void
    {
        AfterCommit::on($connection, static function () use ($connection, $write): void {
            if (self::$deferredPublishes !== null && ! WorkflowStub::faked()) {
                self::$deferredPublishes[] = static function () use ($connection, $write): void {
                    try {
                        // Its own savepoint keeps the batch usable after a failure.
                        $connection->transaction(static fn () => $write());
                    } catch (Throwable $throwable) {
                        report($throwable);
                    }
                };

                return;
            }

            try {
                $write();
            } catch (Throwable $throwable) {
                report($throwable);
            }
        });
    }

    private static function publishOrDefer(
        string $taskId,
        bool $queueTimerWakeup,
        bool $boundedWorkflowTaskProjection,
    ): void {
        if (self::$deferredPublishes !== null && ! WorkflowStub::faked()) {
            self::$deferredPublishes[] = [
                'task_id' => $taskId,
                'queue_timer_wakeup' => $queueTimerWakeup,
                'bounded' => $boundedWorkflowTaskProjection,
            ];

            return;
        }

        self::publish($taskId, $queueTimerWakeup, $boundedWorkflowTaskProjection);
    }

    private static function flushCoalesced(): void
    {
        $publishes = self::$deferredPublishes ?? [];
        self::$deferredPublishes = null;
        $failure = null;
        self::$flushingPublishes = true;

        try {
            $admissionTaskId = null;
            foreach ($publishes as $publish) {
                if (is_array($publish)) {
                    $admissionTaskId = $publish['task_id'];

                    break;
                }
            }
            $steps = array_map(
                static fn (array|\Closure $publish): \Closure => $publish instanceof \Closure
                    ? $publish
                    : static fn () => self::publish(
                        $publish['task_id'],
                        $publish['queue_timer_wakeup'],
                        $publish['bounded'],
                    ),
                $publishes,
            );

            if ($admissionTaskId === null) {
                // Writes alone keep their own transactions.
                foreach ($steps as $step) {
                    $step();
                }
            } else {
                foreach (array_chunk($steps, self::COALESCED_BATCH_SIZE) as $batch) {
                    $failure = self::inBatchTransaction($admissionTaskId, $batch) ?? $failure;
                }
            }
        } finally {
            self::$flushingPublishes = false;
            $summaries = self::$deferredRunSummaries ?? [];
            self::$deferredRunSummaries = null;
        }

        foreach (array_chunk($summaries, self::COALESCED_BATCH_SIZE, true) as $batch) {
            $entries = array_values($batch);
            $failure = self::inBatchTransaction(
                $entries[0][0]['task_id'],
                array_map(
                    static fn (string $runId, array $runEntries): \Closure => static fn () => self::refreshDeferredRunSummary(
                        $runId,
                        $runEntries,
                    ),
                    array_map('strval', array_keys($batch)),
                    $entries,
                ),
            ) ?? $failure;
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Run each step in one batch transaction, isolating every step in its own
     * savepoint so one failure keeps the others, as separate transactions did.
     * Returns the first failure for the caller to report after the batch.
     *
     * @param list<\Closure(): void> $steps
     */
    private static function inBatchTransaction(string $admissionTaskId, array $steps): ?Throwable
    {
        $failure = null;
        $runSteps = static function (Connection $connection) use ($steps, &$failure): void {
            foreach ($steps as $step) {
                try {
                    $connection->transaction(static fn () => $step());
                } catch (Throwable $throwable) {
                    $failure ??= $throwable;
                }
            }
        };

        $connection = ConfiguredV2Models::query('task_model', WorkflowTask::class)->getModel()
            ->getConnection();

        if ($connection->getDriverName() === 'sqlite') {
            // Take SQLite's writer once for the batch. The steps publish jobs,
            // so the batch is never repeated after it starts.
            SqliteTransaction::forTask($admissionTaskId, static fn (Connection $database) => $runSteps($database));
        } else {
            $connection->transaction(static fn (Connection $database) => $runSteps($database), 1);
        }

        return $failure;
    }

    private static function publish(
        string $taskId,
        bool $queueTimerWakeup = false,
        bool $boundedWorkflowTaskProjection = false,
    ): void {
        /** @var WorkflowTask|null $task */
        $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)->find($taskId);

        if (! $task instanceof WorkflowTask || $task->status !== TaskStatus::Ready) {
            return;
        }

        // This dispatcher already waits for the task's storage commit. Do not
        // let Laravel defer its transport again on an unrelated transaction.
        $job = self::makeJob($task)->beforeCommit();
        $attemptedAt = now();

        if (WorkflowStub::faked()) {
            self::markDispatched($task, $attemptedAt, $boundedWorkflowTaskProjection);

            if ($task->available_at === null || ! $task->available_at->isFuture()) {
                app()->call([$job, 'handle']);
            }

            return;
        }

        if (self::isPollMode() && ! $queueTimerWakeup) {
            self::markDispatched($task, $attemptedAt, $boundedWorkflowTaskProjection);

            return;
        }

        try {
            self::ensureBackendSupportsDispatch($task);

            // Timer wakeups are server infrastructure work, independent of SDK worker compatibility.
            $fleetBlockReason = $queueTimerWakeup ? null : self::fleetBlockReason($task);

            if ($fleetBlockReason !== null) {
                self::markDispatchFailure($task, $attemptedAt, $fleetBlockReason, $boundedWorkflowTaskProjection);

                return;
            }

            if (ExecutionTracing::enabled()) {
                ExecutionTracing::dispatch(
                    $task,
                    $job,
                    static fn () => app(BusDispatcher::class)->dispatch($job),
                    true
                );
            } else {
                app(BusDispatcher::class)->dispatch($job);
            }
            self::markDispatched($task, $attemptedAt, $boundedWorkflowTaskProjection, $job);
        } catch (Throwable $throwable) {
            self::markDispatchFailure($task, $attemptedAt, $throwable->getMessage(), $boundedWorkflowTaskProjection);

            throw $throwable;
        }
    }

    private static function isPollMode(): bool
    {
        return config('workflows.v2.task_dispatch_mode') === 'poll';
    }

    private static function ensureBackendSupportsDispatch(WorkflowTask $task): void
    {
        $snapshot = BackendCapabilities::snapshot(queueConnection: $task->connection);

        if (! BackendCapabilities::isSupported($snapshot)) {
            throw new UnsupportedBackendCapabilitiesException($snapshot);
        }
    }

    private static function fleetBlockReason(WorkflowTask $task): ?string
    {
        if (config('workflows.v2.fleet.validation_mode') !== 'fail') {
            return null;
        }

        /** @var WorkflowRun|null $run */
        $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)->find($task->workflow_run_id);

        $required = TaskCompatibility::resolve($task, $run);

        if ($required === null) {
            return null;
        }

        $connection = $task->connection ?? $run?->connection;
        $queue = $task->queue ?? $run?->queue;

        if (WorkerCompatibilityFleet::activeWorkerCount($connection, $queue) === 0) {
            return null;
        }

        if (WorkerCompatibilityFleet::supports($required, $connection, $queue)) {
            return null;
        }

        $reason = WorkerCompatibilityFleet::mismatchReason($required, $connection, $queue);

        return $reason === null
            ? 'Dispatch blocked: no compatible worker in fleet.'
            : 'Dispatch blocked under fail validation mode. ' . $reason;
    }

    private static function makeJob(WorkflowTask $task): RunWorkflowTask|RunActivityTask|RunTimerTask
    {
        $job = match ($task->task_type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            TaskType::Timer => new RunTimerTask($task->id),
        };

        if ($task->connection !== null) {
            $job->onConnection($task->connection);
        }

        if ($task->queue !== null) {
            $job->onQueue($task->queue);
        }

        if ($task->available_at !== null && $task->available_at->isFuture()) {
            // Queue backends store second-resolution deadlines. Never round a wakeup down.
            $availableAt = $task->available_at->copy()
                ->ceilSecond();
            $effectiveDelay = TimerTransportChunker::cappedDispatchDelay($availableAt, $task->connection);

            $job->delay($effectiveDelay);
        }

        return $job;
    }

    private static function markDispatched(
        WorkflowTask $task,
        mixed $attemptedAt,
        bool $boundedWorkflowTaskProjection,
        ?object $job = null,
    ): void {
        if (array_key_exists('queue_delivery_reference', $task->getAttributes())) {
            $task->setAttribute('queue_delivery_reference', DatabaseTaskDelivery::capture($task, $job));
        }
        $task->forceFill([
            'last_dispatch_attempt_at' => $attemptedAt,
            'last_dispatched_at' => $attemptedAt,
            'last_dispatch_error' => null,
            'repair_available_at' => null,
        ])->save();

        self::refreshRunSummary($task, $boundedWorkflowTaskProjection);
    }

    private static function markDispatchFailure(
        WorkflowTask $task,
        mixed $attemptedAt,
        string $message,
        bool $boundedWorkflowTaskProjection,
    ): void {
        $task->forceFill([
            'last_dispatch_attempt_at' => $attemptedAt,
            'last_dispatch_error' => $message,
            'repair_available_at' => TaskRepairPolicy::repairAvailableAtAfterFailure(
                $task,
                $attemptedAt,
                immediateFirstFailure: true,
            ),
        ])->save();

        self::refreshRunSummary($task, $boundedWorkflowTaskProjection);
    }

    private static function refreshRunSummary(WorkflowTask $task, bool $boundedWorkflowTaskProjection): void
    {
        // A publish inside a caller's transaction (atomic poll repair) keeps
        // its refresh in that transaction. Scope batches defer theirs.
        if (
            self::$deferredRunSummaries !== null
            && ! WorkflowStub::faked()
            && (self::$flushingPublishes || $task->getConnection()->transactionLevel() === 0)
        ) {
            self::$deferredRunSummaries[(string) $task->workflow_run_id][] = [
                'task_id' => (string) $task->id,
                'bounded' => $boundedWorkflowTaskProjection,
            ];

            return;
        }

        self::refreshRunSummaryNow($task, $boundedWorkflowTaskProjection);
    }

    /**
     * @param list<array{task_id: string, bounded: bool}> $entries
     */
    private static function refreshDeferredRunSummary(string $runId, array $entries): void
    {
        if (count($entries) === 1) {
            /** @var WorkflowTask|null $task */
            $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)->find($entries[0]['task_id']);

            if ($task instanceof WorkflowTask) {
                self::refreshRunSummaryNow($task, $entries[0]['bounded']);

                return;
            }
        }

        /** @var WorkflowRun|null $run */
        $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)->find($runId);

        if ($run instanceof WorkflowRun) {
            self::historyProjectionRole()->projectRun($run);
        }
    }

    private static function refreshRunSummaryNow(WorkflowTask $task, bool $boundedWorkflowTaskProjection): void
    {
        /** @var WorkflowRun|null $run */
        $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)->find($task->workflow_run_id);

        if ($run instanceof WorkflowRun) {
            $projectionRole = self::historyProjectionRole();

            if (
                $boundedWorkflowTaskProjection
                && $task->task_type === TaskType::Workflow
                && $task->status === TaskStatus::Ready
                && ! $run->status->isTerminal()
                && $projectionRole instanceof WorkflowTaskRepairProjectionRole
            ) {
                $projectionRole->projectRepairedWorkflowTask($run, $task);
            } else {
                $projectionRole->projectRun($run);
            }
        }
    }

    private static function historyProjectionRole(): HistoryProjectionRole
    {
        /** @var HistoryProjectionRole $role */
        $role = app(HistoryProjectionRole::class);

        return $role;
    }
}
