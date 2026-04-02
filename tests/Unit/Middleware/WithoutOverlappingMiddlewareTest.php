<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\Fixtures\TestActivity;
use Tests\Fixtures\TestWorkflow;
use Tests\TestCase;
use Workflow\Middleware\WithoutOverlappingMiddleware;

final class WithoutOverlappingMiddlewareTest extends TestCase
{
    public function testMiddleware(): void
    {
        $this->app->make('cache')
            ->store()
            ->clear();

        $middleware = new WithoutOverlappingMiddleware(1, WithoutOverlappingMiddleware::WORKFLOW);
        $this->assertSame($middleware->getLockKey(), 'laravel-workflow-overlap:1');
        $this->assertSame($middleware->getWorkflowSemaphoreKey(), 'laravel-workflow-overlap:1:workflow');
        $this->assertSame($middleware->getActivitySemaphoreKey(), 'laravel-workflow-overlap:1:activity');

        $middleware = new WithoutOverlappingMiddleware(1, WithoutOverlappingMiddleware::ACTIVITY);
        $this->assertSame($middleware->getLockKey(), 'laravel-workflow-overlap:1');
        $this->assertSame($middleware->getWorkflowSemaphoreKey(), 'laravel-workflow-overlap:1:workflow');
        $this->assertSame($middleware->getActivitySemaphoreKey(), 'laravel-workflow-overlap:1:activity');
    }

    public function testAllowsOnlyOneWorkflowInstance(): void
    {
        $this->app->make('cache')
            ->store()
            ->clear();

        $workflow1 = $this->mock(TestWorkflow::class);
        $middleware1 = new WithoutOverlappingMiddleware(1, WithoutOverlappingMiddleware::WORKFLOW);

        $workflow2 = $this->mock(TestWorkflow::class, static function (MockInterface $mock) {
            $mock->shouldReceive('release')
                ->once();
        });
        $middleware2 = new WithoutOverlappingMiddleware(1, WithoutOverlappingMiddleware::WORKFLOW);

        $activity = $this->mock(TestActivity::class, static function (MockInterface $mock) {
            $mock->shouldReceive('release')
                ->once();
        });
        $middleware3 = new WithoutOverlappingMiddleware(1, WithoutOverlappingMiddleware::ACTIVITY);

        $middleware1->handle($workflow1, function ($job) use (
            $middleware1,
            $workflow2,
            $middleware2,
            $activity,
            $middleware3
        ) {
            $this->assertSame(1, Cache::get($middleware1->getWorkflowSemaphoreKey()));
            $this->assertNull(Cache::get($middleware1->getActivitySemaphoreKey()));

            $middleware2->handle($workflow2, static function ($job) {
            });
            $middleware3->handle($activity, static function ($job) {
            });
        });

        $this->assertNull(Cache::get($middleware1->getWorkflowSemaphoreKey()));
        $this->assertNull(Cache::get($middleware1->getActivitySemaphoreKey()));
    }

    public function testAllowsMultipleActivityInstances(): void
    {
        $this->app->make('cache')
            ->store()
            ->clear();

        $activity1 = $this->mock(TestActivity::class);
        $middleware1 = new WithoutOverlappingMiddleware(1, WithoutOverlappingMiddleware::ACTIVITY);

        $activity2 = $this->mock(TestActivity::class);
        $middleware2 = new WithoutOverlappingMiddleware(1, WithoutOverlappingMiddleware::ACTIVITY);

        $workflow1 = $this->mock(TestWorkflow::class, static function (MockInterface $mock) {
            $mock->shouldReceive('release')
                ->once();
        });
        $middleware3 = new WithoutOverlappingMiddleware(1, WithoutOverlappingMiddleware::WORKFLOW);

        $middleware1->handle($activity1, function ($job) use (
            $middleware1,
            $activity2,
            $middleware2,
            $workflow1,
            $middleware3
        ) {
            $this->assertNull(Cache::get($middleware1->getWorkflowSemaphoreKey()));
            $this->assertSame(1, count(Cache::get($middleware1->getActivitySemaphoreKey())));

            $middleware2->handle($activity2, function ($job) use ($middleware2) {
                $this->assertNull(Cache::get($middleware2->getWorkflowSemaphoreKey()));
                $this->assertSame(2, count(Cache::get($middleware2->getActivitySemaphoreKey())));
            });

            $middleware3->handle($workflow1, static function ($job) {
            });
        });

        $this->assertNull(Cache::get($middleware1->getWorkflowSemaphoreKey()));
        $this->assertSame(0, count(Cache::get($middleware1->getActivitySemaphoreKey(), [])));
    }

