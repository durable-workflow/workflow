<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\TestSimpleWorkflow;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\States\WorkflowPendingStatus;
use Workflow\Watchdog;

final class WatchdogTest extends TestCase
{
    public function testHandleRecoversStalePendingWorkflows(): void
    {
        Queue::fake();

        $timeout = (int) config('workflows.watchdog_timeout', 300);

        $storedWorkflow = StoredWorkflow::create([
            'class' => TestSimpleWorkflow::class,
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
            'updated_at' => now()->subSeconds($timeout + 1),
        ]);

        $watchdog = new Watchdog();
        $watchdog->handle();

        Queue::assertPushed(TestSimpleWorkflow::class, static function ($job) use ($storedWorkflow) {
            return $job->storedWorkflow->id === $storedWorkflow->id;
        });
    }

    public function testHandleIgnoresRecentPendingWorkflows(): void
    {
        Queue::fake();

        StoredWorkflow::create([
            'class' => TestSimpleWorkflow::class,
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);

        $watchdog = new Watchdog();
        $watchdog->handle();

        Queue::assertNotPushed(TestSimpleWorkflow::class);
    }

    public function testHandleIgnoresPendingWithoutArguments(): void
    {
        Queue::fake();

        $timeout = (int) config('workflows.watchdog_timeout', 300);

        StoredWorkflow::create([
            'class' => TestSimpleWorkflow::class,
            'status' => WorkflowPendingStatus::$name,
            'updated_at' => now()->subSeconds($timeout + 1),
        ]);

        $watchdog = new Watchdog();
        $watchdog->handle();

        Queue::assertNotPushed(TestSimpleWorkflow::class);
    }

    public function testHandleClearsUniqueLockBeforeRedispatch(): void
    {
        Queue::fake();

        $timeout = (int) config('workflows.watchdog_timeout', 300);

        $storedWorkflow = StoredWorkflow::create([
            'class' => TestSimpleWorkflow::class,
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
            'updated_at' => now()->subSeconds($timeout + 1),
        ]);

        $lockKey = 'laravel_unique_job:' . TestSimpleWorkflow::class . $storedWorkflow->id;
        Cache::lock($lockKey)->get();

        $watchdog = new Watchdog();
        $watchdog->handle();

        $this->assertTrue(Cache::lock($lockKey)->get());
    }

    public function testHandleRefreshesWatchdogMarker(): void
    {
        Queue::fake();

        Cache::forget('workflow:watchdog');

        $watchdog = new Watchdog();
        $watchdog->handle();

        $this->assertTrue(Cache::has('workflow:watchdog'));
    }

    public function testKickDispatchesWhenMarkerAbsent(): void
    {
        Queue::fake();
        Cache::forget('workflow:watchdog');

        Watchdog::kick();

        Queue::assertPushed(Watchdog::class);
        $this->assertTrue(Cache::has('workflow:watchdog'));
    }

    public function testKickSkipsWhenMarkerPresent(): void
    {
        Queue::fake();
        Cache::put('workflow:watchdog', true, 300);

        Watchdog::kick();

        Queue::assertNotPushed(Watchdog::class);
    }

    public function testKickIsIdempotent(): void
    {
        Queue::fake();
        Cache::forget('workflow:watchdog');

        Watchdog::kick();
        Watchdog::kick();
        Watchdog::kick();

        Queue::assertPushed(Watchdog::class, 1);
    }

    public function testHandleToleratesAlreadyRecoveredWorkflow(): void
    {
        Queue::fake();

        $timeout = (int) config('workflows.watchdog_timeout', 300);

        $storedWorkflow = StoredWorkflow::create([
            'class' => TestSimpleWorkflow::class,
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
            'updated_at' => now()->subSeconds($timeout + 1),
        ]);

        $watchdog = new Watchdog();
        $watchdog->handle();

        $storedWorkflow->refresh();
        $this->assertSame(WorkflowPendingStatus::$name, $storedWorkflow->status::class === WorkflowPendingStatus::class ? 'pending' : (string) $storedWorkflow->status);

        Queue::assertPushed(TestSimpleWorkflow::class, 1);
    }

    public function testWatchdogTimeoutConfig(): void
    {
        Queue::fake();
        Cache::forget('workflow:watchdog');

        config(['workflows.watchdog_timeout' => 60]);

        $storedWorkflow = StoredWorkflow::create([
            'class' => TestSimpleWorkflow::class,
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
            'updated_at' => now()->subSeconds(61),
        ]);

        $watchdog = new Watchdog();
        $watchdog->handle();

        Queue::assertPushed(TestSimpleWorkflow::class);
    }
}
