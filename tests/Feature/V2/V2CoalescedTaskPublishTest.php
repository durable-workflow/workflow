<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDOException;
use RuntimeException;
use Tests\Fixtures\V2\TestFanOutPayloadActivityWorkflow;
use Tests\Fixtures\V2\TestFanOutPayloadChildWorkflow;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\WorkflowStub;

/**
 * A workflow task publishes the tasks it committed in batches (#1231). A
 * failed push keeps its failure record, a failure that ends the batch
 * transaction never continues in it, and jobs are pushed only after the
 * rows they read have committed.
 */
final class V2CoalescedTaskPublishTest extends TestCase
{
    public function testFailedPushKeepsItsDispatchFailureRecord(): void
    {
        Queue::fake();
        $workflow = WorkflowStub::make(TestFanOutPayloadActivityWorkflow::class, 'coalesced-failed-push');
        $workflow->start(3, 16);
        $scheduling = $this->readyWorkflowTask($workflow->runId());

        $bus = $this->app->make(BusDispatcher::class);
        $this->app->instance(BusDispatcher::class, new class($bus) {
            public function __construct(
                private readonly object $bus,
            ) {
            }

            public function dispatch(mixed $job): mixed
            {
                if ($job instanceof RunActivityTask) {
                    throw new RuntimeException('queue unavailable');
                }

                return $this->bus->dispatch($job);
            }

            public function __call(string $method, array $arguments): mixed
            {
                return $this->bus->{$method}(...$arguments);
            }
        });

        try {
            $this->app->call([new RunWorkflowTask($scheduling->id), 'handle']);
            $this->fail('A failed publish must be reported.');
        } catch (RuntimeException $exception) {
            $this->assertSame('queue unavailable', $exception->getMessage());
        } finally {
            $this->app->instance(BusDispatcher::class, $bus);
        }

        $activityTasks = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Activity->value)
            ->get();
        $this->assertCount(3, $activityTasks);
        foreach ($activityTasks as $task) {
            $this->assertSame(TaskStatus::Ready, $task->status);
            $this->assertSame('queue unavailable', $task->last_dispatch_error);
            $this->assertNotNull($task->last_dispatch_attempt_at);
            $this->assertNotNull($task->repair_available_at);
            $this->assertTrue($task->repair_available_at->lte(now()->addSecond()));
            $this->assertNull($task->last_dispatched_at);
        }
    }

    public function testATransactionEndingFailureRestartsTheBatchInsteadOfContinuing(): void
    {
        Queue::fake();
        $workflow = WorkflowStub::make(TestFanOutPayloadActivityWorkflow::class, 'coalesced-deadlock-restart');
        $workflow->start(1, 16);
        $task = $this->readyWorkflowTask($workflow->runId());
        $before = (int) $task->repair_count;
        Queue::fake();
        $deadlocks = 1;

        TaskDispatcher::coalescingRunSummaries(function () use ($task, &$deadlocks): void {
            $connection = $task->getConnection();
            $connection->transaction(function () use ($connection, $task, &$deadlocks): void {
                TaskDispatcher::afterCommitWrite(
                    $connection,
                    static fn () => WorkflowTask::query()->whereKey($task->id)->increment('repair_count'),
                );
                TaskDispatcher::afterCommitWrite($connection, function () use (&$deadlocks): void {
                    if ($deadlocks-- > 0) {
                        throw $this->deadlock();
                    }
                });
                TaskDispatcher::dispatch($task);
            });
        });

        // The first attempt rolled back whole; the restarted batch applied
        // every step exactly once and then pushed the job once.
        $this->assertSame($before + 1, (int) $task->fresh()->repair_count);
        Queue::assertPushed(RunWorkflowTask::class, 1);
    }

    public function testARepeatedTransactionEndingFailureCommitsAndPushesNothing(): void
    {
        Queue::fake();
        $workflow = WorkflowStub::make(TestFanOutPayloadActivityWorkflow::class, 'coalesced-deadlock-twice');
        $workflow->start(1, 16);
        $task = $this->readyWorkflowTask($workflow->runId());
        $before = (int) $task->repair_count;
        $attemptedBefore = $task->getRawOriginal('last_dispatch_attempt_at');
        Queue::fake();

        try {
            TaskDispatcher::coalescingRunSummaries(function () use ($task): void {
                $connection = $task->getConnection();
                $connection->transaction(function () use ($connection, $task): void {
                    TaskDispatcher::afterCommitWrite(
                        $connection,
                        static fn () => WorkflowTask::query()->whereKey($task->id)->increment('repair_count'),
                    );
                    TaskDispatcher::afterCommitWrite($connection, function (): void {
                        throw $this->deadlock();
                    });
                    TaskDispatcher::dispatch($task);
                });
            });
            $this->fail('A repeated deadlock must be reported.');
        } catch (Throwable $exception) {
            // Laravel rethrows a deadlock as DeadlockException.
            $this->assertStringContainsString('Deadlock found', $exception->getMessage());
        }

        $this->assertSame($before, (int) $task->fresh()->repair_count);
        $this->assertSame($attemptedBefore, $task->fresh()->getRawOriginal('last_dispatch_attempt_at'));
        Queue::assertNothingPushed();
    }

    public function testChildJobsArePushedOnlyAfterTheirCallRowsCommit(): void
    {
        Queue::fake();
        $workflow = WorkflowStub::make(TestFanOutPayloadChildWorkflow::class, 'coalesced-child-push-after-commit');
        $workflow->start(3, 16);
        $scheduling = $this->readyWorkflowTask($workflow->runId());
        $runId = $workflow->runId();
        $observed = new \ArrayObject();

        $bus = $this->app->make(BusDispatcher::class);
        $this->app->instance(BusDispatcher::class, new class($bus, $runId, $observed) {
            public function __construct(
                private readonly object $bus,
                private readonly string $runId,
                private readonly \ArrayObject $observed,
            ) {
            }

            public function dispatch(mixed $job): mixed
            {
                $this->observed[] = [
                    'transaction_level' => DB::connection()->transactionLevel(),
                    'child_calls' => WorkflowChildCall::query()
                        ->where('parent_workflow_run_id', $this->runId)
                        ->count(),
                ];

                return $this->bus->dispatch($job);
            }

            public function __call(string $method, array $arguments): mixed
            {
                return $this->bus->{$method}(...$arguments);
            }
        });

        try {
            $this->app->call([new RunWorkflowTask($scheduling->id), 'handle']);
        } finally {
            $this->app->instance(BusDispatcher::class, $bus);
        }

        $this->assertCount(3, $observed);
        foreach ($observed->getArrayCopy() as $push) {
            $this->assertSame(0, $push['transaction_level']);
            $this->assertSame(3, $push['child_calls']);
        }
    }

    protected function startQueueWorkersAutomatically(): bool
    {
        return false;
    }

    private function readyWorkflowTask(string $runId): WorkflowTask
    {
        return WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();
    }

    private function deadlock(): QueryException
    {
        return new QueryException(
            DB::connection()->getName(),
            'update workflow_tasks set id = id',
            [],
            new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock'),
        );
    }
}
