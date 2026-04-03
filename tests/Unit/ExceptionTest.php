<?php

declare(strict_types=1);

namespace Tests\Unit;

use Exception as BaseException;
use InvalidArgumentException;
use RuntimeException;
use Tests\Fixtures\TestProbeBackToBackWorkflow;
use Tests\Fixtures\TestProbeChildFailureWorkflow;
use Tests\Fixtures\TestProbeParallelChildWorkflow;
use Tests\Fixtures\TestProbeRetryActivity;
use Tests\Fixtures\TestWorkflow;
use Tests\TestCase;
use Workflow\Exception;
use Workflow\Middleware\WithoutOverlappingMiddleware;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\States\WorkflowRunningStatus;
use Workflow\WorkflowStub;

final class ExceptionTest extends TestCase
{
    public function testMiddleware(): void
    {
        $exception = new Exception(0, now()->toDateTimeString(), new StoredWorkflow(), new BaseException(
            'Test exception'
        ));

        $middleware = collect($exception->middleware())
            ->values();

        $this->assertCount(1, $middleware);
        $this->assertSame(WithoutOverlappingMiddleware::class, get_class($middleware[0]));
        $this->assertSame(15, $middleware[0]->expiresAfter);
    }

    public function testExceptionWorkflowRunning(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowRunningStatus::$name,
        ]);

        $exception = new Exception(0, now()->toDateTimeString(), $storedWorkflow, new BaseException('Test exception'));
        $exception->handle();

        $this->assertSame(WorkflowRunningStatus::class, $workflow->status());
    }

    public function testSkipsWriteWhenProbeDoesNotReachCandidateException(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestProbeParallelChildWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowRunningStatus::$name,
        ]);

        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => now()
                    ->toDateTimeString(),
                'class' => Exception::class,
                'result' => Serializer::serialize([
                    'class' => BaseException::class,
                    'message' => 'child failed: child-1',
                    'code' => 0,
                ]),
            ]);

        $exception = new Exception(1, now()->toDateTimeString(), $storedWorkflow, [
            'class' => BaseException::class,
            'message' => 'child failed: child-2',
            'code' => 0,
        ], sourceClass: TestProbeChildFailureWorkflow::class);
        $exception->handle();

        $this->assertFalse($storedWorkflow->hasLogByIndex(1));
        $this->assertSame(1, $storedWorkflow->logs()->count());
    }

    public function testPersistsWriteWhenProbeReachesCandidateException(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestProbeBackToBackWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowRunningStatus::$name,
        ]);

        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => now()
                    ->toDateTimeString(),
                'class' => Exception::class,
                'result' => Serializer::serialize([
                    'class' => RuntimeException::class,
                    'message' => 'first failure',
                    'code' => 0,
                ]),
            ]);

        $exception = new Exception(1, now()->toDateTimeString(), $storedWorkflow, [
            'class' => InvalidArgumentException::class,
            'message' => 'second failure',
            'code' => 0,
        ], sourceClass: TestProbeRetryActivity::class);
        $exception->handle();

        $this->assertTrue($storedWorkflow->fresh()->hasLogByIndex(1));
    }
}
