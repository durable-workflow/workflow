<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use RuntimeException;
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
        Sleep::fake(false);
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

        Queue::assertPushed(Watchdog::class, static function (Watchdog $watchdog): bool {
            return $watchdog->connection === 'redis'
                && $watchdog->delay === null;
        });
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
            protected static function booted(): void
            {
                static::addGlobalScope(
                    'mark-watchdog-present',
                    static function (\Illuminate\Database\Eloquent\Builder $builder): void {
                        Cache::put('workflow:watchdog', true, Watchdog::DEFAULT_TIMEOUT);
                    }
                );
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

    public function testWakeWaitsForCommitBeforeDispatching(): void
    {
        Queue::fake();

        $this->createStalePendingWorkflow();

        DB::transaction(function (): void {
            Watchdog::wake('redis');

            Queue::assertNotPushed(Watchdog::class);
            $this->assertFalse(Cache::has('workflow:watchdog'));
        });

        Queue::assertPushed(Watchdog::class, static function (Watchdog $watchdog): bool {
            return $watchdog->connection === 'redis'
                && $watchdog->delay === null;
        });
        $this->assertTrue(Cache::has('workflow:watchdog'));
    }

    public function testWakeDoesNotSetMarkerOrDispatchOnRollback(): void
    {
        Queue::fake();

        $this->createStalePendingWorkflow();

        try {
            DB::transaction(function (): void {
                Watchdog::wake('redis');

                Queue::assertNotPushed(Watchdog::class);
                $this->assertFalse(Cache::has('workflow:watchdog'));

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }

        Queue::assertNotPushed(Watchdog::class);
        $this->assertFalse(Cache::has('workflow:watchdog'));
        $this->assertFalse(Cache::has('workflow:watchdog:looping'));
    }

    public function testWakeClearsMarkerWhenDispatchFails(): void
    {
        $this->createStalePendingWorkflow();

        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->willThrowException(new RuntimeException('dispatch failed'));

        $this->app->instance(Dispatcher::class, $dispatcher);

        try {
            Watchdog::wake('redis');
            $this->fail('Expected dispatch failure to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('dispatch failed', $exception->getMessage());
        }

        $this->assertFalse(Cache::has('workflow:watchdog'));
        $this->assertFalse(Cache::has('workflow:watchdog:looping'));
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

    public function testExpiredLeaseAllowsAStalledChainToBeReplaced(): void
    {
        Queue::fake();
        Carbon::setTestNow(now()->startOfSecond());

        $this->createStalePendingWorkflow();

        try {
            Watchdog::wake('redis');

            Carbon::setTestNow(now()->addSeconds(Watchdog::DEFAULT_TIMEOUT));
            Watchdog::wake('redis');
            Queue::assertPushed(Watchdog::class, 1);

            Carbon::setTestNow(now()->addSeconds(61));
            Watchdog::wake('redis');
            Queue::assertPushed(Watchdog::class, 2);
        } finally {
            Carbon::setTestNow();
        }
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

        Cache::lock('laravel_unique_job:' . TestSimpleWorkflow::class . ':' . $storedWorkflow->id)->get();

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
        $originalStoredWorkflowModel = config('workflows.stored_workflow_model');

        try {
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
        } finally {
            config([
                'workflows.stored_workflow_model' => $originalStoredWorkflowModel,
            ]);
        }
    }

    public function testHandleDispatchesFreshDelayedJobWhenRunningOnQueue(): void
    {
        Queue::fake();

        $timeout = Watchdog::DEFAULT_TIMEOUT;
        $generation = 'current-generation';
        Cache::put('workflow:watchdog', $generation, $timeout + 60);

        $job = $this->createMock(JobContract::class);
        $job->expects($this->never())
            ->method('release');

        $watchdog = (new Watchdog($generation))
            ->onConnection('redis')
            ->onQueue('high');
        $watchdog->setJob($job);
        $watchdog->handle();

        Queue::assertPushed(Watchdog::class, static function (Watchdog $nextWatchdog) use ($watchdog, $timeout): bool {
            return $nextWatchdog !== $watchdog
                && $nextWatchdog->generation !== null
                && $nextWatchdog->generation !== $watchdog->generation
                && $nextWatchdog->connection === 'redis'
                && $nextWatchdog->queue === 'high'
                && $nextWatchdog->delay === $timeout;
        });
    }

    public function testDuplicateCandidatesOnlyScheduleOneSuccessor(): void
    {
        Queue::fake();

        $generation = 'current-generation';
        Cache::put('workflow:watchdog', $generation, Watchdog::DEFAULT_TIMEOUT + 60);

        $first = (new Watchdog($generation))
            ->onConnection('redis')
            ->onQueue('high');
        $first->setJob($this->createMock(JobContract::class));

        $duplicate = (new Watchdog($generation))
            ->onConnection('redis')
            ->onQueue('high');
        $duplicate->setJob($this->createMock(JobContract::class));

        $first->handle();
        $duplicate->handle();

        Queue::assertPushed(Watchdog::class, 1);
    }

    public function testConcurrentCandidateCannotJoinTheContinuingChain(): void
    {
        Queue::fake();
        Carbon::setTestNow(now()->startOfSecond());
        Sleep::fake(true, true);

        $generation = 'current-generation';
        Cache::put('workflow:watchdog', $generation, Watchdog::DEFAULT_TIMEOUT + 60);

        $duplicate = (new Watchdog($generation))
            ->onConnection('redis')
            ->onQueue('high');
        $duplicate->setJob($this->createMock(JobContract::class));

        $modelClass = new class() extends StoredWorkflow {
            public static ?Watchdog $duplicate = null;

            protected static function booted(): void
            {
                static::addGlobalScope('run-concurrent-watchdog', static function (): void {
                    Carbon::setTestNow(now()->addSeconds(Watchdog::DEFAULT_TIMEOUT + 30));
                    $duplicate = self::$duplicate;
                    self::$duplicate = null;
                    $duplicate?->handle();
                });
            }
        };
        $modelClassName = get_class($modelClass);
        $modelClassName::$duplicate = $duplicate;
        $originalModel = config('workflows.stored_workflow_model');

        $watchdog = (new Watchdog($generation))
            ->onConnection('redis')
            ->onQueue('high');
        $watchdog->setJob($this->createMock(JobContract::class));

        try {
            config([
                'workflows.stored_workflow_model' => $modelClassName,
            ]);
            $watchdog->handle();
        } finally {
            config([
                'workflows.stored_workflow_model' => $originalModel,
            ]);
            Carbon::setTestNow();
        }

        Queue::assertPushed(Watchdog::class, 1);
    }

    public function testCurrentGenerationWaitsForStaleContenderAndPreservesQueueAffinity(): void
    {
        Queue::fake();

        $generation = 'current-generation';
        Cache::put('workflow:watchdog', $generation, Watchdog::DEFAULT_TIMEOUT + 60);

        $heldLock = Cache::lock('workflow:watchdog:chain', Watchdog::DEFAULT_TIMEOUT + 60);
        $this->assertTrue($heldLock->get());

        Sleep::fake();
        Sleep::whenFakingSleep(static function () use ($heldLock): void {
            $heldLock->release();
        });

        $watchdog = (new Watchdog($generation))
            ->onConnection('redis')
            ->onQueue('high');
        $watchdog->setJob($this->createMock(JobContract::class));

        try {
            $watchdog->handle();
        } finally {
            $heldLock->release();
        }

        Sleep::assertSleptTimes(1);
        Queue::assertPushed(Watchdog::class, 1);
        Queue::assertPushed(Watchdog::class, static function (Watchdog $nextWatchdog): bool {
            return $nextWatchdog->generation !== null
                && $nextWatchdog->connection === 'redis'
                && $nextWatchdog->queue === 'high'
                && $nextWatchdog->delay === Watchdog::DEFAULT_TIMEOUT;
        });
    }

    public function testExpiredLockHolderCannotReplaceANewerGenerationAfterSlowScan(): void
    {
        Queue::fake();
        Carbon::setTestNow(now()->startOfSecond());

        $generation = 'expired-generation';
        Cache::put('workflow:watchdog', $generation, Watchdog::DEFAULT_TIMEOUT + 60);

        $modelClass = new class() extends StoredWorkflow {
            protected static function booted(): void
            {
                static::addGlobalScope('replace-expired-watchdog-owner', static function (): void {
                    Carbon::setTestNow(now()->addSeconds(Watchdog::DEFAULT_TIMEOUT + 61));

                    Cache::lock('workflow:watchdog:chain', Watchdog::DEFAULT_TIMEOUT + 60)
                        ->get(static function (): void {
                            $replacement = (new Watchdog('replacement-generation'))
                                ->onConnection('redis')
                                ->onQueue('high');

                            Cache::put(
                                'workflow:watchdog',
                                'replacement-generation',
                                Watchdog::DEFAULT_TIMEOUT + 60,
                            );
                            Cache::put('workflow:watchdog:looping', 'replacement-generation', 60);
                            app(Dispatcher::class)->dispatch($replacement);
                        });
                });
            }
        };
        $modelClassName = get_class($modelClass);
        $originalModel = config('workflows.stored_workflow_model');

        $watchdog = (new Watchdog($generation))
            ->onConnection('redis')
            ->onQueue('high');
        $watchdog->setJob($this->createMock(JobContract::class));

        try {
            config([
                'workflows.stored_workflow_model' => $modelClassName,
            ]);
            $watchdog->handle();
        } finally {
            config([
                'workflows.stored_workflow_model' => $originalModel,
            ]);
            Carbon::setTestNow();
        }

        Queue::assertPushed(Watchdog::class, 1);
        Queue::assertPushed(Watchdog::class, static function (Watchdog $queuedWatchdog): bool {
            return $queuedWatchdog->generation === 'replacement-generation';
        });
        $this->assertSame('replacement-generation', Cache::get('workflow:watchdog'));
    }

    public function testQueuedCandidateWithoutGenerationCannotClaimTheChain(): void
    {
        Queue::fake();

        $watchdog = (new Watchdog())
            ->onConnection('redis');
        $watchdog->setJob($this->createMock(JobContract::class));
        $watchdog->handle();

        Queue::assertNotPushed(Watchdog::class);
        $this->assertFalse(Cache::has('workflow:watchdog'));
    }

    public function testRearmingFailureReleasesGenerationForRecovery(): void
    {
        $generation = 'current-generation';
        Cache::put('workflow:watchdog', $generation, Watchdog::DEFAULT_TIMEOUT);
        Cache::put('workflow:watchdog:looping', $generation, 60);

        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->willThrowException(new RuntimeException('rearming failed'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $watchdog = (new Watchdog($generation))
            ->onConnection('redis');
        $watchdog->setJob($this->createMock(JobContract::class));

        try {
            $watchdog->handle();
            $this->fail('Expected rearming failure to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('rearming failed', $exception->getMessage());
        }

        $this->assertFalse(Cache::has('workflow:watchdog'));
        $this->assertFalse(Cache::has('workflow:watchdog:looping'));
    }

    public function testRearmingFailureWaitsForHeldChainLockBeforeReleasingGeneration(): void
    {
        $generation = 'current-generation';
        Cache::put('workflow:watchdog', $generation, Watchdog::DEFAULT_TIMEOUT);
        Cache::put('workflow:watchdog:looping', $generation, 60);

        $heldLock = null;
        Sleep::fake();
        Sleep::whenFakingSleep(static function () use (&$heldLock): void {
            $heldLock?->release();
        });

        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(static function () use (&$heldLock): void {
                $heldLock = Cache::lock('workflow:watchdog:chain', Watchdog::DEFAULT_TIMEOUT + 60);

                if (! $heldLock->get()) {
                    throw new RuntimeException('Unable to hold the watchdog chain lock.');
                }

                throw new RuntimeException('rearming failed while chain lock was held');
            });
        $this->app->instance(Dispatcher::class, $dispatcher);

        $watchdog = (new Watchdog($generation))
            ->onConnection('redis');
        $watchdog->setJob($this->createMock(JobContract::class));

        try {
            $watchdog->handle();
            $this->fail('Expected rearming failure to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('rearming failed while chain lock was held', $exception->getMessage());
        } finally {
            $heldLock?->release();
        }

        Sleep::assertSleptTimes(1);
        $this->assertFalse(Cache::has('workflow:watchdog'));
        $this->assertFalse(Cache::has('workflow:watchdog:looping'));
    }

    public function testDispatchFailureDoesNotClearANewerOwner(): void
    {
        $generation = 'current-generation';
        Cache::put('workflow:watchdog', $generation, Watchdog::DEFAULT_TIMEOUT);

        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(static function (): void {
                Cache::put('workflow:watchdog', 'replacement-generation', Watchdog::DEFAULT_TIMEOUT);
                Cache::put('workflow:watchdog:looping', 'replacement-generation', 60);

                throw new RuntimeException('late dispatch failure');
            });
        $this->app->instance(Dispatcher::class, $dispatcher);

        $watchdog = (new Watchdog($generation))
            ->onConnection('redis');
        $watchdog->setJob($this->createMock(JobContract::class));

        try {
            $watchdog->handle();
            $this->fail('Expected dispatch failure to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('late dispatch failure', $exception->getMessage());
        }

        $this->assertSame('replacement-generation', Cache::get('workflow:watchdog'));
        $this->assertSame('replacement-generation', Cache::get('workflow:watchdog:looping'));
    }

    public function testSynchronousAndNoJobExecutionsAreOneShot(): void
    {
        Queue::fake();

        $direct = new Watchdog();
        $direct->handle();

        $generation = 'sync-generation';
        Cache::put('workflow:watchdog', $generation, Watchdog::DEFAULT_TIMEOUT);

        $sync = (new Watchdog($generation))
            ->onConnection('sync');
        $sync->setJob(new SyncJob($this->app, '{}', 'sync', 'default'));
        $sync->handle();

        Queue::assertNotPushed(Watchdog::class);
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
