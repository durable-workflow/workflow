<?php

declare(strict_types=1);

namespace Workflow\Tests\Unit\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;
use Workflow\V2\Enums\ChildCallStatus;
use Workflow\V2\Enums\ParentClosePolicy;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ChildCallService;
use Workflow\V2\Support\ChildWorkflowCall;
use Workflow\V2\Support\ChildWorkflowOptions;

/**
 * Phase 1/5 test coverage for workflow child calls system.
 *
 * Validates:
 * - Child call scheduling and lifecycle tracking
 * - Reference resolution (requested → resolved IDs)
 * - Outcome recording (completed, failed, cancelled, terminated, abandoned)
 * - Parent-close policy enforcement
 * - Continue-as-new child transfer
 * - Open children queries
 */
class ChildCallServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChildCallService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChildCallService();
    }

    /** @test */
    public function it_schedules_child_call(): void
    {
        $parentRun = $this->createRun();
        $call = new ChildWorkflowCall('TestChildWorkflow', ['arg1', 'arg2']);

        $childCall = $this->service->scheduleChild($parentRun, $call, 10, 'child-123');

        $this->assertNotNull($childCall->id);
        $this->assertEquals($parentRun->id, $childCall->parent_workflow_run_id);
        $this->assertEquals($parentRun->workflow_instance_id, $childCall->parent_workflow_instance_id);
        $this->assertEquals(10, $childCall->sequence);
        $this->assertEquals('TestChildWorkflow', $childCall->child_workflow_type);
        $this->assertEquals('child-123', $childCall->requested_child_id);
        $this->assertEquals(ChildCallStatus::Scheduled, $childCall->status);
        $this->assertEquals(['arg1', 'arg2'], $childCall->arguments);
        $this->assertNotNull($childCall->scheduled_at);
        $this->assertNull($childCall->started_at);
        $this->assertNull($childCall->resolved_child_instance_id);
    }

    /** @test */
    public function it_schedules_child_with_parent_close_policy(): void
    {
        $parentRun = $this->createRun();
        $options = new ChildWorkflowOptions(parentClosePolicy: ParentClosePolicy::RequestCancel);
        $call = new ChildWorkflowCall('TestChildWorkflow', [], $options);

        $childCall = $this->service->scheduleChild($parentRun, $call, 10);

        $this->assertEquals(ParentClosePolicy::RequestCancel, $childCall->parent_close_policy);
    }

    /** @test */
    public function it_schedules_child_with_routing_overrides(): void
    {
        $parentRun = $this->createRun();
        $options = new ChildWorkflowOptions(
            connection: 'redis',
            queue: 'high-priority',
        );
        $call = new ChildWorkflowCall('TestChildWorkflow', [], $options);

        $childCall = $this->service->scheduleChild($parentRun, $call, 10);

        $this->assertEquals('redis', $childCall->connection);
        $this->assertEquals('high-priority', $childCall->queue);
    }

    /** @test */
    public function it_inherits_routing_from_parent_when_not_overridden(): void
    {
        $parentRun = $this->createRun();
        $parentRun->connection = 'database';
        $parentRun->queue = 'default-queue';
        $parentRun->save();

        $call = new ChildWorkflowCall('TestChildWorkflow', []);

        $childCall = $this->service->scheduleChild($parentRun, $call, 10);

        $this->assertEquals('database', $childCall->connection);
        $this->assertEquals('default-queue', $childCall->queue);
    }

    /** @test */
    public function it_resolves_child_references_after_start(): void
    {
        $parentRun = $this->createRun();
        $call = new ChildWorkflowCall('TestChildWorkflow', []);
        $childCall = $this->service->scheduleChild($parentRun, $call, 10);

        $this->assertEquals(ChildCallStatus::Scheduled, $childCall->status);
        $this->assertNull($childCall->resolved_child_instance_id);
        $this->assertNull($childCall->started_at);

        // Resolve references
        $this->service->resolveChildReferences($childCall, 'child-inst-456', 'child-run-789');

        $childCall->refresh();
        $this->assertEquals(ChildCallStatus::Started, $childCall->status);
        $this->assertEquals('child-inst-456', $childCall->resolved_child_instance_id);
        $this->assertEquals('child-run-789', $childCall->resolved_child_run_id);
        $this->assertNotNull($childCall->started_at);
        $this->assertFalse($childCall->isTerminal());
        $this->assertTrue($childCall->isOpen());
        $this->assertTrue($childCall->isResolved());
    }

    /** @test */
    public function it_records_child_completion(): void
    {
        $parentRun = $this->createRun();
        $call = new ChildWorkflowCall('TestChildWorkflow', []);
        $childCall = $this->service->scheduleChild($parentRun, $call, 10);
        $this->service->resolveChildReferences($childCall, 'inst-1', 'run-1');

        $this->service->recordChildCompleted($childCall, 'result_payload_ref_123');

        $childCall->refresh();
        $this->assertEquals(ChildCallStatus::Completed, $childCall->status);
        $this->assertEquals('completed', $childCall->closed_reason);
        $this->assertEquals('result_payload_ref_123', $childCall->result_payload_reference);
        $this->assertNotNull($childCall->closed_at);
        $this->assertTrue($childCall->isTerminal());
        $this->assertFalse($childCall->isOpen());
    }

    /** @test */
    public function it_records_child_failure(): void
    {
        $parentRun = $this->createRun();
        $call = new ChildWorkflowCall('TestChildWorkflow', []);
        $childCall = $this->service->scheduleChild($parentRun, $call, 10);
        $this->service->resolveChildReferences($childCall, 'inst-1', 'run-1');

        $this->service->recordChildFailed($childCall, 'failure_ref_456');

        $childCall->refresh();
        $this->assertEquals(ChildCallStatus::Failed, $childCall->status);
        $this->assertEquals('failed', $childCall->closed_reason);
        $this->assertEquals('failure_ref_456', $childCall->failure_reference);
        $this->assertNotNull($childCall->closed_at);
        $this->assertTrue($childCall->isTerminal());
    }

    /** @test */
    public function it_records_child_cancellation(): void
    {
        $parentRun = $this->createRun();
        $call = new ChildWorkflowCall('TestChildWorkflow', []);
        $childCall = $this->service->scheduleChild($parentRun, $call, 10);
        $this->service->resolveChildReferences($childCall, 'inst-1', 'run-1');

        $this->service->recordChildCancelled($childCall);

        $childCall->refresh();
        $this->assertEquals(ChildCallStatus::Cancelled, $childCall->status);
        $this->assertEquals('cancelled', $childCall->closed_reason);
        $this->assertTrue($childCall->isTerminal());
    }

    /** @test */
    public function it_records_child_termination(): void
    {
        $parentRun = $this->createRun();
        $call = new ChildWorkflowCall('TestChildWorkflow', []);
        $childCall = $this->service->scheduleChild($parentRun, $call, 10);
        $this->service->resolveChildReferences($childCall, 'inst-1', 'run-1');

        $this->service->recordChildTerminated($childCall);

        $childCall->refresh();
        $this->assertEquals(ChildCallStatus::Terminated, $childCall->status);
        $this->assertEquals('terminated', $childCall->closed_reason);
        $this->assertTrue($childCall->isTerminal());
    }

    /** @test */
    public function it_records_child_abandonment(): void
    {
        $parentRun = $this->createRun();
        $call = new ChildWorkflowCall('TestChildWorkflow', []);
        $childCall = $this->service->scheduleChild($parentRun, $call, 10);
        $this->service->resolveChildReferences($childCall, 'inst-1', 'run-1');

        $this->service->recordChildAbandoned($childCall);

        $childCall->refresh();
        $this->assertEquals(ChildCallStatus::Abandoned, $childCall->status);
        $this->assertEquals('abandoned', $childCall->closed_reason);
        $this->assertTrue($childCall->isTerminal());
    }

    /** @test */
    public function it_gets_open_children(): void
    {
        $parentRun = $this->createRun();

        // Create mix of children
        $scheduled = $this->service->scheduleChild(
            $parentRun,
            new ChildWorkflowCall('Child1', []),
            10,
        );

        $started = $this->service->scheduleChild(
            $parentRun,
            new ChildWorkflowCall('Child2', []),
            11,
        );
        $this->service->resolveChildReferences($started, 'inst-2', 'run-2');

        $completed = $this->service->scheduleChild(
            $parentRun,
            new ChildWorkflowCall('Child3', []),
            12,
        );
        $this->service->resolveChildReferences($completed, 'inst-3', 'run-3');
        $this->service->recordChildCompleted($completed);

        // Get open children
        $openChildren = $this->service->getOpenChildren($parentRun);

        $this->assertCount(2, $openChildren);
        $statuses = $openChildren->pluck('status')->map(fn ($s) => $s->value)->toArray();
        $this->assertContains('scheduled', $statuses);
        $this->assertContains('started', $statuses);
        $this->assertNotContains('completed', $statuses);
    }

    /** @test */
    public function it_counts_open_children(): void
    {
        $parentRun = $this->createRun();

        $this->assertEquals(0, $this->service->countOpenChildren($parentRun));
        $this->assertFalse($this->service->hasOpenChildren($parentRun));

        // Add open children
        $this->service->scheduleChild($parentRun, new ChildWorkflowCall('Child1', []), 10);
        $this->service->scheduleChild($parentRun, new ChildWorkflowCall('Child2', []), 11);

        $this->assertEquals(2, $this->service->countOpenChildren($parentRun));
        $this->assertTrue($this->service->hasOpenChildren($parentRun));

        // Add completed child
        $completed = $this->service->scheduleChild($parentRun, new ChildWorkflowCall('Child3', []), 12);
        $this->service->resolveChildReferences($completed, 'inst-3', 'run-3');
        $this->service->recordChildCompleted($completed);

        // Still 2 open
        $this->assertEquals(2, $this->service->countOpenChildren($parentRun));
    }

    /** @test */
    public function it_gets_all_children(): void
    {
        $parentRun = $this->createRun();

        $this->service->scheduleChild($parentRun, new ChildWorkflowCall('Child1', []), 10);
        $this->service->scheduleChild($parentRun, new ChildWorkflowCall('Child2', []), 11);

        $allChildren = $this->service->getAllChildren($parentRun);
        $this->assertCount(2, $allChildren);
    }

    /** @test */
    public function it_gets_child_by_sequence(): void
    {
        $parentRun = $this->createRun();

        $this->service->scheduleChild($parentRun, new ChildWorkflowCall('Child1', []), 10);
        $this->service->scheduleChild($parentRun, new ChildWorkflowCall('Child2', []), 20);

        $childAt20 = $this->service->getChildBySequence($parentRun, 20);
        $this->assertNotNull($childAt20);
        $this->assertEquals(20, $childAt20->sequence);
        $this->assertEquals('Child2', $childAt20->child_workflow_type);

        $childAt999 = $this->service->getChildBySequence($parentRun, 999);
        $this->assertNull($childAt999);
    }

    /** @test */
    public function it_enforces_abandon_parent_close_policy(): void
    {
        $parentRun = $this->createRun();

        // Create child with abandon policy
        $options = new ChildWorkflowOptions(parentClosePolicy: ParentClosePolicy::Abandon);
        $childCall = $this->service->scheduleChild(
            $parentRun,
            new ChildWorkflowCall('Child1', [], $options),
            10,
        );
        $this->service->resolveChildReferences($childCall, 'inst-1', 'run-1');

        // Enforce policy
        $stats = $this->service->enforceParentClosePolicy($parentRun);

        $this->assertEquals(1, $stats['abandoned']);
        $this->assertEquals(0, $stats['cancel_requested']);
        $this->assertEquals(0, $stats['terminate_requested']);

        $childCall->refresh();
        $this->assertEquals(ChildCallStatus::Abandoned, $childCall->status);
    }

    /** @test */
    public function it_enforces_request_cancel_parent_close_policy(): void
    {
        $parentRun = $this->createRun();

        $options = new ChildWorkflowOptions(parentClosePolicy: ParentClosePolicy::RequestCancel);
        $childCall = $this->service->scheduleChild(
            $parentRun,
            new ChildWorkflowCall('Child1', [], $options),
            10,
        );
        $this->service->resolveChildReferences($childCall, 'inst-1', 'run-1');

        $stats = $this->service->enforceParentClosePolicy($parentRun);

        $this->assertEquals(0, $stats['abandoned']);
        $this->assertEquals(1, $stats['cancel_requested']);
        $this->assertEquals(0, $stats['terminate_requested']);

        $childCall->refresh();
        // Status doesn't change immediately (executor will issue cancel command)
        $this->assertEquals(ChildCallStatus::Started, $childCall->status);
        // But metadata records the intent
        $this->assertTrue($childCall->metadata['parent_close_cancel_requested'] ?? false);
    }

    /** @test */
    public function it_enforces_terminate_parent_close_policy(): void
    {
        $parentRun = $this->createRun();

        $options = new ChildWorkflowOptions(parentClosePolicy: ParentClosePolicy::Terminate);
        $childCall = $this->service->scheduleChild(
            $parentRun,
            new ChildWorkflowCall('Child1', [], $options),
            10,
        );
        $this->service->resolveChildReferences($childCall, 'inst-1', 'run-1');

        $stats = $this->service->enforceParentClosePolicy($parentRun);

        $this->assertEquals(0, $stats['abandoned']);
        $this->assertEquals(0, $stats['cancel_requested']);
        $this->assertEquals(1, $stats['terminate_requested']);

        $childCall->refresh();
        $this->assertEquals(ChildCallStatus::Started, $childCall->status);
        $this->assertTrue($childCall->metadata['parent_close_terminate_requested'] ?? false);
    }

    /** @test */
    public function it_enforces_mixed_parent_close_policies(): void
    {
        $parentRun = $this->createRun();

        // Child 1: abandon
        $child1 = $this->service->scheduleChild(
            $parentRun,
            new ChildWorkflowCall('Child1', [], new ChildWorkflowOptions(ParentClosePolicy::Abandon)),
            10,
        );
        $this->service->resolveChildReferences($child1, 'inst-1', 'run-1');

        // Child 2: request cancel
        $child2 = $this->service->scheduleChild(
            $parentRun,
            new ChildWorkflowCall('Child2', [], new ChildWorkflowOptions(ParentClosePolicy::RequestCancel)),
            11,
        );
        $this->service->resolveChildReferences($child2, 'inst-2', 'run-2');

        // Child 3: terminate
        $child3 = $this->service->scheduleChild(
            $parentRun,
            new ChildWorkflowCall('Child3', [], new ChildWorkflowOptions(ParentClosePolicy::Terminate)),
            12,
        );
        $this->service->resolveChildReferences($child3, 'inst-3', 'run-3');

        $stats = $this->service->enforceParentClosePolicy($parentRun);

        $this->assertEquals(1, $stats['abandoned']);
        $this->assertEquals(1, $stats['cancel_requested']);
        $this->assertEquals(1, $stats['terminate_requested']);
    }

    /** @test */
    public function it_only_enforces_policy_on_open_children(): void
    {
        $parentRun = $this->createRun();

        // Open child
        $open = $this->service->scheduleChild(
            $parentRun,
            new ChildWorkflowCall('OpenChild', [], new ChildWorkflowOptions(ParentClosePolicy::Abandon)),
            10,
        );
        $this->service->resolveChildReferences($open, 'inst-1', 'run-1');

        // Completed child
        $completed = $this->service->scheduleChild(
            $parentRun,
            new ChildWorkflowCall('CompletedChild', [], new ChildWorkflowOptions(ParentClosePolicy::Abandon)),
            11,
        );
        $this->service->resolveChildReferences($completed, 'inst-2', 'run-2');
        $this->service->recordChildCompleted($completed);

        $stats = $this->service->enforceParentClosePolicy($parentRun);

        // Only open child affected
        $this->assertEquals(1, $stats['abandoned']);
    }

    /** @test */
    public function it_transfers_child_calls_on_continue_as_new(): void
    {
        $parentInstance = $this->createInstance();
        $closingRun = $this->createRunForInstance($parentInstance);
        $continuedRun = $this->createRunForInstance($parentInstance);

        // Create mix of children on closing run
        $open1 = $this->service->scheduleChild($closingRun, new ChildWorkflowCall('Child1', []), 10);
        $this->service->resolveChildReferences($open1, 'inst-1', 'run-1');

        $open2 = $this->service->scheduleChild($closingRun, new ChildWorkflowCall('Child2', []), 11);
        $this->service->resolveChildReferences($open2, 'inst-2', 'run-2');

        $completed = $this->service->scheduleChild($closingRun, new ChildWorkflowCall('Child3', []), 12);
        $this->service->resolveChildReferences($completed, 'inst-3', 'run-3');
        $this->service->recordChildCompleted($completed);

        // Transfer to continued run
        $this->service->transferChildCallsToContinuedRun($closingRun, $continuedRun);

        // Open children should be transferred
        $continuedChildren = WorkflowChildCall::where('parent_workflow_run_id', $continuedRun->id)->get();
        $this->assertCount(2, $continuedChildren);
        $this->assertTrue($continuedChildren->every(fn ($c) => $c->status->isOpen()));

        // Completed child remains with closing run
        $closingChildren = WorkflowChildCall::where('parent_workflow_run_id', $closingRun->id)->get();
        $this->assertCount(1, $closingChildren);
        $this->assertEquals(ChildCallStatus::Completed, $closingChildren->first()->status);
    }

    /** @test */
    public function it_gets_children_by_instance_id_for_lineage_tracking(): void
    {
        $parentRun1 = $this->createRun();
        $parentRun2 = $this->createRun();

        $childInstanceId = 'child-inst-xyz';

        // Parent 1 spawns child
        $child1 = $this->service->scheduleChild($parentRun1, new ChildWorkflowCall('Child', []), 10);
        $this->service->resolveChildReferences($child1, $childInstanceId, 'run-1');

        // Parent 2 also spawns to same child instance (or child continues-as-new)
        $child2 = $this->service->scheduleChild($parentRun2, new ChildWorkflowCall('Child', []), 15);
        $this->service->resolveChildReferences($child2, $childInstanceId, 'run-2');

        $childrenOfInstance = $this->service->getChildrenByInstanceId($childInstanceId);

        $this->assertCount(2, $childrenOfInstance);
        $this->assertTrue($childrenOfInstance->every(fn ($c) => $c->resolved_child_instance_id === $childInstanceId));
    }

    private function createInstance(): WorkflowInstance
    {
        return WorkflowInstance::create([
            'id' => 'inst-' . uniqid(),
            'workflow_type' => 'TestWorkflow',
            'workflow_class' => 'Tests\\TestWorkflow',
        ]);
    }

    private function createRun(?WorkflowInstance $instance = null): WorkflowRun
    {
        $instance = $instance ?? $this->createInstance();

        return WorkflowRun::create([
            'id' => 'run-' . uniqid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'Tests\\TestWorkflow',
            'workflow_type' => 'TestWorkflow',
            'status' => 'running',
            'connection' => 'sync',
            'queue' => 'default',
        ]);
    }

    private function createRunForInstance(WorkflowInstance $instance): WorkflowRun
    {
        static $runNumber = 1;

        return WorkflowRun::create([
            'id' => 'run-' . uniqid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => $runNumber++,
            'workflow_class' => 'Tests\\TestWorkflow',
            'workflow_type' => 'TestWorkflow',
            'status' => 'running',
            'connection' => 'sync',
            'queue' => 'default',
        ]);
    }
}
