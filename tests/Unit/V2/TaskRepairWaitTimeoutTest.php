<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\TaskRepair;

final class TaskRepairWaitTimeoutTest extends TestCase
{
    #[DataProvider('waitTimeouts')]
    public function testMissingTimeoutWorkIsRecoveredWithTheMatchingWaitMetadata(
        string $kind,
        string $timerState,
        ?string $openWaitId,
    ): void {
        $this->travelTo(Carbon::parse('2026-01-01T00:00:00Z'));
        $run = $this->createRun();
        $this->recordClosedWait($run, $kind);
        $metadata = $this->waitMetadata($kind, 'active-wait');
        WorkflowHistoryEvent::record($run, $this->openedEvent($kind), [
            ...$metadata,
            'sequence' => 7,
            'timeout_seconds' => 60,
        ]);
        $fireAt = $timerState === 'future' ? '2026-01-01T00:01:00Z' : '2025-12-31T23:59:00Z';
        $timerPayload = [
            'timer_id' => 'wait-timeout',
            'sequence' => 7,
            'timer_kind' => $kind . '_timeout',
            'delay_seconds' => 60,
            'fire_at' => $fireAt,
            ...$metadata,
        ];
        WorkflowHistoryEvent::record($run, HistoryEventType::TimerScheduled, $timerPayload);
        if ($timerState === 'fired') {
            WorkflowHistoryEvent::record($run, HistoryEventType::TimerFired, [
                ...$timerPayload,
                'fired_at' => '2025-12-31T23:59:30Z',
            ]);
        }
        $history = $run->historyEvents()
            ->get()
            ->toArray();
        $before = $run->fresh()
            ->getAttributes();
        $summary = $this->summary($kind, $openWaitId, 'wait-timeout');

        $task = TaskRepair::repairRun($run->fresh(), $summary);

        $this->assertInstanceOf(WorkflowTask::class, $task);
        $task->refresh();
        $expected = [
            'timer_id' => 'wait-timeout',
            ...$metadata,
        ];
        if ($timerState === 'fired') {
            $expected += [
                'workflow_wait_kind' => $kind,
                'open_wait_id' => 'active-wait',
                'resume_source_kind' => 'timer',
                'resume_source_id' => 'wait-timeout',
                'workflow_sequence' => 7,
            ];
        }
        ksort($expected);
        $actual = $task->payload;
        ksort($actual);
        $this->assertSame($expected, $actual);
        $this->assertSame($timerState === 'fired' ? TaskType::Workflow : TaskType::Timer, $task->task_type);
        $this->assertSame(match ($timerState) {
            'future' => '2026-01-01T00:01:00+00:00',
            'past' => '2026-01-01T00:00:00+00:00',
            'fired' => '2025-12-31T23:59:30+00:00',
        }, $task->available_at?->toIso8601String());
        $this->assertRouting($run, $task);
        $timer = WorkflowTimer::query()->findOrFail('wait-timeout');
        $this->assertSame($run->id, $timer->workflow_run_id);
        $this->assertSame(7, $timer->sequence);
        $this->assertSame($timerState === 'fired' ? TimerStatus::Fired : TimerStatus::Pending, $timer->status);
        $this->assertSame(60, $timer->delay_seconds);
        $this->assertSame(Carbon::parse($fireAt)->toIso8601String(), $timer->fire_at?->toIso8601String());
        $this->assertSame(
            $timerState === 'fired' ? '2025-12-31T23:59:30+00:00' : null,
            $timer->fired_at?->toIso8601String(),
        );
        $this->assertSame($history, $run->historyEvents()->get()->toArray());
        $this->assertSame($before, $run->fresh()->getAttributes());
        $this->assertSame(1, $run->tasks()->count());
        $this->assertSame(1, $run->timers()->count());
        $this->assertSame('active-wait', $task->payload[$kind . '_wait_id']);
    }

