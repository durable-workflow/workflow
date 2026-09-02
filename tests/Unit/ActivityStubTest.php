<?php

declare(strict_types=1);

namespace Tests\Unit;

use Exception;
use RuntimeException;
use Tests\Fixtures\TestActivity;
use Tests\Fixtures\TestOtherActivity;
use Tests\Fixtures\TestWorkflow;
use Tests\TestCase;
use Workflow\ActivityStub;
use Workflow\Exception as WorkflowException;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\States\WorkflowPendingStatus;
use Workflow\WorkflowStub;

final class ActivityStubTest extends TestCase
{
    public function testStoresResult(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);

        ActivityStub::make(TestActivity::class)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertNull($result);
        $this->assertSame(WorkflowPendingStatus::class, $workflow->status());
        $this->assertNull($workflow->output());
        $this->assertSame(1, $workflow->logs()->count());
        $this->assertDatabaseHas('workflow_logs', [
            'stored_workflow_id' => $workflow->id(),
            'index' => 0,
            'class' => TestActivity::class,
        ]);
        $this->assertSame('activity', Serializer::unserialize($workflow->logs()->firstWhere('index', 0)->result));
    }

    public function testLoadsStoredResult(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);
        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => WorkflowStub::now(),
                'class' => TestActivity::class,
                'result' => Serializer::serialize('test'),
            ]);

        ActivityStub::make(TestActivity::class)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertSame('test', $result);
    }

    public function testLoadsStoredException(): void
    {
        $this->expectException(Exception::class);

        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);
        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => WorkflowStub::now(),
                'class' => TestActivity::class,
                'result' => Serializer::serialize(new Exception('test')),
            ]);

        ActivityStub::make(TestActivity::class)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertSame('test', $result['message']);
    }

    public function testLoadsStoredExceptionWithNonStandardConstructor(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            '[' . ActivityStubNonStandardConstructorException::class . '] test from context (1)'
        );
        $this->expectExceptionCode(123);

        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);
        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => WorkflowStub::now(),
                'class' => TestActivity::class,
                'result' => Serializer::serialize(
                    new ActivityStubNonStandardConstructorException('context', 'test', ['binding'], 123)
                ),
            ]);

        ActivityStub::make(TestActivity::class)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });
    }

    public function testSkipsStoredExceptionForDifferentSourceClass(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);
        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => WorkflowStub::now(),
                'class' => WorkflowException::class,
                'result' => Serializer::serialize([
                    'class' => Exception::class,
                    'message' => 'foreign',
                    'code' => 0,
                    'sourceClass' => TestOtherActivity::class,
                ]),
            ]);
        $storedWorkflow->logs()
            ->create([
                'index' => 1,
                'now' => WorkflowStub::now(),
                'class' => TestActivity::class,
                'result' => Serializer::serialize('test'),
            ]);

        ActivityStub::make(TestActivity::class)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertSame('test', $result);
        $this->assertSame(2, WorkflowStub::getContext()->index);
    }

    public function testSkipsMultipleStoredExceptionsForDifferentSourceClass(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);
        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => WorkflowStub::now(),
                'class' => WorkflowException::class,
                'result' => Serializer::serialize([
                    'class' => Exception::class,
                    'message' => 'foreign-1',
                    'code' => 0,
                    'sourceClass' => TestOtherActivity::class,
                ]),
            ]);
        $storedWorkflow->logs()
            ->create([
                'index' => 1,
                'now' => WorkflowStub::now(),
                'class' => WorkflowException::class,
                'result' => Serializer::serialize([
                    'class' => Exception::class,
                    'message' => 'foreign-2',
                    'code' => 0,
                    'sourceClass' => TestOtherActivity::class,
                ]),
            ]);
        $storedWorkflow->logs()
            ->create([
                'index' => 2,
                'now' => WorkflowStub::now(),
                'class' => TestActivity::class,
                'result' => Serializer::serialize('test'),
            ]);

        ActivityStub::make(TestActivity::class)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertSame('test', $result);
        $this->assertSame(3, WorkflowStub::getContext()->index);
    }

    public function testDoesNotMarkProbeMatchedForForeignStoredException(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $result = null;
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);
        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => WorkflowStub::now(),
                'class' => WorkflowException::class,
                'result' => Serializer::serialize([
                    'class' => Exception::class,
                    'message' => 'foreign',
                    'code' => 0,
                    'sourceClass' => TestOtherActivity::class,
                ]),
            ]);

        WorkflowStub::setContext([
            'storedWorkflow' => $storedWorkflow,
            'index' => 0,
            'now' => now(),
            'replaying' => true,
            'probing' => true,
            'probeIndex' => 0,
            'probeClass' => TestActivity::class,
            'probeMatched' => false,
        ]);

        ActivityStub::make(TestActivity::class)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertNull($result);
        $this->assertTrue(WorkflowStub::probePendingBeforeMatch());
        $this->assertFalse(WorkflowStub::probeMatched());
        $this->assertSame(2, WorkflowStub::getContext()->index);
    }

    public function testAll(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);
        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => WorkflowStub::now(),
                'class' => TestActivity::class,
                'result' => Serializer::serialize('test'),
            ]);

        ActivityStub::all([ActivityStub::make(TestActivity::class)])
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertSame(['test'], $result);
    }

    public function testAsync(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);
        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => WorkflowStub::now(),
                'class' => TestActivity::class,
                'result' => Serializer::serialize('test'),
            ]);

        ActivityStub::async(static function () {
            yield ActivityStub::make(TestActivity::class);
        })
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertSame('test', $result);
    }
}

final class ActivityStubNonStandardConstructorException extends Exception
{
    public function __construct(string $connection, string $message, array $bindings, int $code)
    {
        parent::__construct($message . ' from ' . $connection . ' (' . count($bindings) . ')', $code);
    }
}
