<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\Workflow;

final class DefaultWorkflowTaskBridgeExecutionGuardTest extends TestCase
{
    private DefaultWorkflowTaskBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 07:40:00');
        Queue::fake();
        config()
            ->set([
                'workflows.v2.compatibility.current' => 'build-a',
                'workflows.v2.compatibility.supported' => ['build-a'],
                'workflows.v2.compatibility.namespace' => 'bridge-guards',
                'workflows.v2.types.workflows' => [
                    'bridge-guard' => BridgeExecutionGuardWorkflow::class,
                ],
            ]);
        WorkerCompatibilityFleet::clear();
        BridgeExecutionGuardWorkflow::$calls = 0;
        $this->bridge = $this->app->make(DefaultWorkflowTaskBridge::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        WorkerCompatibilityFleet::clear();
        BridgeExecutionGuardWorkflow::$calls = 0;
        parent::tearDown();
    }

    public function testMissingTaskExecutionDoesNotCreateWork(): void
    {
        $id = (string) Str::ulid();
        $before = $this->records();
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $this->assertSame($this->executionRefusal($id, 'claim_failed'), $this->bridge->execute($id));
        }
        $this->assertSame($before, $this->records());
        $this->assertNoExecution(0);
    }

    #[DataProvider('unclaimableTasks')]
    public function testExecutionRefusesWrongKindsAndClosedTasks(TaskType $kind, TaskStatus $status): void
    {
        $run = $this->createRun();
        $task = $this->createTask($run, $kind, $status);
        $before = $this->records();
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $this->assertSame($this->executionRefusal($task->id, 'claim_failed'), $this->bridge->execute($task->id));
        }
        $this->assertSame($before, $this->records());
        $this->assertNoExecution();
    }

    /**
     * @return iterable<string, array{TaskType, TaskStatus}>
     */
    public static function unclaimableTasks(): iterable
    {
        yield 'activity' => [TaskType::Activity, TaskStatus::Ready];
        yield 'timer' => [TaskType::Timer, TaskStatus::Ready];
        foreach ([TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled] as $status) {
            yield $status->value => [TaskType::Workflow, $status];
        }
    }

    #[DataProvider('failureTargets')]
    public function testWorkerFailureReportsRefuseMissingAndNonWorkflowTasks(?TaskType $kind): void
    {
        $id = (string) Str::ulid();
        if ($kind !== null) {
            $id = $this->createTask($this->createRun(), $kind)
->id;
        }
        $before = $this->records();
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $this->assertSame([
                'recorded' => false,
                'task_id' => $id,
                'reason' => $kind === null ? 'task_not_found' : 'task_not_workflow',
                'next_task_id' => null,
            ], $this->bridge->fail($id, new RuntimeException('worker transport failed')));
        }
        $this->assertSame($before, $this->records());
        $this->assertNoExecution($kind === null ? 0 : 1);
    }

    /**
     * @return iterable<string, array{?TaskType}>
     */
    public static function failureTargets(): iterable
    {
        yield 'missing' => [null];
        yield 'activity' => [TaskType::Activity];
        yield 'timer' => [TaskType::Timer];
    }

    #[DataProvider('closedRuns')]
    public function testExecutionClosesStrandedTasksWithoutReopeningTerminalRuns(RunStatus $status, bool $leased): void
    {
        $run = $this->createRun($status);
        $task = $this->createTask($run, status: $leased ? TaskStatus::Leased : TaskStatus::Ready);
        $before = $run->fresh()
            ->getRawOriginal();
        $result = $this->bridge->execute($task->id);
        $this->assertSame([
            'executed' => true,
            'task_id' => $task->id,
            'workflow_run_id' => $run->id,
            'run_status' => $status->value,
            'next_task_id' => null,
            'reason' => null,
        ], $result);
        $closed = $task->fresh();
        $this->assertSame($status === RunStatus::Failed ? TaskStatus::Failed : TaskStatus::Completed, $closed->status);
        $this->assertSame($leased ? 2 : 3, $closed->attempt_count);
        $this->assertSame($leased ? 'existing-worker' : $task->id, $closed->lease_owner);
        $this->assertNull($closed->lease_expires_at);
        $this->assertSame($before, $run->fresh()->getRawOriginal());
        $this->assertSame($status->value, WorkflowRunSummary::query()->findOrFail($run->id)->status);
        $after = $this->records();
        $this->assertSame($this->executionRefusal($task->id, 'claim_failed'), $this->bridge->execute($task->id));
        $this->assertSame($after, $this->records());
        $this->assertNoExecution();
    }

    /**
     * @return iterable<string, array{RunStatus, bool}>
     */
    public static function closedRuns(): iterable
    {
        foreach ([RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled, RunStatus::Terminated] as $status) {
            yield $status->value . ' ready' => [$status, false];
            yield $status->value . ' already leased' => [$status, true];
        }
    }

    public function testCancellationCommittedAfterClaimKeepsTheTaskCancelled(): void
    {
        $run = $this->createRun();
        $task = $this->createTask($run);
        $scheduled = false;
        Event::listen('eloquent.updated: ' . WorkflowTask::class, static function (WorkflowTask $updated) use (
            $task,
            $run,
            &$scheduled
        ): void {
            if ($scheduled || $updated->id !== $task->id || $updated->status !== TaskStatus::Leased) {
                return;
            }
            $scheduled = true;
            DB::afterCommit(static function () use ($run, $task): void {
                $run->fresh()
                    ->forceFill([
                        'status' => RunStatus::Cancelled,
                    ])->save();
                $task->fresh()
                    ->forceFill([
                        'status' => TaskStatus::Cancelled,
                    ])->save();
            });
        });
        $result = $this->bridge->execute($task->id);
        $this->assertTrue($scheduled);
        $this->assertTrue($result['executed']);
        $this->assertSame('cancelled', $result['run_status']);
        $this->assertNull($result['next_task_id']);
        $this->assertSame(TaskStatus::Cancelled, $task->fresh()->status);
        $this->assertNull($task->fresh()->lease_expires_at);
        $this->assertSame(3, $task->fresh()->attempt_count);
        $this->assertNoExecution();
    }

    #[DataProvider('purgedTasks')]
    public function testConstructionFailureReturnsDiagnosticsAfterRollback(bool $purge): void
    {
        $run = $this->createRun();
        $task = $this->createTask($run);
        $error = new RuntimeException('application dependency unavailable');
        $this->app->bind(BridgeExecutionGuardWorkflow::class, static fn () => throw $error);
        $purged = false;
        if ($purge) {
            Event::listen(TransactionRolledBack::class, static function (TransactionRolledBack $event) use (
                $task,
                &$purged
            ): void {
                if ($purged || $event->connection->transactionLevel() !== 0) {
                    return;
                }
                $purged = true;
                WorkflowTask::query()->whereKey($task->id)->delete();
            });
        }
        $this->assertSame($this->executionRefusal($task->id, 'execution_failed'), $this->bridge->execute($task->id));
        $this->assertSame(RunStatus::Running, $run->fresh()->status);
        if ($purge) {
            $this->assertTrue($purged);
            $this->assertNull($task->fresh());
        } else {
            $failed = $task->fresh();
            $this->assertSame(TaskStatus::Failed, $failed->status);
            $this->assertSame($error->getMessage(), $failed->last_error);
            $this->assertNull($failed->lease_expires_at);
            $this->assertSame(3, $failed->attempt_count);
            $before = $this->records();
            $this->assertSame($this->executionRefusal($task->id, 'claim_failed'), $this->bridge->execute($task->id));
            $this->assertSame($before, $this->records());
        }
        $this->assertNoExecution();
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function purgedTasks(): iterable
    {
        yield 'retain diagnostics' => [false];
        yield 'cleanup removes task after rollback' => [true];
    }

    #[DataProvider('blockedClaims')]
    public function testAdmissionFailureDoesNotLeaseOrExecuteWork(string $case, bool $execute): void
    {
        $run = $this->createRun();
        $task = $this->createTask($run);
        if ($case === 'compatibility') {
            $task->forceFill([
                'compatibility' => 'build-b',
            ])->save();
        } else {
            config()->set('queue.connections.refused-backend', $case === 'sync' ? [
                'driver' => 'sync',
            ] : []);
            $task->forceFill([
                'connection' => 'refused-backend',
            ])->save();
        }
        $result = $execute ? $this->bridge->execute($task->id) : $this->bridge->claimStatus(
            $task->id,
            'requesting-worker'
        );
        if ($execute) {
            $this->assertSame($this->executionRefusal($task->id, 'claim_failed'), $result);
        } else {
            $this->assertFalse($result['claimed']);
            $this->assertSame(
                $case === 'compatibility' ? 'compatibility_blocked' : 'backend_unavailable',
                $result['reason']
            );
            $this->assertNotEmpty($result['reason_detail']);
        }
        $refused = $task->fresh();
        $this->assertSame(TaskStatus::Ready, $refused->status);
        $this->assertSame(2, $refused->attempt_count);
        $this->assertNull($refused->lease_owner);
        $this->assertNull($refused->leased_at);
        $this->assertNull($refused->lease_expires_at);
        if ($case === 'compatibility') {
            $this->assertSame('build-b', $refused->compatibility);
            $this->assertNull($refused->last_claim_error);
        } else {
            $this->assertNotNull($refused->last_claim_failed_at);
            $this->assertNotEmpty($refused->last_claim_error);
            $this->assertStringContainsString('queue', strtolower($refused->last_claim_error));
        }
        $this->assertSame(RunStatus::Running, $run->fresh()->status);
        $this->assertSame('running', WorkflowRunSummary::query()->findOrFail($run->id)->status);
        $this->assertNoExecution();
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function blockedClaims(): iterable
    {
        foreach (['compatibility', 'sync', 'missing'] as $case) {
            yield $case . ' structured claim' => [$case, false];
            yield $case . ' execution' => [$case, true];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function executionRefusal(string $id, string $reason): array
    {
        return [
            'executed' => false,
            'task_id' => $id,
            'workflow_run_id' => null,
            'run_status' => null,
            'next_task_id' => null,
            'reason' => $reason,
        ];
    }

    private function assertNoExecution(int $runCount = 1): void
    {
        $this->assertSame(0, BridgeExecutionGuardWorkflow::$calls);
        $this->assertSame(0, WorkflowHistoryEvent::query()->count());
        $this->assertSame($runCount, WorkflowRun::query()->count());
        Queue::assertNothingPushed();
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function records(): array
    {
        $records = [];
        foreach ([
            WorkflowInstance::class,
            WorkflowRun::class,
            WorkflowTask::class,
            WorkflowHistoryEvent::class,
            WorkflowRunSummary::class,
        ] as $model) {
            $records[$model] = $model::query()->orderBy((new $model())->getKeyName())->get()->map(
                static fn ($row): array => $row->getRawOriginal()
            )->all();
        }
        return $records;
    }

    private function createRun(RunStatus $status = RunStatus::Running): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'bridge-guard-' . strtolower((string) Str::ulid()),
            'workflow_class' => BridgeExecutionGuardWorkflow::class,
            'workflow_type' => 'bridge-guard',
            'namespace' => 'bridge-guards',
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => BridgeExecutionGuardWorkflow::class,
            'workflow_type' => 'bridge-guard',
            'namespace' => 'bridge-guards',
            'status' => $status,
            'payload_codec' => 'avro',
            'arguments' => Serializer::serializeWithCodec('avro', []),
            'compatibility' => 'build-a',
            'connection' => 'redis',
            'queue' => 'workflows',
            'started_at' => now()
                ->subMinute(),
            'closed_at' => $status->isTerminal() ? now() : null,
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();
        return $run;
    }

    private function createTask(
        WorkflowRun $run,
        TaskType $kind = TaskType::Workflow,
        TaskStatus $status = TaskStatus::Ready
    ): WorkflowTask {
        return WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => $kind,
            'status' => $status,
            'payload' => [
                'recorded' => true,
            ],
            'namespace' => 'bridge-guards',
            'compatibility' => 'build-a',
            'connection' => 'redis',
            'queue' => 'workflows',
            'available_at' => now(),
            'attempt_count' => 2,
            'lease_owner' => $status === TaskStatus::Leased ? 'existing-worker' : null,
            'lease_expires_at' => $status === TaskStatus::Leased ? now()->addMinute() : null,
        ]);
    }
}

final class BridgeExecutionGuardWorkflow extends Workflow
{
    public static int $calls = 0;

    public function handle(): string
    {
        ++self::$calls;
        return 'unexpected execution';
    }
}
