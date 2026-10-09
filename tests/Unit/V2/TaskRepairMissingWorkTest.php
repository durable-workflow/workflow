<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\ActivityRecovery;
use Workflow\V2\Support\TaskRepair;
use Workflow\V2\Support\TimerRecovery;

final class TaskRepairMissingWorkTest extends TestCase
{
    #[DataProvider('timerReferences')]
    public function testMissingTimerTaskUsesOnlyThePendingOrdinaryTimer(?string $reference): void
    {
        $this->travelTo(Carbon::parse('2026-01-01T00:00:00Z'));
        $run = $this->createRun();
        $this->scheduledTimer($run, 'condition-timeout', 1, 'condition_timeout');
        $this->scheduledTimer($run, 'signal-timeout', 2, 'signal_timeout');
        $this->scheduledTimer($run, 'fired', 3);
        WorkflowHistoryEvent::record($run, HistoryEventType::TimerFired, [
            'timer_id' => 'fired',
            'fired_at' => now()
                ->toIso8601String(),
        ]);
        $this->scheduledTimer($run, 'cancelled', 4);
        WorkflowHistoryEvent::record($run, HistoryEventType::TimerCancelled, [
            'timer_id' => 'cancelled',
            'cancelled_at' => now()
                ->toIso8601String(),
        ]);
        $this->scheduledTimer($run, 'ordinary-pending', 5);
        $history = $run->historyEvents()
            ->get()
            ->toArray();
        $before = $run->fresh()
            ->getAttributes();

        $task = TaskRepair::repairRun($run->fresh(), $this->summary('timer', $reference));

        $this->assertInstanceOf(WorkflowTask::class, $task);
        $this->assertSame(TaskType::Timer, $task->task_type);
        $this->assertSame([
            'timer_id' => 'ordinary-pending',
        ], $task->payload);
        $this->assertSame('2026-01-01T00:01:00+00:00', $task->available_at?->toIso8601String());
        $this->assertTaskRouting($run, $task);
        $timer = WorkflowTimer::query()->findOrFail('ordinary-pending');
        $this->assertSame($run->id, $timer->workflow_run_id);
        $this->assertSame(5, $timer->sequence);
        $this->assertSame(TimerStatus::Pending, $timer->status);
        $this->assertSame(60, $timer->delay_seconds);
        $this->assertSame($history, $run->historyEvents()->get()->toArray());
        $this->assertSame($before, $run->fresh()->getAttributes());
        $this->assertFalse(
            WorkflowTimer::query()->whereIn('id', ['condition-timeout', 'signal-timeout', 'cancelled'])->exists()
        );
        $this->assertSame(1, $run->tasks()->count());
    }

    public static function timerReferences(): array
    {
        return [
            'selected pending timer' => ['ordinary-pending'],
            'no resume ID' => [null],
            'unknown resume ID' => ['missing'],
            'completed timer ID' => ['fired'],
        ];
    }

    public function testTimeoutTimersDoNotSubstituteForMissingOrdinaryWork(): void
    {
        $run = $this->createRun();
        $this->scheduledTimer($run, 'condition-timeout', 1, 'condition_timeout');
        $this->scheduledTimer($run, 'signal-timeout', 2, 'signal_timeout');
        $history = $run->historyEvents()
            ->get()
            ->toArray();

        $task = TaskRepair::repairRun($run->fresh(), $this->summary('timer', null));

        $this->assertInstanceOf(WorkflowTask::class, $task);
        $this->assertSame(TaskType::Workflow, $task->task_type);
        $this->assertSame([], $task->payload);
        $this->assertTaskRouting($run, $task);
        $this->assertSame(0, $run->timers()->count());
        $this->assertSame($history, $run->historyEvents()->get()->toArray());
    }

