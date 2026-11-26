<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\Log;
use LogicException;
use Workflow\V2\CommandContext;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\ParentClosePolicy;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\WorkflowStub;

/**
 * Applies parent-close policies to open child workflows when a parent run closes.
 *
 * Each child link records a parent_close_policy that governs what happens to
 * the child when the parent completes, fails, is cancelled, terminated, or
 * times out. The default policy (abandon) leaves children running independently.
 *
 * A run that continued as new hands its open children to the continued run
 * (see ChildSupervision), so the chain's final run applies their policies.
 */
final class ParentClosePolicyEnforcer
{
    /**
     * Apply parent-close policies for all open children of the given run.
     *
     * This should be called after the parent run has been closed (completed,
     * failed, cancelled, terminated, timed out) but before the summary
     * projection is rebuilt.
     *
     * @return list<string> Instance IDs of children that had policy applied
     */
    public static function enforce(
        WorkflowRun $run,
        ?DeadlineCloseBudget $budget = null,
        ?bool &$complete = null,
    ): array {
        $complete = true;

        return $run->getConnection()
            ->transaction(static function () use ($run, $budget, &$complete): array {
                /** @var WorkflowRun $lockedRun */
                $lockedRun = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                    ->lockForUpdate()
                    ->findOrFail($run->id);

                return self::enforceLocked($lockedRun, $budget, $complete);
            }, 3);
    }

    /**
     * Apply policies while the caller holds the parent run's write lock.
     *
     * With a budget (a bounded deadline close, #1116), children are acted on
     * in link order while each one's whole outcome fits what is left of the
     * budget (#1290): a terminated or cancelled child is charged its own
     * close with its open work, accepted updates and the children its own
     * policy closes. A child this run already recorded a policy outcome for
     * is skipped, so a later transaction continues where this one stopped;
     * $complete reports whether any child is left.
     *
     * @internal
     * @return list<string>
     */
    public static function enforceLocked(
        WorkflowRun $run,
        ?DeadlineCloseBudget $budget = null,
        bool &$complete = true,
    ): array {
        $appliedTo = [];
        $complete = true;
        // Children this run already recorded a policy outcome for, read once.
        $settled = $budget === null ? [] : array_flip(array_filter(
            $run->historyEvents()
                ->whereIn('event_type', [
                    HistoryEventType::ParentClosePolicyApplied->value,
                    HistoryEventType::ParentClosePolicyFailed->value,
                ])
                ->get()
                ->map(static fn (WorkflowHistoryEvent $event): mixed => $event->payload['child_run_id'] ?? null)
                ->all(),
            'is_string',
        ));

        foreach (self::targets($run) as $target) {
            $policy = ParentClosePolicy::tryFrom($target['policy']);
            $childRun = $target['child_run'];

            if ($policy === null || $policy === ParentClosePolicy::Abandon
                || ! $childRun instanceof WorkflowRun || ChildSupervision::isTerminal($childRun)) {
                continue;
            }

            if (isset($settled[$childRun->id])) {
                continue;
            }

            if ($budget !== null && ! $budget->take(self::cost($childRun, $policy, $budget))) {
                $complete = false;

                break;
            }

            if (self::applyTarget($run, $target, $childRun, $policy)) {
                $appliedTo[] = $target['child_instance_id'];
            }
        }

        return $appliedTo;
    }

