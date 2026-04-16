<?php

declare(strict_types=1);

namespace Workflow\Tests\Unit\V2;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
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
    use RefreshDatabase;

    private SearchAttributeUpsertService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SearchAttributeUpsertService();
    }

    /** @test */
    public function it_infers_and_stores_string_type(): void
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

    /** @test */
    public function it_infers_and_stores_keyword_type_for_short_strings(): void
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

    /** @test */
    public function it_infers_and_stores_int_type(): void
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

    /** @test */
    public function it_infers_and_stores_float_type(): void
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

    /** @test */
    public function it_infers_and_stores_bool_type(): void
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

    /** @test */
    public function it_upserts_existing_attribute(): void
    {
        $run = $this->createRun();

        // First upsert
        $call1 = new UpsertSearchAttributesCall(['status' => 'pending']);
        $this->service->upsert($run, $call1, 1);

        $attr = WorkflowSearchAttribute::where('workflow_run_id', $run->id)
            ->where('key', 'status')
            ->first();

        $this->assertEquals('pending', $attr->value_keyword);
        $this->assertEquals(1, $attr->upserted_at_sequence);

        // Second upsert (update)
        $call2 = new UpsertSearchAttributesCall(['status' => 'running']);
        $this->service->upsert($run, $call2, 5);

        $attr->refresh();

        $this->assertEquals('running', $attr->value_keyword);
        $this->assertEquals(5, $attr->upserted_at_sequence);

        // Should still be only one attribute
        $this->assertEquals(1, WorkflowSearchAttribute::where('workflow_run_id', $run->id)->count());
    }

    /** @test */
    public function it_deletes_attribute_when_null_value(): void
    {
        $run = $this->createRun();

        // Create attribute
        $call1 = new UpsertSearchAttributesCall(['temp_flag' => true]);
        $this->service->upsert($run, $call1, 1);

        $this->assertEquals(1, WorkflowSearchAttribute::where('workflow_run_id', $run->id)->count());

        // Delete by setting to null
        $call2 = new UpsertSearchAttributesCall(['temp_flag' => null]);
        $this->service->upsert($run, $call2, 2);

        $this->assertEquals(0, WorkflowSearchAttribute::where('workflow_run_id', $run->id)->count());
    }

    /** @test */
    public function it_enforces_max_attributes_per_run_limit(): void
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

        $call2 = new UpsertSearchAttributesCall(['one_too_many' => 'fail']);
        $this->service->upsert($run, $call2, 2);
    }

    /** @test */
    public function it_enforces_string_length_limit(): void
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

    /** @test */
    public function it_enforces_keyword_length_limit(): void
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

    /** @test */
    public function it_inherits_attributes_via_continue_as_new(): void
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

    /** @test */
    public function it_can_override_inherited_attributes(): void
    {
        $parentRun = $this->createRun();
        $childRun = $this->createRun();

        // Parent attributes
        $call1 = new UpsertSearchAttributesCall(['status' => 'running']);
        $this->service->upsert($parentRun, $call1, 5);

        // Inherit to child
        $this->service->inheritFromParent($parentRun, $childRun, 1);

        // Child overrides
        $call2 = new UpsertSearchAttributesCall(['status' => 'completed']);
        $this->service->upsert($childRun, $call2, 10);

        $attr = WorkflowSearchAttribute::where('workflow_run_id', $childRun->id)
            ->where('key', 'status')
            ->first();

        $this->assertEquals('completed', $attr->getValue());
        $this->assertFalse($attr->inherited_from_parent); // Override clears inherited flag
        $this->assertEquals(10, $attr->upserted_at_sequence);
    }

    /** @test */
    public function it_retrieves_attributes_as_key_value_array(): void
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

    /** @test */
    public function it_retrieves_typed_attributes_with_metadata(): void
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

    /** @test */
    public function it_supports_efficient_keyword_filtering(): void
    {
        // Create multiple runs with different customer_ids
        $run1 = $this->createRun();
        $run2 = $this->createRun();
        $run3 = $this->createRun();

        $this->service->upsert($run1, new UpsertSearchAttributesCall(['customer_id' => 'cust_a']), 1);
        $this->service->upsert($run2, new UpsertSearchAttributesCall(['customer_id' => 'cust_b']), 1);
        $this->service->upsert($run3, new UpsertSearchAttributesCall(['customer_id' => 'cust_a']), 1);

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

    /** @test */
    public function it_supports_efficient_int_range_queries(): void
    {
        $run1 = $this->createRun();
        $run2 = $this->createRun();
        $run3 = $this->createRun();

        $this->service->upsert($run1, new UpsertSearchAttributesCall(['priority' => 1]), 1);
        $this->service->upsert($run2, new UpsertSearchAttributesCall(['priority' => 5]), 1);
        $this->service->upsert($run3, new UpsertSearchAttributesCall(['priority' => 10]), 1);

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
