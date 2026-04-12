<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

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
        WorkflowStarted::dispatch(
            (string) $run->instance?->id,
            (string) $run->id,
            (string) ($run->workflow_type ?? $run->workflow_class),
            (string) $run->workflow_class,
            now()->toIso8601String(),
        );
    }

    public static function workflowCompleted(WorkflowRun $run): void
    {
        WorkflowCompleted::dispatch(
            (string) $run->instance?->id,
            (string) $run->id,
            (string) ($run->workflow_type ?? $run->workflow_class),
            (string) $run->workflow_class,
            now()->toIso8601String(),
        );
    }

    public static function workflowFailed(WorkflowRun $run, string $exceptionClass, string $message): void
    {
        WorkflowFailed::dispatch(
            (string) $run->instance?->id,
            (string) $run->id,
            (string) ($run->workflow_type ?? $run->workflow_class),
            (string) $run->workflow_class,
            $exceptionClass,
            $message,
            now()->toIso8601String(),
        );
    }

    public static function activityStarted(
        WorkflowRun $run,
        string $activityExecutionId,
        string $activityType,
        string $activityClass,
        int $sequence,
        int $attemptNumber,
    ): void {
        ActivityStarted::dispatch(
            (string) $run->instance?->id,
            (string) $run->id,
            $activityExecutionId,
            $activityType,
            $activityClass,
            $sequence,
            $attemptNumber,
            now()->toIso8601String(),
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
        ActivityCompleted::dispatch(
            (string) $run->instance?->id,
            (string) $run->id,
            $activityExecutionId,
            $activityType,
            $activityClass,
            $sequence,
            $attemptNumber,
            now()->toIso8601String(),
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
        ActivityFailed::dispatch(
            (string) $run->instance?->id,
            (string) $run->id,
            $activityExecutionId,
            $activityType,
            $activityClass,
            $sequence,
            $attemptNumber,
            $exceptionClass,
            $message,
            now()->toIso8601String(),
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
}
