<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
use Workflow\V2\Enums\ChildCallStatus;
use Workflow\V2\Enums\ParentClosePolicy;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowRun;

class ChildCallService
{
    /**
     * Schedule a child workflow call.
     *
     * Creates a child call record tracking the parent's request to start a child workflow.
     *
     * @param WorkflowRun $parentRun The parent workflow run
     * @param ChildWorkflowCall $call The child workflow call
     * @param int $sequence The history event sequence that scheduled the child
     * @param string|null $requestedChildId User-specified child instance ID (optional)
     *
     * @return WorkflowChildCall The created child call record
     */
    public function scheduleChild(
        WorkflowRun $parentRun,
        ChildWorkflowCall $call,
        int $sequence,
        ?string $requestedChildId = null,
    ): WorkflowChildCall {
        $options = $call->options ?? new ChildWorkflowOptions();

        $childCall = new WorkflowChildCall([
            'parent_workflow_run_id' => $parentRun->id,
            'parent_workflow_instance_id' => $parentRun->workflow_instance_id,
            'sequence' => $sequence,
            'child_workflow_type' => $call->workflow,
            'child_workflow_class' => $call->workflow, // Will be resolved by TypeRegistry
            'requested_child_id' => $requestedChildId,
            'parent_close_policy' => $options->parentClosePolicy,
            'connection' => $options->connection ?? $parentRun->connection,
            'queue' => $options->queue ?? $parentRun->queue,
            'compatibility' => $parentRun->compatibility,
            'cancellation_propagation' => false, // Future expansion
            'retry_policy' => null, // Future expansion
            'timeout_policy' => null, // Future expansion
            'arguments' => $call->arguments,
            'status' => ChildCallStatus::Scheduled,
            'scheduled_at' => now(),
        ]);

        $childCall->save();

        return $childCall;
    }

    /**
     * Resolve child references after child workflow starts.
     *
     * Updates the child call record with the actual child instance and run IDs.
     *
     * @param WorkflowChildCall $childCall The child call record
     * @param string $childInstanceId The resolved child instance ID
     * @param string $childRunId The resolved child run ID
     */
    public function resolveChildReferences(
        WorkflowChildCall $childCall,
        string $childInstanceId,
        string $childRunId,
    ): void {
        $childCall->resolveReferences($childInstanceId, $childRunId);
    }

    /**
     * Record child completion outcome.
     *
     * @param WorkflowChildCall $childCall The child call record
     * @param string|null $resultPayloadReference Optional result payload reference
     */
    public function recordChildCompleted(
        WorkflowChildCall $childCall,
        ?string $resultPayloadReference = null,
    ): void {
        $childCall->markCompleted($resultPayloadReference);
    }

    /**
     * Record child failure outcome.
     *
     * @param WorkflowChildCall $childCall The child call record
     * @param string|null $failureReference Optional failure reference
     */
    public function recordChildFailed(
        WorkflowChildCall $childCall,
        ?string $failureReference = null,
    ): void {
        $childCall->markFailed($failureReference);
    }

    /**
     * Record child cancellation.
     *
     * @param WorkflowChildCall $childCall The child call record
     */
    public function recordChildCancelled(WorkflowChildCall $childCall): void
    {
        $childCall->markCancelled();
    }

    /**
     * Record child termination.
     *
     * @param WorkflowChildCall $childCall The child call record
     */
    public function recordChildTerminated(WorkflowChildCall $childCall): void
    {
        $childCall->markTerminated();
    }

    /**
     * Record child abandonment (parent closed with abandon policy).
     *
     * @param WorkflowChildCall $childCall The child call record
     */
    public function recordChildAbandoned(WorkflowChildCall $childCall): void
    {
        $childCall->markAbandoned();
    }

    /**
     * Get open children for a parent run.
     *
     * Returns children that are scheduled or started but not yet terminal.
     *
     * @param WorkflowRun $parentRun The parent workflow run
     *
     * @return \Illuminate\Database\Eloquent\Collection<WorkflowChildCall>
     */
    public function getOpenChildren(WorkflowRun $parentRun): \Illuminate\Database\Eloquent\Collection
    {
        return WorkflowChildCall::getOpenChildren($parentRun);
    }

    /**
     * Get all children for a parent run.
     *
     * @param WorkflowRun $parentRun The parent workflow run
     *
     * @return \Illuminate\Database\Eloquent\Collection<WorkflowChildCall>
     */
    public function getAllChildren(WorkflowRun $parentRun): \Illuminate\Database\Eloquent\Collection
    {
        return WorkflowChildCall::getAllChildren($parentRun);
    }

