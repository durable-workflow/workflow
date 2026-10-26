<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;
use WeakMap;
use Workflow\Events\ActivityCompleted as LegacyActivityCompleted;
use Workflow\Events\ActivityFailed as LegacyActivityFailed;
use Workflow\Events\ActivityStarted as LegacyActivityStarted;
use Workflow\Events\StateChanged;
use Workflow\Events\WorkflowCompleted as LegacyWorkflowCompleted;
use Workflow\Events\WorkflowFailed as LegacyWorkflowFailed;
use Workflow\Events\WorkflowStarted as LegacyWorkflowStarted;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowFailedStatus;
use Workflow\States\WorkflowRunningStatus;
use Workflow\V2\Events\ActivityCompleted;
use Workflow\V2\Events\ActivityFailed;
use Workflow\V2\Events\ActivityStarted;
use Workflow\V2\Events\FailureRecorded;
use Workflow\V2\Events\WorkflowCancelled;
use Workflow\V2\Events\WorkflowCompleted;
use Workflow\V2\Events\WorkflowFailed;
use Workflow\V2\Events\WorkflowStarted;
use Workflow\V2\Events\WorkflowTerminated;
use Workflow\V2\Models\WorkflowRun;

/**
 * Dispatches V2 lifecycle events from committed durable truth.
 *
 * Call sites dispatch only after the durable lifecycle write is no longer
 * eligible to roll back. Retried transaction paths must dispatch after
 * DB::transaction() returns successfully (or via DB::afterCommit()) so a
 * failed attempt cannot leak duplicate public events.
 *
 * Events carry scalar identity values captured eagerly from the models and
 * are dispatched synchronously within the same request/job that committed
 * the state change.
 *
 * A transaction body that may still roll back after dispatching runs under
 * {@see self::collect()}: its events are held back and dispatched by
 * {@see self::release()} once the transaction has committed.
 */
final class LifecycleEventDispatcher
{
    /**
     * The events held back by each running transaction attempt, innermost last.
     *
     * @var list<list<Closure(): void>>
     */
    private static array $collectors = [];

    /**
     * @var WeakMap<Closure, Connection>|null
     */
    private static ?WeakMap $eventConnections = null;

    /**
     * Run one transaction attempt's body and hold back the lifecycle events it
     * dispatches. Call it inside the DB::transaction() callback and pass the
     * returned events to {@see self::release()} after DB::transaction()
     * returns. Each call collects for its own attempt: if the body throws,
     * its events are discarded, so a refused, failed or retried attempt
     * dispatches nothing, even when an enclosing attempt catches the failure
     * and commits.
     *
     * @template T
     * @param callable(): T $body
     * @return array{0: T, 1: list<Closure(): void>}
     */
    public static function collect(callable $body): array
    {
        self::$collectors[] = [];
        $depth = count(self::$collectors);

        try {
            $result = $body();
        } catch (Throwable $error) {
            array_splice(self::$collectors, $depth - 1);

            throw $error;
        }

        $events = self::$collectors[$depth - 1];
        array_splice(self::$collectors, $depth - 1);

        return [$result, $events];
    }

    /**
     * Release the events of an attempt whose transaction has committed. Inside
     * an enclosing collecting attempt they join that attempt, and are
     * dispatched or discarded with it. Otherwise, inside a caller's still-open
     * transaction they wait for its commit, and outside any transaction they
     * are dispatched now.
     *
     * @param list<Closure(): void> $events
     */
    public static function release(array $events): void
    {
        if ($events === []) {
            return;
        }

        if (self::$collectors !== []) {
            array_push(self::$collectors[count(self::$collectors) - 1], ...$events);

            return;
        }

        foreach ($events as $event) {
            $connection = self::$eventConnections !== null && isset(self::$eventConnections[$event])
                ? self::$eventConnections[$event]
                : DB::connection();
            if ($connection->transactionLevel() > 0) {
                $connection->afterCommit($event);
            } else {
                $event();
            }
        }
    }

    public static function workflowCancelled(WorkflowRun $run, ?string $reason): void
    {
        self::workflowTerminal($run, $reason, WorkflowCancelled::class);
    }

    public static function workflowTerminated(WorkflowRun $run, ?string $reason): void
    {
        self::workflowTerminal($run, $reason, WorkflowTerminated::class);
    }

