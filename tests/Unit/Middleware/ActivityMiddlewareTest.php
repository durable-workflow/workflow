<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\Fixtures\TestActivity;
use Tests\Fixtures\TestModelNotFoundWorkflow;
use Tests\Fixtures\TestWorkflow;
use Tests\TestCase;
use Workflow\Events\ActivityCompleted;
use Workflow\Events\ActivityFailed;
use Workflow\Events\ActivityStarted;
use Workflow\Middleware\ActivityMiddleware;
use Workflow\Models\StoredWorkflow;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowFailedStatus;
use Workflow\States\WorkflowRunningStatus;
use Workflow\States\WorkflowWaitingStatus;
use Workflow\WorkflowStub;

final class ActivityMiddlewareTest extends TestCase
{
    public function testMiddleware(): void
    {
        Event::fake();
        Queue::fake();

        Cache::flush();

        $workflow = WorkflowStub::make(TestWorkflow::class);
        $workflow->start();

        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'status' => WorkflowWaitingStatus::class,
        ]);

        // Workflow implements ShouldBeUnique. Queue::fake never runs the job,
        // so the unique lock acquired by start() is still held — next() would
        // silently drop the re-dispatch. Release it explicitly before the
        // activity unlock so the second push actually reaches the fake queue.
        $this->releaseUniqueLock($storedWorkflow);

        $activity = $this->mock(TestActivity::class);
        $activity->index = 0;
        $activity->now = now()
            ->toDateTimeString();
        $activity->storedWorkflow = $storedWorkflow;

        $middleware = new ActivityMiddleware();

        $middleware->handle($activity, static function ($job) {
            return true;
        });

        $this->assertTrue(isset($activity->onUnlock));
        ($activity->onUnlock)(true);

        Event::assertDispatched(ActivityStarted::class);
        Event::assertDispatched(ActivityCompleted::class);
        Queue::assertPushed(TestWorkflow::class, 2);
    }

    private function releaseUniqueLock(StoredWorkflow $storedWorkflow): void
    {
        $job = new TestWorkflow($storedWorkflow);
        (new \Illuminate\Bus\UniqueLock(
            app(\Illuminate\Contracts\Cache\Repository::class)
        ))->release($job);
    }

    public function testAlreadyCompleted(): void
    {
        Event::fake();
        Queue::fake();

        Cache::flush();

        $workflow = WorkflowStub::make(TestWorkflow::class);
        $workflow->start();

        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'status' => WorkflowCompletedStatus::class,
        ]);

        $activity = $this->mock(TestActivity::class);
        $activity->index = 0;
        $activity->now = now()
            ->toDateTimeString();
        $activity->storedWorkflow = $storedWorkflow;

        $middleware = new ActivityMiddleware();

        $middleware->handle($activity, static function ($job) {
            return true;
        });

        $this->assertTrue(isset($activity->onUnlock));
        ($activity->onUnlock)(true);

        Event::assertDispatched(ActivityStarted::class);
        Event::assertNotDispatched(ActivityCompleted::class);
        Event::assertNotDispatched(ActivityFailed::class);
        Queue::assertPushed(TestWorkflow::class, 1);
    }

    public function testAlreadyRunning(): void
    {
        Event::fake();
        Queue::fake();

        Cache::flush();

        $workflow = WorkflowStub::make(TestWorkflow::class);
        $workflow->start();

        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'status' => WorkflowRunningStatus::class,
        ]);

        Cache::flush();

        $activity = $this->mock(TestActivity::class, static function (MockInterface $mock) {
            $mock->shouldReceive('release')
                ->once();
        });
        $activity->index = 0;
        $activity->now = now()
            ->toDateTimeString();
        $activity->storedWorkflow = $storedWorkflow;

        $middleware = new ActivityMiddleware();

        $middleware->handle($activity, static function ($job) {
            return true;
        });

        $this->assertTrue(isset($activity->onUnlock));
        ($activity->onUnlock)(true);

        Event::assertDispatched(ActivityStarted::class);
        Event::assertNotDispatched(ActivityCompleted::class);
        Event::assertNotDispatched(ActivityFailed::class);
        Queue::assertPushed(TestWorkflow::class, 1);
    }

    public function testException(): void
    {
        Event::fake();
        Queue::fake();

        $workflow = WorkflowStub::make(TestWorkflow::class);
        $workflow->start();

        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'status' => WorkflowWaitingStatus::class,
        ]);

        Cache::flush();

        $activity = $this->mock(TestActivity::class);
        $activity->index = 0;
        $activity->now = now()
            ->toDateTimeString();
        $activity->storedWorkflow = $storedWorkflow;

        $middleware = new ActivityMiddleware();

        try {
            $middleware->handle($activity, static function ($job) {
                throw new Exception('test');
            });
        } catch (Exception $exception) {
            $this->assertSame('test', $exception->getMessage());
        }

        Event::assertDispatched(ActivityStarted::class);
        Event::assertDispatched(ActivityFailed::class);
        Queue::assertPushed(TestWorkflow::class, 1);
    }

    public function testModelNotFoundException(): void
    {
        Event::fake();
        Queue::fake();

        $workflow = WorkflowStub::make(TestWorkflow::class);
        $workflow->start();

        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'status' => WorkflowWaitingStatus::class,
        ]);

        Cache::flush();

        $activity = $this->mock(TestActivity::class);
        $activity->index = 0;
        $activity->now = now()
            ->toDateTimeString();
        $activity->storedWorkflow = $storedWorkflow;

        $middleware = new ActivityMiddleware();

        try {
            $middleware->handle($activity, static function ($job) {
                throw new ModelNotFoundException('test');
            });
        } catch (Exception $exception) {
            $this->assertSame('test', $exception->getMessage());
        }

        Event::assertDispatched(ActivityStarted::class);
        Event::assertDispatched(ActivityFailed::class);
        Queue::assertPushed(TestWorkflow::class, 1);

        $this->assertSame(WorkflowWaitingStatus::class, $workflow->status());
    }

    public function testModelNotFoundExceptionInNextMethod(): void
    {
        Event::fake();
        Queue::fake();

        $deletedWorkflow = StoredWorkflow::create([
            'class' => TestWorkflow::class,
        ]);

        $workflow = WorkflowStub::make(TestModelNotFoundWorkflow::class);
        $workflow->start($deletedWorkflow);

        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'status' => WorkflowWaitingStatus::class,
        ]);

        $deletedWorkflow->delete();

        $activity = $this->mock(TestActivity::class);
        $activity->index = 0;
        $activity->now = now()
            ->toDateTimeString();
        $activity->storedWorkflow = $storedWorkflow;

        $middleware = new ActivityMiddleware();

        $middleware->handle($activity, static function ($job) {
            return true;
        });

        $this->assertTrue(isset($activity->onUnlock));
        ($activity->onUnlock)(true);

        $this->assertSame(WorkflowFailedStatus::class, $workflow->status());

        Queue::assertPushed(TestWorkflow::class, 0);
    }
}
