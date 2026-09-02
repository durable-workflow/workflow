<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use Mockery;
use Tests\Fixtures\TestWorkflow;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\Signal;
use Workflow\States\WorkflowPendingStatus;
use Workflow\Timer;
use Workflow\WorkflowStub;

final class AwaitWithTimeoutsTest extends TestCase
{
    public function testDefersIfNoResult(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);

        WorkflowStub::awaitWithTimeout(60, static fn () => false)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertNull($result);
        $this->assertSame(0, $workflow->logs()->count());

        WorkflowStub::awaitWithTimeout('1 minute', static fn () => false)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertNull($result);
        $this->assertSame(0, $workflow->logs()->count());
    }

    public function testStoresResult(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());

        WorkflowStub::awaitWithTimeout('1 minute', static fn () => true)
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

        WorkflowStub::awaitWithTimeout('1 minute', static fn () => true)
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

    public function testLoadsStoredTimerResultAsTimedOut(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => WorkflowStub::now(),
                'class' => Timer::class,
                'result' => Serializer::serialize(true),
            ]);

        WorkflowStub::awaitWithTimeout('1 minute', static fn () => true)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertSame(false, $result);
        $this->assertSame(1, $workflow->logs()->count());
    }

    public function testResolvesConflictingResult(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());

        WorkflowStub::awaitWithTimeout('1 minute', static function () use ($workflow) {
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

        WorkflowStub::awaitWithTimeout('1 minute', static fn () => true);

        Mockery::close();
    }
}
