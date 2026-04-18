<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestAwaitSignalTimeoutWorkflow;
use Tests\Fixtures\V2\TestAwaitWithTimeoutWorkflow;
use Tests\Fixtures\V2\TestAwaitWorkflow;
use Tests\Fixtures\V2\TestKeyedAwaitWithTimeoutWorkflow;
use Tests\Fixtures\V2\TestKeyedAwaitWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Exceptions\ConditionWaitDefinitionMismatchException;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\WorkflowStub;

final class V2AwaitWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'redis');
    }

    public function testAwaitWorkflowRecordsConditionWaitAndResumesAfterUpdate(): void
    {
        Queue::fake();
        config()
            ->set('workflows.v2.history_budget.continue_as_new_event_threshold', 3);
        config()
            ->set('workflows.v2.history_budget.continue_as_new_size_bytes_threshold', 1000000);

        $workflow = WorkflowStub::make(TestAwaitWorkflow::class, 'await-update');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame('condition', $workflow->summary()?->wait_kind);
        $this->assertSame('Waiting for condition', $workflow->summary()?->wait_reason);
        $this->assertSame(3, $workflow->summary()?->history_event_count);
        $this->assertGreaterThan(0, $workflow->summary()?->history_size_bytes);
        $this->assertTrue($workflow->summary()?->continue_as_new_recommended);

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));
        $conditionWait = $this->findConditionWait($detail['waits']);

        $this->assertSame('condition', $detail['wait_kind']);
        $this->assertSame(3, $detail['history_event_count']);
        $this->assertGreaterThan(0, $detail['history_size_bytes']);
        $this->assertSame(3, $detail['history_event_threshold']);
        $this->assertSame(1000000, $detail['history_size_bytes_threshold']);
        $this->assertTrue($detail['continue_as_new_recommended']);
        $this->assertSame($conditionWait['condition_wait_id'], $detail['open_wait_id']);
        $this->assertSame('external_input', $detail['resume_source_kind']);
        $this->assertNull($detail['resume_source_id']);
        $this->assertSame('open', $conditionWait['status']);
        $this->assertSame('waiting', $conditionWait['source_status']);
        $this->assertTrue($conditionWait['external_only']);
        $this->assertFalse($conditionWait['task_backed']);
        $this->assertSame('Waiting for condition.', $conditionWait['summary']);

        $update = $workflow->attemptUpdate('approve', true);

        $this->assertTrue($update->accepted());
        $this->assertTrue($update->completed());
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'approved' => true,
            'stage' => 'completed',
            'workflow_id' => 'await-update',
            'run_id' => $workflow->runId(),
        ], $workflow->output());

        $this->assertSame(9, $workflow->summary()?->history_event_count);
        $this->assertTrue($workflow->summary()?->continue_as_new_recommended);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ConditionWaitOpened',
            'UpdateAccepted',
            'UpdateApplied',
            'UpdateCompleted',
            'MessageCursorAdvanced',
            'ConditionWaitSatisfied',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testConditionWaitProjectionIgnoresUnrelatedOpenWorkflowTaskRows(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestAwaitWorkflow::class, 'await-condition-stray-task');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()
            ->with('summary')
            ->findOrFail($workflow->runId());
        $detail = RunDetailView::forRun($run);
        $conditionWait = $this->findConditionWait($detail['waits']);

        /** @var WorkflowTask $strayTask */
        $strayTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now(),
            'payload' => [
                'resume_source_kind' => 'workflow_task',
                'resume_source_id' => 'unrelated',
            ],
            'connection' => $run->connection,
            'queue' => $run->queue,
            'compatibility' => $run->compatibility,
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        /** @var WorkflowRun $projectedRun */
        $projectedRun = WorkflowRun::query()
            ->with('summary')
            ->findOrFail($run->id);
        $summary = $projectedRun->summary;
        $projectedDetail = RunDetailView::forRun($projectedRun);
        $projectedConditionWait = $this->findConditionWait($projectedDetail['waits']);

        $this->assertSame('condition', $summary?->wait_kind);
        $this->assertSame('Waiting for condition', $summary?->wait_reason);
        $this->assertSame($conditionWait['condition_wait_id'], $summary?->open_wait_id);
        $this->assertSame('external_input', $summary?->resume_source_kind);
        $this->assertNull($summary?->resume_source_id);
        $this->assertSame('waiting_for_condition', $summary?->liveness_state);
        $this->assertNotSame('workflow-task:' . $strayTask->id, $summary?->open_wait_id);

        $this->assertSame('condition', $projectedDetail['wait_kind']);
        $this->assertSame($conditionWait['condition_wait_id'], $projectedDetail['open_wait_id']);
        $this->assertSame('external_input', $projectedDetail['resume_source_kind']);
        $this->assertNull($projectedDetail['resume_source_id']);
        $this->assertSame('waiting_for_condition', $projectedDetail['liveness_state']);
        $this->assertSame($conditionWait['condition_wait_id'], $projectedConditionWait['condition_wait_id']);
        $this->assertNotSame('workflow-task:' . $strayTask->id, $projectedDetail['open_wait_id']);
    }

    public function testKeyedAwaitWorkflowRecordsConditionKeyAndResumesAfterUpdate(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestKeyedAwaitWorkflow::class, 'await-keyed-update');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $this->assertSame('condition', $workflow->refresh()->summary()?->wait_kind);
        $this->assertSame('Waiting for condition approval.ready', $workflow->summary()?->wait_reason);

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));
        $conditionWait = $this->findConditionWait($detail['waits']);
        $conditionTimeline = collect($detail['timeline'])
            ->firstWhere('type', HistoryEventType::ConditionWaitOpened->value);

        $this->assertSame('approval.ready', $conditionWait['condition_key']);
        $this->assertIsString($conditionWait['condition_definition_fingerprint']);
        $this->assertSame('approval.ready', $conditionWait['target_name']);
        $this->assertSame('condition', $conditionWait['target_type']);
        $this->assertSame('approval.ready', $conditionTimeline['condition_key'] ?? null);
        $this->assertSame(
            $conditionWait['condition_definition_fingerprint'],
            $conditionTimeline['condition_definition_fingerprint'] ?? null,
        );
        $this->assertSame('Waiting for condition approval.ready.', $conditionTimeline['summary'] ?? null);

        /** @var WorkflowHistoryEvent $opened */
        $opened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::ConditionWaitOpened->value)
            ->firstOrFail();

        $this->assertSame('approval.ready', $opened->payload['condition_key'] ?? null);
        $this->assertSame(
            $conditionWait['condition_definition_fingerprint'],
            $opened->payload['condition_definition_fingerprint'] ?? null,
        );

        $update = $workflow->attemptUpdate('approve', true);

        $this->assertTrue($update->completed());
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'approved' => true,
        ], $workflow->output());

        /** @var WorkflowHistoryEvent $satisfied */
        $satisfied = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::ConditionWaitSatisfied->value)
            ->firstOrFail();

        $this->assertSame('approval.ready', $satisfied->payload['condition_key'] ?? null);
        $this->assertSame(
            $conditionWait['condition_definition_fingerprint'],
            $satisfied->payload['condition_definition_fingerprint'] ?? null,
        );
    }

    public function testKeyedAwaitWithTimeoutCarriesConditionKeyIntoTimeoutTransport(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestKeyedAwaitWithTimeoutWorkflow::class, 'await-keyed-timeout');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));
        $conditionWait = $this->findConditionWait($detail['waits']);
        $timerTask = $this->findTask($detail['tasks'], 'timer');

        $this->assertSame('Waiting for condition approval.ready or timeout', $detail['wait_reason']);
        $this->assertSame('approval.ready', $conditionWait['condition_key']);
        $this->assertIsString($conditionWait['condition_definition_fingerprint']);
        $this->assertSame('approval.ready', $conditionWait['target_name']);
        $this->assertSame('approval.ready', $timerTask['condition_key']);
        $this->assertSame(
            $conditionWait['condition_definition_fingerprint'],
            $timerTask['condition_definition_fingerprint'],
        );
        $this->assertSame('approval.ready', $detail['timers'][0]['condition_key']);
        $this->assertSame(
            $conditionWait['condition_definition_fingerprint'],
            $detail['timers'][0]['condition_definition_fingerprint'],
        );

        /** @var WorkflowHistoryEvent $timerScheduled */
        $timerScheduled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::TimerScheduled->value)
            ->firstOrFail();

        $this->assertSame('approval.ready', $timerScheduled->payload['condition_key'] ?? null);
        $this->assertSame(
            $conditionWait['condition_definition_fingerprint'],
            $timerScheduled->payload['condition_definition_fingerprint'] ?? null,
        );
    }

    public function testQueryReplayRejectsConditionPredicateFingerprintDrift(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestKeyedAwaitWorkflow::class, 'await-keyed-query-fingerprint-drift');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        /** @var WorkflowHistoryEvent $opened */
        $opened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::ConditionWaitOpened->value)
            ->firstOrFail();

        $payload = $opened->payload;
        $payload['condition_definition_fingerprint'] = 'sha256:' . str_repeat('0', 64);
        $opened->forceFill([
            'payload' => $payload,
        ])->save();

        $this->expectException(ConditionWaitDefinitionMismatchException::class);
        $this->expectExceptionMessage('predicate fingerprint');
        $this->expectExceptionMessage('sha256:' . str_repeat('0', 64));

        $workflow->refresh()
            ->currentState();
    }

    public function testQueryReplayRejectsConditionWaitKeyDrift(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestKeyedAwaitWorkflow::class, 'await-keyed-query-drift');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        /** @var WorkflowHistoryEvent $opened */
        $opened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::ConditionWaitOpened->value)
            ->firstOrFail();

        $payload = $opened->payload;
        $payload['condition_key'] = 'approval.changed';
        $opened->forceFill([
            'payload' => $payload,
        ])->save();

        $this->expectException(ConditionWaitDefinitionMismatchException::class);
        $this->expectExceptionMessage('approval.changed');

        $workflow->refresh()
            ->currentState();
    }

    public function testQueryReplayRejectsPreviouslyUnkeyedConditionWaitWhenCurrentYieldHasKey(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestKeyedAwaitWorkflow::class, 'await-unkeyed-query-drift');
        $workflow->start();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        WorkflowHistoryEvent::record($run, HistoryEventType::ConditionWaitOpened, [
            'condition_wait_id' => 'condition:1',
            'sequence' => 1,
        ]);

        $this->expectException(ConditionWaitDefinitionMismatchException::class);
        $this->expectExceptionMessage('recorded with condition key [none]');
        $this->expectExceptionMessage('current workflow yielded [approval.ready]');

        $workflow->refresh()
            ->currentState();
    }

    public function testQueryReplayRejectsConditionWaitWhenHistoryRecordedDifferentStepShape(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestAwaitWorkflow::class, 'await-query-history-shape-drift');
        $workflow->start();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        WorkflowHistoryEvent::record($run, HistoryEventType::TimerScheduled, [
            'timer_id' => 'timer-from-older-definition',
            'sequence' => 1,
            'delay_seconds' => 60,
            'fire_at' => now()
                ->addMinute()
                ->toJSON(),
        ]);

        $this->expectException(HistoryEventShapeMismatchException::class);
        $this->expectExceptionMessage('recorded [TimerScheduled]');
        $this->expectExceptionMessage('current workflow yielded condition wait');

        $workflow->refresh()
            ->currentState();
    }

    public function testWorkflowWorkerBlocksReplayWhenRecordedConditionKeyDoesNotMatchCurrentYield(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestKeyedAwaitWorkflow::class, 'await-keyed-worker-drift');
        $workflow->start();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        WorkflowHistoryEvent::record($run, HistoryEventType::ConditionWaitOpened, [
            'condition_wait_id' => 'condition:1',
            'condition_key' => 'approval.changed',
            'sequence' => 1,
        ]);

        $this->runReadyWorkflowTask($workflow->runId());

        $this->assertFalse($workflow->refresh()->failed());
        $this->assertDatabaseMissing('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => HistoryEventType::WorkflowFailed->value,
        ]);
        $this->assertDatabaseMissing('workflow_failures', [
            'workflow_run_id' => $workflow->runId(),
        ]);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertTrue($task->payload['replay_blocked'] ?? false);
        $this->assertSame('condition_wait_definition_mismatch', $task->payload['replay_blocked_reason'] ?? null);
        $this->assertSame('approval.changed', $task->payload['replay_blocked_recorded_condition_key'] ?? null);
        $this->assertSame('approval.ready', $task->payload['replay_blocked_current_condition_key'] ?? null);
        $this->assertStringContainsString('recorded with condition key [approval.changed]', (string) $task->last_error);
        $this->assertStringContainsString('current workflow yielded [approval.ready]', (string) $task->last_error);

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));

        $this->assertSame('workflow_replay_blocked', $detail['liveness_state']);
        $this->assertStringContainsString('Run this workflow on a compatible build', $detail['liveness_reason']);
        $this->assertTrue($detail['can_repair']);
        $this->assertSame('replay_blocked', $detail['tasks'][0]['transport_state']);
        $this->assertTrue($detail['tasks'][0]['replay_blocked']);
        $this->assertSame('condition:1', $detail['tasks'][0]['replay_blocked_condition_wait_id']);
        $this->assertSame('approval.changed', $detail['tasks'][0]['replay_blocked_recorded_condition_key']);
        $this->assertSame('approval.ready', $detail['tasks'][0]['replay_blocked_current_condition_key']);

        $repair = $workflow->refresh()
            ->attemptRepair();

        $this->assertTrue($repair->accepted());
        $this->assertSame('repair_dispatched', $repair->outcome());

        $task->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertFalse($task->payload['replay_blocked'] ?? false);
        $this->assertNull($task->last_error);
        $this->assertSame(1, $task->repair_count);
    }

    public function testWorkflowWorkerBlocksReplayWhenPreviouslyUnkeyedConditionWaitGainsCurrentKey(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestKeyedAwaitWorkflow::class, 'await-unkeyed-worker-drift');
        $workflow->start();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        WorkflowHistoryEvent::record($run, HistoryEventType::ConditionWaitOpened, [
            'condition_wait_id' => 'condition:1',
            'sequence' => 1,
        ]);

        $this->runReadyWorkflowTask($workflow->runId());

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertTrue($task->payload['replay_blocked'] ?? false);
        $this->assertSame('condition_wait_definition_mismatch', $task->payload['replay_blocked_reason'] ?? null);
        $this->assertNull($task->payload['replay_blocked_recorded_condition_key'] ?? null);
        $this->assertSame('approval.ready', $task->payload['replay_blocked_current_condition_key'] ?? null);
        $this->assertStringContainsString('recorded with condition key [none]', (string) $task->last_error);

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));

        $this->assertSame('workflow_replay_blocked', $detail['liveness_state']);
        $this->assertStringContainsString(
            'recorded condition key [none] does not match the current yielded key [approval.ready]',
            $detail['liveness_reason'],
        );
        $this->assertSame('replay_blocked', $detail['tasks'][0]['transport_state']);
        $this->assertNull($detail['tasks'][0]['replay_blocked_recorded_condition_key']);
        $this->assertSame('approval.ready', $detail['tasks'][0]['replay_blocked_current_condition_key']);
    }

    public function testWorkflowWorkerBlocksReplayWhenConditionWaitHistoryShapeDrifts(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestAwaitWorkflow::class, 'await-worker-history-shape-drift');
        $workflow->start();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        WorkflowHistoryEvent::record($run, HistoryEventType::TimerScheduled, [
            'timer_id' => 'timer-from-older-definition',
            'sequence' => 1,
            'delay_seconds' => 60,
            'fire_at' => now()
                ->addMinute()
                ->toJSON(),
        ]);

        $this->runReadyWorkflowTask($workflow->runId());

        $this->assertFalse($workflow->refresh()->failed());
        $this->assertDatabaseMissing('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => HistoryEventType::WorkflowFailed->value,
        ]);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertTrue($task->payload['replay_blocked'] ?? false);
        $this->assertSame('history_shape_mismatch', $task->payload['replay_blocked_reason'] ?? null);
        $this->assertSame(1, $task->payload['replay_blocked_workflow_sequence'] ?? null);
        $this->assertSame('condition wait', $task->payload['replay_blocked_expected_history_shape'] ?? null);
        $this->assertSame(['TimerScheduled'], $task->payload['replay_blocked_recorded_event_types'] ?? null);
        $this->assertStringContainsString('recorded [TimerScheduled]', (string) $task->last_error);

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));

        $this->assertSame('workflow_replay_blocked', $detail['liveness_state']);
        $this->assertStringContainsString('history recorded [TimerScheduled]', $detail['liveness_reason']);
        $this->assertTrue($detail['can_repair']);
        $this->assertSame('replay_blocked', $detail['tasks'][0]['transport_state']);
        $this->assertTrue($detail['tasks'][0]['replay_blocked']);
        $this->assertSame('history_shape_mismatch', $detail['tasks'][0]['replay_blocked_reason']);
        $this->assertSame('condition wait', $detail['tasks'][0]['replay_blocked_expected_history_shape']);
        $this->assertSame(['TimerScheduled'], $detail['tasks'][0]['replay_blocked_recorded_event_types']);
        $this->assertStringContainsString('history shape drift', $detail['tasks'][0]['summary']);

        $repair = $workflow->refresh()
            ->attemptRepair();

        $this->assertTrue($repair->accepted());
        $this->assertSame('repair_dispatched', $repair->outcome());

        $task->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertFalse($task->payload['replay_blocked'] ?? false);
        $this->assertNull($task->last_error);
        $this->assertSame(1, $task->repair_count);
    }

    public function testWorkflowWorkerBlocksReplayWhenConditionPredicateFingerprintDrifts(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestKeyedAwaitWorkflow::class, 'await-keyed-worker-fingerprint-drift');
        $workflow->start();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $recordedFingerprint = 'sha256:' . str_repeat('1', 64);

        WorkflowHistoryEvent::record($run, HistoryEventType::ConditionWaitOpened, [
            'condition_wait_id' => 'condition:1',
            'condition_key' => 'approval.ready',
            'condition_definition_fingerprint' => $recordedFingerprint,
            'sequence' => 1,
        ]);

        $this->runReadyWorkflowTask($workflow->runId());

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertTrue($task->payload['replay_blocked'] ?? false);
        $this->assertSame('condition_wait_definition_mismatch', $task->payload['replay_blocked_reason'] ?? null);
        $this->assertSame('approval.ready', $task->payload['replay_blocked_recorded_condition_key'] ?? null);
        $this->assertSame('approval.ready', $task->payload['replay_blocked_current_condition_key'] ?? null);
        $this->assertSame(
            $recordedFingerprint,
            $task->payload['replay_blocked_recorded_condition_definition_fingerprint'] ?? null,
        );
        $this->assertIsString($task->payload['replay_blocked_current_condition_definition_fingerprint'] ?? null);
        $this->assertNotSame(
            $recordedFingerprint,
            $task->payload['replay_blocked_current_condition_definition_fingerprint'] ?? null,
        );
        $this->assertStringContainsString('predicate fingerprint', (string) $task->last_error);

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));

        $this->assertSame('workflow_replay_blocked', $detail['liveness_state']);
        $this->assertStringContainsString('predicate fingerprint', $detail['liveness_reason']);
        $this->assertSame('replay_blocked', $detail['tasks'][0]['transport_state']);
        $this->assertSame(
            $recordedFingerprint,
            $detail['tasks'][0]['replay_blocked_recorded_condition_definition_fingerprint'],
        );
        $this->assertIsString($detail['tasks'][0]['replay_blocked_current_condition_definition_fingerprint']);
    }

    public function testAwaitWorkflowCanApplySubmittedUpdateOnWorkflowWorker(): void
    {
        config()->set('queue.default', 'redis');

        Queue::fake();

        $workflow = WorkflowStub::make(TestAwaitWorkflow::class, 'await-submitted-update');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $update = $workflow->submitUpdate('approve', true);

        $this->assertTrue($update->accepted());
        $this->assertFalse($update->completed());
        $this->assertSame('accepted', $update->updateStatus());
        $this->assertSame('waiting', $workflow->refresh()->status());

        $this->runReadyWorkflowTask($workflow->runId());

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'approved' => true,
            'stage' => 'completed',
            'workflow_id' => 'await-submitted-update',
            'run_id' => $workflow->runId(),
        ], $workflow->output());

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ConditionWaitOpened',
            'UpdateAccepted',
            'UpdateApplied',
            'UpdateCompleted',
            'MessageCursorAdvanced',
            'ConditionWaitSatisfied',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testAwaitWorkflowUpdateRedispatchesExistingReadyWorkflowTaskBeforeWorkerRuns(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestAwaitWorkflow::class, 'await-update-before-worker');
        $workflow->start();

        /** @var WorkflowTask $initialTask */
        $initialTask = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        Queue::fake();

        $update = $workflow->attemptUpdate('approve', true);

        $this->assertTrue($update->accepted());
        $this->assertTrue($update->completed());
        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->count());

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $initialTask->id,
        );

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'approved' => true,
            'stage' => 'completed',
            'workflow_id' => 'await-update-before-worker',
            'run_id' => $workflow->runId(),
        ], $workflow->output());
    }

    public function testAwaitWithTimeoutProjectsConditionWaitAndTimesOut(): void
    {
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-08 12:00:00'));

        try {
            $workflow = WorkflowStub::make(TestAwaitWithTimeoutWorkflow::class, 'await-timeout');
            $workflow->start();

            $this->runReadyWorkflowTask($workflow->runId());

            $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));
            $conditionWait = $this->findConditionWait($detail['waits']);
            $timerTask = $this->findTask($detail['tasks'], 'timer');

            $this->assertSame('condition', $detail['wait_kind']);
            $this->assertSame('Waiting for condition or timeout', $detail['wait_reason']);
            $this->assertSame('waiting_for_condition', $detail['liveness_state']);
            $this->assertSame('timer', $detail['resume_source_kind']);
            $this->assertNotNull($detail['resume_source_id']);
            $this->assertSame($conditionWait['condition_wait_id'], $detail['open_wait_id']);
            $this->assertSame('open', $conditionWait['status']);
            $this->assertTrue($conditionWait['external_only']);
            $this->assertTrue($conditionWait['task_backed']);
            $this->assertSame(5, $conditionWait['timeout_seconds']);
            $this->assertSame('timer', $conditionWait['resume_source_kind']);
            $this->assertNotNull($conditionWait['resume_source_id']);
            $this->assertSame($conditionWait['condition_wait_id'], $timerTask['condition_wait_id']);
            $this->assertSame(1, $timerTask['timer_sequence']);
            $this->assertStringContainsString('Condition timeout for 5 seconds', $timerTask['summary']);

            Carbon::setTestNow(now()->addSeconds(5));

            $this->runReadyTimerTask($workflow->runId());
            $this->runReadyWorkflowTask($workflow->runId());

            $this->assertTrue($workflow->refresh()->completed());
            $this->assertSame([
                'approved' => false,
                'timed_out' => true,
                'stage' => 'timed-out',
                'workflow_id' => 'await-timeout',
                'run_id' => $workflow->runId(),
            ], $workflow->output());

            $this->assertSame([
                'StartAccepted',
                'WorkflowStarted',
                'ConditionWaitOpened',
                'TimerScheduled',
                'TimerFired',
                'ConditionWaitTimedOut',
                'WorkflowCompleted',
            ], WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $workflow->runId())
                ->orderBy('sequence')
                ->pluck('event_type')
                ->map(static fn ($eventType) => $eventType->value)
                ->all());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testAwaitWithTimeoutCancelsTimerWhenUpdateWins(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestAwaitWithTimeoutWorkflow::class, 'await-timeout-update');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $workflow->runId())
            ->firstOrFail();

        $workflow->attemptUpdate('approve', true);

        $this->assertTrue($workflow->refresh()->completed());

        $timer->refresh();

        /** @var WorkflowTask $timerTask */
        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Timer->value)
            ->firstOrFail();

        $this->assertSame('cancelled', $timer->status->value);
        $this->assertSame(TaskStatus::Cancelled->value, $timerTask->status->value);
        $this->assertDatabaseMissing('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => HistoryEventType::ConditionWaitTimedOut->value,
        ]);
        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => HistoryEventType::TimerCancelled->value,
        ]);

        /** @var WorkflowHistoryEvent $timerCancelled */
        $timerCancelled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::TimerCancelled->value)
            ->firstOrFail();

        $this->assertSame($timer->id, $timerCancelled->payload['timer_id'] ?? null);
        $this->assertSame($timer->sequence, $timerCancelled->payload['sequence'] ?? null);
        $this->assertSame('condition_timeout', $timerCancelled->payload['timer_kind'] ?? null);
        $this->assertNotNull($timerCancelled->payload['condition_wait_id'] ?? null);
        $this->assertNotNull($timerCancelled->payload['cancelled_at'] ?? null);

        $timerId = $timer->id;
        $deadlineAt = $timer->fire_at?->toJSON();

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));
        $conditionWait = $this->findConditionWait($detail['waits']);

        $this->assertSame('resolved', $conditionWait['status']);
        $this->assertSame('satisfied', $conditionWait['source_status']);
        $this->assertSame('external_input', $conditionWait['resume_source_kind']);
        $this->assertNull($conditionWait['resume_source_id']);
        $this->assertNotNull($conditionWait['deadline_at']);

        $this->assertSame($timerId, $detail['timers'][0]['id']);
        $this->assertSame('cancelled', $detail['timers'][0]['status']);
        $this->assertSame($deadlineAt, $detail['timers'][0]['fire_at']?->toJSON());
        $this->assertNotNull($detail['timers'][0]['cancelled_at']);

        $timerTask->delete();
        $timer->delete();

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));

        $this->assertSame($timerId, $detail['timers'][0]['id']);
        $this->assertSame('cancelled', $detail['timers'][0]['status']);
        $this->assertSame('condition_timeout', $detail['timers'][0]['timer_kind']);
        $this->assertSame($timerCancelled->payload['condition_wait_id'], $detail['timers'][0]['condition_wait_id']);
        $this->assertSame($deadlineAt, $detail['timers'][0]['fire_at']?->toJSON());
        $this->assertNotNull($detail['timers'][0]['cancelled_at']);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ConditionWaitOpened',
            'TimerScheduled',
            'UpdateAccepted',
            'UpdateApplied',
            'UpdateCompleted',
            'MessageCursorAdvanced',
            'TimerCancelled',
            'ConditionWaitSatisfied',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());

        $this->assertSame([
            'approved' => true,
            'timed_out' => false,
            'stage' => 'approved',
            'workflow_id' => 'await-timeout-update',
            'run_id' => $workflow->runId(),
        ], $workflow->output());
    }

    public function testAwaitWithTimeoutKeepsConditionWaitProjectionWhenTimerRowDrifts(): void
    {
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-08 13:00:00'));

        try {
            $workflow = WorkflowStub::make(TestAwaitWithTimeoutWorkflow::class, 'await-timeout-drift');
            $workflow->start();

            $this->runReadyWorkflowTask($workflow->runId());

            /** @var WorkflowTimer $timer */
            $timer = WorkflowTimer::query()
                ->where('workflow_run_id', $workflow->runId())
                ->firstOrFail();

            $timerId = $timer->id;
            $deadlineAt = $timer->fire_at?->toJSON();

            $timer->delete();

            RunSummaryProjector::project(WorkflowRun::query()->findOrFail($workflow->runId()));

            $run = WorkflowRun::query()->with('summary')->findOrFail($workflow->runId());
            $detail = RunDetailView::forRun($run);
            $conditionWait = $this->findConditionWait($detail['waits']);
            $timerTask = $this->findTask($detail['tasks'], 'timer');

            $this->assertSame('condition', $detail['wait_kind']);
            $this->assertSame('Waiting for condition or timeout', $detail['wait_reason']);
            $this->assertSame('waiting_for_condition', $detail['liveness_state']);
            $this->assertSame('timer', $detail['resume_source_kind']);
            $this->assertSame($timerId, $detail['resume_source_id']);
            $this->assertSame($conditionWait['condition_wait_id'], $detail['open_wait_id']);
            $this->assertSame($deadlineAt, $run->summary?->wait_deadline_at?->toJSON());
            $this->assertSame('open', $conditionWait['status']);
            $this->assertSame('timer', $conditionWait['resume_source_kind']);
            $this->assertSame($timerId, $conditionWait['resume_source_id']);
            $this->assertSame($deadlineAt, $conditionWait['deadline_at']?->toJSON());
            $this->assertSame(5, $conditionWait['timeout_seconds']);
            $this->assertTrue($conditionWait['task_backed']);
            $this->assertSame($conditionWait['condition_wait_id'], $timerTask['condition_wait_id']);
            $this->assertSame($timerId, $timerTask['timer_id']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testAwaitWithTimeoutDoesNotTimeOutFromDriftedTimerRowWithoutFiredHistory(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-08 14:15:00'));

        try {
            $workflow = WorkflowStub::make(
                TestAwaitWithTimeoutWorkflow::class,
                'await-timeout-row-fired-without-history'
            );
            $workflow->start();

            $runId = $workflow->runId();

            $this->assertNotNull($runId);

            $this->runReadyWorkflowTask($runId);

            $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($runId));
            $conditionWait = $this->findConditionWait($detail['waits']);

            /** @var WorkflowTimer $timer */
            $timer = WorkflowTimer::query()
                ->where('workflow_run_id', $runId)
                ->firstOrFail();

            $timer->forceFill([
                'status' => TimerStatus::Fired->value,
                'fired_at' => now()
                    ->addSeconds(5),
            ])->save();

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()->findOrFail($runId);

            WorkflowTask::query()->create([
                'workflow_run_id' => $runId,
                'task_type' => TaskType::Workflow->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => now()
                    ->addSeconds(5),
                'payload' => [
                    'workflow_wait_kind' => 'condition',
                    'open_wait_id' => $conditionWait['condition_wait_id'],
                    'resume_source_kind' => 'timer',
                    'resume_source_id' => $timer->id,
                    'timer_id' => $timer->id,
                    'condition_wait_id' => $conditionWait['condition_wait_id'],
                    'condition_key' => $conditionWait['condition_key'],
                    'condition_definition_fingerprint' => $conditionWait['condition_definition_fingerprint'],
                    'workflow_sequence' => 1,
                ],
                'connection' => $run->connection,
                'queue' => $run->queue,
                'compatibility' => $run->compatibility,
            ]);

            Carbon::setTestNow(now()->addSeconds(5));

            $this->runReadyWorkflowTask($runId);

            $this->assertSame('waiting', $workflow->refresh()->status());
            $this->assertDatabaseMissing('workflow_history_events', [
                'workflow_run_id' => $runId,
                'event_type' => HistoryEventType::TimerFired->value,
            ]);
            $this->assertDatabaseMissing('workflow_history_events', [
                'workflow_run_id' => $runId,
                'event_type' => HistoryEventType::ConditionWaitTimedOut->value,
            ]);

            $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($runId));
            $conditionWait = $this->findConditionWait($detail['waits']);

            $this->assertSame('condition', $detail['wait_kind']);
            $this->assertSame('Waiting for condition or timeout', $detail['wait_reason']);
            $this->assertSame('open', $conditionWait['status']);
            $this->assertSame('waiting', $conditionWait['source_status']);
            $this->assertSame($timer->id, $conditionWait['resume_source_id']);
            $this->assertSame('pending', $detail['timers'][0]['status']);
            $this->assertSame($timer->id, $detail['timers'][0]['id']);

            $this->runReadyTimerTask($runId);
            $this->runReadyWorkflowTask($runId);

            $this->assertTrue($workflow->refresh()->completed());
            $this->assertSame([
                'approved' => false,
                'timed_out' => true,
                'stage' => 'timed-out',
                'workflow_id' => 'await-timeout-row-fired-without-history',
                'run_id' => $runId,
            ], $workflow->output());

            $this->assertSame(1, WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::TimerFired->value)
                ->count());
            $this->assertSame(1, WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::ConditionWaitTimedOut->value)
                ->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testAwaitWithTimeoutStillTimesOutWhenTimerRowIsRecoveredFromHistory(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-08 15:00:00'));

        try {
            $workflow = WorkflowStub::make(TestAwaitWithTimeoutWorkflow::class, 'await-timeout-history-fire');
            $workflow->start();

            $this->runReadyWorkflowTask($workflow->runId());

            /** @var WorkflowTimer $timer */
            $timer = WorkflowTimer::query()
                ->where('workflow_run_id', $workflow->runId())
                ->firstOrFail();

            $timerId = $timer->id;

            $timer->delete();

            RunSummaryProjector::project(WorkflowRun::query()->findOrFail($workflow->runId()));

            Carbon::setTestNow(now()->addSeconds(5));

            $this->runReadyTimerTask($workflow->runId());
            $this->runReadyWorkflowTask($workflow->runId());

            /** @var WorkflowTimer $restoredTimer */
            $restoredTimer = WorkflowTimer::query()->findOrFail($timerId);

            $this->assertTrue($workflow->refresh()->completed());
            $this->assertSame('fired', $restoredTimer->status->value);
            $this->assertNotNull($restoredTimer->fired_at);
            $this->assertSame([
                'approved' => false,
                'timed_out' => true,
                'stage' => 'timed-out',
                'workflow_id' => 'await-timeout-history-fire',
                'run_id' => $workflow->runId(),
            ], $workflow->output());

            $this->assertSame([
                'StartAccepted',
                'WorkflowStarted',
                'ConditionWaitOpened',
                'TimerScheduled',
                'TimerFired',
                'ConditionWaitTimedOut',
                'WorkflowCompleted',
            ], WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $workflow->runId())
                ->orderBy('sequence')
                ->pluck('event_type')
                ->map(static fn ($eventType) => $eventType->value)
                ->all());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testAwaitWithTimeoutAppliesFiredTimeoutHistoryWhenTimerRowAndResumeTaskDrift(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-08 15:30:00'));

        try {
            $workflow = WorkflowStub::make(TestAwaitWithTimeoutWorkflow::class, 'await-timeout-fired-history');
            $workflow->start();

            $runId = $workflow->runId();

            $this->assertNotNull($runId);

            $this->runReadyWorkflowTask($runId);

            Carbon::setTestNow(now()->addSeconds(5));

            $this->runReadyTimerTask($runId);

            /** @var WorkflowTimer $timer */
            $timer = WorkflowTimer::query()
                ->where('workflow_run_id', $runId)
                ->firstOrFail();
            $timerId = $timer->id;

            /** @var WorkflowTask $resumeTask */
            $resumeTask = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Workflow->value)
                ->where('status', TaskStatus::Ready->value)
                ->sole();

            $resumeTask->delete();
            $timer->delete();

            RunSummaryProjector::project(WorkflowRun::query()->findOrFail($runId));

            $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($runId));
            $conditionWait = $this->findConditionWait($detail['waits']);
            $missingWorkflowTask = collect($detail['tasks'])
                ->first(
                    static fn (array $task): bool => ($task['type'] ?? null) === 'workflow'
                        && ($task['transport_state'] ?? null) === 'missing'
                );

            $this->assertSame('condition', $detail['wait_kind']);
            $this->assertSame('Waiting to apply condition timeout', $detail['wait_reason']);
            $this->assertSame('repair_needed', $detail['liveness_state']);
            $this->assertSame('timeout_fired', $conditionWait['source_status']);
            $this->assertNotNull($conditionWait['timeout_fired_at']);
            $this->assertIsArray($missingWorkflowTask);
            $this->assertSame('missing', $missingWorkflowTask['status']);
            $this->assertSame('condition', $missingWorkflowTask['workflow_wait_kind']);
            $this->assertSame($timerId, $missingWorkflowTask['timer_id']);
            $this->assertStringContainsString('condition timeout', $missingWorkflowTask['summary']);

            $result = WorkflowStub::loadRun($runId)->attemptRepair();

            $this->assertTrue($result->accepted());
            $this->assertSame('repair_dispatched', $result->outcome());

            /** @var WorkflowTask $repairedTask */
            $repairedTask = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Workflow->value)
                ->where('status', TaskStatus::Ready->value)
                ->sole();

            $this->assertSame('condition', $repairedTask->payload['workflow_wait_kind'] ?? null);
            $this->assertSame('timer', $repairedTask->payload['resume_source_kind'] ?? null);
            $this->assertSame($timerId, $repairedTask->payload['resume_source_id'] ?? null);
            $this->assertSame($timerId, $repairedTask->payload['timer_id'] ?? null);
            $this->assertSame(1, $repairedTask->repair_count);

            Queue::assertPushed(
                RunWorkflowTask::class,
                static fn (RunWorkflowTask $job): bool => $job->taskId === $repairedTask->id,
            );

            $this->runReadyWorkflowTask($runId);

            $this->assertTrue($workflow->refresh()->completed());
            $this->assertSame([
                'approved' => false,
                'timed_out' => true,
                'stage' => 'timed-out',
                'workflow_id' => 'await-timeout-fired-history',
                'run_id' => $runId,
            ], $workflow->output());
            $this->assertSame(1, WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::TimerScheduled->value)
                ->count());
            $this->assertSame(1, WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::TimerFired->value)
                ->count());
            $this->assertDatabaseHas('workflow_history_events', [
                'workflow_run_id' => $runId,
                'event_type' => HistoryEventType::ConditionWaitTimedOut->value,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testAwaitWithTimeoutRepairRestoresMissingTimeoutTimerTransportFromHistory(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-08 16:00:00'));

        try {
            $workflow = WorkflowStub::make(TestAwaitWithTimeoutWorkflow::class, 'await-timeout-history-repair');
            $workflow->start();

            $runId = $workflow->runId();

            $this->assertNotNull($runId);

            $this->runReadyWorkflowTask($runId);

            $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($runId));
            $conditionWait = $this->findConditionWait($detail['waits']);
            $timerTask = $this->findTask($detail['tasks'], 'timer');
            $timerId = $timerTask['timer_id'] ?? null;
            $timerTaskId = $timerTask['id'] ?? null;
            $conditionWaitId = $conditionWait['condition_wait_id'] ?? null;
            $deadlineAt = $conditionWait['deadline_at']?->toJSON();

            $this->assertIsString($timerId);
            $this->assertIsString($timerTaskId);
            $this->assertIsString($conditionWaitId);
            $this->assertNotNull($deadlineAt);

            WorkflowTask::query()->whereKey($timerTaskId)->delete();
            WorkflowTimer::query()->whereKey($timerId)->delete();

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()->findOrFail($runId);
            $summary = RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
            );

            $this->assertSame('condition', $summary->wait_kind);
            $this->assertSame('timer', $summary->resume_source_kind);
            $this->assertSame($timerId, $summary->resume_source_id);
            $this->assertSame('repair_needed', $summary->liveness_state);

            $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($runId));
            $conditionWait = $this->findConditionWait($detail['waits']);

            $this->assertTrue($detail['can_repair']);
            $this->assertFalse($conditionWait['task_backed']);
            $this->assertSame($timerId, $conditionWait['resume_source_id']);
            $this->assertSame($deadlineAt, $conditionWait['deadline_at']?->toJSON());
            $missingTimerTask = $this->findTask($detail['tasks'], 'timer');

            $this->assertSame('missing', $missingTimerTask['status']);
            $this->assertSame('missing', $missingTimerTask['transport_state']);
            $this->assertTrue($missingTimerTask['task_missing']);
            $this->assertSame($timerId, $missingTimerTask['timer_id']);

            $result = WorkflowStub::loadRun($runId)->attemptRepair();

            $this->assertTrue($result->accepted());
            $this->assertSame('repair_dispatched', $result->outcome());

            /** @var WorkflowTimer $restoredTimer */
            $restoredTimer = WorkflowTimer::query()->findOrFail($timerId);
            /** @var WorkflowTask $repairedTask */
            $repairedTask = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Timer->value)
                ->where('status', TaskStatus::Ready->value)
                ->sole();

            $this->assertSame('pending', $restoredTimer->status->value);
            $this->assertSame($deadlineAt, $restoredTimer->fire_at?->toJSON());
            $this->assertSame($timerId, $repairedTask->payload['timer_id'] ?? null);
            $this->assertSame($conditionWaitId, $repairedTask->payload['condition_wait_id'] ?? null);
            $this->assertIsString($repairedTask->payload['condition_definition_fingerprint'] ?? null);
            $this->assertSame($deadlineAt, $repairedTask->available_at?->toJSON());
            $this->assertSame(1, $repairedTask->repair_count);

            Queue::assertPushed(
                RunTimerTask::class,
                static fn (RunTimerTask $job): bool => $job->taskId === $repairedTask->id,
            );

            Carbon::setTestNow(now()->addSeconds(5));

            $this->runReadyTimerTask($runId);
            $this->runReadyWorkflowTask($runId);

            $this->assertTrue($workflow->refresh()->completed());
            $this->assertSame([
                'approved' => false,
                'timed_out' => true,
                'stage' => 'timed-out',
                'workflow_id' => 'await-timeout-history-repair',
                'run_id' => $runId,
            ], $workflow->output());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testAwaitStringSignalReturnsPayloadAndCancelsTimeoutWhenSignalWins(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestAwaitSignalTimeoutWorkflow::class, 'await-signal-payload');
        $workflow->start('approved-by', 5);

        $this->runReadyWorkflowTask($workflow->runId());

        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame('signal', $workflow->summary()?->wait_kind);
        $this->assertSame('Waiting for signal approved-by or timeout', $workflow->summary()?->wait_reason);
        $this->assertSame('timer', $workflow->summary()?->resume_source_kind);
        $this->assertNotNull($workflow->summary()?->wait_deadline_at);

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));
        $signalWait = $this->findSignalWait($detail['waits']);
        $timerTask = $this->findTask($detail['tasks'], TaskType::Timer->value);

        $this->assertSame('open', $signalWait['status']);
        $this->assertSame('waiting', $signalWait['source_status']);
        $this->assertSame('approved-by', $signalWait['target_name']);
        $this->assertSame(5, $signalWait['timeout_seconds']);
        $this->assertNotNull($signalWait['deadline_at']);
        $this->assertTrue($signalWait['task_backed']);
        $this->assertSame('timer', $signalWait['resume_source_kind']);
        $this->assertSame($timerTask['timer_id'], $signalWait['resume_source_id']);
        $this->assertSame($signalWait['signal_wait_id'], $timerTask['signal_wait_id']);
        $this->assertSame('approved-by', $timerTask['signal_name']);
        $this->assertStringContainsString('Signal timeout for 5 seconds', $timerTask['summary']);

        $result = $workflow->attemptSignal('approved-by', 'Jordan');

        $this->assertTrue($result->accepted());
        $this->assertSame('signal_received', $result->outcome());

        $this->runReadyWorkflowTask($workflow->runId());

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'payload' => 'Jordan',
            'timed_out' => false,
            'stage' => 'received',
            'workflow_id' => 'await-signal-payload',
            'run_id' => $workflow->runId(),
        ], $workflow->output());

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $workflow->runId())
            ->firstOrFail();

        $this->assertSame(TimerStatus::Cancelled->value, $timer->status->value);

        /** @var WorkflowHistoryEvent $timerCancelled */
        $timerCancelled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::TimerCancelled->value)
            ->firstOrFail();

        $this->assertSame('signal_timeout', $timerCancelled->payload['timer_kind'] ?? null);
        $this->assertSame($signalWait['signal_wait_id'], $timerCancelled->payload['signal_wait_id'] ?? null);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'SignalWaitOpened',
            'TimerScheduled',
            'SignalReceived',
            'TimerCancelled',
            'MessageCursorAdvanced',
            'SignalApplied',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testAwaitStringSignalTimeoutRepairRestoresMissingTimeoutTimerTransportFromHistory(): void
    {
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-16 13:00:00'));

        try {
            $workflow = WorkflowStub::make(TestAwaitSignalTimeoutWorkflow::class, 'await-signal-timeout-repair');
            $workflow->start('approved-by', 5);

            $runId = $workflow->runId();

            $this->assertNotNull($runId);

            $this->runReadyWorkflowTask($runId);

            $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($runId));
            $signalWait = $this->findSignalWait($detail['waits']);
            $timerTask = $this->findTask($detail['tasks'], TaskType::Timer->value);
            $timerId = $timerTask['timer_id'] ?? null;
            $timerTaskId = $timerTask['id'] ?? null;
            $signalWaitId = $signalWait['signal_wait_id'] ?? null;
            $deadlineAt = $signalWait['deadline_at']?->toJSON();

            $this->assertIsString($timerId);
            $this->assertIsString($timerTaskId);
            $this->assertIsString($signalWaitId);
            $this->assertNotNull($deadlineAt);

            WorkflowTask::query()->whereKey($timerTaskId)->delete();
            WorkflowTimer::query()->whereKey($timerId)->delete();

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()->findOrFail($runId);
            $summary = RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
            );

            $this->assertSame('signal', $summary->wait_kind);
            $this->assertSame('timer', $summary->resume_source_kind);
            $this->assertSame($timerId, $summary->resume_source_id);
            $this->assertSame('repair_needed', $summary->liveness_state);

            $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($runId));
            $signalWait = $this->findSignalWait($detail['waits']);
            $missingTimerTask = $this->findTask($detail['tasks'], TaskType::Timer->value);

            $this->assertTrue($detail['can_repair']);
            $this->assertFalse($signalWait['task_backed']);
            $this->assertSame('timer', $signalWait['resume_source_kind']);
            $this->assertSame($timerId, $signalWait['resume_source_id']);
            $this->assertSame($deadlineAt, $signalWait['deadline_at']?->toJSON());
            $this->assertSame('missing', $missingTimerTask['status']);
            $this->assertSame('missing', $missingTimerTask['transport_state']);
            $this->assertTrue($missingTimerTask['task_missing']);
            $this->assertSame($timerId, $missingTimerTask['timer_id']);
            $this->assertSame($signalWaitId, $missingTimerTask['signal_wait_id']);
            $this->assertSame('approved-by', $missingTimerTask['signal_name']);
            $this->assertStringContainsString('Signal timeout task missing', $missingTimerTask['summary']);

            $result = WorkflowStub::loadRun($runId)->attemptRepair();

            $this->assertTrue($result->accepted());
            $this->assertSame('repair_dispatched', $result->outcome());

            /** @var WorkflowTimer $restoredTimer */
            $restoredTimer = WorkflowTimer::query()->findOrFail($timerId);
            /** @var WorkflowTask $repairedTask */
            $repairedTask = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Timer->value)
                ->where('status', TaskStatus::Ready->value)
                ->sole();

            $this->assertSame(TimerStatus::Pending->value, $restoredTimer->status->value);
            $this->assertSame($deadlineAt, $restoredTimer->fire_at?->toJSON());
            $this->assertSame($timerId, $repairedTask->payload['timer_id'] ?? null);
            $this->assertSame($signalWaitId, $repairedTask->payload['signal_wait_id'] ?? null);
            $this->assertSame('approved-by', $repairedTask->payload['signal_name'] ?? null);
            $this->assertSame($deadlineAt, $repairedTask->available_at?->toJSON());
            $this->assertSame(1, $repairedTask->repair_count);

            Queue::assertPushed(
                RunTimerTask::class,
                static fn (RunTimerTask $job): bool => $job->taskId === $repairedTask->id,
            );

            Carbon::setTestNow(now()->addSeconds(5));

            $this->runReadyTimerTask($runId);
            $this->runReadyWorkflowTask($runId);

            $this->assertTrue($workflow->refresh()->completed());
            $this->assertSame([
                'payload' => null,
                'timed_out' => true,
                'stage' => 'timed-out',
                'workflow_id' => 'await-signal-timeout-repair',
                'run_id' => $runId,
            ], $workflow->output());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testAwaitStringSignalTimeoutAppliesFiredTimeoutHistoryWhenTimerRowAndResumeTaskDrift(): void
    {
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-16 14:00:00'));

        try {
            $workflow = WorkflowStub::make(TestAwaitSignalTimeoutWorkflow::class, 'await-signal-timeout-fired-repair');
            $workflow->start('approved-by', 5);

            $runId = $workflow->runId();

            $this->assertNotNull($runId);

            $this->runReadyWorkflowTask($runId);

            Carbon::setTestNow(now()->addSeconds(5));

            $this->runReadyTimerTask($runId);

            /** @var WorkflowTimer $timer */
            $timer = WorkflowTimer::query()
                ->where('workflow_run_id', $runId)
                ->firstOrFail();
            $timerId = $timer->id;

            /** @var WorkflowTask $resumeTask */
            $resumeTask = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Workflow->value)
                ->where('status', TaskStatus::Ready->value)
                ->sole();

            $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($runId));
            $signalWait = $this->findSignalWait($detail['waits']);
            $signalWaitId = $signalWait['signal_wait_id'] ?? null;

            $this->assertIsString($signalWaitId);

            $resumeTask->delete();
            $timer->delete();

            RunSummaryProjector::project(WorkflowRun::query()->findOrFail($runId));

            $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($runId));
            $signalWait = $this->findSignalWait($detail['waits']);
            $missingWorkflowTask = collect($detail['tasks'])
                ->first(
                    static fn (array $task): bool => ($task['type'] ?? null) === TaskType::Workflow->value
                        && ($task['transport_state'] ?? null) === 'missing'
                );

            $this->assertSame('signal', $detail['wait_kind']);
            $this->assertSame('Waiting to apply signal approved-by timeout', $detail['wait_reason']);
            $this->assertSame('repair_needed', $detail['liveness_state']);
            $this->assertSame('timed_out', $signalWait['source_status']);
            $this->assertNotNull($signalWait['timeout_fired_at']);
            $this->assertSame('timer', $signalWait['resume_source_kind']);
            $this->assertSame($timerId, $signalWait['resume_source_id']);
            $this->assertIsArray($missingWorkflowTask);
            $this->assertSame('missing', $missingWorkflowTask['status']);
            $this->assertSame('signal', $missingWorkflowTask['workflow_wait_kind']);
            $this->assertSame($timerId, $missingWorkflowTask['timer_id']);
            $this->assertSame($signalWaitId, $missingWorkflowTask['signal_wait_id']);
            $this->assertSame('approved-by', $missingWorkflowTask['signal_name']);
            $this->assertStringContainsString('signal approved-by timeout', $missingWorkflowTask['summary']);

            $result = WorkflowStub::loadRun($runId)->attemptRepair();

            $this->assertTrue($result->accepted());
            $this->assertSame('repair_dispatched', $result->outcome());

            /** @var WorkflowTask $repairedTask */
            $repairedTask = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Workflow->value)
                ->where('status', TaskStatus::Ready->value)
                ->sole();

            $this->assertSame('signal', $repairedTask->payload['workflow_wait_kind'] ?? null);
            $this->assertSame('timer', $repairedTask->payload['resume_source_kind'] ?? null);
            $this->assertSame($timerId, $repairedTask->payload['resume_source_id'] ?? null);
            $this->assertSame($timerId, $repairedTask->payload['timer_id'] ?? null);
            $this->assertSame($signalWaitId, $repairedTask->payload['signal_wait_id'] ?? null);
            $this->assertSame('approved-by', $repairedTask->payload['signal_name'] ?? null);
            $this->assertSame(1, $repairedTask->repair_count);

            Queue::assertPushed(
                RunWorkflowTask::class,
                static fn (RunWorkflowTask $job): bool => $job->taskId === $repairedTask->id,
            );

            $this->runReadyWorkflowTask($runId);

            $this->assertTrue($workflow->refresh()->completed());
            $this->assertSame([
                'payload' => null,
                'timed_out' => true,
                'stage' => 'timed-out',
                'workflow_id' => 'await-signal-timeout-fired-repair',
                'run_id' => $runId,
            ], $workflow->output());
            $this->assertSame(1, WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::TimerScheduled->value)
                ->count());
            $this->assertSame(1, WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::TimerFired->value)
                ->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testAwaitStringSignalTimeoutReturnsNullAndReplaysForQueries(): void
    {
        Queue::fake();

        try {
            Carbon::setTestNow(Carbon::parse('2026-04-16 12:00:00'));

            $workflow = WorkflowStub::make(TestAwaitSignalTimeoutWorkflow::class, 'await-signal-timeout');
            $workflow->start('approved-by', 5);

            $this->runReadyWorkflowTask($workflow->runId());

            Carbon::setTestNow(now()->addSeconds(5));

            $this->runReadyTimerTask($workflow->runId());

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()->findOrFail($workflow->runId());

            $this->assertSame([
                'stage' => 'timed-out',
            ], (new QueryStateReplayer())->query($run, 'currentState'));

            $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));
            $signalWait = $this->findSignalWait($detail['waits']);
            $workflowTask = $this->findTask($detail['tasks'], TaskType::Workflow->value);

            $this->assertSame('resolved', $signalWait['status']);
            $this->assertSame('timed_out', $signalWait['source_status']);
            $this->assertSame('timer', $signalWait['resume_source_kind']);
            $this->assertNotNull($signalWait['timeout_fired_at']);
            $this->assertSame('Workflow task ready to apply signal timeout.', $workflowTask['summary']);

            $this->runReadyWorkflowTask($workflow->runId());

            $this->assertTrue($workflow->refresh()->completed());
            $this->assertSame([
                'payload' => null,
                'timed_out' => true,
                'stage' => 'timed-out',
                'workflow_id' => 'await-signal-timeout',
                'run_id' => $workflow->runId(),
            ], $workflow->output());

            $this->assertSame([
                'StartAccepted',
                'WorkflowStarted',
                'SignalWaitOpened',
                'TimerScheduled',
                'TimerFired',
                'WorkflowCompleted',
            ], WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $workflow->runId())
                ->orderBy('sequence')
                ->pluck('event_type')
                ->map(static fn ($eventType) => $eventType->value)
                ->all());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testAwaitStringSignalReceivedBeforeTimeoutFiredWinsByHistoryOrder(): void
    {
        Queue::fake();

        try {
            Carbon::setTestNow(Carbon::parse('2026-04-16 12:00:00'));

            $workflow = WorkflowStub::make(TestAwaitSignalTimeoutWorkflow::class, 'await-signal-before-timeout-race');
            $workflow->start('approved-by', 5);

            $this->runReadyWorkflowTask($workflow->runId());

            $signal = $workflow->attemptSignal('approved-by', 'Jordan');

            $this->assertTrue($signal->accepted());

            Carbon::setTestNow(now()->addSeconds(5));

            $this->runReadyTimerTask($workflow->runId());
            $this->runReadyWorkflowTask($workflow->runId());

            $this->assertTrue($workflow->refresh()->completed());
            $this->assertSame([
                'payload' => 'Jordan',
                'timed_out' => false,
                'stage' => 'received',
                'workflow_id' => 'await-signal-before-timeout-race',
                'run_id' => $workflow->runId(),
            ], $workflow->output());

            $eventTypes = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $workflow->runId())
                ->orderBy('sequence')
                ->pluck('event_type')
                ->map(static fn ($eventType) => $eventType->value)
                ->all();

            $this->assertLessThan(
                array_search('TimerFired', $eventTypes, true),
                array_search('SignalReceived', $eventTypes, true),
            );
            $this->assertContains('SignalApplied', $eventTypes);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testAwaitStringSignalTimeoutFiredBeforeSignalReceivedWinsByHistoryOrder(): void
    {
        Queue::fake();

        try {
            Carbon::setTestNow(Carbon::parse('2026-04-16 12:00:00'));

            $workflow = WorkflowStub::make(TestAwaitSignalTimeoutWorkflow::class, 'await-timeout-before-signal-race');
            $workflow->start('approved-by', 5);

            $this->runReadyWorkflowTask($workflow->runId());

            Carbon::setTestNow(now()->addSeconds(5));

            $this->runReadyTimerTask($workflow->runId());

            $signal = $workflow->attemptSignal('approved-by', 'Jordan');

            $this->assertTrue($signal->accepted());

            $this->runReadyWorkflowTask($workflow->runId());

            $this->assertTrue($workflow->refresh()->completed());
            $this->assertSame([
                'payload' => null,
                'timed_out' => true,
                'stage' => 'timed-out',
                'workflow_id' => 'await-timeout-before-signal-race',
                'run_id' => $workflow->runId(),
            ], $workflow->output());

            $eventTypes = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $workflow->runId())
                ->orderBy('sequence')
                ->pluck('event_type')
                ->map(static fn ($eventType) => $eventType->value)
                ->all();

            $this->assertLessThan(
                array_search('SignalReceived', $eventTypes, true),
                array_search('TimerFired', $eventTypes, true),
            );
            $this->assertNotContains('SignalApplied', $eventTypes);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testAwaitStringSignalPreservesEmptyAndMultipleArgumentPayloadRules(): void
    {
        Queue::fake();

        $empty = WorkflowStub::make(TestAwaitSignalTimeoutWorkflow::class, 'await-signal-empty');
        $empty->start('empty', 30);
        $this->runReadyWorkflowTask($empty->runId());

        $empty->attemptSignal('empty');
        $this->runReadyWorkflowTask($empty->runId());

        $this->assertTrue($empty->refresh()->completed());
        $this->assertTrue($empty->output()['payload'] ?? null);

        $multi = WorkflowStub::make(TestAwaitSignalTimeoutWorkflow::class, 'await-signal-multi');
        $multi->start('multi', 30);
        $this->runReadyWorkflowTask($multi->runId());

        $multi->attemptSignalWithArguments('multi', ['alpha', 'beta']);
        $this->runReadyWorkflowTask($multi->runId());

        $this->assertTrue($multi->refresh()->completed());
        $this->assertSame(['alpha', 'beta'], $multi->output()['payload'] ?? null);
    }

    private function runReadyWorkflowTask(string $runId): void
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->firstOrFail();

        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }

    private function runReadyTimerTask(string $runId): void
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Timer->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->firstOrFail();

        $this->app->call([new RunTimerTask($task->id), 'handle']);
    }

    /**
     * @param list<array<string, mixed>> $waits
     * @return array<string, mixed>
     */
    private function findConditionWait(array $waits): array
    {
        foreach ($waits as $wait) {
            if (($wait['kind'] ?? null) === 'condition') {
                return $wait;
            }
        }

        $this->fail('Condition wait was not found.');
    }

    /**
     * @param list<array<string, mixed>> $waits
     * @return array<string, mixed>
     */
    private function findSignalWait(array $waits): array
    {
        foreach ($waits as $wait) {
            if (($wait['kind'] ?? null) === 'signal') {
                return $wait;
            }
        }

        $this->fail('Signal wait was not found.');
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return array<string, mixed>
     */
    private function findTask(array $tasks, string $type): array
    {
        foreach ($tasks as $task) {
            if (($task['type'] ?? null) === $type) {
                return $task;
            }
        }

        $this->fail(sprintf('Task of type [%s] was not found.', $type));
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return array<string, mixed>|null
     */
    private function findTaskOrNull(array $tasks, string $type): ?array
    {
        foreach ($tasks as $task) {
            if (($task['type'] ?? null) === $type) {
                return $task;
            }
        }

        return null;
    }
}
