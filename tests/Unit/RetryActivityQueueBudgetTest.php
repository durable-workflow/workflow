<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\TestProbeRetryActivity;
use Tests\Fixtures\TestProbeRetryWorkflow;
use Tests\TestCase;
use Throwable;
use Workflow\Middleware\WithoutOverlappingMiddleware;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\WorkflowStub;

final class RetryActivityQueueBudgetTest extends TestCase
{
    public static function activityAttempts(): array
    {
        return [
            'first exception' => [1, RuntimeException::class],
            'second exception' => [2, InvalidArgumentException::class],
            'success' => [3, null],
        ];
    }

    #[DataProvider('activityAttempts')]
    public function testOverlapReleaseDoesNotSpendTheExceptionBudget(int $attempt, ?string $exceptionClass): void
    {
        config([
            'queue.default' => 'database',
        ]);

        $workflow = WorkflowStub::make(TestProbeRetryWorkflow::class);
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $activity = new TestProbeRetryActivity(0, now()->toDateTimeString(), $storedWorkflow, $attempt);
        $activity->onConnection('database')
            ->onQueue('retry-activity-budget');

        $queue = Queue::connection('database');
        $queue->push($activity, '', 'retry-activity-budget');
        /** @var Worker $worker */
        $worker = $this->app->make('queue.worker');
        $worker->setCache($this->app->make('cache.store'));
        $options = new WorkerOptions();
        $middleware = new WithoutOverlappingMiddleware($workflow->id(), WithoutOverlappingMiddleware::WORKFLOW);

        // Force the same overlap release that can race a workflow's dispatch in CI.
        Cache::put($middleware->getWorkflowSemaphoreKey(), 1);
        try {
            $blocked = $queue->pop('retry-activity-budget');
            $this->assertNotNull($blocked);
            $this->assertSame(1, $blocked->attempts());
            $worker->process('database', $blocked, $options);
            $this->assertTrue($blocked->isReleased());
            $this->assertSame(0, $workflow->exceptions()->count());
            $this->assertSame(0, $workflow->logs()->count());
        } finally {
            Cache::forget($middleware->getWorkflowSemaphoreKey());
        }

        $ready = $queue->pop('retry-activity-budget');
        $this->assertNotNull($ready);
        $this->assertSame(2, $ready->attempts());
        $thrown = null;
        try {
            $worker->process('database', $ready, $options);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertFalse($ready->isReleased());
        $this->assertSame(0, $queue->size('retry-activity-budget'));

        if ($exceptionClass !== null) {
            $this->assertInstanceOf($exceptionClass, $thrown);
            $this->assertTrue($ready->hasFailed());
            $this->assertSame(1, $workflow->exceptions()->count());
            $exception = Serializer::unserialize($workflow->exceptions()->firstOrFail()->exception);
            $this->assertSame($exceptionClass, $exception['class']);
        } else {
            $this->assertNull($thrown);
            $this->assertFalse($ready->hasFailed());
            $this->assertSame(0, $workflow->exceptions()->count());
            $log = $workflow->logs()
                ->sole();
            $this->assertSame(TestProbeRetryActivity::class, $log->class);
            $this->assertSame('success', Serializer::unserialize($log->result));
        }
    }
}
