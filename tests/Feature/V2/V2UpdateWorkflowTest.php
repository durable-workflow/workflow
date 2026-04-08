<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestAliasedUpdateWorkflow;
use Tests\Fixtures\V2\TestSignalThenUpdateWorkflow;
use Tests\Fixtures\V2\TestUpdateWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\Support\RunDetailView;
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
        $this->assertTrue($detail['commands'][1]['result_available']);
        $this->assertSame([
            'approved' => true,
            'events' => ['started', 'approved:yes:api'],
        ], unserialize($detail['commands'][1]['result']));

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

    public function testAttemptUpdateProcessesEarlierSignalBeforeApplyingLaterUpdate(): void
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
        $this->assertTrue($pendingDetail['can_update']);
        $this->assertNull($pendingDetail['update_blocked_reason']);

        $result = $workflow->attemptUpdate('approve', true, 'api');

        $this->assertTrue($signal->accepted());
        $this->assertTrue($result->accepted());
        $this->assertTrue($result->completed());
        $this->assertSame('update_completed', $result->outcome());
        $this->assertSame(3, $result->commandSequence());
        $this->assertSame([
            'stage' => 'waiting-for-finish',
            'name' => 'Taylor',
            'approved' => true,
            'events' => ['started', 'signal:Taylor', 'approved:yes:api'],
        ], $result->result());
        $this->assertSame([
            'stage' => 'waiting-for-finish',
            'name' => 'Taylor',
            'approved' => true,
            'events' => ['started', 'signal:Taylor', 'approved:yes:api'],
        ], $workflow->currentState());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'order-update-linearized',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'update',
            'status' => 'accepted',
            'outcome' => 'update_completed',
        ]);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'SignalWaitOpened',
            'SignalReceived',
            'SignalApplied',
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

        $timeline = HistoryTimeline::forRun(WorkflowRun::query()->findOrFail($workflow->runId()));
        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'SignalWaitOpened',
            'SignalReceived',
            'SignalApplied',
            'SignalWaitOpened',
            'UpdateAccepted',
            'UpdateApplied',
            'UpdateCompleted',
        ], array_column($timeline, 'type'));

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($workflow->runId());
        $detail = RunDetailView::forRun($run);
        $signalWait = collect($detail['waits'])
            ->first(static fn (array $wait): bool => ($wait['kind'] ?? null) === 'signal' && ($wait['target_name'] ?? null) === 'finish');

        $this->assertSame('signal', $detail['wait_kind']);
        $this->assertSame('waiting_for_signal', $detail['liveness_state']);
        $this->assertTrue($detail['can_signal']);
        $this->assertNull($detail['signal_blocked_reason']);
        $this->assertTrue($detail['can_update']);
        $this->assertNull($detail['update_blocked_reason']);
        $this->assertSame('approve', $detail['commands'][2]['target_name']);
        $this->assertIsArray($signalWait);
        $this->assertSame('open', $signalWait['status']);
        $this->assertSame('waiting', $signalWait['source_status']);
    }

    public function testAttemptUpdateProcessesEarlierSignalBeforeRejectingClosedRun(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-blocked');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        Queue::fake();

        $signal = $workflow->signal('name-provided', 'Taylor');
        $result = $workflow->attemptUpdate('approve', true, 'api');

        $this->assertTrue($signal->accepted());
        $this->assertTrue($result->rejected());
        $this->assertSame('rejected_not_active', $result->outcome());
        $this->assertSame('run_not_active', $result->rejectionReason());
        $this->assertSame(3, $result->commandSequence());
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'stage' => 'completed',
            'approved' => false,
            'events' => ['started', 'signal:Taylor'],
        ], $workflow->currentState());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'order-update-blocked',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'update',
            'status' => 'rejected',
            'outcome' => 'rejected_not_active',
            'rejection_reason' => 'run_not_active',
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
            'SignalApplied',
            'WorkflowCompleted',
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
        $this->assertSame('rejected_not_active', $rejectedUpdate['command_outcome']);
        $this->assertSame('Rejected update approve: run_not_active.', $rejectedUpdate['summary']);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($workflow->runId());
        $detail = RunDetailView::forRun($run);

        $this->assertSame('completed', $detail['status']);
        $this->assertFalse($detail['can_signal']);
        $this->assertSame('run_closed', $detail['signal_blocked_reason']);
        $this->assertFalse($detail['can_update']);
        $this->assertSame('run_closed', $detail['update_blocked_reason']);
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
        $this->assertSame('rejected_not_active', $update->outcome());

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
        $this->assertFalse($detail['can_signal']);
        $this->assertSame('run_closed', $detail['signal_blocked_reason']);
        $this->assertFalse($detail['can_update']);
        $this->assertSame('run_closed', $detail['update_blocked_reason']);

        $timeline = HistoryTimeline::forRun($run->fresh());
        $startAccepted = collect($timeline)
            ->firstWhere('type', 'StartAccepted');
        $signalReceived = collect($timeline)
            ->firstWhere('type', 'SignalReceived');
        $signalApplied = collect($timeline)
            ->firstWhere('type', 'SignalApplied');

        $this->assertIsArray($startAccepted);
        $this->assertIsArray($signalReceived);
        $this->assertIsArray($signalApplied);
        $this->assertSame(1, $startAccepted['command_sequence']);
        $this->assertSame(2, $signalReceived['command_sequence']);
        $this->assertSame(2, $signalApplied['command_sequence']);
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

        $this->assertSame('workflow_command', $failure->source_kind);
        $this->assertSame('update', $failure->propagation_kind);
        $this->assertSame('boom', $failure->message);

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
}