    /**
     * What one child's policy outcome writes in the parent's transaction. A
     * cancellation request leaves the child open. A terminate or
     * request_cancel runs the child's whole close: its open tasks, activities
     * and timers, its accepted updates, and in turn the open children its own
     * policy closes. Counting stops once the total no longer fits $budget,
     * since the child is then taken only as a pass's first item.
     *
     * @param array{int, int} $total the cost counted so far
     * @return array{int, int}
     */
    private static function cost(
        WorkflowRun $childRun,
        ParentClosePolicy $policy,
        DeadlineCloseBudget $budget,
        array $total = [0, 0],
    ): array {
        if ($policy === ParentClosePolicy::RequestCancellation) {
            return DeadlineCloseBudget::plus($total, DeadlineCloseBudget::CANCELLATION_REQUEST);
        }
        $total = DeadlineCloseBudget::plus($total, DeadlineCloseBudget::CHILD);
        if ($budget->unbounded()) {
            return $total;
        }

        $open = ConfiguredV2Models::query('run_model', WorkflowRun::class)
            ->whereKey($childRun->id)
            ->withCount([
                'tasks as open_tasks' => static fn ($tasks) => $tasks->whereIn(
                    'status',
                    [TaskStatus::Ready->value, TaskStatus::Leased->value],
                ),
                'activityExecutions as open_activities' => static fn ($activities) => $activities->whereIn(
                    'status',
                    [ActivityStatus::Pending->value, ActivityStatus::Running->value],
                ),
                'timers as open_timers' => static fn ($timers) => $timers->where(
                    'status',
                    TimerStatus::Pending->value,
                ),
                'updates as accepted_updates' => static fn ($updates) => $updates->where(
                    'status',
                    UpdateStatus::Accepted->value,
                ),
            ])
            ->toBase()
            ->first();
        $activities = (int) ($open->open_activities ?? 0);
        // An activity's cost includes its task.
        $total = DeadlineCloseBudget::plus($total, DeadlineCloseBudget::ACTIVITY, $activities);
        $total = DeadlineCloseBudget::plus(
            $total,
            DeadlineCloseBudget::TASK,
            max(0, (int) ($open->open_tasks ?? 0) - $activities),
        );
        $total = DeadlineCloseBudget::plus($total, DeadlineCloseBudget::TIMER, (int) ($open->open_timers ?? 0));
        $total = DeadlineCloseBudget::plus(
            $total,
            DeadlineCloseBudget::UPDATE,
            (int) ($open->accepted_updates ?? 0),
        );

        foreach (self::targets($childRun) as $target) {
            if (! $budget->fits($total)) {
                break;
            }
            $grandchildPolicy = ParentClosePolicy::tryFrom($target['policy']);
            $grandchild = $target['child_run'];
            if ($grandchildPolicy === null || $grandchildPolicy === ParentClosePolicy::Abandon
                || ! $grandchild instanceof WorkflowRun || ChildSupervision::isTerminal($grandchild)) {
                continue;
            }
            $total = self::cost($grandchild, $grandchildPolicy, $budget, $total);
        }

        return $total;
    }

