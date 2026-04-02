<?php

declare(strict_types=1);

namespace Tests\Unit;

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
        $exception = new Exception(0, now()->toDateTimeString(), new StoredWorkflow(), new \Exception(
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

        $exception = new Exception(0, now()->toDateTimeString(), $storedWorkflow, new \Exception('Test exception'));
        $exception->handle();

        $this->assertSame(WorkflowRunningStatus::class, $workflow->status());
    }

    public function testSkipsWriteWhenSiblingExceptionLogExists(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
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
                    'class' => \Exception::class,
                    'message' => 'first child failed',
                    'code' => 0,
                ]),
            ]);

        $exception = new Exception(1, now()->toDateTimeString(), $storedWorkflow, [
            'class' => \Exception::class,
            'message' => 'second child failed',
            'code' => 0,
        ]);
        $exception->handle();

        $this->assertFalse($storedWorkflow->hasLogByIndex(1));
        $this->assertSame(1, $storedWorkflow->logs()->count());
    }
}
