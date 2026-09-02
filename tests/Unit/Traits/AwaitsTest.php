<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use Mockery;
use Tests\Fixtures\TestWorkflow;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\Signal;
use Workflow\WorkflowStub;

final class AwaitsTest extends TestCase
{
    public function testDefersIfNoResult(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());

        WorkflowStub::await(static fn () => false)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertNull($result);
        $this->assertSame(0, $workflow->logs()->count());
    }

    public function testStoresResult(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());

        WorkflowStub::await(static fn () => true)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertSame(true, $result);
        $this->assertSame(1, $workflow->logs()->count());
        $this->assertDatabaseHas('workflow_logs', [
            'stored_workflow_id' => $workflow->id(),
            'index' => 0,
            'class' => Signal::class,
        ]);
        $this->assertTrue(Serializer::unserialize($workflow->logs()->firstWhere('index', 0)->result));
    }

    public function testLoadsStoredResult(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => WorkflowStub::now(),
                'class' => Signal::class,
                'result' => Serializer::serialize(true),
            ]);

        WorkflowStub::await(static fn () => true)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertSame(true, $result);
        $this->assertSame(1, $workflow->logs()->count());
        $this->assertDatabaseHas('workflow_logs', [
            'stored_workflow_id' => $workflow->id(),
            'index' => 0,
            'class' => Signal::class,
        ]);
        $this->assertTrue(Serializer::unserialize($workflow->logs()->firstWhere('index', 0)->result));
    }

    public function testResolvesConflictingResult(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());

        WorkflowStub::await(static function () use ($workflow) {
            $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
            $storedWorkflow->logs()
                ->create([
                    'index' => 0,
                    'now' => WorkflowStub::now(),
                    'class' => Signal::class,
                    'result' => Serializer::serialize(false),
                ]);
            return true;
        })
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertSame(false, $result);
        $this->assertSame(1, $workflow->logs()->count());
        $this->assertDatabaseHas('workflow_logs', [
            'stored_workflow_id' => $workflow->id(),
            'index' => 0,
            'class' => Signal::class,
        ]);
        $this->assertFalse(Serializer::unserialize($workflow->logs()->firstWhere('index', 0)->result));
    }

    public function testDefersWhenProbing(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $conditionEvaluated = false;
        $result = null;

        WorkflowStub::setContext([
            'storedWorkflow' => $storedWorkflow,
            'index' => 0,
            'now' => now(),
            'replaying' => true,
            'probing' => true,
        ]);

        WorkflowStub::await(static function () use (&$conditionEvaluated): bool {
            $conditionEvaluated = true;

            return true;
        })
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertFalse($conditionEvaluated);
        $this->assertNull($result);
        $this->assertTrue(WorkflowStub::probePendingBeforeMatch());
        $this->assertSame(0, $workflow->logs()->count());
        $this->assertSame(1, WorkflowStub::getContext()->index);
    }

    public function testThrowsQueryExceptionWhenNotDuplicateKey(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());

        $mockStoredWorkflow = Mockery::spy($storedWorkflow);
        $mockStoredWorkflow->shouldReceive('findLogByIndex')
            ->once()
            ->with(0)
            ->andReturn(null);
        $mockStoredWorkflow->shouldReceive('createLog')
            ->once()
            ->andThrow(new \Illuminate\Database\QueryException('', '', [], new \Exception('Some other error')));
        $mockStoredWorkflow->shouldReceive('findLogByIndex')
            ->once()
            ->with(0, true)
            ->andReturn(null);

        WorkflowStub::setContext([
            'storedWorkflow' => $mockStoredWorkflow,
            'index' => 0,
            'now' => now(),
            'replaying' => false,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        WorkflowStub::await(static fn () => true);

        Mockery::close();
    }
}
