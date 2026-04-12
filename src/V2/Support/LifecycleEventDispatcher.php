<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

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
 * All call sites are at the end of their DB::transaction() scope, after all
 * durable state has been written. Events carry only scalar identity values
 * captured eagerly from the models, so they remain valid even if the model
 * instance is later refreshed or discarded.
 *
 * Events are dispatched synchronously so that listeners execute within the
 * same request/job that committed the state change. Since all call sites
 * are inside transactions that either fully commit or fully roll back (and
 * the dispatch is placed after all writes but before the transaction
 * closure returns), listeners always observe committed state.
 */
final class LifecycleEventDispatcher
{
    public static function workflowStarted(WorkflowRun $run): void
    {
        $instanceId = (string) $run->instance?->id;
        $committedAt = now()->toIso8601String();

        WorkflowStarted::dispatch(
            $instanceId,
            (string) $run->id,
            (string) ($run->workflow_type ?? $run->workflow_class),
            (string) $run->workflow_class,
            $committedAt,
        );

        LegacyWorkflowStarted::dispatch(
            $instanceId,
            (string) $run->workflow_class,
            '[]',
            $committedAt,
        );

        self::dispatchStateChanged($run, null, new WorkflowRunningStatus($run));
    }

    public static function workflowCompleted(WorkflowRun $run): void
    {
        $instanceId = (string) $run->instance?->id;
        $committedAt = now()->toIso8601String();

        WorkflowCompleted::dispatch(
            $instanceId,
            (string) $run->id,
            (string) ($run->workflow_type ?? $run->workflow_class),
            (string) $run->workflow_class,
            $committedAt,
        );

        LegacyWorkflowCompleted::dispatch(
            $instanceId,
            '',
            $committedAt,
        );

        self::dispatchStateChanged($run, new WorkflowRunningStatus($run), new WorkflowCompletedStatus($run));
    }

    public static function workflowFailed(WorkflowRun $run, string $exceptionClass, string $message): void
    {
        $instanceId = (string) $run->instance?->id;
        $committedAt = now()->toIso8601String();

        WorkflowFailed::dispatch(
            $instanceId,
            (string) $run->id,
            (string) ($run->workflow_type ?? $run->workflow_class),
            (string) $run->workflow_class,
            $exceptionClass,
            $message,
            $committedAt,
        );

        LegacyWorkflowFailed::dispatch(
            $instanceId,
            $exceptionClass . ': ' . $message,
            $committedAt,
        );

        self::dispatchStateChanged($run, new WorkflowRunningStatus($run), new WorkflowFailedStatus($run));
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
        $committedAt = now()->toIso8601String();

        ActivityStarted::dispatch(
            $instanceId,
            (string) $run->id,
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
        $committedAt = now()->toIso8601String();

        ActivityCompleted::dispatch(
            $instanceId,
            (string) $run->id,
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
        $committedAt = now()->toIso8601String();

        ActivityFailed::dispatch(
            $instanceId,
            (string) $run->id,
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
    }

    public static function failureRecorded(
        WorkflowRun $run,
        string $failureId,
        string $sourceKind,
        string $sourceId,
        string $exceptionClass,
        string $message,
    ): void {
        FailureRecorded::dispatch(
            (string) $run->instance?->id,
            (string) $run->id,
            $failureId,
            $sourceKind,
            $sourceId,
            $exceptionClass,
            $message,
            now()->toIso8601String(),
        );
    }

    /**
     * Dispatch a V1-compatible StateChanged event over a run-status transition.
     *
     * This is a compatibility adapter — V2 does not use V1 state machines, but
     * apps listening for StateChanged continue to receive notifications when
     * workflow status transitions occur.
     */
    private static function dispatchStateChanged(
        WorkflowRun $run,
        ?\Workflow\States\WorkflowStatus $initialState,
        \Workflow\States\WorkflowStatus $finalState,
    ): void {
        event(new StateChanged(
            $initialState,
            $finalState,
            $run,
            'status',
        ));
    }
}
