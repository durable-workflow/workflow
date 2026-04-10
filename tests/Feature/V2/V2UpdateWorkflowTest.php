<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestAliasedUpdateWorkflow;
use Tests\Fixtures\V2\TestChildHandleParentWorkflow;
use Tests\Fixtures\V2\TestSignalThenUpdateWorkflow;
use Tests\Fixtures\V2\TestUpdateWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\WorkflowStub;

final class V2UpdateWorkflowTest extends TestCase
{
    public function testAttemptUpdateUsesDeclaredAliasAsTheDurableTarget(): void
    {
        $workflow = WorkflowStub::make(TestAliasedUpdateWorkflow::class, 'order-update-alias');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $update = $workflow->attemptUpdate('mark-approved', true, 'api');

        $this->assertTrue($update->accepted());
        $this->assertTrue($update->completed());
        $this->assertSame('update_completed', $update->outcome());
        $this->assertSame([
            'approved' => true,
            'events' => ['started', 'approved:yes:api'],
        ], $update->result());

        $command = WorkflowCommand::query()->findOrFail($update->commandId());
        $this->assertSame('mark-approved', $command->targetName());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $detail = RunDetailView::forRun($run->fresh());
        $timeline = HistoryTimeline::forRun($run);
        $updateEntries = array_values(array_filter(
            $timeline,
            static fn (array $entry): bool => str_starts_with($entry['type'], 'Update'),
        ));

        $this->assertSame(['mark-approved'], $detail['declared_updates']);
        $this->assertSame('mark-approved', $detail['commands'][1]['target_name']);
        $this->assertSame(['mark-approved', 'mark-approved', 'mark-approved'], array_column(
            $updateEntries,
            'update_name',
        ));
    }

    public function testCallingAliasedUpdateMethodRecordsTheDeclaredAlias(): void
    {
        $workflow = WorkflowStub::make(TestAliasedUpdateWorkflow::class, 'order-update-aliased-method');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $result = $workflow->applyApproval(true, 'console');

        $this->assertSame([
            'approved' => true,
            'events' => ['started', 'approved:yes:console'],
        ], $result);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $detail = RunDetailView::forRun($run->fresh());

        $this->assertSame(['mark-approved'], $detail['declared_updates']);
        $this->assertSame('mark-approved', $detail['commands'][1]['target_name']);
    }

    public function testAttemptUpdateRejectsPhpMethodNameWhenAnAliasIsDeclared(): void
    {
        $workflow = WorkflowStub::make(TestAliasedUpdateWorkflow::class, 'order-update-aliased-reject');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $result = $workflow->attemptUpdate('applyApproval', true, 'api');

        $this->assertTrue($result->rejected());
        $this->assertSame('rejected_unknown_update', $result->outcome());
        $this->assertSame('unknown_update', $result->rejectionReason());
    }