    public static function waitTimeouts(): array
    {
        $cases = [];
        foreach (['condition', 'signal'] as $kind) {
            foreach (['future', 'past', 'fired'] as $state) {
                foreach ([
                    'matching wait' => 'active-wait',
                    'unknown wait' => 'missing',
                    'no wait ID' => null,
                ] as $label => $id) {
                    $cases[$kind . ' / ' . $state . ' / ' . $label] = [$kind, $state, $id];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('missingTimerReferences')]
    public function testUnmatchedTimeoutReferenceLeavesWorkflowContinuationWithoutInventingATimer(
        string $kind,
        ?string $timerId,
    ): void {
        $run = $this->createRun();
        $this->recordClosedWait($run, $kind);
        WorkflowHistoryEvent::record($run, $this->openedEvent($kind), [
            ...$this->waitMetadata($kind, 'active-wait'),
            'sequence' => 7,
        ]);
        $history = $run->historyEvents()
            ->get()
            ->toArray();
        $before = $run->fresh()
            ->getAttributes();

        $task = TaskRepair::repairRun($run->fresh(), $this->summary($kind, 'missing', $timerId));

        $this->assertInstanceOf(WorkflowTask::class, $task);
        $task->refresh();
        $this->assertSame(TaskType::Workflow, $task->task_type);
        $this->assertSame($kind === 'condition' ? [] : array_filter([
            'workflow_wait_kind' => 'signal',
            'open_wait_id' => 'missing',
            'resume_source_kind' => 'timer',
            'resume_source_id' => $timerId,
        ], static fn (mixed $value): bool => $value !== null), $task->payload);
        $this->assertRouting($run, $task);
        $this->assertSame(0, $run->timers()->count());
        $this->assertSame(1, $run->tasks()->count());
        $this->assertSame($history, $run->historyEvents()->get()->toArray());
        $this->assertSame($before, $run->fresh()->getAttributes());
    }

    public static function missingTimerReferences(): array
    {
        return [
            'condition / no timer ID' => ['condition', null],
            'condition / unknown timer ID' => ['condition', 'missing-timer'],
            'signal / no timer ID' => ['signal', null],
            'signal / unknown timer ID' => ['signal', 'missing-timer'],
        ];
    }

    private function recordClosedWait(WorkflowRun $run, string $kind): void
    {
        $payload = [
            ...$this->waitMetadata($kind, 'closed-wait'),
            'sequence' => 1,
        ];
        WorkflowHistoryEvent::record($run, $this->openedEvent($kind), $payload);
        WorkflowHistoryEvent::record($run, $kind === 'condition'
            ? HistoryEventType::ConditionWaitCancelled
            : HistoryEventType::SignalWaitCancelled, $payload);
    }

    private function waitMetadata(string $kind, string $id): array
    {
        return $kind === 'condition' ? [
            'condition_wait_id' => $id,
            'condition_key' => 'ready',
            'condition_definition_fingerprint' => 'ready-definition-v1',
        ] : [
            'signal_wait_id' => $id,
            'signal_name' => 'approval',
        ];
    }

    private function openedEvent(string $kind): HistoryEventType
    {
        return $kind === 'condition' ? HistoryEventType::ConditionWaitOpened : HistoryEventType::SignalWaitOpened;
    }

    private function summary(string $kind, ?string $waitId, ?string $timerId): WorkflowRunSummary
    {
        return new WorkflowRunSummary([
            'liveness_state' => 'repair_needed',
            'wait_kind' => $kind,
            'open_wait_id' => $waitId,
            'resume_source_kind' => 'timer',
            'resume_source_id' => $timerId,
        ]);
    }

    private function assertRouting(WorkflowRun $run, WorkflowTask $task): void
    {
        $this->assertSame($run->id, $task->workflow_run_id);
        $this->assertSame('wait-tenant', $task->namespace);
        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame('redis', $task->connection);
        $this->assertSame('workflow', $task->queue);
        $this->assertSame('wait-recovery-v1', $task->compatibility);
        $this->assertSame(1, $task->repair_count);
        $this->assertSame(0, $task->attempt_count);
        $this->assertNull($task->lease_owner);
        $this->assertNull($task->lease_expires_at);
    }

    private function createRun(): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'wait-recovery-' . strtolower((string) Str::ulid()),
            'namespace' => 'wait-tenant',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => TestGreetingWorkflow::class,
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'namespace' => 'wait-tenant',
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => TestGreetingWorkflow::class,
            'status' => RunStatus::Waiting,
            'connection' => 'redis',
            'queue' => 'workflow',
            'compatibility' => 'wait-recovery-v1',
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }
}
