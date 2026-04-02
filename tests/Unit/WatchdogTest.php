<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\TestSimpleWorkflow;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowPendingStatus;
use Workflow\States\WorkflowRunningStatus;
use Workflow\Watchdog;

final class WatchdogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('workflow:watchdog');
        Cache::forget('workflow:watchdog:looping');
    }

    protected function tearDown(): void
    {
        Cache::forget('workflow:watchdog');
        Cache::forget('workflow:watchdog:looping');

        parent::tearDown();
    }

    public function testHandleRecoversStalePendingWorkflow(): void
    {
        Queue::fake();

        $timeout = Watchdog::DEFAULT_TIMEOUT;

        $storedWorkflow = StoredWorkflow::create([
            'class' => TestSimpleWorkflow::class,
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
            'updated_at' => now()
                ->subSeconds($timeout + 1),
        ]);

        $watchdog = new Watchdog();
        $watchdog->handle();

        $storedWorkflow->refresh();
        $this->assertSame(WorkflowPendingStatus::class, $storedWorkflow->status::class);

        Queue::assertPushed(TestSimpleWorkflow::class, static function (TestSimpleWorkflow $workflow): bool {
            return $workflow->connection === 'redis'
                && $workflow->queue === 'default';
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

        $timeout = Watchdog::DEFAULT_TIMEOUT;

        StoredWorkflow::create([
            'class' => TestSimpleWorkflow::class,
            'status' => WorkflowPendingStatus::$name,
            'updated_at' => now()
                ->subSeconds($timeout + 1),
        ]);

        $watchdog = new Watchdog();
        $watchdog->handle();

        Queue::assertNotPushed(TestSimpleWorkflow::class);
    }

    public function testHandleSkipsAlreadyRecoveredWorkflow(): void
    {
        Queue::fake();

        $timeout = Watchdog::DEFAULT_TIMEOUT;

        $storedWorkflow = StoredWorkflow::create([
            'class' => TestSimpleWorkflow::class,
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
            'updated_at' => now()
                ->subSeconds($timeout + 1),
        ]);

        $storedWorkflow->update([
            'status' => WorkflowRunningStatus::$name,
        ]);

        $watchdog = new Watchdog();
        $watchdog->handle();

        Queue::assertNotPushed(TestSimpleWorkflow::class);
    }

    public function testHandleSkipsAlreadyCompletedWorkflow(): void
    {
        Queue::fake();

        $timeout = Watchdog::DEFAULT_TIMEOUT;

        StoredWorkflow::create([
            'class' => TestSimpleWorkflow::class,
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowCompletedStatus::$name,
            'updated_at' => now()
                ->subSeconds($timeout + 1),
        ]);

        $watchdog = new Watchdog();
        $watchdog->handle();

        Queue::assertNotPushed(TestSimpleWorkflow::class);
    }

    public function testHandleRefreshesWatchdogMarker(): void
    {
        Queue::fake();

        Cache::forget('workflow:watchdog');

        $watchdog = new Watchdog();
        $watchdog->handle();

        $this->assertTrue(Cache::has('workflow:watchdog'));
    }

    public function testWakeDispatchesWhenPendingWorkflowNeedsRecovery(): void
    {
        Queue::fake();
        Cache::forget('workflow:watchdog');

        $this->createStalePendingWorkflow();

        Watchdog::wake('redis');

        Queue::assertPushed(Watchdog::class);
        $this->assertTrue(Cache::has('workflow:watchdog'));
    }

    public function testWakeUsesRequestedConnectionAndQueue(): void
    {
        Queue::fake();
        Cache::forget('workflow:watchdog');

        $this->createStalePendingWorkflow();

        Watchdog::wake('redis', 'high,default');

        Queue::assertPushed(Watchdog::class, static function (Watchdog $watchdog): bool {
            return $watchdog->connection === 'redis'
                && $watchdog->queue === 'high';
        });
    }

    public function testWakeLeavesQueueUnsetWhenQueueStringHasNoUsableQueue(): void
    {
        Queue::fake();
        Cache::forget('workflow:watchdog');

        $this->createStalePendingWorkflow();

        Watchdog::wake('redis', ' , ');

        Queue::assertPushed(Watchdog::class, static function (Watchdog $watchdog): bool {
            return $watchdog->connection === 'redis'
                && $watchdog->queue === null;
        });
    }

    public function testWakeSkipsWhenMarkerPresent(): void
    {
        Queue::fake();
        Cache::put('workflow:watchdog', true, 300);

        $this->createStalePendingWorkflow();

        Watchdog::wake('redis');

        Queue::assertNotPushed(Watchdog::class);
    }

    public function testWakeSkipsWhenNoRecoverablePendingWorkflowsExist(): void
    {
        Queue::fake();
        Cache::forget('workflow:watchdog');
        Cache::forget('workflow:watchdog:looping');

        Watchdog::wake('redis');

        Queue::assertNotPushed(Watchdog::class);
    }

    public function testWakeSkipsWhenAnotherWorkerClaimsMarkerFirst(): void
    {
        Queue::fake();

        $this->createStalePendingWorkflow();

        $modelClass = new class() extends StoredWorkflow {
            public function newQuery()
            {
                Cache::put('workflow:watchdog', true, Watchdog::DEFAULT_TIMEOUT);

                return parent::newQuery();
            }
        };
        $modelClassName = get_class($modelClass);
        $originalModel = config('workflows.stored_workflow_model');

        config([
            'workflows.stored_workflow_model' => $modelClassName,
        ]);

        try {
            Watchdog::wake('redis');
        } finally {
            config([
                'workflows.stored_workflow_model' => $originalModel,
            ]);
        }

        Queue::assertNotPushed(Watchdog::class);
    }

    public function testWakeIsIdempotent(): void
    {
        Queue::fake();
        Cache::forget('workflow:watchdog');
        Cache::forget('workflow:watchdog:looping');

        $this->createStalePendingWorkflow();

        Watchdog::wake('redis');
        Watchdog::wake('redis');
        Watchdog::wake('redis');

        Queue::assertPushed(Watchdog::class, 1);
    }

    public function testHandleTouchesWorkflowBeforeRedispatch(): void
    {
        Queue::fake();

        $timeout = Watchdog::DEFAULT_TIMEOUT;

        $storedWorkflow = StoredWorkflow::create([
            'class' => TestSimpleWorkflow::class,
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
            'updated_at' => now()
                ->subSeconds($timeout + 1),
        ]);

        $watchdog = new Watchdog();
        $watchdog->handle();

        $storedWorkflow->refresh();
        $this->assertTrue($storedWorkflow->updated_at->greaterThan(now()->subSeconds(5)));
    }

    public function testHandleRecoversPendingWorkflowOnStoredConnectionAndQueue(): void
    {
        Queue::fake();

        $timeout = Watchdog::DEFAULT_TIMEOUT;

        $storedWorkflow = StoredWorkflow::create([
            'class' => TestSimpleWorkflow::class,
            'arguments' => Serializer::serialize([
                'arguments' => [],
                'options' => [
                    'connection' => 'sync',
                    'queue' => 'high',
                ],
            ]),
            'status' => WorkflowPendingStatus::$name,
            'updated_at' => now()
                ->subSeconds($timeout + 1),
        ]);

        Cache::lock('laravel_unique_job:' . TestSimpleWorkflow::class . $storedWorkflow->id)->get();

        $watchdog = new Watchdog();
        $watchdog->handle();

        Queue::assertPushed(TestSimpleWorkflow::class, static function (TestSimpleWorkflow $workflow): bool {
            return $workflow->connection === 'sync'
                && $workflow->queue === 'high';
        });
    }

    public function testHandleContinuesScanningAfterSkippedWorkflow(): void
    {
        Queue::fake();

        $timeout = Watchdog::DEFAULT_TIMEOUT;

        $skippedWorkflow = StoredWorkflow::create([
            'class' => TestSimpleWorkflow::class,
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
            'updated_at' => now()
                ->subSeconds($timeout + 1),
        ]);

        StoredWorkflow::create([
            'class' => TestSimpleWorkflow::class,
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
            'updated_at' => now()
                ->subSeconds($timeout + 1),
        ]);

        Cache::lock('workflow:watchdog:recovering:' . $skippedWorkflow->id, $timeout)
            ->get();

        $watchdog = new Watchdog();
        $watchdog->handle();

        Queue::assertPushed(TestSimpleWorkflow::class, 1);
    }

    public function testHandleSkipsWorkflowAlreadyClaimedForRecovery(): void
    {
        Queue::fake();

        $timeout = Watchdog::DEFAULT_TIMEOUT;

        $storedWorkflow = StoredWorkflow::create([
            'class' => TestSimpleWorkflow::class,
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
            'updated_at' => now()
                ->subSeconds($timeout + 1),
        ]);

        Cache::lock('workflow:watchdog:recovering:' . $storedWorkflow->id, $timeout)
            ->get();

        $watchdog = new Watchdog();
        $watchdog->handle();

        Queue::assertNotPushed(TestSimpleWorkflow::class);
    }

    public function testHandleReleasesRecoveryClaimAfterRecoveringWorkflow(): void
    {
        Queue::fake();

        $timeout = Watchdog::DEFAULT_TIMEOUT;

        $storedWorkflow = StoredWorkflow::create([
            'class' => TestSimpleWorkflow::class,
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
            'updated_at' => now()
                ->subSeconds($timeout + 1),
        ]);

        $watchdog = new Watchdog();
        $watchdog->handle();

        $this->assertTrue(Cache::lock('workflow:watchdog:recovering:' . $storedWorkflow->id, 1)->get());
    }

    public function testHandleSkipsWorkflowThatStopsBeingPendingAfterRefresh(): void
    {
        Queue::fake();

        $timeout = Watchdog::DEFAULT_TIMEOUT;
        $modelClass = new class() extends StoredWorkflow {
            public function refresh(): static
            {
                $this->status = WorkflowRunningStatus::$name;

                return $this;
            }
        };
        $modelClassName = get_class($modelClass);

        config([
            'workflows.stored_workflow_model' => $modelClassName,
        ]);

        $storedWorkflow = $modelClassName::create([
            'class' => TestSimpleWorkflow::class,
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
            'updated_at' => now()
                ->subSeconds($timeout + 1),
        ]);

        $watchdog = new Watchdog();
        $watchdog->handle();

        $storedWorkflow->refresh();

        Queue::assertNotPushed(TestSimpleWorkflow::class);
    }

    public function testHandleReleasesCurrentJobWhenRunningOnQueue(): void
    {
        Queue::fake();

        $timeout = Watchdog::DEFAULT_TIMEOUT;
        $job = $this->createMock(JobContract::class);
        $job->expects($this->once())
            ->method('release')
            ->with($timeout);

        $watchdog = new Watchdog();
        $watchdog->setJob($job);
        $watchdog->handle();
    }

    private function createStalePendingWorkflow(array $attributes = []): StoredWorkflow
    {
        $timeout = Watchdog::DEFAULT_TIMEOUT;

        return StoredWorkflow::create(array_merge([
            'class' => TestSimpleWorkflow::class,
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
            'updated_at' => now()
                ->subSeconds($timeout + 1),
        ], $attributes));
    }
}