    /**
     * Get child call by sequence.
     *
     * @param WorkflowRun $parentRun The parent workflow run
     * @param int $sequence The history event sequence
     *
     * @return WorkflowChildCall|null The child call or null if not found
     */
    public function getChildBySequence(WorkflowRun $parentRun, int $sequence): ?WorkflowChildCall
    {
        return WorkflowChildCall::getChildBySequence($parentRun, $sequence);
    }

    /**
     * Count open children for a parent run.
     *
     * @param WorkflowRun $parentRun The parent workflow run
     *
     * @return int Open child count
     */
    public function countOpenChildren(WorkflowRun $parentRun): int
    {
        return WorkflowChildCall::countOpenChildren($parentRun);
    }

    /**
     * Enforce parent-close policy on open children.
     *
     * Called when parent workflow closes. Applies each child's parent-close policy.
     *
     * @param WorkflowRun $parentRun The closing parent workflow run
     *
     * @return array{
     *     abandoned: int,
     *     cancel_requested: int,
     *     terminate_requested: int
     * } Counts of actions taken
     */
    public function enforceParentClosePolicy(WorkflowRun $parentRun): array
    {
        $openChildren = $this->getOpenChildren($parentRun);

        $stats = [
            'abandoned' => 0,
            'cancel_requested' => 0,
            'terminate_requested' => 0,
        ];

        foreach ($openChildren as $childCall) {
            match ($childCall->parent_close_policy) {
                ParentClosePolicy::Abandon => $this->handleAbandon($childCall, $stats),
                ParentClosePolicy::RequestCancel => $this->handleRequestCancel($childCall, $stats),
                ParentClosePolicy::Terminate => $this->handleTerminate($childCall, $stats),
            };
        }

        return $stats;
    }

    /**
     * Handle abandon policy: mark child as abandoned, let it continue independently.
     */
    private function handleAbandon(WorkflowChildCall $childCall, array &$stats): void
    {
        $childCall->markAbandoned();
        $stats['abandoned']++;
    }

    /**
     * Handle request_cancel policy: issue cancel command to child.
     *
     * Note: This method marks the intent. Actual cancel command dispatch
     * should be handled by WorkflowExecutor integration.
     */
    private function handleRequestCancel(WorkflowChildCall $childCall, array &$stats): void
    {
        // Mark that cancel was requested
        // The actual cancel command will be issued by the executor
        $childCall->forceFill([
            'metadata' => array_merge($childCall->metadata ?? [], [
                'parent_close_cancel_requested' => true,
                'parent_close_cancel_requested_at' => now()->toIso8601String(),
            ]),
        ])->save();

        $stats['cancel_requested']++;
    }

    /**
     * Handle terminate policy: issue terminate command to child.
     *
     * Note: This method marks the intent. Actual terminate command dispatch
     * should be handled by WorkflowExecutor integration.
     */
    private function handleTerminate(WorkflowChildCall $childCall, array &$stats): void
    {
        // Mark that terminate was requested
        // The actual terminate command will be issued by the executor
        $childCall->forceFill([
            'metadata' => array_merge($childCall->metadata ?? [], [
                'parent_close_terminate_requested' => true,
                'parent_close_terminate_requested_at' => now()->toIso8601String(),
            ]),
        ])->save();

        $stats['terminate_requested']++;
    }

    /**
     * Transfer child call tracking to a continued run (continue-as-new).
     *
     * Updates parent_workflow_run_id for open children when parent continues as new run.
     *
     * @param WorkflowRun $closingRun The run being closed
     * @param WorkflowRun $continuedRun The new run from continue-as-new
     */
    public function transferChildCallsToContinuedRun(
        WorkflowRun $closingRun,
        WorkflowRun $continuedRun,
    ): void {
        DB::transaction(function () use ($closingRun, $continuedRun): void {
            // Transfer open children to new run
            WorkflowChildCall::where('parent_workflow_run_id', $closingRun->id)
                ->whereIn('status', [
                    ChildCallStatus::Scheduled->value,
                    ChildCallStatus::Started->value,
                ])
                ->update([
                    'parent_workflow_run_id' => $continuedRun->id,
                    'updated_at' => now(),
                ]);

            // Terminal children remain with closing run (historical record)
        });
    }

    /**
     * Get children by child instance ID (for lineage tracking across continue-as-new).
     *
     * @param string $childInstanceId The child instance ID
     *
     * @return \Illuminate\Database\Eloquent\Collection<WorkflowChildCall>
     */
    public function getChildrenByInstanceId(string $childInstanceId): \Illuminate\Database\Eloquent\Collection
    {
        return WorkflowChildCall::getChildrenByInstanceId($childInstanceId);
    }

    /**
     * Check if parent has any open children.
     *
     * @param WorkflowRun $parentRun The parent workflow run
     *
     * @return bool True if parent has open children
     */
    public function hasOpenChildren(WorkflowRun $parentRun): bool
    {
        return $this->countOpenChildren($parentRun) > 0;
    }
}
