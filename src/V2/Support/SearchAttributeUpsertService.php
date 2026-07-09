<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
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
     * @param array<string, string> $attributeTypes Declared storage types keyed by attribute name
     */
    public function upsert(
        WorkflowRun $run,
        UpsertSearchAttributesCall $call,
        int $sequence,
        bool $inheritedFromParent = false,
        array $attributeTypes = [],
    ): void {
        self::assertDeclaredTypesCompatible($call, $attributeTypes);

        DB::transaction(static function () use ($run, $call, $sequence, $inheritedFromParent, $attributeTypes): void {
            $existingAttributes = WorkflowSearchAttribute::where('workflow_run_id', $run->id)
                ->get()
                ->keyBy('key')
                ->all();
            $mergedAttributes = $existingAttributes;
            $deleteKeys = [];
            $upsertRows = [];
            $timestamp = (new WorkflowSearchAttribute())->freshTimestampString();

            foreach ($call->attributes as $key => $value) {
                if ($value === null) {
                    unset($mergedAttributes[$key]);
                    $deleteKeys[] = $key;

                    continue;
                }

                $attribute = new WorkflowSearchAttribute([
                    'workflow_run_id' => $run->id,
                    'key' => $key,
                ]);

                $attribute->workflow_instance_id = $run->workflow_instance_id;
                $attribute->upserted_at_sequence = $sequence;
                $attribute->inherited_from_parent = $inheritedFromParent;

                $type = $attributeTypes[$key] ?? null;

                if (is_string($type) && in_array($type, WorkflowSearchAttribute::VALID_TYPES, true)) {
                    $attribute->setTypedValue($value, $type);
                } else {
                    $attribute->setTypedValueWithInference($value);
                }

                $mergedAttributes[$key] = $attribute;
                $upsertRows[] = self::upsertRow($attribute, $timestamp);
            }

            self::validateAttributeSet($mergedAttributes);

            if ($deleteKeys !== []) {
                WorkflowSearchAttribute::where('workflow_run_id', $run->id)
                    ->whereIn('key', array_values(array_unique($deleteKeys)))
                    ->delete();
            }

            if ($upsertRows !== []) {
                WorkflowSearchAttribute::query()->upsert(
                    $upsertRows,
                    ['workflow_run_id', 'key'],
                    [
                        'workflow_instance_id',
                        'type',
                        'value_string',
                        'value_keyword',
                        'value_keyword_list',
                        'value_int',
                        'value_float',
                        'value_bool',
                        'value_datetime',
                        'upserted_at_sequence',
                        'inherited_from_parent',
                        'updated_at',
                    ],
                );
            }
        });
    }

    /**
     * @param array<string, WorkflowSearchAttribute> $attributes
     *
     * @throws \InvalidArgumentException
     */
    private static function validateAttributeSet(array $attributes): void
    {
        $count = count($attributes);

        if ($count > WorkflowSearchAttribute::MAX_ATTRIBUTES_PER_RUN) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Search attributes count exceeds maximum (%d > %d)',
                    $count,
                    WorkflowSearchAttribute::MAX_ATTRIBUTES_PER_RUN,
                ),
            );
        }

        $totalBytes = 0;

        foreach ($attributes as $attribute) {
            $value = $attribute->getValue();

            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                $totalBytes += mb_strlen($value, '8bit');

                continue;
            }

            $totalBytes += match ($attribute->type) {
                WorkflowSearchAttribute::TYPE_INT, WorkflowSearchAttribute::TYPE_FLOAT => 8,
                WorkflowSearchAttribute::TYPE_BOOL => 1,
                WorkflowSearchAttribute::TYPE_DATETIME => 8,
                WorkflowSearchAttribute::TYPE_KEYWORD_LIST => array_sum(array_map(
                    static fn (mixed $entry): int => is_string($entry) ? mb_strlen($entry, '8bit') : 0,
                    is_array($value) ? $value : [],
                )),
                default => 0,
            };
        }

        if ($totalBytes > WorkflowSearchAttribute::MAX_TOTAL_SIZE_BYTES) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Total search attributes size exceeds maximum (%d > %d bytes)',
                    $totalBytes,
                    WorkflowSearchAttribute::MAX_TOTAL_SIZE_BYTES,
                ),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function upsertRow(WorkflowSearchAttribute $attribute, string $timestamp): array
    {
        return array_merge([
            'workflow_run_id' => $attribute->workflow_run_id,
            'workflow_instance_id' => $attribute->workflow_instance_id,
            'key' => $attribute->key,
            'type' => $attribute->type,
            'value_string' => null,
            'value_keyword' => null,
            'value_keyword_list' => null,
            'value_int' => null,
            'value_float' => null,
            'value_bool' => null,
            'value_datetime' => null,
            'upserted_at_sequence' => $attribute->upserted_at_sequence,
            'inherited_from_parent' => $attribute->inherited_from_parent,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ], array_intersect_key(
            $attribute->getAttributes(),
            array_flip([
                'value_string',
                'value_keyword',
                'value_keyword_list',
                'value_int',
                'value_float',
                'value_bool',
                'value_datetime',
            ]),
        ));
    }

    /**
     * Validate that every declared search-attribute type can store the
     * corresponding normalized value before any upsert mutates state.
     *
     * @param array<string, string> $attributeTypes Declared storage types keyed by attribute name
     *
     * @throws \InvalidArgumentException
     */
    public static function assertDeclaredTypesCompatible(
        UpsertSearchAttributesCall $call,
        array $attributeTypes,
    ): void {
        foreach ($call->attributes as $key => $value) {
            if ($value === null) {
                continue;
            }

            $type = $attributeTypes[$key] ?? null;

            if (! is_string($type) || ! in_array($type, WorkflowSearchAttribute::VALID_TYPES, true)) {
                continue;
            }

            try {
                (new WorkflowSearchAttribute())->setTypedValue($value, $type);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(sprintf(
                    'Search attribute [%s] value is not compatible with declared type [%s]: %s',
                    $key,
                    $type,
                    $e->getMessage(),
                ), previous: $e);
            }
        }
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
        DB::transaction(static function () use ($parentRun, $childRun, $childStartSequence): void {
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
                    'value_keyword_list' => $parentAttr->value_keyword_list,
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
