<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\Log;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\ParentClosePolicy;
use Workflow\V2\Enums\RunStatus;
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
        $appliedTo = [];

        $childLinks = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->where('parent_close_policy', '!=', ParentClosePolicy::Abandon->value)
            ->get();

        foreach ($childLinks as $link) {
            $policy = ParentClosePolicy::tryFrom($link->parent_close_policy);

            if ($policy === null || $policy === ParentClosePolicy::Abandon) {
                continue;
            }

            $childRun = $link->childRun;

            if (! $childRun instanceof WorkflowRun) {
                continue;
            }

            // Only act on non-terminal children.
            if ($childRun->status instanceof RunStatus && $childRun->status->isTerminal()) {
                continue;
            }

            if (is_string($childRun->status) && in_array($childRun->status, ['completed', 'failed', 'cancelled', 'terminated'], true)) {
                continue;
            }

            $childInstanceId = $link->child_workflow_instance_id;

            $reason = sprintf(
                'Parent workflow closed (%s); parent-close policy: %s.',
                $run->closed_reason ?? $run->status->value ?? 'unknown',
                $policy->value,
            );

            try {
                $stub = WorkflowStub::load($childInstanceId);

                match ($policy) {
                    ParentClosePolicy::RequestCancel => $stub->attemptCancel($reason),
                    ParentClosePolicy::Terminate => $stub->attemptTerminate($reason),
                    default => null,
                };

                WorkflowHistoryEvent::record($run, HistoryEventType::ParentClosePolicyApplied, [
                    'child_instance_id' => $childInstanceId,
                    'child_run_id' => $childRun->id,
                    'policy' => $policy->value,
                    'reason' => $reason,
                ]);

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
}