    public function testUnknownTypeDoesNotCallNext(): void
    {
        $this->app->make('cache')
            ->store()
            ->clear();

        $job = $this->mock(TestActivity::class, static function (MockInterface $mock) {
            $mock->shouldReceive('release')
                ->once();
        });

        $middleware = new WithoutOverlappingMiddleware(1, 999);

        $middleware->handle($job, function ($job) {
            $this->fail('Should not call next when type is unknown');
        });

        $this->assertNull(Cache::get($middleware->getWorkflowSemaphoreKey()));
        $this->assertNull(Cache::get($middleware->getActivitySemaphoreKey()));
    }

    public function testReleaseWhenCompareAndSetFails(): void
    {
        $this->app->make('cache')
            ->store()
            ->clear();

        $job = $this->mock(TestWorkflow::class, static function (MockInterface $mock) {
            $mock->shouldReceive('release')
                ->once();
        });

        $lock = $this->mock(Lock::class, static function (MockInterface $mock) {
            $mock->shouldReceive('get')
                ->times(10)
                ->andReturn(false);
        });

        $cache = $this->mock(Repository::class, static function (MockInterface $mock) use ($lock) {
            $mock->shouldReceive('lock')
                ->times(10)
                ->andReturn($lock);
            $mock->shouldReceive('get')
                ->andReturn([]);
        });

        $middleware = new WithoutOverlappingMiddleware(1, WithoutOverlappingMiddleware::WORKFLOW);

        $middleware->handle($job, function ($job) {
            $this->fail('Should not call next when lock is not acquired');
        });

        $this->assertNull(Cache::get($middleware->getWorkflowSemaphoreKey()));
        $this->assertNull(Cache::get($middleware->getActivitySemaphoreKey()));
    }

    public function testUnlockWithUnknownTypeReturnsTrue(): void
    {
        $this->app->make('cache')
            ->store()
            ->clear();

        $job = new \stdClass();
        $job->key = 'test-key';

        $middleware = new WithoutOverlappingMiddleware(1, 999);

        $result = $middleware->unlock($job);

        $this->assertTrue($result);
    }

    public function testActivitySemaphoreIsEmptyAfterUnlock(): void
    {
        $this->app->make('cache')
            ->store()
            ->clear();

        $activity = $this->mock(TestActivity::class);
        $middleware = new WithoutOverlappingMiddleware(1, WithoutOverlappingMiddleware::ACTIVITY);

        $middleware->handle($activity, static function ($job) {
            // Activity is now locked
        });

        $this->assertSame([], Cache::get($middleware->getActivitySemaphoreKey(), []));
    }

    public function testUnlockActivityReturnsFalseWhenOtherActivitiesStillRunning(): void
    {
        $this->app->make('cache')
            ->store()
            ->clear();

        $activity1 = $this->mock(TestActivity::class);
        $activity2 = $this->mock(TestActivity::class);
        $middleware1 = new WithoutOverlappingMiddleware(1, WithoutOverlappingMiddleware::ACTIVITY);
        $middleware2 = new WithoutOverlappingMiddleware(1, WithoutOverlappingMiddleware::ACTIVITY);

        $unlockResult1 = null;

        $middleware1->handle($activity1, function ($job) use (
            $middleware1,
            $middleware2,
            $activity2,
            &$unlockResult1
        ) {
            $middleware2->handle($activity2, function ($job) use ($middleware1, $activity2, &$unlockResult1) {
                $this->assertSame(2, count(Cache::get($middleware1->getActivitySemaphoreKey())));
            });

            $this->assertSame(1, count(Cache::get($middleware1->getActivitySemaphoreKey())));
        });

        $this->assertSame([], Cache::get($middleware1->getActivitySemaphoreKey(), []));
    }

    public function testUnlockActivityReturnsTrueWhenLastActivityCompletes(): void
    {
        $this->app->make('cache')
            ->store()
            ->clear();

        $activity = $this->mock(TestActivity::class);
        $middleware = new WithoutOverlappingMiddleware(1, WithoutOverlappingMiddleware::ACTIVITY);

        $unlockResult = null;

        $middleware->handle($activity, function ($job) use ($middleware) {
            $this->assertSame(1, count(Cache::get($middleware->getActivitySemaphoreKey())));
        });

        $this->assertSame([], Cache::get($middleware->getActivitySemaphoreKey(), []));

        $job = new \stdClass();
        $job->key = $middleware->getActivitySemaphoreKey() . ':test-key';

        Cache::put($middleware->getActivitySemaphoreKey(), [$job->key]);
        Cache::put($job->key, 1);

        $result = $middleware->unlock($job);

        $this->assertTrue($result);
        $this->assertSame([], Cache::get($middleware->getActivitySemaphoreKey(), []));
    }

