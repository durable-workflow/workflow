<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\TestOverlapDelayWorkflow;
use Tests\TestCase;
use Workflow\Middleware\WithoutOverlappingMiddleware;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\States\WorkflowPendingStatus;
use Workflow\WorkflowStub;

final class WorkflowOverlapReleaseTest extends TestCase
{
    public static function releaseDelays(): array
    {
        return ['zero' => [0], 'positive' => [5]];
    }

    #[DataProvider('releaseDelays')]
    public function testDatabaseQueueContentionPreservesTheWorkflowAndDelaysReadmission(int $delay): void
    {
        Carbon::setTestNow(now()->startOfSecond());
        config(['queue.default' => 'database']);
        $stub = WorkflowStub::make(TestOverlapDelayWorkflow::class);
        $stored = StoredWorkflow::findOrFail($stub->id());
        $stored->update([
            'arguments' => Serializer::serialize([
                'arguments' => [],
                'options' => ['connection' => 'database', 'overlapReleaseAfter' => $delay],
            ]),
            'status' => WorkflowPendingStatus::class,
        ]);
        $workflow = new TestOverlapDelayWorkflow($stored);
        $queue = Queue::connection('database');
        $queue->push($workflow);
        /** @var Worker $worker */
        $worker = $this->app->make('queue.worker');
        $worker->setCache($this->app->make('cache.store'));

        // Two outstanding activity locks reproduce a parallel activity group.
        $lock = new WithoutOverlappingMiddleware($stub->id(), WithoutOverlappingMiddleware::ACTIVITY);
        $firstActivity = new \stdClass();
        $secondActivity = new \stdClass();
        $this->assertTrue($lock->lock($firstActivity));
        $this->assertTrue($lock->lock($secondActivity));

        try {
            $blocked = $queue->pop();
            $this->assertNotNull($blocked);
            $payload = $blocked->getRawBody();
            $this->assertSame(1, $blocked->attempts());
            $worker->process('database', $blocked, new WorkerOptions());
            $this->assertTrue($blocked->isReleased());
            $this->assertFalse($blocked->hasFailed());
            $this->assertSame(0, $stub->exceptions()->count());
            $this->assertFalse($lock->unlock($firstActivity));
            $this->assertTrue($lock->unlock($secondActivity));

            if ($delay > 0) {
                $this->assertTrue($queue->pop() === null, 'A released job must not be immediately available.');
                Carbon::setTestNow(now()->addSeconds($delay - 1));
                $this->assertNull($queue->pop());
                Carbon::setTestNow(now()->addSecond());
            }

            $ready = $queue->pop();
            $this->assertNotNull($ready);
            $this->assertSame(2, $ready->attempts());
            $this->assertSame($payload, $ready->getRawBody());
            $worker->process('database', $ready, new WorkerOptions());
            $this->assertFalse($ready->isReleased());
            $this->assertFalse($ready->hasFailed());
            $this->assertSame(0, $queue->size());
            $this->assertTrue($stub->completed());
            $this->assertSame('completed', $stub->output());
        } finally {
            Carbon::setTestNow();
        }
    }
}
