<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\TestOtherActivity;
use Tests\Fixtures\TestOverlapDelayWorkflow;
use Tests\TestCase;
use Workflow\ChildWorkflow;
use Workflow\Middleware\WithoutOverlappingMiddleware;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\Signal;
use Workflow\States\WorkflowPendingStatus;
use Workflow\WorkflowStub;

final class WorkflowOverlapReleaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('jobs', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public static function releaseDelays(): array
    {
        return [
            'zero' => [0],
            'positive' => [5],
        ];
    }

    public static function otherContendedJobs(): array
    {
        return [
            'child workflow' => ['child', 5],
            'child result' => ['result', 7],
            'activity' => ['activity', 5],
            'signal' => ['signal', 5],
        ];
    }

    #[DataProvider('otherContendedJobs')]
    public function testRelatedJobsUseTheSelectedDelayWithoutChangingTheLockOwner(string $kind, int $delay): void
    {
        Carbon::setTestNow(now()->startOfSecond());
        config([
            'queue.default' => 'database',
        ]);
        $stored = $this->storedWorkflowWithDelay(5);
        $parent = $this->storedWorkflowWithDelay(7);
        $stored->parents()
            ->attach($parent, [
                'parent_index' => 0,
                'parent_now' => now(),
            ]);
        $job = match ($kind) {
            'child' => new TestOverlapDelayWorkflow($stored),
            'result' => new ChildWorkflow(0, now()->toDateTimeString(), $stored, 'result', $parent),
            'activity' => new TestOtherActivity(0, now()->toDateTimeString(), $stored),
            'signal' => new Signal($stored),
        };
        $middleware = $job->middleware()[0];
        $owner = in_array($kind, ['child', 'result'], true) ? $parent->id : $stored->id;
        $this->assertSame((string) $owner, $middleware->key);
        $this->assertSame($delay, $middleware->releaseAfter);
        $this->assertSame(
            $kind === 'signal' ? WithoutOverlappingMiddleware::WORKFLOW : WithoutOverlappingMiddleware::ACTIVITY,
            $middleware->type
        );
        $this->assertSame($kind === 'activity' ? $job->timeout : 15, $middleware->expiresAfter);

        $lock = new WithoutOverlappingMiddleware($owner, WithoutOverlappingMiddleware::WORKFLOW);
        $holder = new \stdClass();
        $this->assertTrue($lock->lock($holder));
        $queue = Queue::connection('database');
        $queue->push($job);
        /** @var Worker $worker */
        $worker = $this->app->make('queue.worker');
        $worker->setCache($this->app->make('cache.store'));

        try {
            $blocked = $queue->pop();
            $this->assertNotNull($blocked);
            $payload = $blocked->getRawBody();
            $worker->process('database', $blocked, new WorkerOptions());
            $this->assertTrue($blocked->isReleased());
            $this->assertFalse($blocked->hasFailed());
            $this->assertTrue($queue->pop() === null);
            $this->assertTrue($lock->unlock($holder));
            Carbon::setTestNow(now()->addSeconds($delay));
            $ready = $queue->pop();
            $this->assertNotNull($ready);
            $this->assertSame(2, $ready->attempts());
            $this->assertSame($payload, $ready->getRawBody());
            $ready->delete();
        } finally {
            Carbon::setTestNow();
        }
    }

    #[DataProvider('releaseDelays')]
    public function testDatabaseQueueContentionPreservesTheWorkflowAndDelaysReadmission(int $delay): void
    {
        Carbon::setTestNow(now()->startOfSecond());
        config([
            'queue.default' => 'database',
        ]);
        $stub = WorkflowStub::make(TestOverlapDelayWorkflow::class);
        $stored = StoredWorkflow::findOrFail($stub->id());
        $stored->update([
            'arguments' => Serializer::serialize([
                'arguments' => [],
                'options' => [
                    'connection' => 'database',
                    'overlapReleaseAfter' => $delay,
                ],
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

    private function storedWorkflowWithDelay(int $delay): StoredWorkflow
    {
        return StoredWorkflow::create([
            'class' => TestOverlapDelayWorkflow::class,
            'arguments' => Serializer::serialize([
                'arguments' => [],
                'options' => [
                    'connection' => 'database',
                    'overlapReleaseAfter' => $delay,
                ],
            ]),
            'status' => WorkflowPendingStatus::class,
        ]);
    }
}
