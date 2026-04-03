<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use Mockery;
use Tests\Fixtures\TestWorkflow;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\WorkflowStub;

final class SideEffectsTest extends TestCase
{
    public function testStoresResult(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());

        WorkflowStub::sideEffect(static fn () => 'test')
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertSame('test', $result);
        $this->assertSame(1, $workflow->logs()->count());
        $this->assertDatabaseHas('workflow_logs', [
            'stored_workflow_id' => $workflow->id(),
            'index' => 0,
            'class' => TestWorkflow::class,
        ]);
        $this->assertSame('test', Serializer::unserialize($workflow->logs()->firstWhere('index', 0)->result));
    }

    public function testLoadsStoredResult(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => WorkflowStub::now(),
                'class' => TestWorkflow::class,
                'result' => Serializer::serialize('test'),
            ]);

        WorkflowStub::sideEffect(static fn () => '')
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertSame('test', $result);
        $this->assertSame(1, $workflow->logs()->count());
        $this->assertDatabaseHas('workflow_logs', [
            'stored_workflow_id' => $workflow->id(),
            'index' => 0,
            'class' => TestWorkflow::class,
        ]);
        $this->assertSame('test', Serializer::unserialize($workflow->logs()->firstWhere('index', 0)->result));
    }

    public function testResolvesConflictingResult(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());

        WorkflowStub::sideEffect(static function () use ($workflow) {
            $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
            $storedWorkflow->logs()
                ->create([
                    'index' => 0,
                    'now' => WorkflowStub::now(),
                    'class' => TestWorkflow::class,
                    'result' => Serializer::serialize('test'),
                ]);
            return '';
        })
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertSame('test', $result);
        $this->assertSame(1, $workflow->logs()->count());
        $this->assertDatabaseHas('workflow_logs', [
            'stored_workflow_id' => $workflow->id(),
            'index' => 0,
            'class' => TestWorkflow::class,
        ]);
        $this->assertSame('test', Serializer::unserialize($workflow->logs()->firstWhere('index', 0)->result));
    }

    public function testDefersWhenProbing(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $callableEvaluated = false;
        $result = null;

        WorkflowStub::setContext([
            'storedWorkflow' => $storedWorkflow,
            'index' => 0,
            'now' => now(),
            'replaying' => true,
            'probing' => true,
        ]);

        WorkflowStub::sideEffect(static function () use (&$callableEvaluated): string {
            $callableEvaluated = true;

            return 'test';
        })
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertFalse($callableEvaluated);
        $this->assertNull($result);
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

        WorkflowStub::sideEffect(static fn () => 'test');

        Mockery::close();
    }
}
