<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Events\WorkflowCancelled;
use Workflow\V2\Events\WorkflowCompleted;
use Workflow\V2\Events\WorkflowFailed;
use Workflow\V2\Events\WorkflowTerminated;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\LifecycleEventDispatcher;
use Workflow\V2\TaskWatchdog;
use function Workflow\V2\upsertMemo;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

#[Type('terminal-lifecycle-consumer-wait')]
final class TerminalLifecycleWaitingWorkflow extends Workflow
{
    public function handle(): void
    {
        try {
            await(static fn (): bool => false, timeout: 3600, conditionKey: 'consumer.ready');
        } finally {
            upsertMemo([
                'cleanup' => true,
            ]);
        }
    }
}

#[Type('terminal-lifecycle-consumer-complete')]
final class TerminalLifecycleCompletingWorkflow extends Workflow
{
    public function handle(): string
    {
        return 'done';
    }
}

final class TerminalLifecycleStorageRun extends WorkflowRun
{
    protected $connection = 'secondary';
}

final class TerminalLifecycleEventTest extends TestCase
{
    public static function terminalCommands(): array
    {
        return [
            'immediate cancel' => ['cancel', HistoryEventType::WorkflowCancelled],
            'cooperative cancellation' => ['cooperative', HistoryEventType::WorkflowCancelled],
            'terminate' => ['terminate', HistoryEventType::WorkflowTerminated],
        ];
    }

