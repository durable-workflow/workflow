<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSearchAttribute;

/**
 * Service for upserting typed search attributes.
 *
 * Handles conversion from UpsertSearchAttributesCall to typed
 * WorkflowSearchAttribute records with proper validation.
 *
 * Phase 1 implementation replacing JSON blob storage.
 */
final class SearchAttributeUpsertService
{
    /**
     * Upsert search attributes for a workflow run.
     *
     * @param WorkflowRun $run The workflow run
     * @param UpsertSearchAttributesCall $call The upsert command
     * @param int $sequence History sequence when this upsert occurred
     * @param bool $inheritedFromParent Whether these are inherited via continue-as-new
     *
     * @throws InvalidArgumentException If validation fails
     */
    public function upsert(
        WorkflowRun $run,
        UpsertSearchAttributesCall $call,
        int $sequence,
        bool $inheritedFromParent = false,
    ): void {
        DB::transaction(function () use ($run, $call, $sequence, $inheritedFromParent): void {
            foreach ($call->attributes as $key => $value) {
                if ($value === null) {
                    // Null means delete the attribute
                    WorkflowSearchAttribute::where('workflow_run_id', $run->id)
                        ->where('key', $key)
                        ->delete();

                    continue;
                }

                // Upsert or update existing attribute
                $attribute = WorkflowSearchAttribute::firstOrNew([
                    'workflow_run_id' => $run->id,
                    'key' => $key,
                ]);

                $attribute->workflow_instance_id = $run->workflow_instance_id;
                $attribute->upserted_at_sequence = $sequence;
                $attribute->inherited_from_parent = $inheritedFromParent;

                // Set typed value with inference
                $attribute->setTypedValueWithInference($value);

                $attribute->save();
            }

            // Validate limits after all upserts
            WorkflowSearchAttribute::validateCount($run->id);
            WorkflowSearchAttribute::validateTotalSize($run->id);
        });
    }

    /**
     * Copy search attributes from parent run to child (continue-as-new inheritance).
     *
     * @param WorkflowRun $parentRun The parent run
     * @param WorkflowRun $childRun The new run after continue-as-new
     * @param int $childStartSequence History sequence in child run where inheritance occurred
     */
    public function inheritFromParent(
        WorkflowRun $parentRun,
        WorkflowRun $childRun,
        int $childStartSequence,
    ): void {
        DB::transaction(function () use ($parentRun, $childRun, $childStartSequence): void {
            $parentAttributes = WorkflowSearchAttribute::where('workflow_run_id', $parentRun->id)
                ->get();

            foreach ($parentAttributes as $parentAttr) {
                $childAttr = new WorkflowSearchAttribute([
                    'workflow_run_id' => $childRun->id,
                    'workflow_instance_id' => $childRun->workflow_instance_id,
                    'key' => $parentAttr->key,
                    'type' => $parentAttr->type,
                    'value_string' => $parentAttr->value_string,
                    'value_keyword' => $parentAttr->value_keyword,
                    'value_int' => $parentAttr->value_int,
                    'value_float' => $parentAttr->value_float,
                    'value_bool' => $parentAttr->value_bool,
                    'value_datetime' => $parentAttr->value_datetime,
                    'upserted_at_sequence' => $childStartSequence,
                    'inherited_from_parent' => true,
                ]);

                $childAttr->save();
            }

            // Validate limits for child run
            WorkflowSearchAttribute::validateCount($childRun->id);
            WorkflowSearchAttribute::validateTotalSize($childRun->id);
        });
    }

    /**
     * Get all search attributes for a run as key-value array.
     *
     * @param WorkflowRun $run The workflow run
     *
     * @return array<string, mixed> Key-value pairs
     */
    public function getAttributes(WorkflowRun $run): array
    {
        $attributes = WorkflowSearchAttribute::where('workflow_run_id', $run->id)
            ->orderBy('key')
            ->get();

        $result = [];

        foreach ($attributes as $attribute) {
            $result[$attribute->key] = $attribute->getValue();
        }

        return $result;
    }

    /**
     * Get typed attributes with metadata.
     *
     * @param WorkflowRun $run The workflow run
     *
     * @return array<string, array{value: mixed, type: string, inherited: bool}> Typed attributes
     */
    public function getTypedAttributes(WorkflowRun $run): array
    {
        $attributes = WorkflowSearchAttribute::where('workflow_run_id', $run->id)
            ->orderBy('key')
            ->get();

        $result = [];

        foreach ($attributes as $attribute) {
            $result[$attribute->key] = [
                'value' => $attribute->getValue(),
                'type' => $attribute->type,
                'inherited' => $attribute->inherited_from_parent,
            ];
        }

        return $result;
    }

    /**
     * Delete all search attributes for a run.
     *
     * Used during run cleanup or archive.
     *
     * @param string $runId Workflow run ID
     */
    public function deleteAllForRun(string $runId): void
    {
        WorkflowSearchAttribute::where('workflow_run_id', $runId)->delete();
    }
}
