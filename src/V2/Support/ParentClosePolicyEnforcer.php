<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\ParentClosePolicy;
use Workflow\V2\Enums\RunStatus;
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

            try {
                $stub = WorkflowStub::load($childInstanceId);

                $reason = sprintf(
                    'Parent workflow closed (%s); parent-close policy: %s.',
                    $run->closed_reason ?? $run->status->value ?? 'unknown',
                    $policy->value,
                );

                match ($policy) {
                    ParentClosePolicy::RequestCancel => $stub->attemptCancel($reason),
                    ParentClosePolicy::Terminate => $stub->attemptTerminate($reason),
                    default => null,
                };

                $appliedTo[] = $childInstanceId;
            } catch (\Throwable) {
                // Best-effort: if the child cannot be loaded or the command
                // is rejected (already terminal), continue with the remaining
                // children. The child's independent lifecycle is not disrupted
                // by parent-side enforcement failures.
                continue;
            }
        }

        return $appliedTo;
    }
}