    public function testAttemptUpdateAppliesDurableStateAndReturnsTypedResult(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $update = $workflow->attemptUpdate('approve', true, 'api');

        $this->assertTrue($update->accepted());
        $this->assertTrue($update->completed());
        $this->assertSame('update_completed', $update->outcome());
        $this->assertSame(2, $update->commandSequence());
        $this->assertNotNull($update->updateId());
        $this->assertSame([
            'approved' => true,
            'events' => ['started', 'approved:yes:api'],
        ], $update->result());

        $this->assertSame([
            'stage' => 'waiting-for-name',
            'approved' => true,
            'events' => ['started', 'approved:yes:api'],
        ], $workflow->currentState());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $timeline = HistoryTimeline::forRun($run);
        $updateEntries = array_values(array_filter(
            $timeline,
            static fn (array $entry): bool => str_starts_with($entry['type'], 'Update'),
        ));

        $this->assertSame(
            ['UpdateAccepted', 'UpdateApplied', 'UpdateCompleted'],
            array_column($updateEntries, 'type'),
        );
        $this->assertSame(['workflow_command', 'workflow_command', 'workflow_command'], array_column(
            $updateEntries,
            'source_kind',
        ));
        $this->assertSame([$update->commandId(), $update->commandId(), $update->commandId()], array_column(
            $updateEntries,
            'source_id',
        ));
        $this->assertSame('command', $updateEntries[0]['kind']);
        $this->assertSame('update', $updateEntries[1]['kind']);
        $this->assertSame('approve', $updateEntries[0]['update_name']);
        $this->assertSame('approve', $updateEntries[1]['update_name']);
        $this->assertSame('approve', $updateEntries[2]['update_name']);
        $this->assertSame([null, null, 'update_completed'], array_column($updateEntries, 'command_outcome'));

        $this->assertDatabaseHas('workflow_updates', [
            'id' => $update->updateId(),
            'workflow_command_id' => $update->commandId(),
            'workflow_instance_id' => 'order-update',
            'workflow_run_id' => $workflow->runId(),
            'update_name' => 'approve',
            'status' => 'completed',
            'outcome' => 'update_completed',
            'command_sequence' => 2,
            'workflow_sequence' => 1,
        ]);

        $detail = RunDetailView::forRun($run->fresh());

        $this->assertTrue($detail['can_update']);
        $this->assertNull($detail['update_blocked_reason']);
        $this->assertTrue($detail['can_signal']);
        $this->assertNull($detail['signal_blocked_reason']);
        $this->assertTrue($detail['can_cancel']);
        $this->assertNull($detail['cancel_blocked_reason']);
        $this->assertTrue($detail['can_terminate']);
        $this->assertNull($detail['terminate_blocked_reason']);
        $this->assertFalse($detail['can_repair']);
        $this->assertSame('repair_not_needed', $detail['repair_blocked_reason']);
        $this->assertSame(['name-provided'], $detail['declared_signals']);
        $this->assertSame('name-provided', $detail['declared_signal_contracts'][0]['name']);
        $this->assertSame('name', $detail['declared_signal_contracts'][0]['parameters'][0]['name']);
        $this->assertTrue($detail['declared_signal_contracts'][0]['parameters'][0]['required']);
        $this->assertSame('string', $detail['declared_signal_contracts'][0]['parameters'][0]['type']);
        $this->assertSame(['approve', 'explode'], $detail['declared_updates']);
        $this->assertSame('approve', $detail['declared_update_contracts'][0]['name']);
        $this->assertSame('approved', $detail['declared_update_contracts'][0]['parameters'][0]['name']);
        $this->assertTrue($detail['declared_update_contracts'][0]['parameters'][0]['required']);
        $this->assertSame('bool', $detail['declared_update_contracts'][0]['parameters'][0]['type']);
        $this->assertSame('source', $detail['declared_update_contracts'][0]['parameters'][1]['name']);
        $this->assertFalse($detail['declared_update_contracts'][0]['parameters'][1]['required']);
        $this->assertSame('manual', $detail['declared_update_contracts'][0]['parameters'][1]['default']);
        $this->assertSame('durable_history', $detail['declared_contract_source']);
        $this->assertCount(2, $detail['commands']);
        $this->assertSame('update', $detail['commands'][1]['type']);
        $this->assertSame('approve', $detail['commands'][1]['target_name']);
        $this->assertSame($update->updateId(), $detail['commands'][1]['update_id']);
        $this->assertSame('completed', $detail['commands'][1]['update_status']);
        $this->assertTrue($detail['commands'][1]['result_available']);
        $this->assertSame([
            'approved' => true,
            'events' => ['started', 'approved:yes:api'],
        ], unserialize($detail['commands'][1]['result']));
        $this->assertCount(1, $detail['updates']);
        $this->assertSame($update->updateId(), $detail['updates'][0]['id']);
        $this->assertSame($update->commandId(), $detail['updates'][0]['command_id']);
        $this->assertSame(2, $detail['updates'][0]['command_sequence']);
        $this->assertSame(1, $detail['updates'][0]['workflow_sequence']);
        $this->assertSame('approve', $detail['updates'][0]['name']);
        $this->assertSame('completed', $detail['updates'][0]['status']);
        $this->assertSame('update_completed', $detail['updates'][0]['outcome']);
        $this->assertTrue($detail['updates'][0]['result_available']);
        $this->assertSame([
            'approved' => true,
            'events' => ['started', 'approved:yes:api'],
        ], unserialize($detail['updates'][0]['result']));

        $signal = $workflow->signal('name-provided', 'Taylor');

        $this->assertSame(3, $signal->commandSequence());

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        $this->assertSame([
            'approved' => true,
            'events' => ['started', 'approved:yes:api', 'signal:Taylor'],
            'workflow_id' => 'order-update',
            'run_id' => $workflow->runId(),
        ], $workflow->output());
    }