    /**
     * @param array{link_id: string, sequence: int|null, child_instance_id: string, origin_run_id: string} $target
     */
    private static function applyTarget(
        WorkflowRun $run,
        array $target,
        WorkflowRun $childRun,
        ParentClosePolicy $policy,
    ): bool {
        $childInstanceId = $target['child_instance_id'];

        $reason = sprintf(
            'Parent workflow closed (%s); parent-close policy: %s.',
            $run->closed_reason ?? $run->status->value ?? 'unknown',
            $policy->value,
        );

        try {
            $cancellation = [];

            if ($policy === ParentClosePolicy::RequestCancellation) {
                // A previously accepted request is already durable. Replaying
                // enforcement must not append another receipt or grant time.
                if ($run->historyEvents()
                    ->where('event_type', HistoryEventType::ParentClosePolicyApplied->value)
                    ->where('payload->child_run_id', $childRun->id)
                    ->where('payload->policy', $policy->value)
                    ->exists()) {
                    return false;
                }
                ParentCloseCancellation::ensureOrigin($run, $reason);
                $result = WorkflowStub::load($childInstanceId)->withCommandContext(CommandContext::workflow(
                    $run->workflow_instance_id,
                    $run->id,
                    $target['sequence'] ?? 0,
                    $target['link_id'],
                ))
                    ->attemptRequestCancellationFromParent($run->id);
                $cancellation = [
                    'request_id' => $result->commandId(),
                    'cancellation' => $result->cancellationContext()?->toArray(),
                ];
                if (! $result->accepted()) {
                    $failure = [
                        'child_instance_id' => $childInstanceId,
                        'child_run_id' => $childRun->id,
                        'policy' => $policy->value,
                        'reason' => $reason,
                        'error' => $result->rejectionReason() ?? 'cancellation_request_rejected',
                        'request_diagnostics' => $result->payloadValues([
                            'existing_request_id', 'existing_root_request_id', 'existing_cleanup_deadline_at',
                            'incoming_root_request_id', 'incoming_cleanup_deadline_at',
                        ]),
                    ];
                    WorkflowHistoryEvent::recordWhileRunLocked(
                        $run,
                        HistoryEventType::ParentClosePolicyFailed,
                        array_merge($failure, $cancellation)
                    );

                    return false;
                }
                if ($result->cancellationContext() === null) {
                    throw new LogicException('cancellation_child_context_unavailable');
                }
            } else {
                WorkflowStub::closeChildWithParentPolicy($childRun, $childInstanceId, $run, $policy, $reason);
            }

            self::markChildCallForPolicy($run, $target, $policy);

            WorkflowHistoryEvent::recordWhileRunLocked($run, HistoryEventType::ParentClosePolicyApplied, array_merge([
                'child_instance_id' => $childInstanceId,
                'child_run_id' => $childRun->id,
                'policy' => $policy->value,
                'reason' => $reason,
            ], $cancellation));

            return true;
        } catch (\Throwable $throwable) {
            // Best-effort: if the child cannot be loaded or the command
            // is rejected (already terminal), continue with the remaining
            // children. Record the failure so operators can distinguish
            // "policy applied" from "policy failed silently".
            WorkflowHistoryEvent::recordWhileRunLocked($run, HistoryEventType::ParentClosePolicyFailed, [
                'child_instance_id' => $childInstanceId,
                'child_run_id' => $childRun->id,
                'policy' => $policy->value,
                'reason' => $reason,
                'error' => $throwable->getMessage(),
            ]);

            Log::warning('Parent-close policy enforcement failed.', [
                'parent_run_id' => $run->id,
                'child_instance_id' => $childInstanceId,
                'policy' => $policy->value,
                'error' => $throwable->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The children this run applies parent-close policy to: every child link
     * it started, then every open child that an earlier run of its
     * continue-as-new chain handed to it (#868). A handed-over child keeps its
     * original link, sequence and policy; this run only applies the policy.
     *
     * @return iterable<array{
     *     link_id: string,
     *     sequence: int|null,
     *     policy: string,
     *     child_instance_id: string,
     *     child_run: WorkflowRun|null,
     *     origin_run_id: string
     * }>
     */
    private static function targets(WorkflowRun $run): iterable
    {
        // Keep only one page of child runs and their instances in memory.
        $childLinks = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->where('parent_close_policy', '!=', ParentClosePolicy::Abandon->value)
            ->with('childRun.instance')
            ->lazyById(100);

        foreach ($childLinks as $link) {
            $childRun = $link->childRun;

            yield [
                'link_id' => (string) $link->id,
                'sequence' => is_int($link->sequence) ? $link->sequence : null,
                'policy' => (string) $link->parent_close_policy,
                'child_instance_id' => (string) $link->child_workflow_instance_id,
                'child_run' => $childRun instanceof WorkflowRun ? $childRun : null,
                'origin_run_id' => $run->id,
            ];
        }

        foreach (ChildSupervision::transferredTo($run) as $supervision) {
            if ($supervision->parent_close_policy === ParentClosePolicy::Abandon->value) {
                continue;
            }

            /** @var WorkflowRun|null $childRun */
            $childRun = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->find($supervision->child_workflow_run_id);

            yield [
                'link_id' => (string) $supervision->workflow_link_id,
                'sequence' => is_int($supervision->sequence) ? $supervision->sequence : null,
                'policy' => (string) $supervision->parent_close_policy,
                'child_instance_id' => (string) $supervision->child_workflow_instance_id,
                'child_run' => ChildSupervision::currentChildRun(
                    $childRun,
                    (string) $supervision->child_workflow_instance_id,
                ),
                'origin_run_id' => (string) $supervision->parent_workflow_run_id,
            ];
        }
    }

    /**
     * @param array{sequence: int|null, origin_run_id: string} $target
     */
    private static function markChildCallForPolicy(WorkflowRun $run, array $target, ParentClosePolicy $policy): void
    {
        if (! is_int($target['sequence'])) {
            return;
        }

        // The child call stays keyed by the run that started the child.
        $originRun = $target['origin_run_id'] === $run->id
            ? $run
            : ConfiguredV2Models::query('run_model', WorkflowRun::class)->find($target['origin_run_id']);

        if (! $originRun instanceof WorkflowRun) {
            return;
        }

        ChildRunHistory::markChildCallClosedByParentPolicy($originRun, $target['sequence'], $policy);
    }
}
