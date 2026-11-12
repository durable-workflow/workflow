<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\Log;
use LogicException;
use Workflow\V2\CommandContext;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\ParentClosePolicy;
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
    public static function enforce(WorkflowRun $run): array
    {
        return $run->getConnection()
            ->transaction(static function () use ($run): array {
                /** @var WorkflowRun $lockedRun */
                $lockedRun = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                    ->lockForUpdate()
                    ->findOrFail($run->id);

                return self::enforceLocked($lockedRun);
            }, 3);
    }

    /**
     * @return list<string>
     */
    private static function enforceLocked(WorkflowRun $run): array
    {
        $appliedTo = [];

        foreach (self::targets($run) as $target) {
            $policy = ParentClosePolicy::tryFrom($target['policy']);

            if ($policy === null || $policy === ParentClosePolicy::Abandon) {
                continue;
            }

            $childRun = $target['child_run'];

            if (! $childRun instanceof WorkflowRun) {
                continue;
            }

            // Only act on non-terminal children.
            if (ChildSupervision::isTerminal($childRun)) {
                continue;
            }

            $childInstanceId = $target['child_instance_id'];

            $reason = sprintf(
                'Parent workflow closed (%s); parent-close policy: %s.',
                $run->closed_reason ?? $run->status->value ?? 'unknown',
                $policy->value,
            );

            try {
                $stub = WorkflowStub::load($childInstanceId);
                $cancellation = [];

                if ($policy === ParentClosePolicy::RequestCancellation) {
                    // A previously accepted request is already durable. Replaying
                    // enforcement must not append another receipt or grant time.
                    if ($run->historyEvents()
                        ->where('event_type', HistoryEventType::ParentClosePolicyApplied->value)
                        ->where('payload->child_run_id', $childRun->id)
                        ->where('payload->policy', $policy->value)
                        ->exists()) {
                        continue;
                    }
                    ParentCloseCancellation::ensureOrigin($run, $reason);
                    $result = $stub->withCommandContext(CommandContext::workflow(
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
                        WorkflowHistoryEvent::record($run, HistoryEventType::ParentClosePolicyFailed, array_merge([
                            'child_instance_id' => $childInstanceId,
                            'child_run_id' => $childRun->id,
                            'policy' => $policy->value,
                            'reason' => $reason,
                            'error' => $result->rejectionReason() ?? 'cancellation_request_rejected',
                            'request_diagnostics' => $result->payloadValues([
                                'existing_request_id', 'existing_root_request_id', 'existing_cleanup_deadline_at',
                                'incoming_root_request_id', 'incoming_cleanup_deadline_at',
                            ]),
                        ], $cancellation));

                        continue;
                    }
                    if ($result->cancellationContext() === null) {
                        throw new LogicException('cancellation_child_context_unavailable');
                    }
                }

                match ($policy) {
                    ParentClosePolicy::RequestCancel => $stub->attemptCancel($reason),
                    ParentClosePolicy::Terminate => $stub->attemptTerminate($reason),
                    default => null,
                };

                self::markChildCallForPolicy($run, $target, $policy);

                WorkflowHistoryEvent::record($run, HistoryEventType::ParentClosePolicyApplied, array_merge([
                    'child_instance_id' => $childInstanceId,
                    'child_run_id' => $childRun->id,
                    'policy' => $policy->value,
                    'reason' => $reason,
                ], $cancellation));

                $appliedTo[] = $childInstanceId;
            } catch (\Throwable $throwable) {
                // Best-effort: if the child cannot be loaded or the command
                // is rejected (already terminal), continue with the remaining
                // children. Record the failure so operators can distinguish
                // "policy applied" from "policy failed silently".
                WorkflowHistoryEvent::record($run, HistoryEventType::ParentClosePolicyFailed, [
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

                continue;
            }
        }

        return $appliedTo;
    }

    /**
     * The children this run applies parent-close policy to: every child link
     * it started, then every open child that an earlier run of its
     * continue-as-new chain handed to it (#868). A handed-over child keeps its
     * original link, sequence and policy; this run only applies the policy.
     *
     * @return list<array{
     *     link_id: string,
     *     sequence: int|null,
     *     policy: string,
     *     child_instance_id: string,
     *     child_run: WorkflowRun|null,
     *     origin_run_id: string
     * }>
     */
    private static function targets(WorkflowRun $run): array
    {
        $targets = [];

        $childLinks = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->where('parent_close_policy', '!=', ParentClosePolicy::Abandon->value)
            ->get();

        foreach ($childLinks as $link) {
            $childRun = $link->childRun;

            $targets[] = [
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

            $targets[] = [
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

        return $targets;
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
