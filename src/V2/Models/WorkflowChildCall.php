<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workflow\V2\Enums\ChildCallStatus;
use Workflow\V2\Enums\ParentClosePolicy;
use Workflow\V2\Support\ConfiguredV2Models;

class WorkflowChildCall extends Model
{
    public $incrementing = true;

    protected $table = 'workflow_child_calls';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'sequence' => 'integer',
        'status' => ChildCallStatus::class,
        'parent_close_policy' => ParentClosePolicy::class,
        'cancellation_propagation' => 'boolean',
        'retry_policy' => 'array',
        'timeout_policy' => 'array',
        'arguments' => 'array',
        'metadata' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function parentRun(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('run_model', WorkflowRun::class),
            'parent_workflow_run_id',
        );
    }

    public function parentInstance(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('instance_model', WorkflowInstance::class),
            'parent_workflow_instance_id',
        );
    }

    public function childInstance(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('instance_model', WorkflowInstance::class),
            'resolved_child_instance_id',
        );
    }

    public function childRun(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('run_model', WorkflowRun::class),
            'resolved_child_run_id',
        );
    }

    /**
     * Check if child call is open (not terminal).
     */
    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Check if child call is terminal.
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Check if child has been resolved (instance/run IDs known).
     */
    public function isResolved(): bool
    {
        return $this->resolved_child_instance_id !== null;
    }

    /**
     * Resolve child references after child starts.
     */
    public function resolveReferences(string $childInstanceId, string $childRunId): void
    {
        $this->forceFill([
            'resolved_child_instance_id' => $childInstanceId,
            'resolved_child_run_id' => $childRunId,
            'status' => ChildCallStatus::Started,
            'started_at' => now(),
        ])->save();
    }

    /**
     * Mark child as completed.
     */
    public function markCompleted(?string $resultPayloadReference = null): void
    {
        $this->forceFill([
            'status' => ChildCallStatus::Completed,
            'closed_reason' => 'completed',
            'result_payload_reference' => $resultPayloadReference,
            'closed_at' => now(),
        ])->save();
    }

    /**
     * Mark child as failed.
     */
    public function markFailed(?string $failureReference = null): void
    {
        $this->forceFill([
            'status' => ChildCallStatus::Failed,
            'closed_reason' => 'failed',
            'failure_reference' => $failureReference,
            'closed_at' => now(),
        ])->save();
    }

    /**
     * Mark child as cancelled.
     */
    public function markCancelled(): void
    {
        $this->forceFill([
            'status' => ChildCallStatus::Cancelled,
            'closed_reason' => 'cancelled',
            'closed_at' => now(),
        ])->save();
    }

    /**
     * Mark child as terminated.
     */
    public function markTerminated(): void
    {
        $this->forceFill([
            'status' => ChildCallStatus::Terminated,
            'closed_reason' => 'terminated',
            'closed_at' => now(),
        ])->save();
    }

    /**
     * Mark child as abandoned (parent closed with abandon policy).
     */
    public function markAbandoned(): void
    {
        $this->forceFill([
            'status' => ChildCallStatus::Abandoned,
            'closed_reason' => 'abandoned',
            'closed_at' => now(),
        ])->save();
    }

    /**
     * Get open children for a parent run.
     */
    public static function getOpenChildren(WorkflowRun $parentRun): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('parent_workflow_run_id', $parentRun->id)
            ->whereIn('status', [ChildCallStatus::Scheduled->value, ChildCallStatus::Started->value])
            ->orderBy('sequence', 'asc')
            ->get();
    }

    /**
     * Get all children for a parent run.
     */
    public static function getAllChildren(WorkflowRun $parentRun): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('parent_workflow_run_id', $parentRun->id)
            ->orderBy('sequence', 'asc')
            ->get();
    }

    /**
     * Get child by sequence for a parent run.
     */
    public static function getChildBySequence(WorkflowRun $parentRun, int $sequence): ?self
    {
        return static::where('parent_workflow_run_id', $parentRun->id)
            ->where('sequence', $sequence)
            ->first();
    }

    /**
     * Get children by resolved instance ID (for continue-as-new tracking).
     */
    public static function getChildrenByInstanceId(string $childInstanceId): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('resolved_child_instance_id', $childInstanceId)
            ->orderBy('started_at', 'asc')
            ->get();
    }

    /**
     * Count open children for a parent run.
     */
    public static function countOpenChildren(WorkflowRun $parentRun): int
    {
        return static::where('parent_workflow_run_id', $parentRun->id)
            ->whereIn('status', [ChildCallStatus::Scheduled->value, ChildCallStatus::Started->value])
            ->count();
    }
}
