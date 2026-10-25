<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Closure;
use Illuminate\Support\Facades\DB;
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
use Workflow\V2\Events\WorkflowCompleted;
use Workflow\V2\Events\WorkflowFailed;
use Workflow\V2\Events\WorkflowStarted;
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
     * @var list<Closure(): void>|null The events held back for the running transaction attempt.
     */
    private static ?array $collected = null;

    /**
     * Run one transaction attempt's body and hold back the lifecycle events it
     * dispatches. Call it inside the DB::transaction() callback and pass the
     * returned events to {@see self::release()} after DB::transaction()
     * returns. A refused, failed or retried attempt therefore dispatches
     * nothing, and a committed one dispatches its events once. A body that runs
     * inside a collecting attempt adds its events to that attempt.
     *
     * @template T
     * @param callable(): T $body
     * @return array{0: T, 1: list<Closure(): void>}
     */
    public static function collect(callable $body): array
    {
        if (self::$collected !== null) {
            return [$body(), []];
        }

        self::$collected = [];

        try {
            $result = $body();

            return [$result, self::$collected];
        } finally {
            self::$collected = null;
        }
    }

    /**
     * Dispatch the events a committed attempt held back. Inside a caller's
     * still-open transaction they wait for that transaction's commit.
     *
     * @param list<Closure(): void> $events
     */
    public static function release(array $events): void
    {
        if ($events === []) {
            return;
        }

        $dispatch = static function () use ($events): void {
            foreach ($events as $event) {
                $event();
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);

            return;
        }

        $dispatch();
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
     * Dispatch now, or hold back for the collecting transaction attempt.
     *
     * @param Closure(): void $dispatch
     */
    private static function emit(Closure $dispatch): void
    {
        if (self::$collected !== null) {
            self::$collected[] = $dispatch;

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
