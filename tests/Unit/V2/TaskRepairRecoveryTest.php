<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\TaskRepair;

final class TaskRepairRecoveryTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('candidateOrder')]
    public function testRepairSelectsTheOldestEligibleTaskAndPreservesItsSibling(
        array $available,
        array $created,
        int $winner,
    ): void {
        $run = $this->createRun();
        $tasks = [];
        foreach ([1, 0] as $index) {
            $tasks[$index] = $this->task($run, [
                'id' => '01J0000000000000000000000' . ($index + 1),
                'available_at' => $available[$index] === null ? null : now()->addSeconds($available[$index]),
                'created_at' => now()
                    ->addSeconds($created[$index] ?? 0),
            ]);
            if ($created[$index] === null) {
                DB::table('workflow_tasks')->where('id', $tasks[$index]->id)->update([
                    'created_at' => null,
                ]);
            }
        }
        $other = $tasks[1 - $winner]->fresh()->getAttributes();
        $runBefore = $run->fresh()
            ->getAttributes();
        $repaired = TaskRepair::repairRun($run->fresh(), new WorkflowRunSummary([
            'liveness_state' => 'healthy',
        ]));

        $this->assertInstanceOf(WorkflowTask::class, $repaired);
        $this->assertSame($tasks[$winner]->id, $repaired->id);
        $this->assertSame(TaskStatus::Ready, $repaired->fresh()->status);
        $this->assertSame('repair-tenant', $repaired->fresh()->namespace);
        $this->assertSame(3, $repaired->fresh()->repair_count);
        $this->assertNull($repaired->fresh()->last_error);
        $this->assertNull($repaired->fresh()->repair_available_at);
        $this->assertSame($other, $tasks[1 - $winner]->fresh()->getAttributes());
        $this->assertSame($runBefore, $run->fresh()->getAttributes());
        $this->assertSame(2, WorkflowTask::query()->count());
        $this->assertSame(0, DB::table('workflow_history_events')->count());
    }

    public static function candidateOrder(): array
    {
        return [
            'earlier availability' => [[-10, -20], [-40, -30], 1],
            'missing availability sorts last' => [[null, -20], [-40, -30], 1],
            'equal availability uses creation time' => [[-20, -20], [-40, -30], 0],
            'missing creation time sorts last' => [[-20, -20], [null, -30], 1],
            'equal timestamps use stable task identity' => [[-20, -20], [-30, -30], 0],
            'legacy missing timestamps use stable identity' => [[null, null], [null, null], 0],
        ];
    }

    #[DataProvider('terminalRuns')]
    public function testTerminalRunSettlesTheTaskWithoutRedispatchOrNewWork(
        RunStatus $runStatus,
        TaskStatus $taskStatus,
        TaskStatus $settled,
    ): void {
        $run = $this->createRun($runStatus);
        $task = $this->task($run, [
            'status' => $taskStatus,
        ]);
        $runBefore = $run->fresh()
            ->getAttributes();

        $this->assertNull(TaskRepair::recoverExistingTask($task->fresh(), $run->fresh()));
        $task = $task->fresh();
        $this->assertSame($settled, $task->status);
        $this->assertNull($task->leased_at);
        $this->assertNull($task->lease_owner);
        $this->assertNull($task->lease_expires_at);
        $this->assertSame(2, $task->repair_count);
        $this->assertSame('original-error', $task->last_error);
        $this->assertSame([
            'business' => false,
        ], $task->payload);
        $this->assertSame($runBefore, $run->fresh()->getAttributes());
        $this->assertSame(1, WorkflowTask::query()->count());
        $this->assertSame(0, ActivityAttempt::query()->count());
        $this->assertSame(0, DB::table('workflow_history_events')->count());
    }

    public static function terminalRuns(): iterable
    {
        foreach ([RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled, RunStatus::Terminated] as $run) {
            yield $run->value . ' leased' => [
                $run, TaskStatus::Leased, $run === RunStatus::Failed ? TaskStatus::Failed : TaskStatus::Completed,
            ];
            yield $run->value . ' already cancelled' => [$run, TaskStatus::Cancelled, TaskStatus::Cancelled];
        }
    }

    #[DataProvider('healthyTasks')]
    public function testHealthyOrUnrecoverableTaskIsUnchanged(TaskStatus $status, string $condition): void
    {
        $run = $this->createRun();
        $attributes = [
            'status' => $status,
        ];
        if ($condition === 'future') {
            $attributes['available_at'] = now()->addMinute();
        } elseif ($condition === 'recent') {
            $attributes['last_dispatched_at'] = now();
        } elseif ($condition === 'current-lease') {
            $attributes['lease_expires_at'] = now()->addMinute();
        }
        $task = $this->task($run, $attributes);
        $before = $task->fresh()
            ->getAttributes();
        $runBefore = $run->fresh()
            ->getAttributes();

        $this->assertNull(TaskRepair::recoverExistingTask($task->fresh(), $run->fresh()));
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame($runBefore, $run->fresh()->getAttributes());
        $this->assertSame(1, WorkflowTask::query()->count());
        $this->assertSame(0, DB::table('workflow_history_events')->count());
    }

    public static function healthyTasks(): array
    {
        return [
            'future ready' => [TaskStatus::Ready, 'future'],
            'recently dispatched ready' => [TaskStatus::Ready, 'recent'],
            'unexpired lease' => [TaskStatus::Leased, 'current-lease'],
            'ordinary failure' => [TaskStatus::Failed, 'ordinary'],
            'completed task' => [TaskStatus::Completed, 'ordinary'],
            'cancelled task' => [TaskStatus::Cancelled, 'ordinary'],
        ];
    }

    public function testReplayBlockedRecoveryRemovesOnlyItsDiagnosticsAndRetainsBusinessPayload(): void
    {
        $run = $this->createRun();
        $task = $this->task($run, [
            'status' => TaskStatus::Failed,
            'payload' => [
                'replay_blocked' => true,
                'replay_blocked_reason' => 'temporary-build-mismatch',
                'replay_blocked_at' => now()
                    ->toISOString(),
                'business' => false,
                'attempt' => 0,
                'optional' => null,
                'replay_context' => [
                    'build' => 'build-a',
                ],
            ],
        ]);
        $runBefore = $run->fresh()
            ->getAttributes();
        $repaired = TaskRepair::recoverExistingTask($task->fresh(), $run->fresh());

        $this->assertInstanceOf(WorkflowTask::class, $repaired);
        $this->assertSame($task->id, $repaired->id);
        $this->assertSame(TaskStatus::Ready, $repaired->fresh()->status);
        $payload = $repaired->fresh()
->payload;
        ksort($payload);
        $this->assertSame([
            'attempt' => 0,
            'business' => false,
            'optional' => null,
            'replay_context' => [
                'build' => 'build-a',
            ],
        ], $payload);
        $this->assertSame(3, $repaired->fresh()->repair_count);
        $this->assertNull($repaired->fresh()->lease_owner);
        $this->assertNull($repaired->fresh()->lease_expires_at);
        $this->assertNull($repaired->fresh()->repair_available_at);
        $this->assertNull($repaired->fresh()->last_error);
        $this->assertSame($runBefore, $run->fresh()->getAttributes());
        $this->assertSame(1, WorkflowTask::query()->count());
        $this->assertSame(0, DB::table('workflow_history_events')->count());
    }

    #[DataProvider('expiredActivityStates')]
    public function testExpiredActivityLeaseClosesOnlyARunningAttempt(
        ActivityStatus $executionStatus,
        ?ActivityAttemptStatus $attemptStatus,
        ?ActivityAttemptStatus $expected,
    ): void {
        $run = $this->createRun();
        $task = $this->task($run, [
            'task_type' => TaskType::Activity,
            'status' => TaskStatus::Leased,
        ]);
        [$execution, $attempt] = $this->activity($run, $task, $executionStatus, $attemptStatus);
        $executionBefore = $execution->fresh()
            ->getAttributes();
        $attemptBefore = $attempt?->fresh()
            ->getAttributes();
        $runBefore = $run->fresh()
            ->getAttributes();
        $repaired = TaskRepair::recoverExistingTask($task->fresh(), $run->fresh());

        $this->assertInstanceOf(WorkflowTask::class, $repaired);
        $this->assertSame($task->id, $repaired->id);
        $this->assertSame(TaskStatus::Ready, $repaired->fresh()->status);
        $this->assertNull($repaired->fresh()->lease_owner);
        $this->assertNull($repaired->fresh()->leased_at);
        $this->assertNull($repaired->fresh()->lease_expires_at);
        $this->assertSame(3, $repaired->fresh()->repair_count);
        $this->assertSame($executionBefore, $execution->fresh()->getAttributes());
        if ($expected === null) {
            $this->assertSame(0, ActivityAttempt::query()->count());
        } else {
            $this->assertNotNull($attempt);
            $this->assertSame($expected, $attempt->fresh()->status);
            if ($attemptStatus === ActivityAttemptStatus::Running) {
                $this->assertNull($attempt->fresh()->lease_expires_at);
                $this->assertTrue($attempt->fresh()->closed_at->equalTo(now()));
                $this->assertSame('original-owner', $attempt->fresh()->lease_owner);
            } else {
                $this->assertSame($attemptBefore, $attempt->fresh()->getAttributes());
            }
            $this->assertSame(1, ActivityAttempt::query()->count());
        }
        $this->assertSame($runBefore, $run->fresh()->getAttributes());
        $this->assertSame(0, DB::table('workflow_history_events')->count());
    }

    public static function expiredActivityStates(): array
    {
        return [
            'running attempt expires' => [
                ActivityStatus::Running, ActivityAttemptStatus::Running, ActivityAttemptStatus::Expired,
            ],
            'completed attempt is preserved' => [
                ActivityStatus::Completed, ActivityAttemptStatus::Completed, ActivityAttemptStatus::Completed,
            ],
            'unstarted activity has no attempt to close' => [ActivityStatus::Pending, null, null],
        ];
    }

    #[DataProvider('terminalActivityRuns')]
    public function testCancelledOrTerminatedRunClosesRunningActivityAttempt(RunStatus $status): void
    {
        $run = $this->createRun($status);
        $task = $this->task($run, [
            'task_type' => TaskType::Activity,
            'status' => TaskStatus::Leased,
        ]);
        [$execution, $attempt] = $this->activity(
            $run,
            $task,
            ActivityStatus::Running,
            ActivityAttemptStatus::Running,
        );
        $this->assertNotNull($attempt);
        $executionBefore = $execution->fresh()
            ->getAttributes();
        $runBefore = $run->fresh()
            ->getAttributes();

        $this->assertNull(TaskRepair::recoverExistingTask($task->fresh(), $run->fresh()));
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        $this->assertNull($task->fresh()->lease_owner);
        $this->assertNull($task->fresh()->lease_expires_at);
        $this->assertSame(ActivityAttemptStatus::Cancelled, $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->lease_expires_at);
        $this->assertTrue($attempt->fresh()->closed_at->equalTo(now()));
        $this->assertSame($executionBefore, $execution->fresh()->getAttributes());
        $this->assertSame($runBefore, $run->fresh()->getAttributes());
        $this->assertSame(1, ActivityAttempt::query()->count());
        $this->assertSame(0, DB::table('workflow_history_events')->count());
    }

    public static function terminalActivityRuns(): array
    {
        return [
            'cancelled' => [RunStatus::Cancelled],
            'terminated' => [RunStatus::Terminated],
        ];
    }

    private function createRun(RunStatus $status = RunStatus::Waiting): WorkflowRun
    {
        Carbon::setTestNow('2026-10-09 03:00:00 UTC');
        $instance = WorkflowInstance::query()->create([
            'id' => 'task-repair-' . strtolower((string) Str::ulid()),
            'namespace' => 'repair-tenant',
            'workflow_class' => 'App\\Workflows\\RepairWorkflow',
            'workflow_type' => 'repair.workflow',
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'namespace' => 'repair-tenant',
            'run_number' => 1,
            'workflow_class' => $instance->workflow_class,
            'workflow_type' => $instance->workflow_type,
            'status' => $status,
            'connection' => 'redis',
            'queue' => 'workflow',
            'compatibility' => 'build-a',
            'started_at' => now()
                ->subMinute(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }

    private function task(WorkflowRun $run, array $attributes = []): WorkflowTask
    {
        return WorkflowTask::query()->create($attributes + [
            'workflow_run_id' => $run->id,
            'namespace' => null,
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Ready,
            'available_at' => now()
                ->subMinute(),
            'last_dispatched_at' => now()
                ->subMinute(),
            'leased_at' => now()
                ->subMinute(),
            'lease_owner' => 'original-owner',
            'lease_expires_at' => now()
                ->subSecond(),
            'repair_count' => 2,
            'repair_available_at' => now()
                ->subSecond(),
            'last_error' => 'original-error',
            'connection' => $run->connection,
            'queue' => $run->queue,
            'compatibility' => $run->compatibility,
            'payload' => [
                'business' => false,
            ],
        ]);
    }

    /**
     * @return array{ActivityExecution, ?ActivityAttempt}
     */
    private function activity(
        WorkflowRun $run,
        WorkflowTask $task,
        ActivityStatus $status,
        ?ActivityAttemptStatus $attemptStatus,
    ): array {
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'App\\Activities\\RepairActivity',
            'activity_type' => 'repair.activity',
            'status' => $status,
            'attempt_count' => $attemptStatus === null ? 0 : 1,
            'started_at' => $attemptStatus === null ? null : now()
                ->subMinute(),
            'closed_at' => $status === ActivityStatus::Completed ? now()->subSecond() : null,
        ]);
        $task->forceFill([
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
        ])->save();
        $attempt = $attemptStatus === null ? null : ActivityAttempt::query()->create([
            'workflow_run_id' => $run->id,
            'activity_execution_id' => $execution->id,
            'workflow_task_id' => $task->id,
            'attempt_number' => 1,
            'status' => $attemptStatus,
            'lease_owner' => 'original-owner',
            'lease_expires_at' => $task->lease_expires_at,
            'started_at' => now()
                ->subMinute(),
            'closed_at' => $attemptStatus === ActivityAttemptStatus::Completed ? now()->subSecond() : null,
        ]);
        if ($attempt !== null) {
            $execution->forceFill([
                'current_attempt_id' => $attempt->id,
            ])->save();
        }

        return [$execution, $attempt];
    }
}