    public function testRecoveryFindsAnOwnTimerCreatedAfterTheRelationWasLoaded(): void
    {
        $run = $this->createRun();
        $run->load('timers');
        $timer = WorkflowTimer::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 7,
            'status' => TimerStatus::Pending,
            'delay_seconds' => 60,
            'fire_at' => now()
                ->addMinute(),
        ]);
        $before = $timer->fresh()
            ->getAttributes();

        $restored = TimerRecovery::restore($run, $timer->id);

        $this->assertInstanceOf(WorkflowTimer::class, $restored);
        $this->assertSame($timer->id, $restored->id);
        $this->assertSame($run->id, $restored->workflow_run_id);
        $this->assertSame($before, $restored->getAttributes());
        $this->assertSame(1, $run->timers()->count());
        $this->assertSame(0, $run->tasks()->count());
    }

    #[DataProvider('retryMetadata')]
    public function testMissingActivityTaskPreservesTheLatestMatchingRetry(
        mixed $availableAt,
        bool $explicitPolicy,
        bool $activityRouting,
    ): void {
        $this->travelTo(Carbon::parse('2026-01-01T00:00:00Z'));
        $run = $this->createRun();
        $policy = [
            'max_attempts' => 4,
            'backoff_seconds' => 10,
        ];
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 7,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => TestGreetingActivity::class,
            'status' => ActivityStatus::Pending,
            'attempt_count' => 2,
            'retry_policy' => $policy,
            'connection' => $activityRouting ? 'redis' : null,
            'queue' => $activityRouting ? 'activity-queue' : null,
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityRetryScheduled, [
            'activity_execution_id' => $execution->id,
            'retry_after_attempt' => 1,
            'retry_available_at' => '2026-01-01T00:10:00Z',
        ]);
        $metadata = [
            'retry_of_task_id' => 'original-task',
            'retry_after_attempt_id' => null,
            'retry_after_attempt' => 2,
            'retry_backoff_seconds' => 0,
            'max_attempts' => 4,
        ];
        if ($explicitPolicy) {
            $metadata['retry_policy'] = [
                'max_attempts' => 4,
                'backoff_seconds' => 0,
            ];
        }
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityRetryScheduled, [
            'activity_execution_id' => $execution->id,
            'retry_available_at' => $availableAt,
            ...$metadata,
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityRetryScheduled, [
            'activity_execution_id' => 'unrelated-activity',
            'retry_after_attempt' => 99,
            'retry_available_at' => '2026-01-01T00:20:00Z',
        ]);
        $history = $run->historyEvents()
            ->get()
            ->toArray();
        $before = $execution->fresh()
            ->getAttributes();

        $task = TaskRepair::repairRun($run->fresh(), $this->summary('activity', $execution->id));

        $this->assertInstanceOf(WorkflowTask::class, $task);
        $this->assertSame(TaskType::Activity, $task->task_type);
        $expected = [
            'activity_execution_id' => $execution->id,
            ...$metadata,
        ];
        $expected['retry_policy'] ??= $policy;
        ksort($expected);
        $actual = $task->payload;
        ksort($actual);
        $this->assertSame($expected, $actual);
        $this->assertSame(
            $availableAt === '2026-01-01T00:02:00Z' ? '2026-01-01T00:02:00+00:00' : '2026-01-01T00:00:00+00:00',
            $task->available_at?->toIso8601String(),
        );
        $this->assertTaskRouting($run, $task, $activityRouting ? 'activity-queue' : 'workflow-queue');
        $this->assertSame(2, $task->attempt_count);
        $this->assertSame($before, $execution->fresh()->getAttributes());
        $this->assertSame($history, $run->historyEvents()->get()->toArray());
        $this->assertSame(1, $run->tasks()->count());
    }

    public static function retryMetadata(): array
    {
        return [
            'future retry / explicit policy / activity queue' => ['2026-01-01T00:02:00Z', true, true],
            'future retry / retained policy / workflow queue' => ['2026-01-01T00:02:00Z', false, false],
            'invalid date / retained policy' => ['not-a-date', false, true],
            'empty date / explicit policy' => ['', true, false],
            'null date / retained policy' => [null, false, false],
            'non-string date / explicit policy' => [false, true, true],
        ];
    }

    #[DataProvider('activityReferences')]
    public function testMissingActivityCanBeRestoredFromItsOwnScheduledHistory(?string $reference): void
    {
        $this->travelTo(Carbon::parse('2026-01-01T00:00:00Z'));
        $run = $this->createRun();
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => 'already-completed',
            'sequence' => 1,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => TestGreetingActivity::class,
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityCompleted, [
            'activity_execution_id' => 'already-completed',
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => 'pending-activity',
            'sequence' => 2,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => TestGreetingActivity::class,
            'activity' => [
                'connection' => 'redis',
                'queue' => 'restored-activity-queue',
                'retry_policy' => [
                    'max_attempts' => 3,
                ],
            ],
        ]);
        $history = $run->historyEvents()
            ->get()
            ->toArray();

        $task = TaskRepair::repairRun($run->fresh(), $this->summary('activity', $reference));

        $this->assertInstanceOf(WorkflowTask::class, $task);
        $this->assertSame(TaskType::Activity, $task->task_type);
        $this->assertSame([
            'activity_execution_id' => 'pending-activity',
        ], $task->payload);
        $this->assertTaskRouting($run, $task, 'restored-activity-queue');
        $this->assertSame(0, $task->attempt_count);
        $execution = ActivityExecution::query()->findOrFail('pending-activity');
        $this->assertSame($run->id, $execution->workflow_run_id);
        $this->assertSame(2, $execution->sequence);
        $this->assertSame(ActivityStatus::Pending, $execution->status);
        $this->assertSame(TestGreetingActivity::class, $execution->activity_class);
        $this->assertSame(TestGreetingActivity::class, $execution->activity_type);
        $this->assertSame([
            'max_attempts' => 3,
        ], $execution->retry_policy);
        $this->assertNull($execution->arguments);
        $this->assertSame('2026-01-01T00:00:00+00:00', $execution->created_at?->toIso8601String());
        $this->assertSame($history, $run->historyEvents()->get()->toArray());
        $this->assertSame(1, $run->activityExecutions()->count());
        $before = $execution->getAttributes();
        $restoredAgain = ActivityRecovery::restore($run->fresh(), 'pending-activity');
        $this->assertInstanceOf(ActivityExecution::class, $restoredAgain);
        $this->assertSame($before, $restoredAgain->getAttributes());
        $this->assertSame(1, $run->activityExecutions()->count());
    }

    public static function activityReferences(): array
    {
        return [
            'explicit pending' => ['pending-activity'],
            'missing reference' => [null],
            'unknown reference' => ['missing'],
            'completed reference' => ['already-completed'],
        ];
    }

    #[DataProvider('incompleteActivityHistory')]
    public function testIncompleteActivityHistoryCannotCreateAnExecution(array $payload): void
    {
        $run = $this->createRun();
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => 'incomplete-activity',
            ...$payload,
        ]);
        $history = $run->historyEvents()
            ->get()
            ->toArray();

        $this->assertNull(ActivityRecovery::restore($run->fresh(), 'incomplete-activity'));
        $this->assertSame(0, $run->activityExecutions()->count());
        $this->assertSame(0, $run->tasks()->count());
        $this->assertSame($history, $run->historyEvents()->get()->toArray());
    }

    public static function incompleteActivityHistory(): array
    {
        return [
            'no command sequence' => [[
                'activity_class' => TestGreetingActivity::class,
                'activity_type' => TestGreetingActivity::class,
            ]],
            'no activity class' => [[
                'sequence' => 1,
                'activity_type' => TestGreetingActivity::class,
            ]],
            'no activity type' => [[
                'sequence' => 1,
                'activity_class' => TestGreetingActivity::class,
            ]],
        ];
    }

    private function scheduledTimer(WorkflowRun $run, string $id, int $sequence, ?string $kind = null): void
    {
        WorkflowHistoryEvent::record($run, HistoryEventType::TimerScheduled, array_filter([
            'timer_id' => $id,
            'sequence' => $sequence,
            'delay_seconds' => 60,
            'fire_at' => '2026-01-01T00:01:00Z',
            'timer_kind' => $kind,
        ], static fn (mixed $value): bool => $value !== null));
    }

    private function summary(string $waitKind, ?string $id): WorkflowRunSummary
    {
        return new WorkflowRunSummary([
            'liveness_state' => 'repair_needed',
            'wait_kind' => $waitKind,
            'resume_source_kind' => $waitKind,
            'resume_source_id' => $id,
        ]);
    }

    private function assertTaskRouting(WorkflowRun $run, WorkflowTask $task, string $queue = 'workflow-queue'): void
    {
        $this->assertSame($run->id, $task->workflow_run_id);
        $this->assertSame('recovery-tenant', $task->namespace);
        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame('redis', $task->connection);
        $this->assertSame($queue, $task->queue);
        $this->assertSame('recovery-v1', $task->compatibility);
        $this->assertSame(1, $task->repair_count);
        $this->assertNull($task->lease_owner);
        $this->assertNull($task->lease_expires_at);
    }

    private function createRun(): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'missing-work-' . strtolower((string) Str::ulid()),
            'namespace' => 'recovery-tenant',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => TestGreetingWorkflow::class,
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'namespace' => 'recovery-tenant',
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => TestGreetingWorkflow::class,
            'status' => RunStatus::Waiting,
            'connection' => 'redis',
            'queue' => 'workflow-queue',
            'compatibility' => 'recovery-v1',
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }
}
