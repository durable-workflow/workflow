<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowRun;

class MemoUpsertService
{
    /**
     * Upsert memos for a workflow run.
     *
     * @param WorkflowRun $run The workflow run
     * @param UpsertMemosCall $call The upsert call containing memo key-value pairs
     * @param int $sequence The history sequence number
     * @param bool $inheritedFromParent Whether these memos are inherited from parent via continue-as-new
     */
    public function upsert(
        WorkflowRun $run,
        UpsertMemosCall $call,
        int $sequence,
        bool $inheritedFromParent = false,
    ): void {
        DB::transaction(function () use ($run, $call, $sequence, $inheritedFromParent): void {
            foreach ($call->memos as $key => $value) {
                if ($value === null) {
                    // Null value means delete
                    WorkflowMemo::where('workflow_run_id', $run->id)
                        ->where('key', $key)
                        ->delete();

                    continue;
                }

                // Upsert (create or update)
                $memo = WorkflowMemo::firstOrNew([
                    'workflow_run_id' => $run->id,
                    'key' => $key,
                ]);

                $memo->workflow_instance_id = $run->workflow_instance_id;
                $memo->setValue($value); // Validates per-memo size
                $memo->upserted_at_sequence = $sequence;
                $memo->inherited_from_parent = $inheritedFromParent;

                $memo->save();
            }

            // Validate total size and count after all upserts
            WorkflowMemo::validateTotalSize($run->id);
            WorkflowMemo::validateCount($run->id);
        });
    }

    /**
     * Inherit memos from parent run to child run (continue-as-new).
     *
     * All parent memos are copied to the child with inherited_from_parent = true.
     *
     * @param WorkflowRun $parentRun The parent workflow run
     * @param WorkflowRun $childRun The child workflow run
     * @param int $childStartSequence The history sequence in the child's history
     */
    public function inheritFromParent(
        WorkflowRun $parentRun,
        WorkflowRun $childRun,
        int $childStartSequence,
    ): void {
        $parentMemos = WorkflowMemo::where('workflow_run_id', $parentRun->id)->get();

        DB::transaction(function () use ($parentMemos, $childRun, $childStartSequence): void {
            foreach ($parentMemos as $parentMemo) {
                $childMemo = new WorkflowMemo([
                    'workflow_run_id' => $childRun->id,
                    'workflow_instance_id' => $childRun->workflow_instance_id,
                    'key' => $parentMemo->key,
                    'value' => $parentMemo->value,
                    'upserted_at_sequence' => $childStartSequence,
                    'inherited_from_parent' => true,
                ]);

                $childMemo->save();
            }

            // Validate after inheritance
            if ($parentMemos->isNotEmpty()) {
                WorkflowMemo::validateTotalSize($childRun->id);
                WorkflowMemo::validateCount($childRun->id);
            }
        });
    }

    /**
     * Get memos as a key-value array.
     *
     * @param WorkflowRun $run The workflow run
     *
     * @return array<string, mixed> Key-value pairs
     */
    public function getMemos(WorkflowRun $run): array
    {
        return WorkflowMemo::where('workflow_run_id', $run->id)
            ->get()
            ->mapWithKeys(fn (WorkflowMemo $memo): array => [$memo->key => $memo->getValue()])
            ->toArray();
    }

    /**
     * Get memos with metadata (value, inherited flag, sequence).
     *
     * @param WorkflowRun $run The workflow run
     *
     * @return array<string, array{value: mixed, inherited: bool, sequence: int}>
     */
    public function getMemosWithMetadata(WorkflowRun $run): array
    {
        return WorkflowMemo::where('workflow_run_id', $run->id)
            ->get()
            ->mapWithKeys(fn (WorkflowMemo $memo): array => [
                $memo->key => [
                    'value' => $memo->getValue(),
                    'inherited' => $memo->inherited_from_parent,
                    'sequence' => $memo->upserted_at_sequence,
                ],
            ])
            ->toArray();
    }

    /**
     * Delete all memos for a run (cleanup).
     *
     * @param string $runId The workflow run ID
     */
    public function deleteAllForRun(string $runId): void
    {
        WorkflowMemo::where('workflow_run_id', $runId)->delete();
    }
}
