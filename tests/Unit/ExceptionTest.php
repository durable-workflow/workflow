<?php

declare(strict_types=1);

namespace Tests\Unit;

use Exception as BaseException;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\Fixtures\TestActivity;
use Tests\Fixtures\TestProbeBackToBackWorkflow;
use Tests\Fixtures\TestProbeChildFailureWorkflow;
use Tests\Fixtures\TestProbeParallelChildWorkflow;
use Tests\Fixtures\TestProbeRetryActivity;
use Tests\Fixtures\TestSagaActivity;
use Tests\Fixtures\TestSagaParallelActivityWorkflow;
use Tests\Fixtures\TestWorkflow;
use Tests\TestCase;
use Workflow\Exception;
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

        $this->assertSame([], $exception->middleware());
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

    public function testHandleResumesWorkflowWhenLogAlreadyExists(): void
    {
        $lock = Mockery::mock();
        $lock->shouldReceive('get')
            ->once()
            ->andReturn(true);
        $lock->shouldReceive('release')
            ->once();

        Cache::shouldReceive('lock')
            ->once()
            ->with('laravel-workflow-exception:123', 15)
            ->andReturn($lock);

        $workflow = Mockery::mock();
        $workflow->shouldReceive('resume')
            ->once();

        $storedWorkflow = Mockery::mock(StoredWorkflow::class)
            ->makePartial();
        $storedWorkflow->id = 123;
        $storedWorkflow->shouldReceive('effectiveConnection')
            ->andReturn(null);
        $storedWorkflow->shouldReceive('effectiveQueue')
            ->andReturn(null);
        $storedWorkflow->shouldReceive('toWorkflow')
            ->once()
            ->andReturn($workflow);
        $storedWorkflow->shouldReceive('hasLogByIndex')
            ->once()
            ->with(0)
            ->andReturn(true);

        $exception = new Exception(0, now()->toDateTimeString(), $storedWorkflow, new BaseException('existing log'));
        $exception->handle();

        $this->assertSame(123, $storedWorkflow->id);

        Mockery::close();
    }

    public function testHandleReleasesWhenExceptionLockUnavailable(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());

        $lock = Mockery::mock();
        $lock->shouldReceive('get')
            ->once()
            ->andReturn(false);

        Cache::shouldReceive('lock')
            ->once()
            ->with('laravel-workflow-exception:' . $storedWorkflow->id, 15)
            ->andReturn($lock);

        $job = Mockery::mock(JobContract::class);
        $job->shouldReceive('release')
            ->once()
            ->with(0);

        $exception = new Exception(0, now()->toDateTimeString(), $storedWorkflow, [
            'class' => BaseException::class,
            'message' => 'locked',
            'code' => 0,
        ]);
        $exception->setJob($job);
        $exception->handle();

        $this->assertFalse($storedWorkflow->hasLogByIndex(0));

        Mockery::close();
    }

    public function testProbeReplayShortCircuitsWhenWorkflowClassIsInvalid(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->class = '';

        $exception = new Exception(0, now()->toDateTimeString(), $storedWorkflow, [
            'class' => BaseException::class,
            'message' => 'invalid workflow class',
            'code' => 0,
        ]);

        $method = new ReflectionMethod(Exception::class, 'shouldPersistAfterProbeReplay');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($exception));
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

        $log = $storedWorkflow->fresh()
            ->logs()
            ->firstWhere('index', 1);

        $this->assertNotNull($log);
        $this->assertTrue($storedWorkflow->fresh()->hasLogByIndex(1));
        $this->assertSame(TestProbeRetryActivity::class, Serializer::unserialize($log->result)['sourceClass']);
    }

    public function testSkipsWriteWhenProbeReachesDifferentActivityClassAtSameIndex(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestSagaParallelActivityWorkflow::class)->id());
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
                'class' => TestActivity::class,
                'result' => Serializer::serialize('step complete'),
            ]);

        $storedWorkflow->logs()
            ->create([
                'index' => 1,
                'now' => now()
                    ->toDateTimeString(),
                'class' => Exception::class,
                'result' => Serializer::serialize([
                    'class' => RuntimeException::class,
                    'message' => 'parallel failure',
                    'code' => 0,
                ]),
            ]);

        $exception = new Exception(2, now()->toDateTimeString(), $storedWorkflow, [
            'class' => RuntimeException::class,
            'message' => 'another parallel failure',
            'code' => 0,
        ], sourceClass: TestSagaActivity::class);
        $exception->handle();

        $this->assertFalse($storedWorkflow->fresh()->hasLogByIndex(2));
    }
}