    public function testUnlockActivityReturnsFalseWhenOtherActivityKeyExists(): void
    {
        $this->app->make('cache')
            ->store()
            ->clear();

        $middleware = new WithoutOverlappingMiddleware(1, WithoutOverlappingMiddleware::ACTIVITY);

        $job1 = new \stdClass();
        $job1->key = $middleware->getActivitySemaphoreKey() . ':job1';

        $job2 = new \stdClass();
        $job2->key = $middleware->getActivitySemaphoreKey() . ':job2';

        Cache::put($middleware->getActivitySemaphoreKey(), [$job1->key, $job2->key]);
        Cache::put($job1->key, 1);
        Cache::put($job2->key, 1);

        $result = $middleware->unlock($job1);

        $this->assertFalse($result);

        $this->assertSame([$job2->key], Cache::get($middleware->getActivitySemaphoreKey()));

        $result = $middleware->unlock($job2);

        $this->assertTrue($result);
        $this->assertSame([], Cache::get($middleware->getActivitySemaphoreKey(), []));
    }

    public function testUnlockActivityAppliesTtlWhenExpiresAfterIsConfigured(): void
    {
        $job = new \stdClass();
        $job->key = 'test-activity-key';

        $lock = $this->mock(Lock::class, static function (MockInterface $mock) {
            $mock->shouldReceive('get')
                ->once()
                ->andReturn(true);
            $mock->shouldReceive('release')
                ->once();
        });

        $cache = $this->mock(Repository::class, static function (MockInterface $mock) use ($lock, $job) {
            $mock->shouldReceive('lock')
                ->once()
                ->andReturn($lock);
            $mock->shouldReceive('get')
                ->with('laravel-workflow-overlap:1:activity', [])
                ->andReturn([$job->key]);
            $mock->shouldReceive('forget')
                ->with('laravel-workflow-overlap:1:activity')
                ->once();
            $mock->shouldReceive('forget')
                ->with($job->key)
                ->once();
        });

        $middleware = new WithoutOverlappingMiddleware(1, WithoutOverlappingMiddleware::ACTIVITY, 0, 60);

        $result = $middleware->unlock($job);

        $this->assertTrue($result);
    }

    public function testUnlockActivityAppliesTtlWhenOtherActivitiesRemain(): void
    {
        $job = new \stdClass();
        $job->key = 'test-activity-key';

        $remainingKey = 'other-activity-key';

        $lock = $this->mock(Lock::class, static function (MockInterface $mock) {
            $mock->shouldReceive('get')
                ->once()
                ->andReturn(true);
            $mock->shouldReceive('release')
                ->once();
        });

        $this->mock(Repository::class, static function (MockInterface $mock) use ($job, $lock, $remainingKey) {
            $mock->shouldReceive('lock')
                ->once()
                ->andReturn($lock);
            $mock->shouldReceive('get')
                ->with('laravel-workflow-overlap:1:activity', [])
                ->andReturn([$job->key, $remainingKey]);
            $mock->shouldReceive('put')
                ->with('laravel-workflow-overlap:1:activity', [$remainingKey], 60)
                ->once();
            $mock->shouldReceive('forget')
                ->with($job->key)
                ->once();
            $mock->shouldReceive('has')
                ->with($remainingKey)
                ->once()
                ->andReturn(false);
        });

        $middleware = new WithoutOverlappingMiddleware(1, WithoutOverlappingMiddleware::ACTIVITY, 0, 60);

        $result = $middleware->unlock($job);

        $this->assertTrue($result);
    }

    public function testUnlockActivityRetriesOnLockFailure(): void
    {
        $job = new \stdClass();
        $job->key = 'test-activity-key';

        $callCount = 0;

        $lock = $this->mock(Lock::class, static function (MockInterface $mock) use (&$callCount) {
            $mock->shouldReceive('get')
                ->twice()
                ->andReturnUsing(static function () use (&$callCount) {
                    $callCount++;
                    return $callCount > 1;
                });
            $mock->shouldReceive('release')
                ->once();
        });

        $cache = $this->mock(Repository::class, static function (MockInterface $mock) use ($lock, $job) {
            $mock->shouldReceive('lock')
                ->twice()
                ->andReturn($lock);
            $mock->shouldReceive('get')
                ->with('laravel-workflow-overlap:1:activity', [])
                ->andReturn([$job->key]);
            $mock->shouldReceive('forget')
                ->with('laravel-workflow-overlap:1:activity')
                ->once();
            $mock->shouldReceive('forget')
                ->with($job->key)
                ->once();
        });

        $middleware = new WithoutOverlappingMiddleware(1, WithoutOverlappingMiddleware::ACTIVITY);

        $result = $middleware->unlock($job);

        $this->assertTrue($result);
        $this->assertSame(2, $callCount);
    }