    #[DataProvider('terminalCommands')]
    public function testTerminalRunNotifiesTheApplication(string $command, HistoryEventType $terminal): void
    {
        Queue::fake();
        $events = [];
        Event::listen('Workflow\\V2\\Events\\' . $terminal->value, static function ($event) use (&$events): void {
            $events[] = $event;
        });
        $workflow = WorkflowStub::make(TerminalLifecycleWaitingWorkflow::class);
        $workflow->start();
        $this->runReadyWorkflowTask($workflow->runId());
        $reason = 'consumer requested ' . $command;
        $result = match ($command) {
            'cancel' => $workflow->cancel($reason),
            'cooperative' => $workflow->requestCancellation($reason, 30),
            'terminate' => $workflow->terminate($reason),
        };
        $this->assertTrue($result->accepted());
        if ($command === 'cooperative') {
            $this->runReadyWorkflowTask($workflow->runId());
            $this->assertSame([
                'cleanup' => true,
            ], $workflow->refresh() ->memo());
        }
        $run = $workflow->refresh()
            ->run();
        $this->assertSame(
            $terminal === HistoryEventType::WorkflowCancelled ? 'cancelled' : 'terminated',
            $run->status->value
        );
        $this->assertNotNull($run->closed_at);
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', $terminal->value)
            ->count());
        $this->assertCount(1, $events, 'A genuinely closed embedded run must emit its application lifecycle event.');
        $this->assertSame($workflow->id(), $events[0]->instanceId);
        $this->assertSame($workflow->runId(), $events[0]->runId);
        $this->assertSame('terminal-lifecycle-consumer-wait', $events[0]->workflowType);
        $this->assertSame(TerminalLifecycleWaitingWorkflow::class, $events[0]->workflowClass);
        $this->assertSame($reason, $events[0]->reason);
        $this->assertSame($run->closed_at->toISOString(), $events[0]->closedAt);
        $this->assertNotSame('', $events[0]->committedAt);
    }

    public function testExistingCompletedLifecycleEventIsObservable(): void
    {
        Queue::fake();
        $events = [];
        Event::listen(WorkflowCompleted::class, static function ($event) use (&$events): void {
            $events[] = $event;
        });
        $workflow = WorkflowStub::make(TerminalLifecycleCompletingWorkflow::class);
        $workflow->start();
        $this->runReadyWorkflowTask($workflow->runId());
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertCount(1, $events);
        $this->assertSame($workflow->runId(), $events[0]->runId);
    }

    public static function terminalTransactions(): array
    {
        $cases = [];
        foreach (['cancel', 'terminate'] as $command) {
            foreach ([false, true] as $secondary) {
                foreach ([false, true] as $commit) {
                    $cases[] = [$command, $secondary, $commit];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('terminalTransactions')]
    public function testTerminalNotificationFollowsTheStorageCommit(
        string $command,
        bool $secondary,
        bool $commit
    ): void {
        Queue::fake();
        Event::fake([WorkflowCancelled::class, WorkflowTerminated::class]);
        $connection = $secondary ? $this->secondaryStorage() : DB::connection();
        $workflow = WorkflowStub::make(TerminalLifecycleWaitingWorkflow::class);
        $workflow->start();
        $this->runReadyWorkflowTask($workflow->runId());
        $event = $command === 'cancel' ? WorkflowCancelled::class : WorkflowTerminated::class;

        try {
            $connection->transaction(function () use ($workflow, $command, $commit, $event): void {
                $result = $command === 'cancel' ? $workflow->cancel('storage outcome') : $workflow->terminate(
                    'storage outcome'
                );
                $this->assertTrue($result->accepted());
                Event::assertNotDispatched($event);
                if (! $commit) {
                    throw new RuntimeException('application storage rollback');
                }
            });
        } catch (RuntimeException $error) {
            $this->assertSame('application storage rollback', $error->getMessage());
        }

        if ($commit) {
            Event::assertDispatched($event, 1);
            $this->assertTrue($workflow->refresh()->run()->status->isTerminal());
        } else {
            Event::assertNotDispatched($event);
            $this->assertFalse($workflow->refresh()->run()->status->isTerminal());
            $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $workflow->runId())
                ->whereIn(
                    'event_type',
                    [HistoryEventType::WorkflowCancelled->value, HistoryEventType::WorkflowTerminated->value]
                )->count());
        }
    }

    public static function cooperativeTransactions(): array
    {
        return [[false, false], [false, true], [true, false], [true, true]];
    }

    #[DataProvider('cooperativeTransactions')]
    public function testCooperativeFinallyAndNotificationCommitTogether(bool $secondary, bool $commit): void
    {
        Queue::fake();
        Event::fake([WorkflowCancelled::class, WorkflowTerminated::class]);
        $connection = $secondary ? $this->secondaryStorage() : DB::connection();
        $workflow = WorkflowStub::make(TerminalLifecycleWaitingWorkflow::class);
        $workflow->start();
        $this->runReadyWorkflowTask($workflow->runId());
        $request = $connection->transaction(
            static fn () => $workflow->requestCancellation('original command reason', 30)
        );
        $this->assertTrue($request->accepted());
        Event::assertNotDispatched(WorkflowCancelled::class);

        try {
            $connection->transaction(function () use ($workflow, $commit): void {
                $this->runReadyWorkflowTask($workflow->runId());
                $this->assertSame([
                    'cleanup' => true,
                ], $workflow->refresh() ->memo());
                $this->assertTrue($workflow->cancelled());
                Event::assertNotDispatched(WorkflowCancelled::class);
                if (! $commit) {
                    throw new RuntimeException('cleanup rollback');
                }
            });
        } catch (RuntimeException $error) {
            $this->assertSame('cleanup rollback', $error->getMessage());
        }

        if (! $commit) {
            Event::assertNotDispatched(WorkflowCancelled::class);
            $this->assertFalse($workflow->refresh()->cancelled());
            $this->assertSame([], $workflow->memo());
            $this->runReadyWorkflowTask($workflow->runId());
        }
        Event::assertDispatched(WorkflowCancelled::class, 1);
        Event::assertDispatched(
            WorkflowCancelled::class,
            static fn (WorkflowCancelled $event): bool => $event->reason === 'original command reason'
            && $event->closedAt === $workflow->refresh()
                ->run()
                ->closed_at->toISOString()
        );
        $this->assertSame([
            'cleanup' => true,
        ], $workflow->refresh() ->memo());
        $this->assertFalse($workflow->attemptCancel('rejected duplicate')->accepted());
        $this->assertFalse($workflow->attemptTerminate('rejected terminal command')->accepted());
        Event::assertDispatched(WorkflowCancelled::class, 1);
        Event::assertNotDispatched(WorkflowTerminated::class);
    }

    public function testRejectedCommandsDispatchNothing(): void
    {
        Queue::fake();
        Event::fake([WorkflowCancelled::class, WorkflowTerminated::class]);
        $workflow = WorkflowStub::make(TerminalLifecycleWaitingWorkflow::class);
        $this->assertFalse($workflow->attemptCancel('not started')->accepted());
        $this->assertFalse($workflow->attemptTerminate('not started')->accepted());
        Event::assertNotDispatched(WorkflowCancelled::class);
        Event::assertNotDispatched(WorkflowTerminated::class);
    }

    public function testAnUnrelatedDefaultRollbackCannotSuppressACommittedStorageNotification(): void
    {
        Queue::fake();
        Event::fake([WorkflowCancelled::class]);
        $this->secondaryStorage();
        $workflow = WorkflowStub::make(TerminalLifecycleWaitingWorkflow::class);
        $workflow->start();
        $this->runReadyWorkflowTask($workflow->runId());
        try {
            DB::transaction(function () use ($workflow): void {
                $this->assertTrue($workflow->cancel('independent storage commit')->accepted());
                Event::assertDispatched(WorkflowCancelled::class, 1);
                throw new RuntimeException('unrelated default rollback');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('unrelated default rollback', $error->getMessage());
        }
        $this->assertTrue($workflow->refresh()->cancelled());
        Event::assertDispatched(WorkflowCancelled::class, 1);
    }

    public static function commitOutcomes(): array
    {
        return [[false], [true]];
    }

    public function testCooperativeCleanupExpiryEmitsCancelledWithTheOriginalReason(): void
    {
        Queue::fake();
        Event::fake([WorkflowCancelled::class, WorkflowTerminated::class, WorkflowFailed::class]);
        $workflow = WorkflowStub::make(TerminalLifecycleWaitingWorkflow::class);
        $workflow->start();
        $this->runReadyWorkflowTask($workflow->runId());
        $request = $workflow->requestCancellation('original expiry command', 30);
        $context = $request->cancellationContext();
        $this->assertNotNull($context);
        Carbon::setTestNow($context->deadline());
        try {
            $report = TaskWatchdog::runPass(respectThrottle: false, runIds: [$workflow->runId()]);
            $this->assertSame(1, $report['cancellation_deadlines_enforced']);
            $this->assertTrue($workflow->refresh()->cancelled());
            Event::assertDispatched(WorkflowCancelled::class, 1);
            Event::assertDispatched(
                WorkflowCancelled::class,
                static fn (WorkflowCancelled $event): bool => $event->reason === 'original expiry command'
            );
            Event::assertNotDispatched(WorkflowFailed::class);
            Event::assertNotDispatched(WorkflowTerminated::class);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testRunTimeoutStillEmitsFailedInsteadOfCancelledOrTerminated(): void
    {
        Queue::fake();
        Event::fake([WorkflowCancelled::class, WorkflowTerminated::class, WorkflowFailed::class]);
        $workflow = WorkflowStub::make(TerminalLifecycleWaitingWorkflow::class);
        $workflow->start(StartOptions::rejectDuplicate()->withRunTimeout(1));
        Carbon::setTestNow(now()->addSeconds(2));
        try {
            $this->runReadyWorkflowTask($workflow->runId());
            $this->assertTrue($workflow->refresh()->failed());
            Event::assertDispatched(WorkflowFailed::class, 1);
            Event::assertNotDispatched(WorkflowCancelled::class);
            Event::assertNotDispatched(WorkflowTerminated::class);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[DataProvider('commitOutcomes')]
    public function testCollectedNotificationsFollowTheActualCustomModelConnection(bool $commit): void
    {
        Queue::fake();
        $storage = $this->secondaryStorage();
        $workflow = WorkflowStub::make(TerminalLifecycleWaitingWorkflow::class);
        $workflow->start();
        $workflow->cancel('persisted terminal command');
        config()
            ->set('workflows.storage.connection', null);
        $run = TerminalLifecycleStorageRun::query()->findOrFail($workflow->runId());
        $this->assertSame('secondary', $run->getConnectionName());
        Event::fake([WorkflowCancelled::class]);
        DB::beginTransaction();
        $storage->beginTransaction();
        try {
            [, $events] = LifecycleEventDispatcher::collect(
                static fn () => LifecycleEventDispatcher::workflowCancelled($run, 'persisted terminal command')
            );
            LifecycleEventDispatcher::release($events);
            Event::assertNotDispatched(WorkflowCancelled::class);
            DB::commit();
            Event::assertNotDispatched(WorkflowCancelled::class);
            if ($commit) {
                $storage->commit();
                Event::assertDispatched(WorkflowCancelled::class, 1);
            } else {
                $storage->rollBack();
                Event::assertNotDispatched(WorkflowCancelled::class);
            }
        } finally {
            while ($storage->transactionLevel() > 0) {
                $storage->rollBack();
            }
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('workflows.v1.enabled', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
            '--force' => true,
        ])->assertExitCode(0);
    }

    private function secondaryStorage(): \Illuminate\Database\Connection
    {
        config()->set('database.connections.secondary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()
            ->set('workflows.storage.connection', 'secondary');
        $this->artisan('migrate', [
            '--database' => 'secondary',
            '--force' => true,
        ])->assertExitCode(0);

        return DB::connection('secondary');
    }

    private function runReadyWorkflowTask(?string $runId): void
    {
        $this->assertIsString($runId);
        $task = WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)->orderBy('created_at')->firstOrFail();
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }
}
