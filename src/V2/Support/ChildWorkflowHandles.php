<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\ChildWorkflowHandle;
use Workflow\V2\Models\WorkflowRun;

final class ChildWorkflowHandles
{
    /**
     * @return list<ChildWorkflowHandle>
     */
    public static function forRun(
        WorkflowRun $run,
        int $visibleSequence,
        bool $commandDispatchEnabled,
    ): array {
        if ($visibleSequence <= 1) {
            return [];
        }

        return collect(RunLineageView::continuedWorkflowsForRun($run))
            ->filter(
                static fn (array $entry): bool => ($entry['link_type'] ?? null) === 'child_workflow'
                    && is_int($entry['sequence'] ?? null)
                    && $entry['sequence'] < $visibleSequence
                    && is_string($entry['workflow_instance_id'] ?? null)
            )
            ->map(
                static fn (array $entry): ChildWorkflowHandle => new ChildWorkflowHandle(
                    $entry['workflow_instance_id'],
                    is_string($entry['workflow_run_id'] ?? null) ? $entry['workflow_run_id'] : null,
                    is_string($entry['child_call_id'] ?? null) ? $entry['child_call_id'] : null,
                    $commandDispatchEnabled,
                ),
            )
            ->values()
            ->all();
    }
}
