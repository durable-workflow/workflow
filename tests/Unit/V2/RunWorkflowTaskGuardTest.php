<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\Workflow;

final class RunWorkflowTaskGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-07T12:00:00Z'));
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
            'workflows.v2.compatibility.current' => 'build-a',
            'workflows.v2.compatibility.supported' => ['build-a'],
            'workflows.v2.compatibility.namespace' => 'job-guards',
        ]);
        WorkerCompatibilityFleet::clear();
        JobGuardProbeWorkflow::$calls = 0;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        WorkerCompatibilityFleet::clear();
        JobGuardProbeWorkflow::$calls = 0;

        parent::tearDown();
    }

    public function testMissingTaskDeliveryDoesNotRecreateWorkOrExecuteAWorkflow(): void
    {
        $this->projectionRole(0);
        $id = (string) Str::ulid();

        foreach ([1, 2] as $_) {
            $this->deliver($id);
            $this->assertSame(0, WorkflowTask::query()->count());
            $this->assertSame(0, WorkflowRun::query()->count());
            $this->assertSame(0, WorkflowHistoryEvent::query()->count());
            $this->assertSame(0, JobGuardProbeWorkflow::$calls);
        }
    }

    #[DataProvider('unclaimableTasks')]
    public function testRedeliveryPreservesUnclaimableTaskAndRunRecords(TaskType $type, TaskStatus $status): void
    {
        $run = $this->createRun();
        $task = $this->createTask($run, $type, $status);
        $this->projectionRole(0);
        $before = [$run->fresh()->getRawOriginal(), $task->fresh()->getRawOriginal()];

        foreach ([1, 2] as $_) {
            $this->deliver($task->id);
            $this->assertSame($before, [$run->fresh()->getRawOriginal(), $task->fresh()->getRawOriginal()]);
            $this->assertSame(1, WorkflowTask::query()->count());
            $this->assertSame(0, WorkflowHistoryEvent::query()->count());
            $this->assertSame(0, JobGuardProbeWorkflow::$calls);
        }
    }

    /**
     * @return iterable<string, array{TaskType, TaskStatus}>
     */
    public static function unclaimableTasks(): iterable
    {
        yield 'activity delivered to workflow carrier' => [TaskType::Activity, TaskStatus::Ready];
        yield 'timer delivered to workflow carrier' => [TaskType::Timer, TaskStatus::Ready];
        foreach ([TaskStatus::Leased, TaskStatus::Cancelled, TaskStatus::Completed, TaskStatus::Failed] as $status) {
            yield 'workflow task already ' . $status->value => [TaskType::Workflow, $status];
        }
    }

    #[DataProvider('terminalRuns')]
    public function testAReadyTaskCannotReopenOrReexecuteATerminalRun(RunStatus $status, TaskStatus $expected): void
    {
        $run = $this->createRun($status);
        $task = $this->createTask($run);
        $this->projectionRole(2);
        $before = $run->fresh()
            ->getRawOriginal();

        $this->deliver($task->id);

        $closed = $task->fresh();
        $this->assertSame($expected, $closed->status);
        $this->assertSame(3, $closed->attempt_count);
        $this->assertSame($task->id, $closed->lease_owner);
        $this->assertNull($closed->lease_expires_at);
        $this->assertSame($before, $run->fresh()->getRawOriginal());
        $this->assertSame(0, WorkflowHistoryEvent::query()->count());
        $this->assertSame(0, JobGuardProbeWorkflow::$calls);

        $taskBeforeRedelivery = $closed->getRawOriginal();
        $this->deliver($task->id);
        $this->assertSame($taskBeforeRedelivery, $task->fresh()->getRawOriginal());
        $this->assertSame($before, $run->fresh()->getRawOriginal());
        $this->assertSame(0, JobGuardProbeWorkflow::$calls);
    }

    /**
     * @return iterable<string, array{RunStatus, TaskStatus}>
     */
    public static function terminalRuns(): iterable
    {
        yield 'completed' => [RunStatus::Completed, TaskStatus::Completed];
        yield 'failed' => [RunStatus::Failed, TaskStatus::Failed];
        yield 'cancelled' => [RunStatus::Cancelled, TaskStatus::Completed];
        yield 'terminated' => [RunStatus::Terminated, TaskStatus::Completed];
    }

    public function testCancellationCommittedAfterClaimRemainsCancelledBeforeExecution(): void
    {
        $run = $this->createRun();
        $task = $this->createTask($run);
        $this->projectionRole(2);
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
            // Commit the competing cancellation between claim and execution,
            // after the claim transaction has released its locks.
            DB::afterCommit(static function () use ($task, $run): void {
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

        $this->deliver($task->id);

        $this->assertTrue($scheduled);
        $this->assertSame(RunStatus::Cancelled, $run->fresh()->status);
        $this->assertSame(TaskStatus::Cancelled, $task->fresh()->status);
        $this->assertNull($task->fresh()->lease_expires_at);
        $this->assertSame(3, $task->fresh()->attempt_count);
        $this->assertSame(0, WorkflowHistoryEvent::query()->count());
        $this->assertSame(0, JobGuardProbeWorkflow::$calls);
    }

    #[DataProvider('purgedTasks')]
    public function testWorkflowConstructionFailureRethrowsWithoutLosingTaskDiagnostics(bool $purge): void
    {
        $run = $this->createRun();
        $task = $this->createTask($run);
        $this->projectionRole($purge ? 1 : 2);
        $error = new RuntimeException('application dependency unavailable');
        $this->app->bind(JobGuardProbeWorkflow::class, static fn () => throw $error);
        $purged = false;
        if ($purge) {
            Event::listen(TransactionRolledBack::class, static function (TransactionRolledBack $event) use (
                $task,
                &$purged
            ): void {
                if ($purged || $event->connection->transactionLevel() !== 0) {
                    return;
                }
                // A separate cleanup actor can remove the task after execution
                // rolls back and before the failure carrier reacquires it.
                $purged = true;
                WorkflowTask::query()->whereKey($task->id)->delete();
            });
        }

        try {
            $this->deliver($task->id);
            $this->fail('Construction failure was swallowed by the queue carrier.');
        } catch (RuntimeException $caught) {
            $this->assertSame($error, $caught);
        }

        $this->assertSame(RunStatus::Running, $run->fresh()->status);
        $this->assertSame(0, WorkflowHistoryEvent::query()->count());
        $this->assertSame(0, JobGuardProbeWorkflow::$calls);
        if ($purge) {
            $this->assertTrue($purged);
            $this->assertNull($task->fresh());
            $this->assertSame(0, WorkflowTask::query()->count());
        } else {
            $failed = $task->fresh();
            $this->assertSame(TaskStatus::Failed, $failed->status);
            $this->assertSame($error->getMessage(), $failed->last_error);
            $this->assertNull($failed->lease_expires_at);
            $this->assertSame(3, $failed->attempt_count);
            $before = $failed->getRawOriginal();
            $this->deliver($task->id);
            $this->assertSame($before, $task->fresh()->getRawOriginal());
        }
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function purgedTasks(): iterable
    {
        yield 'failed task retained for inspection' => [false];
        yield 'task purged at rollback boundary' => [true];
    }

    private function deliver(string $id): void
    {
        $this->app->call([new RunWorkflowTask($id), 'handle']);
    }

    private function projectionRole(int $calls): void
    {
        $role = Mockery::mock(HistoryProjectionRole::class);
        if ($calls === 0) {
            $role->shouldNotReceive('projectRun');
        } else {
            $role->shouldReceive('projectRun')
                ->times($calls)
                ->andReturnUsing(
                    static fn (WorkflowRun $run): WorkflowRunSummary => new WorkflowRunSummary([
                        'workflow_run_id' => $run->id,
                        'status' => $run->status->value,
                    ]),
                );
        }
        $role->shouldNotReceive('recordActivityStarted');
        $this->app->instance(HistoryProjectionRole::class, $role);
    }

    private function createRun(RunStatus $status = RunStatus::Running): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'job-guard-' . strtolower((string) Str::ulid()),
            'workflow_class' => JobGuardProbeWorkflow::class,
            'workflow_type' => 'job-guard-probe',
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => JobGuardProbeWorkflow::class,
            'workflow_type' => 'job-guard-probe',
            'namespace' => 'job-guards',
            'status' => $status,
            'payload_codec' => 'avro',
            'arguments' => Serializer::serializeWithCodec('avro', []),
            'output_payload_codec' => $status === RunStatus::Completed ? 'avro' : null,
            'output' => $status === RunStatus::Completed ? Serializer::serializeWithCodec('avro', [
                'stored' => true,
            ]) : null,
            'compatibility' => 'build-a',
            'connection' => 'redis',
            'queue' => 'workflows',
            'started_at' => now()
                ->subMinute(),
            'closed_at' => $status === RunStatus::Completed ? now() : null,
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }

    private function createTask(
        WorkflowRun $run,
        TaskType $type = TaskType::Workflow,
        TaskStatus $status = TaskStatus::Ready,
    ): WorkflowTask {
        return WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => $type,
            'status' => $status,
            'payload' => [
                'recorded' => true,
            ],
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

final class JobGuardProbeWorkflow extends Workflow
{
    public static int $calls = 0;

    public function handle(): string
    {
        ++self::$calls;

        return 'unexpected execution';
    }
}