    public static function workflowStarted(WorkflowRun $run): void
    {
        $instanceId = (string) $run->instance?->id;
        $runId = (string) $run->id;
        $workflowType = (string) ($run->workflow_type ?? $run->workflow_class);
        $workflowClass = (string) $run->workflow_class;
        $committedAt = now()
            ->toIso8601String();
        $stateChanged = self::stateChanged($run, null, new WorkflowRunningStatus($run));

        self::emit(static function () use (
            $instanceId,
            $runId,
            $workflowType,
            $workflowClass,
            $committedAt,
            $stateChanged
        ): void {
            WorkflowStarted::dispatch($instanceId, $runId, $workflowType, $workflowClass, $committedAt);

            LegacyWorkflowStarted::dispatch($instanceId, $workflowClass, '[]', $committedAt);

            event($stateChanged);
        });
    }

    public static function workflowCompleted(WorkflowRun $run): void
    {
        $instanceId = (string) $run->instance?->id;
        $runId = (string) $run->id;
        $workflowType = (string) ($run->workflow_type ?? $run->workflow_class);
        $workflowClass = (string) $run->workflow_class;
        $committedAt = now()
            ->toIso8601String();
        $stateChanged = self::stateChanged($run, new WorkflowRunningStatus($run), new WorkflowCompletedStatus($run));

        self::emit(static function () use (
            $instanceId,
            $runId,
            $workflowType,
            $workflowClass,
            $committedAt,
            $stateChanged
        ): void {
            WorkflowCompleted::dispatch($instanceId, $runId, $workflowType, $workflowClass, $committedAt);

            LegacyWorkflowCompleted::dispatch($instanceId, '', $committedAt);

            event($stateChanged);
        });
    }

    public static function workflowFailed(WorkflowRun $run, string $exceptionClass, string $message): void
    {
        $instanceId = (string) $run->instance?->id;
        $runId = (string) $run->id;
        $workflowType = (string) ($run->workflow_type ?? $run->workflow_class);
        $workflowClass = (string) $run->workflow_class;
        $committedAt = now()
            ->toIso8601String();
        $stateChanged = self::stateChanged($run, new WorkflowRunningStatus($run), new WorkflowFailedStatus($run));

        self::emit(static function () use (
            $instanceId,
            $runId,
            $workflowType,
            $workflowClass,
            $exceptionClass,
            $message,
            $committedAt,
            $stateChanged
        ): void {
            WorkflowFailed::dispatch(
                $instanceId,
                $runId,
                $workflowType,
                $workflowClass,
                $exceptionClass,
                $message,
                $committedAt,
            );

            LegacyWorkflowFailed::dispatch($instanceId, $exceptionClass . ': ' . $message, $committedAt);

            event($stateChanged);
        });
    }

    public static function activityStarted(
        WorkflowRun $run,
        string $activityExecutionId,
        string $activityType,
        string $activityClass,
        int $sequence,
        int $attemptNumber,
    ): void {
        $instanceId = (string) $run->instance?->id;
        $runId = (string) $run->id;
        $committedAt = now()
            ->toIso8601String();

        self::emit(static function () use (
            $instanceId,
            $runId,
            $activityExecutionId,
            $activityType,
            $activityClass,
            $sequence,
            $attemptNumber,
            $committedAt
        ): void {
            ActivityStarted::dispatch(
                $instanceId,
                $runId,
                $activityExecutionId,
                $activityType,
                $activityClass,
                $sequence,
                $attemptNumber,
                $committedAt,
            );

            LegacyActivityStarted::dispatch(
                $instanceId,
                $activityExecutionId,
                $activityClass,
                $sequence,
                '[]',
                $committedAt,
            );
        });
    }

    public static function activityCompleted(
        WorkflowRun $run,
        string $activityExecutionId,
        string $activityType,
        string $activityClass,
        int $sequence,
        int $attemptNumber,
    ): void {
        $instanceId = (string) $run->instance?->id;
        $runId = (string) $run->id;
        $committedAt = now()
            ->toIso8601String();

        self::emit(static function () use (
            $instanceId,
            $runId,
            $activityExecutionId,
            $activityType,
            $activityClass,
            $sequence,
            $attemptNumber,
            $committedAt
        ): void {
            ActivityCompleted::dispatch(
                $instanceId,
                $runId,
                $activityExecutionId,
                $activityType,
                $activityClass,
                $sequence,
                $attemptNumber,
                $committedAt,
            );

            LegacyActivityCompleted::dispatch(
                $instanceId,
                $activityExecutionId,
                '',
                $committedAt,
                $activityClass,
                $sequence,
            );
        });
    }

