<?php

declare(strict_types=1);

namespace Tests\Unit;

use Mockery;
use Tests\Fixtures\TestChildWorkflow;
use Tests\Fixtures\TestParentWorkflow;
use Tests\TestCase;
use Workflow\ChildWorkflowStub;
use Workflow\Exceptions\TransitionNotFound;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\States\WorkflowPendingStatus;
use Workflow\WorkflowOptions;
use Workflow\WorkflowStub;

final class ChildWorkflowStubTest extends TestCase
{
    public function testStoresResult(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestParentWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);

        ChildWorkflowStub::make(TestChildWorkflow::class)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertNull($result);
        $this->assertSame(WorkflowPendingStatus::class, $workflow->status());
        $this->assertNull($workflow->output());
    }

    public function testLoadsStoredResult(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestParentWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);
        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => WorkflowStub::now(),
                'class' => TestParentWorkflow::class,
                'result' => Serializer::serialize('test'),
            ]);

        ChildWorkflowStub::make(TestChildWorkflow::class)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertSame('test', $result);
    }

    public function testLoadsChildWorkflow(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestParentWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);

        $childWorkflow = WorkflowStub::load(WorkflowStub::make(TestChildWorkflow::class)->id());
        $storedChildWorkflow = StoredWorkflow::findOrFail($childWorkflow->id());
        $storedChildWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);
        $storedChildWorkflow->parents()
            ->attach($storedWorkflow, [
                'parent_index' => 0,
                'parent_now' => now(),
            ]);

        $workflow = $storedWorkflow->toWorkflow();

        $existingChildWorkflow = ChildWorkflowStub::make(TestChildWorkflow::class)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertNull($result);
    }

    public function testIgnoresTransitionNotFoundWhenChildResumeThrows(): void
    {
        $childWorkflow = new class() {
            public function running(): bool
            {
                return true;
            }

            public function created(): bool
            {
                return false;
            }

            public function resume(): void
            {
                throw TransitionNotFound::make('running', 'pending', StoredWorkflow::class);
            }

            public function completed(): bool
            {
                return false;
            }

            public function startAsChild(...$arguments): void
            {
            }
        };

        $storedChildWorkflow = Mockery::mock();
        $storedChildWorkflow->status = new \stdClass();
        $storedChildWorkflow->shouldReceive('toWorkflow')
            ->once()
            ->andReturn($childWorkflow);

        $children = Mockery::mock();
        $children->shouldReceive('wherePivot')
            ->once()
            ->with('parent_index', 0)
            ->andReturnSelf();
        $children->shouldReceive('first')
            ->once()
            ->andReturn($storedChildWorkflow);

        $storedWorkflow = Mockery::mock();
        $storedWorkflow->shouldReceive('findLogByIndex')
            ->once()
            ->with(0)
            ->andReturn(null);
        $storedWorkflow->shouldReceive('children')
            ->once()
            ->andReturn($children);
        $storedWorkflow->shouldReceive('workflowOptions')
            ->once()
            ->andReturn(new WorkflowOptions());

        WorkflowStub::setContext([
            'storedWorkflow' => $storedWorkflow,
            'index' => 0,
            'now' => now(),
            'replaying' => false,
        ]);

        ChildWorkflowStub::make(TestChildWorkflow::class);

        $this->assertSame(1, WorkflowStub::getContext()->index);
    }

    public function testUsesParentContextForInheritedWorkflowOptions(): void
    {
        $childContextStoredWorkflow = Mockery::mock();
        $childContextStoredWorkflow->shouldNotReceive('workflowOptions');

        $parentStoredWorkflow = Mockery::mock();
        $parentStoredWorkflow->shouldReceive('findLogByIndex')
            ->once()
            ->with(0)
            ->andReturn(null);
        $parentStoredWorkflow->shouldReceive('workflowOptions')
            ->once()
            ->andReturn(new WorkflowOptions('sync', 'parent-queue'));

        $childWorkflow = Mockery::mock();
        $childWorkflow->shouldReceive('running')
            ->once()
            ->andReturn(false);
        $childWorkflow->shouldReceive('completed')
            ->once()
            ->andReturn(false);
        $childWorkflow->shouldReceive('startAsChild')
            ->once()
            ->withArgs(
                static function (...$arguments) use ($parentStoredWorkflow): bool {
                    if (count($arguments) !== 4) {
                        return false;
                    }

                    [$parentWorkflow, $index, $_now, $options] = $arguments;

                    return $parentWorkflow === $parentStoredWorkflow
                        && $index === 0
                        && $options instanceof WorkflowOptions
                        && $options->connection === 'sync'
                        && $options->queue === 'parent-queue';
                }
            );

        $storedChildWorkflow = Mockery::mock();
        $storedChildWorkflow->status = new \stdClass();
        $storedChildWorkflow->shouldReceive('toWorkflow')
            ->once()
            ->andReturnUsing(static function () use ($childWorkflow, $childContextStoredWorkflow) {
                WorkflowStub::setContext([
                    'storedWorkflow' => $childContextStoredWorkflow,
                    'index' => 0,
                    'now' => now(),
                    'replaying' => false,
                ]);

                return $childWorkflow;
            });

        $children = Mockery::mock();
        $children->shouldReceive('wherePivot')
            ->once()
            ->with('parent_index', 0)
            ->andReturnSelf();
        $children->shouldReceive('first')
            ->once()
            ->andReturn($storedChildWorkflow);

        $parentStoredWorkflow->shouldReceive('children')
            ->once()
            ->andReturn($children);

        WorkflowStub::setContext([
            'storedWorkflow' => $parentStoredWorkflow,
            'index' => 0,
            'now' => now(),
            'replaying' => false,
        ]);

        ChildWorkflowStub::make(TestChildWorkflow::class);

        $this->assertSame(1, WorkflowStub::getContext()->index);
        $this->assertSame($parentStoredWorkflow, WorkflowStub::getContext()->storedWorkflow);
    }

    public function testDoesNotPassWorkflowOptionsWhenParentOptionsAreUnset(): void
    {
        $parentStoredWorkflow = Mockery::mock();
        $parentStoredWorkflow->shouldReceive('findLogByIndex')
            ->once()
            ->with(0)
            ->andReturn(null);
        $parentStoredWorkflow->shouldReceive('workflowOptions')
            ->once()
            ->andReturn(new WorkflowOptions());

        $childWorkflow = Mockery::mock();
        $childWorkflow->shouldReceive('running')
            ->once()
            ->andReturn(false);
        $childWorkflow->shouldReceive('completed')
            ->once()
            ->andReturn(false);
        $childWorkflow->shouldReceive('startAsChild')
            ->once()
            ->withArgs(
                static function (...$arguments) use ($parentStoredWorkflow): bool {
                    if (count($arguments) !== 3) {
                        return false;
                    }

                    [$parentWorkflow, $index, $_now] = $arguments;

                    return $parentWorkflow === $parentStoredWorkflow
                        && $index === 0;
                }
            );

        $storedChildWorkflow = Mockery::mock();
        $storedChildWorkflow->status = new \stdClass();
        $storedChildWorkflow->shouldReceive('toWorkflow')
            ->once()
            ->andReturn($childWorkflow);

        $children = Mockery::mock();
        $children->shouldReceive('wherePivot')
            ->once()
            ->with('parent_index', 0)
            ->andReturnSelf();
        $children->shouldReceive('first')
            ->once()
            ->andReturn($storedChildWorkflow);

        $parentStoredWorkflow->shouldReceive('children')
            ->once()
            ->andReturn($children);

        WorkflowStub::setContext([
            'storedWorkflow' => $parentStoredWorkflow,
            'index' => 0,
            'now' => now(),
            'replaying' => false,
        ]);

        ChildWorkflowStub::make(TestChildWorkflow::class);

        $this->assertSame(1, WorkflowStub::getContext()->index);
        $this->assertSame($parentStoredWorkflow, WorkflowStub::getContext()->storedWorkflow);
    }

    public function testAll(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestParentWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);
        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => WorkflowStub::now(),
                'class' => TestParentWorkflow::class,
                'result' => Serializer::serialize('test'),
            ]);

        ChildWorkflowStub::all([ChildWorkflowStub::make(TestChildWorkflow::class)])
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertSame(['test'], $result);
    }

    public function testThrowsExceptionWhenLogContainsException(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestParentWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);

        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => WorkflowStub::now(),
                'class' => \Workflow\Exception::class,
                'result' => Serializer::serialize([
                    'class' => \Exception::class,
                    'message' => 'child failed',
                    'code' => 0,
                ]),
            ]);

        WorkflowStub::setContext([
            'storedWorkflow' => $storedWorkflow,
            'index' => 0,
            'now' => now(),
            'replaying' => false,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('child failed');

        ChildWorkflowStub::make(TestChildWorkflow::class);
    }

    public function testThrowsRuntimeExceptionWhenExceptionClassCannotBeInstantiated(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestParentWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);

        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => WorkflowStub::now(),
                'class' => \Workflow\Exception::class,
                'result' => Serializer::serialize([
                    'class' => \Tests\Fixtures\TestRequiredArgException::class,
                    'message' => 'bad',
                    'code' => 0,
                ]),
            ]);

        WorkflowStub::setContext([
            'storedWorkflow' => $storedWorkflow,
            'index' => 0,
            'now' => now(),
            'replaying' => false,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('[Tests\Fixtures\TestRequiredArgException] bad');

        ChildWorkflowStub::make(TestChildWorkflow::class);
    }
}