    public function testUnlockActivityReturnsTrueAfterMaxRetries(): void
    {
        $job = new \stdClass();
        $job->key = 'test-activity-key';

        $lock = $this->mock(Lock::class, static function (MockInterface $mock) {
            $mock->shouldReceive('get')
                ->times(100)
                ->andReturn(false);
        });

        $cache = $this->mock(Repository::class, static function (MockInterface $mock) use ($lock) {
            $mock->shouldReceive('lock')
                ->times(100)
                ->andReturn($lock);
        });

        $middleware = new WithoutOverlappingMiddleware(1, WithoutOverlappingMiddleware::ACTIVITY);

        $result = $middleware->unlock($job);

        $this->assertTrue($result);
    }

    public function testActivityRetryBreaksWhenWorkflowSemaphoreBecomesActive(): void
    {
        $this->app->make('cache')
            ->store()
            ->clear();

        $job = $this->mock(TestActivity::class, static function (MockInterface $mock) {
            $mock->shouldReceive('release')
                ->once();
        });

        $lock = $this->mock(Lock::class, static function (MockInterface $mock) {
            $mock->shouldReceive('get')
                ->andReturn(false);
        });

        $cache = $this->mock(Repository::class, static function (MockInterface $mock) use ($lock) {
            $mock->shouldReceive('lock')
                ->andReturn($lock);
            $mock->shouldReceive('get')
                ->with('laravel-workflow-overlap:1:workflow', 0)
                ->andReturn(0, 0, 1);
            $mock->shouldReceive('get')
                ->with('laravel-workflow-overlap:1:activity', [])
                ->andReturn([]);
        });

        $middleware = new WithoutOverlappingMiddleware(1, WithoutOverlappingMiddleware::ACTIVITY);

        $middleware->handle($job, function ($job) {
            $this->fail('Should not call next when workflow semaphore becomes active on retry');
        });
    }

    public function testCompareAndSetReturnsFalseOnValueMismatch(): void
    {
        $this->app->make('cache')
            ->store()
            ->clear();

        $job = $this->mock(TestWorkflow::class, static function (MockInterface $mock) {
            $mock->shouldReceive('release')
                ->once();
        });

        $lock = $this->mock(Lock::class, static function (MockInterface $mock) {
            $mock->shouldReceive('get')
                ->once()
                ->andReturn(true);
            $mock->shouldReceive('release')
                ->once();
        });

        $cache = $this->mock(Repository::class, static function (MockInterface $mock) use ($lock) {
            $mock->shouldReceive('lock')
                ->once()
                ->andReturn($lock);
            $mock->shouldReceive('get')
                ->with('laravel-workflow-overlap:1:workflow', 0)
                ->andReturn(0, 99);
            $mock->shouldReceive('get')
                ->with('laravel-workflow-overlap:1:activity', [])
                ->andReturn([]);
        });

        $middleware = new WithoutOverlappingMiddleware(1, WithoutOverlappingMiddleware::WORKFLOW);

        $middleware->handle($job, function ($job) {
            $this->fail('Should not call next when CAS value mismatch');
        });
    }

    public function testActivityLockAppliesTtlToSemaphoreKeyWhenConfigured(): void
    {
        $job = new \stdClass();

        $lock = $this->mock(Lock::class, static function (MockInterface $mock) {
            $mock->shouldReceive('get')
                ->once()
                ->andReturn(true);
            $mock->shouldReceive('release')
                ->once();
        });

        $cache = $this->mock(Repository::class, static function (MockInterface $mock) use ($lock) {
            $mock->shouldReceive('get')
                ->with('laravel-workflow-overlap:1:workflow', 0)
                ->once()
                ->andReturn(0);
            $mock->shouldReceive('get')
                ->with('laravel-workflow-overlap:1:activity', [])
                ->twice()
                ->andReturn([], []);
            $mock->shouldReceive('lock')
                ->with('laravel-workflow-overlap:1', 5)
                ->once()
                ->andReturn($lock);
            $mock->shouldReceive('put')
                ->withArgs(static fn ($key, $value, $ttl): bool => $key === 'laravel-workflow-overlap:1:activity'
                    && is_array($value)
                    && count($value) === 1
                    && $ttl === 60)
                ->once();
            $mock->shouldReceive('put')
                ->withArgs(
                    static fn ($key, $value, $ttl): bool => str_starts_with(
                        $key,
                        'laravel-workflow-overlap:1:activity:'
                    )
                    && $value === 1
                    && $ttl === 60
                )
                ->once();
        });

        $middleware = new WithoutOverlappingMiddleware(1, WithoutOverlappingMiddleware::ACTIVITY, 0, 60);

        $this->assertTrue($middleware->lock($job));
        $this->assertTrue(isset($job->key));
    }
}