    public function testAttemptUpdateReturnsAcceptedLifecycleWhenCompletionWaitTimesOut(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('workflows.v2.update_wait.completion_timeout_seconds', 1);
        config()->set('workflows.v2.update_wait.poll_interval_milliseconds', 10);

        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-timeout');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());
        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        WorkflowRun::query()->findOrFail($workflow->runId())->forceFill([
            'compatibility' => 'build-timeout-test',
        ])->save();

        $startedAt = microtime(true);
        $result = $workflow->attemptUpdate('approve', true, 'timeout-test');
        $elapsedSeconds = microtime(true) - $startedAt;

        $this->assertTrue($result->accepted());
        $this->assertFalse($result->completed());
        $this->assertFalse($result->failed());
        $this->assertSame('accepted', $result->updateStatus());
        $this->assertSame('completed', $result->waitFor());
        $this->assertTrue($result->waitTimedOut());
        $this->assertSame(1, $result->waitTimeoutSeconds());
        $this->assertNull($result->outcome());
        $this->assertNull($result->result());
        $this->assertGreaterThanOrEqual(0.9, $elapsedSeconds);
        $this->assertLessThan(2.5, $elapsedSeconds);

        $this->assertDatabaseHas('workflow_updates', [
            'id' => $result->updateId(),
            'workflow_instance_id' => 'order-update-timeout',
            'status' => 'accepted',
            'outcome' => null,
            'workflow_sequence' => null,
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($workflow->runId());
        $detail = RunDetailView::forRun($run);
        $updateWait = $this->findWait($detail['waits'], 'update', 'approve');
        $signalWait = $this->findWait($detail['waits'], 'signal', 'name-provided');

        $this->assertSame('update', $detail['wait_kind']);
        $this->assertSame('Waiting for update approve', $detail['wait_reason']);
        $this->assertSame('workflow_task_waiting_for_compatible_worker', $detail['liveness_state']);
        $this->assertSame('update:' . $result->updateId(), $detail['open_wait_id']);
        $this->assertSame('workflow_update', $detail['resume_source_kind']);
        $this->assertSame($result->updateId(), $detail['resume_source_id']);
        $this->assertSame(2, $detail['open_wait_count']);
        $this->assertSame('open', $updateWait['status']);
        $this->assertSame('accepted', $updateWait['source_status']);
        $this->assertSame('Waiting for update approve.', $updateWait['summary']);
        $this->assertSame($result->updateId(), $updateWait['update_id']);
        $this->assertTrue($updateWait['task_backed']);
        $this->assertFalse($updateWait['external_only']);
        $this->assertSame('workflow_update', $updateWait['resume_source_kind']);
        $this->assertSame($result->updateId(), $updateWait['resume_source_id']);
        $this->assertSame('workflow', $updateWait['task_type']);
        $this->assertSame('ready', $updateWait['task_status']);
        $this->assertSame('open', $signalWait['status']);
        $this->assertSame('waiting', $signalWait['source_status']);
    }

    public function testInspectUpdateCanFollowTimedOutLifecycleUntilItCloses(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('workflows.v2.update_wait.completion_timeout_seconds', 1);
        config()->set('workflows.v2.update_wait.poll_interval_milliseconds', 10);

        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-inspect-timeout');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());
        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        WorkflowRun::query()->findOrFail($workflow->runId())->forceFill([
            'compatibility' => 'build-inspect-timeout',
        ])->save();

        $timedOut = $workflow->attemptUpdate('approve', true, 'inspect-timeout');
        $accepted = $workflow->inspectUpdate($timedOut->updateId());

        $this->assertTrue($accepted->accepted());
        $this->assertSame('accepted', $accepted->updateStatus());
        $this->assertSame('approve', $accepted->updateName());
        $this->assertNull($accepted->workflowSequence());
        $this->assertNull($accepted->result());
        $this->assertSame('status', $accepted->waitFor());
        $this->assertFalse($accepted->waitTimedOut());
        $this->assertNotNull($accepted->acceptedAt());
        $this->assertNull($accepted->closedAt());

        config()->set('workflows.v2.compatibility.supported', ['build-inspect-timeout']);

        $this->runReadyWorkflowTask($workflow->runId());

        $completed = $workflow->inspectUpdate($timedOut->updateId());

        $this->assertTrue($completed->completed());
        $this->assertSame('completed', $completed->updateStatus());
        $this->assertSame('update_completed', $completed->outcome());
        $this->assertSame('approve', $completed->updateName());
        $this->assertSame(1, $completed->workflowSequence());
        $this->assertSame([
            'approved' => true,
            'events' => ['started', 'approved:yes:inspect-timeout'],
        ], $completed->result());
        $this->assertNotNull($completed->closedAt());
    }

    public function testAcceptedUpdateWithoutWorkflowTaskMarksTheRunAsRepairNeeded(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');

        Queue::fake();

        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-repair-needed');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());
        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $update = $workflow->submitUpdate('approve', true, 'repair-test');

        WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->delete();

        /** @var WorkflowRun $projectedRun */
        $projectedRun = WorkflowRun::query()->findOrFail($workflow->runId());
        RunSummaryProjector::project($projectedRun->fresh([
            'instance',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
        ]));

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($workflow->runId());
        $detail = RunDetailView::forRun($run);
        $updateWait = $this->findWait($detail['waits'], 'update', 'approve');
        $signalWait = $this->findWait($detail['waits'], 'signal', 'name-provided');

        $this->assertSame('update', $run->summary?->wait_kind);
        $this->assertSame('repair_needed', $run->summary?->liveness_state);
        $this->assertSame('update', $detail['wait_kind']);
        $this->assertSame('repair_needed', $detail['liveness_state']);
        $this->assertSame('Accepted update approve is open without an open workflow task.', $detail['liveness_reason']);
        $this->assertTrue($detail['can_repair']);
        $this->assertNull($detail['repair_blocked_reason']);
        $this->assertSame('update:' . $update->updateId(), $detail['open_wait_id']);
        $this->assertSame('workflow_update', $detail['resume_source_kind']);
        $this->assertSame($update->updateId(), $detail['resume_source_id']);
        $this->assertSame(2, $detail['open_wait_count']);
        $this->assertSame('open', $updateWait['status']);
        $this->assertSame('accepted', $updateWait['source_status']);
        $this->assertFalse($updateWait['task_backed']);
        $this->assertSame($update->updateId(), $updateWait['update_id']);
        $this->assertSame('open', $signalWait['status']);
        $this->assertSame('waiting', $signalWait['source_status']);
    }

    public function testUpdateThrowsHelpfulMessageWhenCompletionWaitTimesOut(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('workflows.v2.update_wait.completion_timeout_seconds', 1);
        config()->set('workflows.v2.update_wait.poll_interval_milliseconds', 10);

        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-timeout-throws');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());
        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        WorkflowRun::query()->findOrFail($workflow->runId())->forceFill([
            'compatibility' => 'build-timeout-test',
        ])->save();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('accepted but did not finish applying after waiting 1 second');

        $workflow->update('approve', true, 'timeout-test');
    }

    public function testAttemptUpdateCanSignalAWaitingChildThroughTheCurrentHandle(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestChildHandleParentWorkflow::class, 'update-child-handle');
        $workflow->start();
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runReadyWorkflowTask($parentRunId);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->sole();

        $childRunId = $link->child_workflow_run_id;

        $this->assertIsString($childRunId);

        $childWorkflow = WorkflowStub::load($link->child_workflow_instance_id);

        $this->runReadyWorkflowTask($childRunId);

        $this->assertSame('waiting', $childWorkflow->refresh()->status());
        $this->assertSame('waiting-for-approval', $childWorkflow->query('current-stage'));

        $update = $workflow->attemptUpdate('approve-child', 'Taylor');

        $this->assertTrue($update->accepted());
        $this->assertTrue($update->completed());
        $this->assertSame('update_completed', $update->outcome());
        $this->assertNull($update->result());
        $this->assertSame('waiting-for-approval', $childWorkflow->query('current-stage'));

        $this->runReadyWorkflowTask($childRunId);
        $this->runReadyWorkflowTask($parentRunId);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertTrue($childWorkflow->refresh()->completed());
        $this->assertSame([
            'parent_workflow_id' => 'update-child-handle',
            'parent_run_id' => $parentRunId,
            'child' => [
                'approved_by' => 'Taylor',
                'workflow_id' => $link->child_workflow_instance_id,
                'run_id' => $childRunId,
            ],
        ], $workflow->output());
        $this->assertSame([
            'approved_by' => 'Taylor',
            'workflow_id' => $link->child_workflow_instance_id,
            'run_id' => $childRunId,
        ], $childWorkflow->output());
        $this->assertSame(1, WorkflowCommand::query()
            ->where('workflow_instance_id', $link->child_workflow_instance_id)
            ->where('command_type', 'signal')
            ->count());
        $this->assertSame(1, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $childRunId)
            ->where('event_type', 'SignalReceived')
            ->count());
        $this->assertSame(1, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $childRunId)
            ->where('event_type', 'SignalApplied')
            ->count());
    }

    public function testSubmitUpdateRecordsAcceptedLifecycleAndWorkerAppliesIt(): void
    {
        config()->set('queue.default', 'redis');

        Queue::fake();

        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-submitted');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $this->assertSame('waiting', $workflow->refresh()->status());

        $update = $workflow->submitUpdate('approve', true, 'api');

        $this->assertTrue($update->accepted());
        $this->assertFalse($update->completed());
        $this->assertNull($update->outcome());
        $this->assertSame('accepted', $update->updateStatus());
        $this->assertNull($update->result());
        $this->assertNotNull($update->updateId());

        $this->assertDatabaseHas('workflow_updates', [
            'id' => $update->updateId(),
            'workflow_command_id' => $update->commandId(),
            'workflow_instance_id' => 'order-update-submitted',
            'workflow_run_id' => $workflow->runId(),
            'update_name' => 'approve',
            'status' => 'accepted',
            'outcome' => null,
            'workflow_sequence' => null,
        ]);

        $this->assertSame([
            'stage' => 'waiting-for-name',
            'approved' => false,
            'events' => ['started'],
        ], $workflow->currentState());

        $this->runReadyWorkflowTask($workflow->runId());

        $this->assertSame([
            'stage' => 'waiting-for-name',
            'approved' => true,
            'events' => ['started', 'approved:yes:api'],
        ], $workflow->currentState());

        $this->assertDatabaseHas('workflow_updates', [
            'id' => $update->updateId(),
            'status' => 'completed',
            'outcome' => 'update_completed',
            'workflow_sequence' => 1,
        ]);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'SignalWaitOpened',
            'UpdateAccepted',
            'UpdateApplied',
            'UpdateCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testSubmittedUpdateFailureIsRecordedAndWorkerReplaysCleanly(): void
    {
        config()->set('queue.default', 'redis');

        Queue::fake();

        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-submitted-failure');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $update = $workflow->submitUpdate('explode', 'boom');

        $this->assertTrue($update->accepted());
        $this->assertSame('accepted', $update->updateStatus());

        $this->runReadyWorkflowTask($workflow->runId());

        $this->assertDatabaseHas('workflow_updates', [
            'id' => $update->updateId(),
            'status' => 'failed',
            'outcome' => 'update_failed',
            'workflow_sequence' => 1,
            'failure_message' => 'boom',
        ]);

        $this->assertDatabaseHas('workflow_failures', [
            'workflow_run_id' => $workflow->runId(),
            'source_kind' => 'workflow_command',
            'source_id' => $update->commandId(),
            'propagation_kind' => 'update',
            'message' => 'boom',
        ]);

        $this->runReadyWorkflowTask($workflow->runId());

        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame([
            'stage' => 'waiting-for-name',
            'approved' => false,
            'events' => ['started'],
        ], $workflow->currentState());

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'SignalWaitOpened',
            'UpdateAccepted',
            'UpdateCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testCallingAnnotatedUpdateMethodReturnsTheRawUpdateResult(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-dynamic');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $result = $workflow->approve(true, 'console');

        $this->assertSame([
            'approved' => true,
            'events' => ['started', 'approved:yes:console'],
        ], $result);

        $this->assertSame([
            'stage' => 'waiting-for-name',
            'approved' => true,
            'events' => ['started', 'approved:yes:console'],
        ], $workflow->currentState());
    }

    public function testAttemptUpdateCanonicalizesDefaultArgumentsInDurableHistory(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-defaults');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $result = $workflow->attemptUpdate('approve', true);

        $this->assertTrue($result->accepted());
        $this->assertTrue($result->completed());
        $this->assertSame([
            'approved' => true,
            'events' => ['started', 'approved:yes:manual'],
        ], $result->result());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        $accepted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', 'UpdateAccepted')
            ->sole();
        $applied = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', 'UpdateApplied')
            ->sole();

        $this->assertSame(
            [true, 'manual'],
            Serializer::unserialize($accepted->payload['arguments'] ?? serialize([])),
        );
        $this->assertSame(
            [true, 'manual'],
            Serializer::unserialize($applied->payload['arguments'] ?? serialize([])),
        );
    }

    public function testAttemptUpdateRejectsInvalidArgumentsBeforeApplication(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-invalid');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $result = $workflow->attemptUpdate('approve');

        $this->assertTrue($result->rejected());
        $this->assertTrue($result->rejectedInvalidArguments());
        $this->assertSame('rejected_invalid_arguments', $result->outcome());
        $this->assertSame('invalid_update_arguments', $result->rejectionReason());
        $this->assertSame([
            'approved' => ['The approved argument is required.'],
        ], $result->validationErrors());
        $this->assertSame([
            'stage' => 'waiting-for-name',
            'approved' => false,
            'events' => ['started'],
        ], $workflow->currentState());

        $this->assertSame(0, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', 'UpdateAccepted')
            ->count());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $detail = RunDetailView::forRun($run->fresh(['summary']));

        $this->assertSame('approve', $detail['commands'][1]['target_name']);
        $this->assertSame('invalid_update_arguments', $detail['commands'][1]['rejection_reason']);
        $this->assertSame([
            'approved' => ['The approved argument is required.'],
        ], $detail['commands'][1]['validation_errors']);
    }

    public function testAttemptUpdateRejectsNullArgumentsWhenTheContractDisallowsNull(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-null-invalid');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $result = $workflow->attemptUpdate('approve', null);

        $this->assertTrue($result->rejected());
        $this->assertTrue($result->rejectedInvalidArguments());
        $this->assertSame('rejected_invalid_arguments', $result->outcome());
        $this->assertSame('invalid_update_arguments', $result->rejectionReason());
        $this->assertSame([
            'approved' => ['The approved argument cannot be null.'],
        ], $result->validationErrors());
        $this->assertSame([
            'stage' => 'waiting-for-name',
            'approved' => false,
            'events' => ['started'],
        ], $workflow->currentState());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $detail = RunDetailView::forRun($run->fresh(['summary']));

        $this->assertSame('approve', $detail['commands'][1]['target_name']);
        $this->assertSame('invalid_update_arguments', $detail['commands'][1]['rejection_reason']);
        $this->assertSame([
            'approved' => ['The approved argument cannot be null.'],
        ], $detail['commands'][1]['validation_errors']);
    }

    public function testAttemptUpdateRejectsLaterUpdateWhileAnEarlierSignalIsStillPending(): void
    {
        $workflow = WorkflowStub::make(TestSignalThenUpdateWorkflow::class, 'order-update-linearized');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        Queue::fake();

        $signal = $workflow->signal('advance', 'Taylor');

        /** @var WorkflowRun $pendingRun */
        $pendingRun = WorkflowRun::query()->with('summary')->findOrFail($workflow->runId());
        $pendingDetail = RunDetailView::forRun($pendingRun);

        $this->assertSame('workflow-task', $pendingDetail['wait_kind']);
        $this->assertSame('workflow_task_ready', $pendingDetail['liveness_state']);
        $this->assertFalse($pendingDetail['can_update']);
        $this->assertSame('earlier_signal_pending', $pendingDetail['update_blocked_reason']);

        $result = $workflow->attemptUpdate('approve', true, 'api');

        $this->assertTrue($signal->accepted());
        $this->assertTrue($result->rejected());
        $this->assertTrue($result->rejectedPendingSignal());
        $this->assertSame('rejected_pending_signal', $result->outcome());
        $this->assertSame('earlier_signal_pending', $result->rejectionReason());
        $this->assertSame(3, $result->commandSequence());
        $this->assertNull($result->result());
        $this->assertSame([
            'stage' => 'waiting-for-advance',
            'name' => null,
            'approved' => false,
            'events' => ['started'],
        ], $workflow->currentState());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'order-update-linearized',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'update',
            'status' => 'rejected',
            'outcome' => 'rejected_pending_signal',
            'rejection_reason' => 'earlier_signal_pending',
        ]);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'SignalWaitOpened',
            'SignalReceived',
            'UpdateRejected',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());

        $timeline = HistoryTimeline::forRun(WorkflowRun::query()->findOrFail($workflow->runId()));
        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'SignalWaitOpened',
            'SignalReceived',
            'UpdateRejected',
        ], array_column($timeline, 'type'));

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($workflow->runId());
        $detail = RunDetailView::forRun($run);
        $signalWait = collect($detail['waits'])
            ->first(static fn (array $wait): bool => ($wait['kind'] ?? null) === 'signal' && ($wait['target_name'] ?? null) === 'advance');

        $this->assertSame('workflow-task', $detail['wait_kind']);
        $this->assertSame('workflow_task_ready', $detail['liveness_state']);
        $this->assertTrue($detail['can_signal']);
        $this->assertNull($detail['signal_blocked_reason']);
        $this->assertFalse($detail['can_update']);
        $this->assertSame('earlier_signal_pending', $detail['update_blocked_reason']);
        $this->assertSame('approve', $detail['commands'][2]['target_name']);
        $this->assertIsArray($signalWait);
        $this->assertSame('resolved', $signalWait['status']);
        $this->assertSame('received', $signalWait['source_status']);
    }

    public function testAttemptUpdateDoesNotInlineDrainASignalThatWouldCloseTheRun(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-blocked');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        Queue::fake();

        $signal = $workflow->signal('name-provided', 'Taylor');
        $result = $workflow->attemptUpdate('approve', true, 'api');

        $this->assertTrue($signal->accepted());
        $this->assertTrue($result->rejected());
        $this->assertTrue($result->rejectedPendingSignal());
        $this->assertSame('rejected_pending_signal', $result->outcome());
        $this->assertSame('earlier_signal_pending', $result->rejectionReason());
        $this->assertSame(3, $result->commandSequence());
        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame([
            'stage' => 'waiting-for-name',
            'approved' => false,
            'events' => ['started'],
        ], $workflow->currentState());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'order-update-blocked',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'update',
            'status' => 'rejected',
            'outcome' => 'rejected_pending_signal',
            'rejection_reason' => 'earlier_signal_pending',
        ]);

        $this->assertSame(0, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', 'UpdateAccepted')
            ->count());

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'SignalWaitOpened',
            'SignalReceived',
            'UpdateRejected',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());

        $timeline = HistoryTimeline::forRun(WorkflowRun::query()->findOrFail($workflow->runId()));
        $rejectedUpdate = collect($timeline)
            ->firstWhere('type', 'UpdateRejected');

        $this->assertIsArray($rejectedUpdate);
        $this->assertSame('workflow_command', $rejectedUpdate['source_kind']);
        $this->assertSame($result->commandId(), $rejectedUpdate['source_id']);
        $this->assertSame('command', $rejectedUpdate['kind']);
        $this->assertSame('approve', $rejectedUpdate['update_name']);
        $this->assertSame('rejected', $rejectedUpdate['command_status']);
        $this->assertSame('rejected_pending_signal', $rejectedUpdate['command_outcome']);
        $this->assertSame('Rejected update approve: earlier_signal_pending.', $rejectedUpdate['summary']);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($workflow->runId());
        $detail = RunDetailView::forRun($run);

        $this->assertSame('waiting', $detail['status']);
        $this->assertTrue($detail['can_signal']);
        $this->assertNull($detail['signal_blocked_reason']);
        $this->assertFalse($detail['can_update']);
        $this->assertSame('earlier_signal_pending', $detail['update_blocked_reason']);
        $this->assertSame('approve', $detail['commands'][2]['target_name']);
    }

    public function testLegacyRunsBackfillCommandSequenceBeforeRecordingLaterCommands(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'legacy-update-seq');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        WorkflowCommand::query()
            ->where('workflow_run_id', $workflow->runId())
            ->update([
                'command_sequence' => null,
            ]);

        WorkflowRun::query()
            ->whereKey($workflow->runId())
            ->update([
                'last_command_sequence' => 0,
            ]);

        Queue::fake();

        $signal = $workflow->signal('name-provided', 'Taylor');
        $update = $workflow->attemptUpdate('approve', true, 'api');

        $this->assertSame(2, $signal->commandSequence());
        $this->assertSame(3, $update->commandSequence());
        $this->assertTrue($update->rejected());
        $this->assertSame('rejected_pending_signal', $update->outcome());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        $this->assertSame(3, $run->last_command_sequence);
        $this->assertSame(
            [1, 2, 3],
            WorkflowCommand::query()
                ->where('workflow_run_id', $run->id)
                ->orderBy('command_sequence')
                ->pluck('command_sequence')
                ->all(),
        );
        $this->assertSame(
            ['start', 'signal', 'update'],
            WorkflowCommand::query()
                ->where('workflow_run_id', $run->id)
                ->orderBy('command_sequence')
                ->pluck('command_type')
                ->map(static fn ($commandType) => $commandType->value)
                ->all(),
        );

        $detail = RunDetailView::forRun($run->fresh(['summary']));

        $this->assertSame([1, 2, 3], array_column($detail['commands'], 'sequence'));
        $this->assertTrue($detail['can_signal']);
        $this->assertNull($detail['signal_blocked_reason']);
        $this->assertFalse($detail['can_update']);
        $this->assertSame('earlier_signal_pending', $detail['update_blocked_reason']);

        $timeline = HistoryTimeline::forRun($run->fresh());
        $startAccepted = collect($timeline)
            ->firstWhere('type', 'StartAccepted');
        $signalReceived = collect($timeline)
            ->firstWhere('type', 'SignalReceived');
        $updateRejected = collect($timeline)
            ->firstWhere('type', 'UpdateRejected');

        $this->assertIsArray($startAccepted);
        $this->assertIsArray($signalReceived);
        $this->assertIsArray($updateRejected);
        $this->assertSame(1, $startAccepted['command_sequence']);
        $this->assertSame(2, $signalReceived['command_sequence']);
        $this->assertSame(3, $updateRejected['command_sequence']);
    }

    public function testAttemptSignalBackfillsMissingCommandContractOnWorkflowStartedHistory(): void
    {
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'legacy-command-contract-signal',
            'workflow_class' => TestUpdateWorkflow::class,
            'workflow_type' => 'test-update-workflow',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestUpdateWorkflow::class,
            'workflow_type' => 'test-update-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(20),
            'last_history_sequence' => 2,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        WorkflowHistoryEvent::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => 'WorkflowStarted',
            'payload' => [
                'workflow_class' => TestUpdateWorkflow::class,
                'workflow_type' => 'test-update-workflow',
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
            ],
            'recorded_at' => now()
                ->subSeconds(19),
        ]);

        WorkflowHistoryEvent::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'event_type' => 'SignalWaitOpened',
            'payload' => [
                'signal_name' => 'name-provided',
                'signal_wait_id' => '01JTESTSIGNALWAITBACKFILL01',
                'sequence' => 1,
            ],
            'recorded_at' => now()
                ->subSeconds(18),
        ]);

        $result = WorkflowStub::load($instance->id)->attemptSignal('name-provided', 'Taylor');

        $this->assertTrue($result->accepted());
        $this->assertSame('signal_received', $result->outcome());

        /** @var WorkflowHistoryEvent $started */
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', 'WorkflowStarted')
            ->sole();

        $this->assertSame(['name-provided'], $started->payload['declared_signals'] ?? null);
        $this->assertSame('name-provided', $started->payload['declared_signal_contracts'][0]['name'] ?? null);
        $this->assertSame(
            'name',
            $started->payload['declared_signal_contracts'][0]['parameters'][0]['name'] ?? null,
        );
        $this->assertSame(['approve', 'explode'], $started->payload['declared_updates'] ?? null);

        $detail = RunDetailView::forRun($run->fresh());

        $this->assertSame(['name-provided'], $detail['declared_signals']);
        $this->assertSame('name-provided', $detail['declared_signal_contracts'][0]['name']);
        $this->assertSame(['approve', 'explode'], $detail['declared_updates']);
        $this->assertSame('durable_history', $detail['declared_contract_source']);
    }

    public function testUpdateFailuresAreRecordedWithoutClosingTheRun(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-failure');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $result = $workflow->attemptUpdate('explode', 'boom');

        $this->assertTrue($result->accepted());
        $this->assertTrue($result->failed());
        $this->assertSame('update_failed', $result->outcome());
        $this->assertSame('boom', $result->failureMessage());
        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame([
            'stage' => 'waiting-for-name',
            'approved' => false,
            'events' => ['started'],
        ], $workflow->currentState());

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()
            ->where('source_id', $result->commandId())
            ->firstOrFail();
        $update = WorkflowUpdate::query()
            ->where('workflow_command_id', $result->commandId())
            ->firstOrFail();

        $this->assertSame('workflow_command', $failure->source_kind);
        $this->assertSame('update', $failure->propagation_kind);
        $this->assertSame('boom', $failure->message);
        $this->assertSame('failed', $update->status->value);
        $this->assertSame('update_failed', $update->outcome?->value);
        $this->assertSame($failure->id, $update->failure_id);
        $this->assertSame('boom', $update->failure_message);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'SignalWaitOpened',
            'UpdateAccepted',
            'UpdateCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testAttemptUpdateRejectsUnknownUpdateTarget(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-unknown');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $result = $workflow->attemptUpdate('missingUpdate', true);

        $this->assertTrue($result->rejected());
        $this->assertSame('rejected_unknown_update', $result->outcome());
        $this->assertSame('unknown_update', $result->rejectionReason());
        $this->assertNull($result->result());
        $this->assertSame([
            'stage' => 'waiting-for-name',
            'approved' => false,
            'events' => ['started'],
        ], $workflow->currentState());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'order-update-unknown',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'update',
            'status' => 'rejected',
            'outcome' => 'rejected_unknown_update',
            'rejection_reason' => 'unknown_update',
        ]);
        $this->assertDatabaseHas('workflow_updates', [
            'workflow_command_id' => $result->commandId(),
            'workflow_instance_id' => 'order-update-unknown',
            'workflow_run_id' => $workflow->runId(),
            'update_name' => 'missingUpdate',
            'status' => 'rejected',
            'outcome' => 'rejected_unknown_update',
            'rejection_reason' => 'unknown_update',
        ]);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'SignalWaitOpened',
            'UpdateRejected',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $detail = RunDetailView::forRun($run);

        $this->assertSame('missingUpdate', $detail['commands'][1]['target_name']);
    }

    public function testSignalIntakeUsesDurableRunContractWhenWorkflowClassCannotBeResolved(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-ct-signal');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        Queue::fake();

        WorkflowRun::query()->whereKey($workflow->runId())->update([
            'workflow_class' => 'Missing\\Workflow\\TestUpdateWorkflow',
        ]);

        $accepted = $workflow->attemptSignal('name-provided', 'Taylor');
        $rejected = $workflow->attemptSignal('not-declared', 'Taylor');

        $this->assertTrue($accepted->accepted());
        $this->assertSame('signal_received', $accepted->outcome());
        $this->assertTrue($rejected->rejected());
        $this->assertSame('rejected_unknown_signal', $rejected->outcome());
        $this->assertSame('unknown_signal', $rejected->rejectionReason());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $detail = RunDetailView::forRun($run);

        $this->assertSame(['name-provided'], $detail['declared_signals']);
        $this->assertSame(['approve', 'explode'], $detail['declared_updates']);
        $this->assertSame('durable_history', $detail['declared_contract_source']);
    }

    public function testUnknownUpdateRejectionUsesDurableRunContractWhenWorkflowClassCannotBeResolved(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-ct-update');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        WorkflowRun::query()->whereKey($workflow->runId())->update([
            'workflow_class' => 'Missing\\Workflow\\TestUpdateWorkflow',
        ]);

        $result = $workflow->attemptUpdate('missing-update', true, 'api');

        $this->assertTrue($result->rejected());
        $this->assertSame('rejected_unknown_update', $result->outcome());
        $this->assertSame('unknown_update', $result->rejectionReason());
    }

    public function testNamedUpdateValidationUsesDurableRunContractWhenWorkflowDefinitionCannotBeResolved(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-ct-update-validation');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        WorkflowRun::query()->whereKey($workflow->runId())->update([
            'workflow_class' => 'Missing\\Workflow\\TestUpdateWorkflow',
            'workflow_type' => 'missing-update-workflow',
        ]);

        $result = $workflow->attemptUpdateWithArguments('approve', [
            'source' => 'api',
        ]);

        $this->assertTrue($result->rejected());
        $this->assertTrue($result->rejectedInvalidArguments());
        $this->assertSame('rejected_invalid_arguments', $result->outcome());
        $this->assertSame('invalid_update_arguments', $result->rejectionReason());
        $this->assertSame([
            'approved' => ['The approved argument is required.'],
        ], $result->validationErrors());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $detail = RunDetailView::forRun($run->fresh());

        $this->assertSame('durable_history', $detail['declared_contract_source']);
        $this->assertSame('approve', $detail['commands'][1]['target_name']);
        $this->assertSame('invalid_update_arguments', $detail['commands'][1]['rejection_reason']);
        $this->assertSame([
            'approved' => ['The approved argument is required.'],
        ], $detail['commands'][1]['validation_errors']);
    }

    public function testAttemptUpdateRejectsWhenWorkflowDefinitionCannotBeResolvedAfterDurableValidation(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-ct-update-execution');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        WorkflowRun::query()->whereKey($workflow->runId())->update([
            'workflow_class' => 'Missing\\Workflow\\TestUpdateWorkflow',
            'workflow_type' => 'missing-update-workflow',
        ]);

        $result = $workflow->attemptUpdateWithArguments('approve', [
            'approved' => true,
            'source' => 'api',
        ]);

        $this->assertTrue($result->rejected());
        $this->assertSame('rejected_workflow_definition_unavailable', $result->outcome());
        $this->assertSame('workflow_definition_unavailable', $result->rejectionReason());
        $this->assertNull($result->result());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'order-ct-update-execution',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'update',
            'status' => 'rejected',
            'outcome' => 'rejected_workflow_definition_unavailable',
            'rejection_reason' => 'workflow_definition_unavailable',
        ]);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'SignalWaitOpened',
            'UpdateRejected',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());

        /** @var WorkflowHistoryEvent $rejectedEvent */
        $rejectedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', 'UpdateRejected')
            ->firstOrFail();

        $this->assertSame('approve', $rejectedEvent->payload['update_name'] ?? null);
        $this->assertSame([true, 'api'], Serializer::unserialize($rejectedEvent->payload['arguments'] ?? serialize([])));

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $detail = RunDetailView::forRun($run->fresh());

        $this->assertSame('durable_history', $detail['declared_contract_source']);
        $this->assertSame('approve', $detail['commands'][1]['target_name']);
        $this->assertSame('rejected_workflow_definition_unavailable', $detail['commands'][1]['outcome']);
        $this->assertSame('workflow_definition_unavailable', $detail['commands'][1]['rejection_reason']);
    }

    public function testAttemptUpdateRejectsHistoricalSelectedRuns(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'order-update-historical',
            'workflow_class' => TestUpdateWorkflow::class,
            'workflow_type' => 'test-update-workflow',
            'run_count' => 2,
            'started_at' => now()
                ->subMinutes(5),
        ]);

        /** @var WorkflowRun $historicalRun */
        $historicalRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestUpdateWorkflow::class,
            'workflow_type' => 'test-update-workflow',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(5),
            'closed_at' => now()
                ->subMinutes(4),
            'last_progress_at' => now()
                ->subMinutes(4),
        ]);

        /** @var WorkflowRun $currentRun */
        $currentRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'workflow_class' => TestUpdateWorkflow::class,
            'workflow_type' => 'test-update-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $currentRun->id,
        ])->save();

        $result = WorkflowStub::loadRun($historicalRun->id)->attemptUpdate('approve', true);

        $this->assertTrue($result->rejected());
        $this->assertTrue($result->rejectedNotCurrent());
        $this->assertSame('selected_run_not_current', $result->rejectionReason());
        $this->assertSame($historicalRun->id, $result->runId());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $historicalRun->id,
            'command_type' => 'update',
            'target_scope' => 'run',
            'status' => 'rejected',
            'outcome' => 'rejected_not_current',
            'rejection_reason' => 'selected_run_not_current',
        ]);

        $this->assertSame(['UpdateRejected'], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $historicalRun->id)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());

        $detail = RunDetailView::forRun($historicalRun->fresh());

        $this->assertSame('approve', $detail['commands'][0]['target_name']);
        $this->assertSame('selected_run_not_current', $detail['commands'][0]['rejection_reason']);
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

    private function waitFor(callable $condition): void
    {
        $startedAt = microtime(true);

        while ((microtime(true) - $startedAt) < 5) {
            if ($condition()) {
                return;
            }

            usleep(100000);
        }

        $this->fail('Condition was not met within 5 seconds.');
    }

    /**
     * @param list<array<string, mixed>> $waits
     */
    private function findWait(array $waits, string $kind, ?string $targetName = null): array
    {
        foreach ($waits as $wait) {
            if (($wait['kind'] ?? null) !== $kind) {
                continue;
            }

            if ($targetName !== null && ($wait['target_name'] ?? null) !== $targetName) {
                continue;
            }

            return $wait;
        }

        $this->fail(sprintf('Unable to find %s wait.', $kind));
    }
}
