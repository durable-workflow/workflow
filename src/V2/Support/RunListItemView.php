<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowRunSummary;

final class RunListItemView
{
    /**
     * Project a WorkflowRunSummary into a typed list-item contract.
     *
     * This is the authoritative shape for list/fleet views. It selects only the
     * fields an operator list needs, applies badge metadata via the same helpers
     * the detail view uses, and leaves out internal/projection-only columns such
     * as sort_key, projection_schema_version, resume_source_*, and next_task_*.
     *
     * @return array<string, mixed>
     */
    public static function fromSummary(WorkflowRunSummary $summary): array
    {
        $isTerminal = RunStatus::from($summary->status)->isTerminal();

        return [
            'id' => $summary->id,
            'workflow_instance_id' => $summary->workflow_instance_id,
            'instance_id' => $summary->workflow_instance_id,
            'selected_run_id' => $summary->id,
            'run_id' => $summary->id,
            'run_number' => (int) $summary->run_number,
            'is_current_run' => (bool) $summary->is_current_run,
            'engine_source' => $summary->engine_source ?? 'v2',

            // Identity
            'class' => $summary->class,
            'workflow_type' => $summary->workflow_type,
            'namespace' => $summary->namespace,
            'business_key' => $summary->business_key,
            'compatibility' => $summary->compatibility,

            // Status
            'status' => $summary->status,
            'status_bucket' => $summary->status_bucket,
            'is_terminal' => $isTerminal,
            'closed_reason' => $summary->closed_reason,

            // Timestamps
            'started_at' => $summary->started_at?->toIso8601String(),
            'closed_at' => $summary->closed_at?->toIso8601String(),
            'created_at' => $summary->created_at?->toIso8601String(),
            'updated_at' => $summary->updated_at?->toIso8601String(),
            'sort_timestamp' => $summary->sort_timestamp?->toIso8601String(),
            'sort_key' => $summary->sort_key,
            'duration_ms' => $summary->duration_ms !== null ? (int) $summary->duration_ms : null,

            // Archived
            'archived_at' => $summary->archived_at?->toIso8601String(),
            'archive_reason' => $summary->archive_reason,

            // Wait state
            'wait_kind' => $summary->wait_kind,
            'wait_reason' => $summary->wait_reason,
            'liveness_state' => $summary->liveness_state,

            // Operator metadata
            'visibility_labels' => is_array($summary->visibility_labels) ? $summary->visibility_labels : [],
            'search_attributes' => $summary->getTypedSearchAttributes(),

            // Repair & diagnostics
            'repair_attention' => (bool) $summary->repair_attention,
            'repair_blocked_reason' => $summary->repair_blocked_reason,
            'repair_blocked' => RepairBlockedReason::metadata(
                is_string($summary->repair_blocked_reason) ? $summary->repair_blocked_reason : null,
            ),
            'task_problem' => (bool) $summary->task_problem,
            'task_problem_badge' => WorkflowTaskProblem::metadata(
                (bool) $summary->task_problem,
                is_string($summary->liveness_state) ? $summary->liveness_state : null,
                is_string($summary->wait_kind) ? $summary->wait_kind : null,
            ),

            // Command contract
            'declared_entry_mode' => $summary->declared_entry_mode,
            'declared_contract_source' => $summary->declared_contract_source,

            // History budget
            'exception_count' => (int) $summary->exception_count,
            'history_event_count' => (int) $summary->history_event_count,
            'history_size_bytes' => (int) $summary->history_size_bytes,
            'continue_as_new_recommended' => (bool) $summary->continue_as_new_recommended,

            // Queue routing
            'connection' => $summary->connection,
            'queue' => $summary->queue,
        ];
    }

    /**
     * The list-item contract field names, for documentation and test assertions.
     *
     * @return list<string>
     */
    public static function fields(): array
    {
        return [
            'id',
            'workflow_instance_id',
            'instance_id',
            'selected_run_id',
            'run_id',
            'run_number',
            'is_current_run',
            'engine_source',
            'class',
            'workflow_type',
            'namespace',
            'business_key',
            'compatibility',
            'status',
            'status_bucket',
            'is_terminal',
            'closed_reason',
            'started_at',
            'closed_at',
            'created_at',
            'updated_at',
            'sort_timestamp',
            'sort_key',
            'duration_ms',
            'archived_at',
            'archive_reason',
            'wait_kind',
            'wait_reason',
            'liveness_state',
            'visibility_labels',
            'search_attributes',
            'repair_attention',
            'repair_blocked_reason',
            'repair_blocked',
            'task_problem',
            'task_problem_badge',
            'declared_entry_mode',
            'declared_contract_source',
            'exception_count',
            'history_event_count',
            'history_size_bytes',
            'continue_as_new_recommended',
            'connection',
            'queue',
        ];
    }
}