    public static function activityFailed(
        WorkflowRun $run,
        string $activityExecutionId,
        string $activityType,
        string $activityClass,
        int $sequence,
        int $attemptNumber,
        string $exceptionClass,
        string $message,
    ): void {
        $instanceId = (string) $run->instance?->id;
        $runId = (string) $run->id;
        $committedAt = now()
            ->toIso8601String();

        self::emit(static function () use (
            $instanceId,
            $runId,
            $activityExecutionId,
            $activityType,
            $activityClass,
            $sequence,
            $attemptNumber,
            $exceptionClass,
            $message,
            $committedAt
        ): void {
            ActivityFailed::dispatch(
                $instanceId,
                $runId,
                $activityExecutionId,
                $activityType,
                $activityClass,
                $sequence,
                $attemptNumber,
                $exceptionClass,
                $message,
                $committedAt,
            );

            LegacyActivityFailed::dispatch(
                $instanceId,
                $activityExecutionId,
                $exceptionClass . ': ' . $message,
                $committedAt,
                $activityClass,
                $sequence,
            );
        });
    }

    public static function failureRecorded(
        WorkflowRun $run,
        string $failureId,
        string $sourceKind,
        string $sourceId,
        string $exceptionClass,
        string $message,
    ): void {
        $instanceId = (string) $run->instance?->id;
        $runId = (string) $run->id;
        $committedAt = now()
            ->toIso8601String();

        self::emit(static function () use (
            $instanceId,
            $runId,
            $failureId,
            $sourceKind,
            $sourceId,
            $exceptionClass,
            $message,
            $committedAt
        ): void {
            FailureRecorded::dispatch(
                $instanceId,
                $runId,
                $failureId,
                $sourceKind,
                $sourceId,
                $exceptionClass,
                $message,
                $committedAt,
            );
        });
    }

    /**
     * @param class-string<WorkflowCancelled|WorkflowTerminated> $eventClass
     */
    private static function workflowTerminal(WorkflowRun $run, ?string $reason, string $eventClass): void
    {
        $instanceId = (string) $run->workflow_instance_id;
        $runId = (string) $run->id;
        $workflowType = (string) ($run->workflow_type ?? $run->workflow_class);
        $workflowClass = (string) $run->workflow_class;
        $closedAt = $run->closed_at?->toISOString();
        if ($closedAt === null) {
            throw new LogicException('A terminal lifecycle notification requires the persisted close time.');
        }
        $committedAt = now()
            ->toIso8601String();

        self::emit(static function () use (
            $instanceId,
            $runId,
            $workflowType,
            $workflowClass,
            $committedAt,
            $reason,
            $closedAt,
            $eventClass
        ): void {
            $eventClass::dispatch($instanceId, $runId, $workflowType, $workflowClass, $committedAt, $reason, $closedAt);
        }, $run->getConnection());
    }

    /**
     * Dispatch now, or hold back for the collecting transaction attempt.
     *
     * @param Closure(): void $dispatch
     */
    private static function emit(Closure $dispatch, ?Connection $connection = null): void
    {
        if ($connection !== null) {
            self::$eventConnections ??= new WeakMap();
            self::$eventConnections[$dispatch] = $connection;
        }
        if (self::$collectors !== []) {
            self::$collectors[count(self::$collectors) - 1][] = $dispatch;

            return;
        }

        if ($connection !== null) {
            self::release([$dispatch]);

            return;
        }

        $dispatch();
    }

    /**
     * A V1-compatible StateChanged event over a run-status transition.
     *
     * This is a compatibility adapter — V2 does not use V1 state machines, but
     * apps listening for StateChanged continue to receive notifications when
     * workflow status transitions occur.
     */
    private static function stateChanged(
        WorkflowRun $run,
        ?\Workflow\States\WorkflowStatus $initialState,
        \Workflow\States\WorkflowStatus $finalState,
    ): StateChanged {
        return new StateChanged($initialState, $finalState, $run, 'status');
    }
}
