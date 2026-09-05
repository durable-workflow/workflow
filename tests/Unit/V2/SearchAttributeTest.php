<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Support\SearchAttributeUpsertService;
use Workflow\V2\Support\UpsertSearchAttributesCall;

/**
 * Phase 1 test coverage for typed search attributes.
 *
 * Validates:
 * - Type inference and coercion
 * - Size and count limits
 * - Upsert semantics (create/update/delete)
 * - Continue-as-new inheritance
 * - Query performance (indexed lookups)
 */
class SearchAttributeTest extends TestCase
{
    private SearchAttributeUpsertService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SearchAttributeUpsertService();
    }

    public function testItInfersAndStoresStringType(): void
    {
        $run = $this->createRun();

        $call = new UpsertSearchAttributesCall([
            'description' => 'A long description string',
        ]);

        $this->service->upsert($run, $call, 1);

        $attr = WorkflowSearchAttribute::where('workflow_run_id', $run->id)
            ->where('key', 'description')
            ->first();

        $this->assertNotNull($attr);
        $this->assertEquals(WorkflowSearchAttribute::TYPE_STRING, $attr->type);
        $this->assertEquals('A long description string', $attr->value_string);
        $this->assertEquals('A long description string', $attr->getValue());
    }

    public function testItStoresScalarTextBeyondTheKeywordLimit(): void
    {
        $run = $this->createRun();
        $description = str_repeat("\u{00E9}", WorkflowSearchAttribute::MAX_STRING_LENGTH);

        $this->service->upsert($run, new UpsertSearchAttributesCall([
            'description' => $description,
        ]), 1);

        $attribute = WorkflowSearchAttribute::query()
            ->where('workflow_run_id', $run->id)
            ->where('key', 'description')
            ->firstOrFail();

        $this->assertSame(WorkflowSearchAttribute::TYPE_STRING, $attribute->type);
        $this->assertSame($description, $attribute->value_string);
        $this->assertSame($description, $attribute->getValue());
    }

    public function testItInfersAndStoresKeywordTypeForShortStrings(): void
    {
        $run = $this->createRun();

        $call = new UpsertSearchAttributesCall([
            'customer_id' => 'cust_123',
            'status' => 'completed',
        ]);

        $this->service->upsert($run, $call, 1);

        $attrs = WorkflowSearchAttribute::where('workflow_run_id', $run->id)
            ->get()
            ->keyBy('key');

        $this->assertEquals(WorkflowSearchAttribute::TYPE_KEYWORD, $attrs['customer_id']->type);
        $this->assertEquals('cust_123', $attrs['customer_id']->value_keyword);

        $this->assertEquals(WorkflowSearchAttribute::TYPE_KEYWORD, $attrs['status']->type);
        $this->assertEquals('completed', $attrs['status']->value_keyword);
    }

    public function testItInfersAndStoresKeywordListType(): void
    {
        $run = $this->createRun();

        $call = new UpsertSearchAttributesCall([
            'tags' => ['alpha', 'beta'],
        ]);

        $this->service->upsert($run, $call, 1);

        $attr = WorkflowSearchAttribute::where('workflow_run_id', $run->id)
            ->where('key', 'tags')
            ->first();

        $this->assertNotNull($attr);
        $this->assertEquals(WorkflowSearchAttribute::TYPE_KEYWORD_LIST, $attr->type);
        $this->assertSame(['alpha', 'beta'], $attr->value_keyword_list);
        $this->assertSame(['alpha', 'beta'], $attr->getValue());
    }

    public function testItStoresDeclaredSearchAttributeType(): void
    {
        $run = $this->createRun();

        $call = new UpsertSearchAttributesCall([
            'description' => 'short',
            'score' => 5,
        ]);

        $this->service->upsert($run, $call, 1, attributeTypes: [
            'description' => WorkflowSearchAttribute::TYPE_STRING,
            'score' => WorkflowSearchAttribute::TYPE_FLOAT,
        ]);

        $attrs = WorkflowSearchAttribute::where('workflow_run_id', $run->id)
            ->get()
            ->keyBy('key');

        $this->assertEquals(WorkflowSearchAttribute::TYPE_STRING, $attrs['description']->type);
        $this->assertSame('short', $attrs['description']->value_string);
        $this->assertEquals(WorkflowSearchAttribute::TYPE_FLOAT, $attrs['score']->type);
        $this->assertEqualsWithDelta(5.0, $attrs['score']->value_float, 0.001);
    }

    public function testItBulkUpsertsManyAttributesWithoutPerAttributeReads(): void
    {
        $run = $this->createRun();

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->service->upsert($run, new UpsertSearchAttributesCall([
            'customer_id' => 'cust_123',
            'order_total_cents' => 4250,
            'discount_ratio' => 0.15,
            'priority_tier' => 'gold',
            'is_vip' => true,
            'created_at' => '2026-07-08T12:00:00Z',
            'tags' => ['php', 'mirror'],
        ]), 1, attributeTypes: [
            'customer_id' => WorkflowSearchAttribute::TYPE_KEYWORD,
            'order_total_cents' => WorkflowSearchAttribute::TYPE_INT,
            'discount_ratio' => WorkflowSearchAttribute::TYPE_FLOAT,
            'priority_tier' => WorkflowSearchAttribute::TYPE_KEYWORD,
            'is_vip' => WorkflowSearchAttribute::TYPE_BOOL,
            'created_at' => WorkflowSearchAttribute::TYPE_DATETIME,
            'tags' => WorkflowSearchAttribute::TYPE_KEYWORD_LIST,
        ]);

        $searchAttributeSelects = collect($queries)
            ->map(static fn (string $sql): string => strtolower($sql))
            ->filter(static fn (string $sql): bool => str_starts_with(ltrim($sql), 'select'))
            ->filter(static fn (string $sql): bool => str_contains($sql, 'workflow_search_attributes'))
            ->values();

        $this->assertLessThanOrEqual(
            1,
            $searchAttributeSelects->count(),
            sprintf(
                "Search attribute upsert should not read once per attribute.\nQueries:\n%s",
                $searchAttributeSelects->implode("\n"),
            ),
        );

        $attrs = WorkflowSearchAttribute::where('workflow_run_id', $run->id)
            ->get()
            ->keyBy('key');

        $this->assertCount(7, $attrs);
        $this->assertSame(['php', 'mirror'], $attrs['tags']->getValue());
        $this->assertEqualsWithDelta(0.15, $attrs['discount_ratio']->getValue(), 0.001);
        $this->assertTrue($attrs['is_vip']->getValue());
    }

    public function testItRejectsDeclaredSearchAttributeTypeIncompatibleWithValue(): void
    {
        $run = $this->createRun();

        $call = new UpsertSearchAttributesCall([
            'tags' => 'alpha',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not compatible with declared type [keyword_list]');

        $this->service->upsert($run, $call, 1, attributeTypes: [
            'tags' => WorkflowSearchAttribute::TYPE_KEYWORD_LIST,
        ]);
    }

    public function testItInfersAndStoresIntType(): void
    {
        $run = $this->createRun();

        $call = new UpsertSearchAttributesCall([
            'priority' => 5,
            'retry_count' => 0,
        ]);

        $this->service->upsert($run, $call, 1);

        $attrs = WorkflowSearchAttribute::where('workflow_run_id', $run->id)
            ->get()
            ->keyBy('key');

        $this->assertEquals(WorkflowSearchAttribute::TYPE_INT, $attrs['priority']->type);
        $this->assertEquals(5, $attrs['priority']->value_int);

        $this->assertEquals(WorkflowSearchAttribute::TYPE_INT, $attrs['retry_count']->type);
        $this->assertEquals(0, $attrs['retry_count']->value_int);
    }

    public function testItInfersAndStoresFloatType(): void
    {
        $run = $this->createRun();

        $call = new UpsertSearchAttributesCall([
            'temperature' => 98.6,
            'score' => 3.14159,
        ]);

        $this->service->upsert($run, $call, 1);

        $attrs = WorkflowSearchAttribute::where('workflow_run_id', $run->id)
            ->get()
            ->keyBy('key');

        $this->assertEquals(WorkflowSearchAttribute::TYPE_FLOAT, $attrs['temperature']->type);
        $this->assertEqualsWithDelta(98.6, $attrs['temperature']->value_float, 0.001);

        $this->assertEquals(WorkflowSearchAttribute::TYPE_FLOAT, $attrs['score']->type);
        $this->assertEqualsWithDelta(3.14159, $attrs['score']->value_float, 0.00001);
    }

    public function testItInfersAndStoresBoolType(): void
    {
        $run = $this->createRun();

        $call = new UpsertSearchAttributesCall([
            'is_urgent' => true,
            'is_test' => false,
        ]);

        $this->service->upsert($run, $call, 1);

        $attrs = WorkflowSearchAttribute::where('workflow_run_id', $run->id)
            ->get()
            ->keyBy('key');

        $this->assertEquals(WorkflowSearchAttribute::TYPE_BOOL, $attrs['is_urgent']->type);
        $this->assertTrue($attrs['is_urgent']->value_bool);

        $this->assertEquals(WorkflowSearchAttribute::TYPE_BOOL, $attrs['is_test']->type);
        $this->assertFalse($attrs['is_test']->value_bool);
    }

    public function testItUpsertsExistingAttribute(): void
    {
        $run = $this->createRun();

        // First upsert
        $call1 = new UpsertSearchAttributesCall([
            'status' => 'pending',
        ]);
        $this->service->upsert($run, $call1, 1);

        $attr = WorkflowSearchAttribute::where('workflow_run_id', $run->id)
            ->where('key', 'status')
            ->first();

        $this->assertEquals('pending', $attr->value_keyword);
        $this->assertEquals(1, $attr->upserted_at_sequence);

        // Second upsert (update)
        $call2 = new UpsertSearchAttributesCall([
            'status' => 'running',
        ]);
        $this->service->upsert($run, $call2, 5);

        $attr->refresh();

        $this->assertEquals('running', $attr->value_keyword);
        $this->assertEquals(5, $attr->upserted_at_sequence);

        // Should still be only one attribute
        $this->assertEquals(1, WorkflowSearchAttribute::where('workflow_run_id', $run->id)->count());
    }

    public function testItDeletesAttributeWhenNullValue(): void
    {
        $run = $this->createRun();

        // Create attribute
        $call1 = new UpsertSearchAttributesCall([
            'temp_flag' => true,
        ]);
        $this->service->upsert($run, $call1, 1);

        $this->assertEquals(1, WorkflowSearchAttribute::where('workflow_run_id', $run->id)->count());

        // Delete by setting to null
        $call2 = new UpsertSearchAttributesCall([
            'temp_flag' => null,
        ]);
        $this->service->upsert($run, $call2, 2);

        $this->assertEquals(0, WorkflowSearchAttribute::where('workflow_run_id', $run->id)->count());
    }

    public function testItDeletesEveryAttributeForOnlyTheSelectedRun(): void
    {
        $selectedRun = $this->createRun();
        $otherRun = $this->createRun();

        $this->service->upsert($selectedRun, new UpsertSearchAttributesCall([
            'region' => 'us-east',
            'priority' => 5,
        ]), 1);
        $this->service->upsert($otherRun, new UpsertSearchAttributesCall([
            'region' => 'eu-west',
        ]), 1);

        $this->service->deleteAllForRun($selectedRun->id);

        $this->assertSame(0, WorkflowSearchAttribute::where('workflow_run_id', $selectedRun->id)->count());
        $this->assertSame(1, WorkflowSearchAttribute::where('workflow_run_id', $otherRun->id)->count());
    }

    public function testItExposesTheOwningRunAndInstanceRelations(): void
    {
        $run = $this->createRun();
        $this->service->upsert($run, new UpsertSearchAttributesCall([
            'region' => 'us-east',
        ]), 1);

        $attribute = WorkflowSearchAttribute::where('workflow_run_id', $run->id)->firstOrFail();

        $this->assertTrue($attribute->run->is($run));
        $this->assertTrue($attribute->instance->is($run->instance));
    }

    public function testItEnforcesMaxAttributesPerRunLimit(): void
    {
        $run = $this->createRun();

        $attributes = [];
        for ($i = 0; $i < WorkflowSearchAttribute::MAX_ATTRIBUTES_PER_RUN; $i++) {
            $attributes["attr_{$i}"] = "value_{$i}";
        }

        $call = new UpsertSearchAttributesCall($attributes);
        $this->service->upsert($run, $call, 1);

        // This should succeed - exactly at limit
        $this->assertEquals(
            WorkflowSearchAttribute::MAX_ATTRIBUTES_PER_RUN,
            WorkflowSearchAttribute::where('workflow_run_id', $run->id)->count(),
        );

        // One more should fail
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum');

        $call2 = new UpsertSearchAttributesCall([
            'one_too_many' => 'fail',
        ]);
        $this->service->upsert($run, $call2, 2);
    }

    public function testModelValidationRejectsPersistedAttributeCountsBeyondTheLimit(): void
    {
        $run = $this->createRun();
        $now = now();
        $rows = [];

        for ($i = 0; $i <= WorkflowSearchAttribute::MAX_ATTRIBUTES_PER_RUN; $i++) {
            $rows[] = [
                'workflow_run_id' => $run->id,
                'workflow_instance_id' => $run->workflow_instance_id,
                'key' => "attribute_{$i}",
                'type' => WorkflowSearchAttribute::TYPE_KEYWORD,
                'upserted_at_sequence' => 1,
                'inherited_from_parent' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        WorkflowSearchAttribute::query()->insert($rows);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Search attributes count exceeds maximum (101 > 100)');

        WorkflowSearchAttribute::validateCount($run->id);
    }

    public function testTotalSizeValidationAccountsForEveryStorageType(): void
    {
        $run = $this->createRun();
        $this->service->upsert($run, new UpsertSearchAttributesCall([
            'description' => 'durable workflow',
            'priority' => 5,
            'ratio' => 1.5,
            'active' => true,
            'started_at' => '2026-09-04T12:00:00Z',
            'languages' => ['php', 'python', 'rust'],
        ]), 1, attributeTypes: [
            'started_at' => WorkflowSearchAttribute::TYPE_DATETIME,
        ]);
        WorkflowSearchAttribute::create([
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'key' => 'empty_value',
            'type' => WorkflowSearchAttribute::TYPE_KEYWORD,
            'upserted_at_sequence' => 1,
            'inherited_from_parent' => false,
        ]);

        WorkflowSearchAttribute::validateTotalSize($run->id);

        $this->addToAssertionCount(1);
    }

    public function testModelValidationRejectsPersistedAttributeSetsBeyondTheTotalSizeLimit(): void
    {
        $run = $this->createRun();
        $now = now();
        $rows = [];

        for ($i = 0; $i < 33; $i++) {
            $rows[] = [
                'workflow_run_id' => $run->id,
                'workflow_instance_id' => $run->workflow_instance_id,
                'key' => "description_{$i}",
                'type' => WorkflowSearchAttribute::TYPE_STRING,
                'value_string' => str_repeat('x', WorkflowSearchAttribute::MAX_STRING_LENGTH),
                'upserted_at_sequence' => 1,
                'inherited_from_parent' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        WorkflowSearchAttribute::query()->insert($rows);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Total search attributes size exceeds maximum (67584 > 65536 bytes)');

        WorkflowSearchAttribute::validateTotalSize($run->id);
    }

    public function testUpsertRejectsAnOversizedCombinedAttributeSetAtomically(): void
    {
        $run = $this->createRun();
        $attributes = [];

        for ($i = 0; $i < 33; $i++) {
            $attributes["description_{$i}"] = str_repeat('x', WorkflowSearchAttribute::MAX_STRING_LENGTH);
        }

        try {
            $this->service->upsert($run, new UpsertSearchAttributesCall($attributes), 1);
            $this->fail('The oversized attribute set should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Total search attributes size exceeds maximum (67584 > 65536 bytes)',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, WorkflowSearchAttribute::where('workflow_run_id', $run->id)->count());
    }

    public function testUpsertIgnoresLegacyNullValuesWhenCalculatingTotalSize(): void
    {
        $run = $this->createRun();
        WorkflowSearchAttribute::create([
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'key' => 'empty_value',
            'type' => WorkflowSearchAttribute::TYPE_KEYWORD,
            'upserted_at_sequence' => 1,
            'inherited_from_parent' => false,
        ]);

        $this->service->upsert($run, new UpsertSearchAttributesCall([
            'region' => 'us-east',
        ]), 2);

        $this->assertSame([
            'empty_value' => null,
            'region' => 'us-east',
        ], $this->service->getAttributes($run));
    }

    public function testItEnforcesStringLengthLimit(): void
    {
        $run = $this->createRun();

        $longString = str_repeat('a', WorkflowSearchAttribute::MAX_STRING_LENGTH + 1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum length');

        $attr = new WorkflowSearchAttribute([
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'key' => 'long_text',
        ]);

        $attr->setTypedValue($longString, WorkflowSearchAttribute::TYPE_STRING);
    }

    public function testItEnforcesKeywordLengthLimit(): void
    {
        $run = $this->createRun();

        $longKeyword = str_repeat('a', WorkflowSearchAttribute::MAX_KEYWORD_LENGTH + 1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum length');

        $attr = new WorkflowSearchAttribute([
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'key' => 'long_keyword',
        ]);

        $attr->setTypedValue($longKeyword, WorkflowSearchAttribute::TYPE_KEYWORD);
    }

    public function testItInheritsAttributesViaContinueAsNew(): void
    {
        $parentRun = $this->createRun();
        $childRun = $this->createRun();

        // Set up parent attributes
        $call = new UpsertSearchAttributesCall([
            'customer_id' => 'cust_123',
            'region' => 'us-west',
            'priority' => 5,
        ]);
        $this->service->upsert($parentRun, $call, 10);

        // Inherit to child
        $this->service->inheritFromParent($parentRun, $childRun, 1);

        // Check child has all parent attributes
        $childAttrs = WorkflowSearchAttribute::where('workflow_run_id', $childRun->id)
            ->get()
            ->keyBy('key');

        $this->assertCount(3, $childAttrs);

        $this->assertEquals('cust_123', $childAttrs['customer_id']->getValue());
        $this->assertTrue($childAttrs['customer_id']->inherited_from_parent);
        $this->assertEquals(1, $childAttrs['customer_id']->upserted_at_sequence);

        $this->assertEquals('us-west', $childAttrs['region']->getValue());
        $this->assertTrue($childAttrs['region']->inherited_from_parent);

        $this->assertEquals(5, $childAttrs['priority']->getValue());
        $this->assertTrue($childAttrs['priority']->inherited_from_parent);
    }

    public function testItCanOverrideInheritedAttributes(): void
    {
        $parentRun = $this->createRun();
        $childRun = $this->createRun();

        // Parent attributes
        $call1 = new UpsertSearchAttributesCall([
            'status' => 'running',
        ]);
        $this->service->upsert($parentRun, $call1, 5);

        // Inherit to child
        $this->service->inheritFromParent($parentRun, $childRun, 1);

        // Child overrides
        $call2 = new UpsertSearchAttributesCall([
            'status' => 'completed',
        ]);
        $this->service->upsert($childRun, $call2, 10);

        $attr = WorkflowSearchAttribute::where('workflow_run_id', $childRun->id)
            ->where('key', 'status')
            ->first();

        $this->assertEquals('completed', $attr->getValue());
        $this->assertFalse($attr->inherited_from_parent); // Override clears inherited flag
        $this->assertEquals(10, $attr->upserted_at_sequence);
    }

    public function testItRetrievesAttributesAsKeyValueArray(): void
    {
        $run = $this->createRun();

        $call = new UpsertSearchAttributesCall([
            'customer_id' => 'cust_123',
            'priority' => 5,
            'is_urgent' => true,
            'temperature' => 98.6,
        ]);

        $this->service->upsert($run, $call, 1);

        $attributes = $this->service->getAttributes($run);

        $this->assertIsArray($attributes);
        $this->assertCount(4, $attributes);
        $this->assertEquals('cust_123', $attributes['customer_id']);
        $this->assertEquals(5, $attributes['priority']);
        $this->assertTrue($attributes['is_urgent']);
        $this->assertEqualsWithDelta(98.6, $attributes['temperature'], 0.001);
    }

    public function testItRetrievesTypedAttributesWithMetadata(): void
    {
        $run = $this->createRun();

        $call = new UpsertSearchAttributesCall([
            'customer_id' => 'cust_123',
            'priority' => 5,
        ]);

        $this->service->upsert($run, $call, 1);

        $typed = $this->service->getTypedAttributes($run);

        $this->assertEquals([
            'customer_id' => [
                'value' => 'cust_123',
                'type' => WorkflowSearchAttribute::TYPE_KEYWORD,
                'inherited' => false,
            ],
            'priority' => [
                'value' => 5,
                'type' => WorkflowSearchAttribute::TYPE_INT,
                'inherited' => false,
            ],
        ], $typed);
    }

    public function testItSupportsEfficientKeywordFiltering(): void
    {
        // Create multiple runs with different customer_ids
        $run1 = $this->createRun();
        $run2 = $this->createRun();
        $run3 = $this->createRun();

        $this->service->upsert($run1, new UpsertSearchAttributesCall([
            'customer_id' => 'cust_a',
        ]), 1);
        $this->service->upsert($run2, new UpsertSearchAttributesCall([
            'customer_id' => 'cust_b',
        ]), 1);
        $this->service->upsert($run3, new UpsertSearchAttributesCall([
            'customer_id' => 'cust_a',
        ]), 1);

        // Query by keyword value (should use index)
        $matching = WorkflowSearchAttribute::where('key', 'customer_id')
            ->where('value_keyword', 'cust_a')
            ->pluck('workflow_run_id')
            ->toArray();

        $this->assertCount(2, $matching);
        $this->assertContains($run1->id, $matching);
        $this->assertContains($run3->id, $matching);
        $this->assertNotContains($run2->id, $matching);
    }

    public function testItSupportsEfficientIntRangeQueries(): void
    {
        $run1 = $this->createRun();
        $run2 = $this->createRun();
        $run3 = $this->createRun();

        $this->service->upsert($run1, new UpsertSearchAttributesCall([
            'priority' => 1,
        ]), 1);
        $this->service->upsert($run2, new UpsertSearchAttributesCall([
            'priority' => 5,
        ]), 1);
        $this->service->upsert($run3, new UpsertSearchAttributesCall([
            'priority' => 10,
        ]), 1);

        // Query range (should use index)
        $highPriority = WorkflowSearchAttribute::where('key', 'priority')
            ->where('value_int', '>=', 5)
            ->pluck('workflow_run_id')
            ->toArray();

        $this->assertCount(2, $highPriority);
        $this->assertContains($run2->id, $highPriority);
        $this->assertContains($run3->id, $highPriority);
        $this->assertNotContains($run1->id, $highPriority);
    }

    private function createRun(): WorkflowRun
    {
        $instance = WorkflowInstance::create([
            'id' => 'test-' . uniqid(),
            'workflow_type' => 'TestWorkflow',
            'workflow_class' => 'Tests\\TestWorkflow',
        ]);

        return WorkflowRun::create([
            'id' => 'run-' . uniqid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'Tests\\TestWorkflow',
            'workflow_type' => 'TestWorkflow',
            'status' => 'running',
        ]);
    }
}
