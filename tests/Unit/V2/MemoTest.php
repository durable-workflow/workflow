<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\MemoUpsertService;
use Workflow\V2\Support\UpsertMemosCall;

/**
 * Phase 1 test coverage for workflow memos.
 *
 * Validates:
 * - JSON value storage
 * - Size and count limits
 * - Upsert semantics (create/update/delete)
 * - Continue-as-new inheritance
 * - Non-filterability (memos are returned-only metadata)
 */
class MemoTest extends TestCase
{
    use RefreshDatabase;

    private MemoUpsertService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MemoUpsertService();
    }

    public function testItStoresStringValues(): void
    {
        $run = $this->createRun();

        $call = new UpsertMemosCall([
            'description' => 'A detailed workflow description',
        ]);

        $this->service->upsert($run, $call, 1);

        $memo = WorkflowMemo::where('workflow_run_id', $run->id)
            ->where('key', 'description')
            ->first();

        $this->assertNotNull($memo);
        $this->assertEquals('A detailed workflow description', $memo->getValue());
        $this->assertEquals(1, $memo->upserted_at_sequence);
        $this->assertFalse($memo->inherited_from_parent);
    }

    public function testItStoresArrayValues(): void
    {
        $run = $this->createRun();

        $call = new UpsertMemosCall([
            'metadata' => [
                'user' => 'alice',
                'tags' => ['urgent', 'customer-facing'],
                'config' => [
                    'retries' => 3,
                    'timeout' => 30,
                ],
            ],
        ]);

        $this->service->upsert($run, $call, 1);

        $memo = WorkflowMemo::where('workflow_run_id', $run->id)
            ->where('key', 'metadata')
            ->first();

        $this->assertNotNull($memo);
        $this->assertEquals([
            'user' => 'alice',
            'tags' => ['urgent', 'customer-facing'],
            'config' => [
                'retries' => 3,
                'timeout' => 30,
            ],
        ], $memo->getValue());
    }

    public function testItStoresNestedJsonStructures(): void
    {
        $run = $this->createRun();

        $complexValue = [
            'workflow' => [
                'name' => 'OrderProcessing',
                'steps' => [
                    [
                        'id' => 1,
                        'name' => 'validate',
                        'status' => 'completed',
                    ],
                    [
                        'id' => 2,
                        'name' => 'charge',
                        'status' => 'in_progress',
                    ],
                    [
                        'id' => 3,
                        'name' => 'fulfill',
                        'status' => 'pending',
                    ],
                ],
                'metadata' => [
                    'customer_id' => 'cust_123',
                    'order_id' => 'order_456',
                ],
            ],
        ];

        $call = new UpsertMemosCall([
            'order_context' => $complexValue,
        ]);

        $this->service->upsert($run, $call, 1);

        $memo = WorkflowMemo::where('workflow_run_id', $run->id)->first();
        $this->assertEquals($complexValue, $memo->getValue());
    }

    public function testItUpsertsExistingMemo(): void
    {
        $run = $this->createRun();

        // First upsert
        $call1 = new UpsertMemosCall([
            'status_text' => 'Processing started',
        ]);
        $this->service->upsert($run, $call1, 1);

        $memo = WorkflowMemo::where('workflow_run_id', $run->id)
            ->where('key', 'status_text')
            ->first();

        $this->assertEquals('Processing started', $memo->getValue());
        $this->assertEquals(1, $memo->upserted_at_sequence);

        // Second upsert (update)
        $call2 = new UpsertMemosCall([
            'status_text' => 'Processing completed',
        ]);
        $this->service->upsert($run, $call2, 5);

        $memo->refresh();

        $this->assertEquals('Processing completed', $memo->getValue());
        $this->assertEquals(5, $memo->upserted_at_sequence);

        // Should still be only one memo
        $this->assertEquals(1, WorkflowMemo::where('workflow_run_id', $run->id)->count());
    }

    public function testItDeletesMemoWhenNullValue(): void
    {
        $run = $this->createRun();

        // Create memo
        $call1 = new UpsertMemosCall([
            'temp_data' => [
                'some' => 'data',
            ],
        ]);
        $this->service->upsert($run, $call1, 1);

        $this->assertEquals(1, WorkflowMemo::where('workflow_run_id', $run->id)->count());

        // Delete by setting to null
        $call2 = new UpsertMemosCall([
            'temp_data' => null,
        ]);
        $this->service->upsert($run, $call2, 2);

        $this->assertEquals(0, WorkflowMemo::where('workflow_run_id', $run->id)->count());
    }

    public function testItEnforcesMaxMemosPerRunLimit(): void
    {
        $run = $this->createRun();

        $memos = [];
        for ($i = 0; $i < WorkflowMemo::MAX_MEMOS_PER_RUN; $i++) {
            $memos["memo_{$i}"] = "value_{$i}";
        }

        $call = new UpsertMemosCall($memos);
        $this->service->upsert($run, $call, 1);

        // This should succeed - exactly at limit
        $this->assertEquals(
            WorkflowMemo::MAX_MEMOS_PER_RUN,
            WorkflowMemo::where('workflow_run_id', $run->id)->count(),
        );

        // One more should fail
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum');

        $call2 = new UpsertMemosCall([
            'one_too_many' => 'fail',
        ]);
        $this->service->upsert($run, $call2, 2);
    }

    public function testItEnforcesPerMemoSizeLimit(): void
    {
        $run = $this->createRun();

        // Create a value that exceeds 10KB when JSON-encoded
        $largeArray = array_fill(0, 2000, 'This is a string that will make the JSON large');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum size');

        $call = new UpsertMemosCall([
            'large_memo' => $largeArray,
        ]);
        $this->service->upsert($run, $call, 1);
    }

    public function testItEnforcesTotalSizeLimit(): void
    {
        $run = $this->createRun();

        // Create multiple memos that collectively exceed 64KB
        $memos = [];
        for ($i = 0; $i < 10; $i++) {
            // Each memo ~8KB, total ~80KB
            $memos["memo_{$i}"] = str_repeat('x', 8000);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum');

        $call = new UpsertMemosCall($memos);
        $this->service->upsert($run, $call, 1);
    }

    public function testItInheritsMemosViaContinueAsNew(): void
    {
        $parentRun = $this->createRun();
        $childRun = $this->createRun();

        // Set up parent memos
        $call = new UpsertMemosCall([
            'user_context' => [
                'user_id' => 'user_123',
                'tenant' => 'acme',
            ],
            'workflow_config' => [
                'max_retries' => 5,
                'timeout' => 3600,
            ],
            'trace_id' => 'trace_abc123',
        ]);
        $this->service->upsert($parentRun, $call, 10);

        // Inherit to child
        $this->service->inheritFromParent($parentRun, $childRun, 1);

        // Check child has all parent memos
        $childMemos = WorkflowMemo::where('workflow_run_id', $childRun->id)
            ->get()
            ->keyBy('key');

        $this->assertCount(3, $childMemos);

        $this->assertEquals(
            [
                'user_id' => 'user_123',
                'tenant' => 'acme',
            ],
            $childMemos['user_context']->getValue(),
        );
        $this->assertTrue($childMemos['user_context']->inherited_from_parent);
        $this->assertEquals(1, $childMemos['user_context']->upserted_at_sequence);

        $this->assertEquals(
            [
                'max_retries' => 5,
                'timeout' => 3600,
            ],
            $childMemos['workflow_config']->getValue(),
        );
        $this->assertTrue($childMemos['workflow_config']->inherited_from_parent);

        $this->assertEquals('trace_abc123', $childMemos['trace_id']->getValue());
        $this->assertTrue($childMemos['trace_id']->inherited_from_parent);
    }

    public function testItCanOverrideInheritedMemos(): void
    {
        $parentRun = $this->createRun();
        $childRun = $this->createRun();

        // Parent memos
        $call1 = new UpsertMemosCall([
            'status' => 'parent_status',
            'config' => [
                'key' => 'parent_value',
            ],
        ]);
        $this->service->upsert($parentRun, $call1, 5);

        // Inherit to child
        $this->service->inheritFromParent($parentRun, $childRun, 1);

        // Child overrides
        $call2 = new UpsertMemosCall([
            'status' => 'child_status',
        ]);
        $this->service->upsert($childRun, $call2, 10);

        $statusMemo = WorkflowMemo::where('workflow_run_id', $childRun->id)
            ->where('key', 'status')
            ->first();

        $this->assertEquals('child_status', $statusMemo->getValue());
        $this->assertFalse($statusMemo->inherited_from_parent); // Override clears inherited flag
        $this->assertEquals(10, $statusMemo->upserted_at_sequence);

        // Config should still be inherited
        $configMemo = WorkflowMemo::where('workflow_run_id', $childRun->id)
            ->where('key', 'config')
            ->first();

        $this->assertTrue($configMemo->inherited_from_parent);
    }

    public function testItRetrievesMemosAsKeyValueArray(): void
    {
        $run = $this->createRun();

        $call = new UpsertMemosCall([
            'string_memo' => 'test value',
            'array_memo' => [
                'key' => 'value',
            ],
            'number_memo' => 42,
        ]);

        $this->service->upsert($run, $call, 1);

        $memos = $this->service->getMemos($run);

        $this->assertIsArray($memos);
        $this->assertCount(3, $memos);
        $this->assertEquals('test value', $memos['string_memo']);
        $this->assertEquals([
            'key' => 'value',
        ], $memos['array_memo']);
        $this->assertEquals(42, $memos['number_memo']);
    }

    public function testItRetrievesMemosWithMetadata(): void
    {
        $run = $this->createRun();

        $call = new UpsertMemosCall([
            'memo1' => 'value1',
            'memo2' => [
                'nested' => 'data',
            ],
        ]);

        $this->service->upsert($run, $call, 5);

        $memosWithMeta = $this->service->getMemosWithMetadata($run);

        $this->assertEquals([
            'memo1' => [
                'value' => 'value1',
                'inherited' => false,
                'sequence' => 5,
            ],
            'memo2' => [
                'value' => [
                    'nested' => 'data',
                ],
                'inherited' => false,
                'sequence' => 5,
            ],
        ], $memosWithMeta);
    }

    public function testMemosAreNotFilterableUnlikeSearchAttributes(): void
    {
        // This test documents the contract: memos have NO value indexes
        // They should NOT be used for filtering in visibility queries

        $run1 = $this->createRun();
        $run2 = $this->createRun();

        $this->service->upsert($run1, new UpsertMemosCall([
            'customer' => 'acme',
        ]), 1);
        $this->service->upsert($run2, new UpsertMemosCall([
            'customer' => 'globex',
        ]), 1);

        // Verify memos exist
        $this->assertEquals(2, WorkflowMemo::count());

        // Document: memos should NOT be used in WHERE clauses for filtering.
        // This is intentional — memos are returned-only metadata; callers
        // wanting to filter must use search attributes instead. Even reading
        // memo contents requires loading rows and inspecting in PHP (no
        // indexes, and no portable JSON-path predicate across MySQL/PG/SQLite).
        $acmeMemos = WorkflowMemo::all()
            ->filter(static fn (WorkflowMemo $memo): bool => $memo->getValue() === 'acme');
        $this->assertCount(1, $acmeMemos);
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
