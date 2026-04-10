<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Support\Str;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowUpdate;

final class V2BackfillCommandLifecyclesCommandTest extends TestCase
{
    public function testDryRunReportsMissingSignalAndUpdateLifecyclesWithoutMutatingRows(): void
    {
        [$instance, $run] = $this->createRun('command-lifecycle-dry-run');
        $signalCommand = $this->createLegacyAcceptedSignal($instance, $run);
        $updateCommand = $this->createLegacyCompletedUpdate($instance, $run);

        $expected = [
            'dry_run' => true,
            'commands_matched' => 2,
            'signal_lifecycles_backfilled' => 0,
            'signal_lifecycles_would_backfill' => 1,
            'update_lifecycles_backfilled' => 0,
            'update_lifecycles_would_backfill' => 1,
            'history_events_backfilled' => 0,
            'history_events_would_backfill' => 4,
            'failures' => [],
        ];

        $this->artisan('workflow:v2:backfill-command-lifecycles', [
            '--instance-id' => $instance->id,
            '--dry-run' => true,
            '--json' => true,
        ])
            ->expectsOutput(json_encode($expected, JSON_UNESCAPED_SLASHES))
            ->assertSuccessful();

        $this->assertNull(WorkflowSignal::query()->where('workflow_command_id', $signalCommand->id)->first());
        $this->assertNull(WorkflowUpdate::query()->where('workflow_command_id', $updateCommand->id)->first());

        $signalReceived = WorkflowHistoryEvent::query()
            ->where('workflow_command_id', $signalCommand->id)
            ->where('event_type', HistoryEventType::SignalReceived->value)
            ->firstOrFail();
        $updateEvents = WorkflowHistoryEvent::query()
            ->where('workflow_command_id', $updateCommand->id)
            ->whereIn('event_type', [
                HistoryEventType::UpdateAccepted->value,
                HistoryEventType::UpdateApplied->value,
                HistoryEventType::UpdateCompleted->value,
            ])
            ->get();

        $this->assertArrayNotHasKey('signal_id', $signalReceived->payload);

        foreach ($updateEvents as $event) {
            $this->assertArrayNotHasKey('update_id', $event->payload);
        }
    }

    public function testItBackfillsAcceptedSignalAndCompletedUpdateLifecyclesAndStampsHistory(): void
    {
        [$instance, $run] = $this->createRun('command-lifecycle-backfill');
        $signalCommand = $this->createLegacyAcceptedSignal($instance, $run);
        $updateCommand = $this->createLegacyCompletedUpdate($instance, $run);

        $this->artisan('workflow:v2:backfill-command-lifecycles', [
            '--instance-id' => $instance->id,
        ])
            ->expectsOutput('Backfilled 1 signal lifecycle(s), 1 update lifecycle(s), and stamped 4 history event(s).')
            ->assertSuccessful();

        /** @var WorkflowSignal $signal */
        $signal = WorkflowSignal::query()
            ->where('workflow_command_id', $signalCommand->id)
            ->firstOrFail();
        /** @var WorkflowUpdate $update */
        $update = WorkflowUpdate::query()
            ->where('workflow_command_id', $updateCommand->id)
            ->firstOrFail();

        $this->assertSame($instance->id, $signal->workflow_instance_id);
        $this->assertSame($run->id, $signal->workflow_run_id);
        $this->assertSame('approved-by', $signal->signal_name);
        $this->assertSame('legacy-signal-wait', $signal->signal_wait_id);
        $this->assertSame('received', $signal->status->value);
        $this->assertSame('signal_received', $signal->outcome?->value);
        $this->assertSame(['Taylor'], $signal->signalArguments());

        $this->assertSame($instance->id, $update->workflow_instance_id);
        $this->assertSame($run->id, $update->workflow_run_id);
        $this->assertSame('mark-approved', $update->update_name);
        $this->assertSame('completed', $update->status->value);
        $this->assertSame('update_completed', $update->outcome?->value);
        $this->assertSame(1, $update->workflow_sequence);
        $this->assertSame([true, 'api'], $update->updateArguments());
        $this->assertSame([
            'approved' => true,
            'source' => 'api',
        ], $update->updateResult());

        $signalReceived = WorkflowHistoryEvent::query()
            ->where('workflow_command_id', $signalCommand->id)
            ->where('event_type', HistoryEventType::SignalReceived->value)
            ->firstOrFail();
        $updateEvents = WorkflowHistoryEvent::query()
            ->where('workflow_command_id', $updateCommand->id)
            ->whereIn('event_type', [
                HistoryEventType::UpdateAccepted->value,
                HistoryEventType::UpdateApplied->value,
                HistoryEventType::UpdateCompleted->value,
            ])
            ->get();

        $this->assertSame($signal->id, $signalReceived->payload['signal_id'] ?? null);
        $this->assertSame('legacy-signal-wait', $signalReceived->payload['signal_wait_id'] ?? null);

        foreach ($updateEvents as $event) {
            $this->assertSame($update->id, $event->payload['update_id'] ?? null);
        }

        $expected = [
            'dry_run' => true,
            'commands_matched' => 2,
            'signal_lifecycles_backfilled' => 0,
            'signal_lifecycles_would_backfill' => 0,
            'update_lifecycles_backfilled' => 0,
            'update_lifecycles_would_backfill' => 0,
            'history_events_backfilled' => 0,
            'history_events_would_backfill' => 0,
            'failures' => [],
        ];

        $this->artisan('workflow:v2:backfill-command-lifecycles', [
            '--instance-id' => $instance->id,
            '--dry-run' => true,
            '--json' => true,
        ])
            ->expectsOutput(json_encode($expected, JSON_UNESCAPED_SLASHES))
            ->assertSuccessful();
    }

    public function testItBackfillsRejectedSignalAndFailedUpdateLifecycleDetails(): void
    {
        [$instance, $run] = $this->createRun('command-lifecycle-failure');

        $signalCommand = WorkflowCommand::record($instance, null, [
            'command_type' => CommandType::Signal->value,
            'target_scope' => 'instance',
            'status' => CommandStatus::Rejected->value,
            'outcome' => CommandOutcome::RejectedNotStarted->value,
            'payload_codec' => config('workflows.serializer'),
            'payload' => Serializer::serialize([
                'name' => 'approved-by',
                'arguments' => ['Taylor'],
            ]),
            'rejection_reason' => 'instance_not_started',
            'rejected_at' => now()->subSeconds(20),
        ]);

        $updateCommand = WorkflowCommand::record($instance, $run, [
            'command_type' => CommandType::Update->value,
            'target_scope' => 'instance',
            'status' => CommandStatus::Accepted->value,
            'outcome' => CommandOutcome::UpdateFailed->value,
            'payload_codec' => config('workflows.serializer'),
            'payload' => Serializer::serialize([
                'name' => 'mark-approved',
                'arguments' => [false, 'api'],
            ]),
            'accepted_at' => now()->subSeconds(15),
            'applied_at' => now()->subSeconds(10),
        ]);

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()->create([
            'workflow_run_id' => $run->id,
            'source_kind' => 'workflow_command',
            'source_id' => $updateCommand->id,
            'propagation_kind' => 'update',
            'handled' => false,
            'exception_class' => \RuntimeException::class,
            'message' => 'Update exploded.',
            'file' => __FILE__,
            'line' => __LINE__,
            'trace_preview' => 'stack',
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateAccepted, [
            'workflow_command_id' => $updateCommand->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'update_name' => 'mark-approved',
            'arguments' => Serializer::serialize([false, 'api']),
        ], null, $updateCommand);
        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateCompleted, [
            'workflow_command_id' => $updateCommand->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'update_name' => 'mark-approved',
            'sequence' => 1,
            'failure_id' => $failure->id,
            'message' => $failure->message,
        ], null, $updateCommand);

        $this->artisan('workflow:v2:backfill-command-lifecycles', [
            '--instance-id' => $instance->id,
        ])
            ->expectsOutput('Backfilled 1 signal lifecycle(s), 1 update lifecycle(s), and stamped 2 history event(s).')
            ->assertSuccessful();

        /** @var WorkflowSignal $signal */
        $signal = WorkflowSignal::query()
            ->where('workflow_command_id', $signalCommand->id)
            ->firstOrFail();
        /** @var WorkflowUpdate $update */
        $update = WorkflowUpdate::query()
            ->where('workflow_command_id', $updateCommand->id)
            ->firstOrFail();

        $this->assertNull($signal->workflow_run_id);
        $this->assertSame('approved-by', $signal->signal_name);
        $this->assertSame('rejected', $signal->status->value);
        $this->assertSame('rejected_not_started', $signal->outcome?->value);
        $this->assertSame('instance_not_started', $signal->rejection_reason);

        $this->assertSame('failed', $update->status->value);
        $this->assertSame('update_failed', $update->outcome?->value);
        $this->assertSame($failure->id, $update->failure_id);
        $this->assertSame('Update exploded.', $update->failure_message);

        $updateAccepted = WorkflowHistoryEvent::query()
            ->where('workflow_command_id', $updateCommand->id)
            ->where('event_type', HistoryEventType::UpdateAccepted->value)
            ->firstOrFail();
        $updateCompleted = WorkflowHistoryEvent::query()
            ->where('workflow_command_id', $updateCommand->id)
            ->where('event_type', HistoryEventType::UpdateCompleted->value)
            ->firstOrFail();

        $this->assertSame($update->id, $updateAccepted->payload['update_id'] ?? null);
        $this->assertSame($update->id, $updateCompleted->payload['update_id'] ?? null);
    }

    /**
     * @return array{0: WorkflowInstance, 1: WorkflowRun}
     */
    private function createRun(string $instanceId): array
    {
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => 'App\\Workflows\\LegacyWorkflow',
            'workflow_type' => 'legacy-workflow',
            'run_count' => 1,
            'reserved_at' => now()->subMinutes(5),
            'started_at' => now()->subMinutes(5),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\LegacyWorkflow',
            'workflow_type' => 'legacy-workflow',
            'status' => RunStatus::Waiting->value,
            'started_at' => now()->subMinutes(5),
            'last_progress_at' => now()->subMinutes(4),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return [$instance->refresh(), $run->refresh()];
    }

    private function createLegacyAcceptedSignal(WorkflowInstance $instance, WorkflowRun $run): WorkflowCommand
    {
        $command = WorkflowCommand::record($instance, $run, [
            'command_type' => CommandType::Signal->value,
            'target_scope' => 'instance',
            'status' => CommandStatus::Accepted->value,
            'outcome' => CommandOutcome::SignalReceived->value,
            'payload_codec' => config('workflows.serializer'),
            'payload' => Serializer::serialize([
                'name' => 'approved-by',
                'arguments' => ['Taylor'],
            ]),
            'accepted_at' => now()->subSeconds(20),
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::SignalReceived, [
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'signal_name' => 'approved-by',
            'signal_wait_id' => 'legacy-signal-wait',
        ], null, $command);

        return $command->refresh();
    }

    private function createLegacyCompletedUpdate(WorkflowInstance $instance, WorkflowRun $run): WorkflowCommand
    {
        $command = WorkflowCommand::record($instance, $run, [
            'command_type' => CommandType::Update->value,
            'target_scope' => 'instance',
            'status' => CommandStatus::Accepted->value,
            'outcome' => CommandOutcome::UpdateCompleted->value,
            'payload_codec' => config('workflows.serializer'),
            'payload' => Serializer::serialize([
                'name' => 'mark-approved',
                'arguments' => [true, 'api'],
            ]),
            'accepted_at' => now()->subSeconds(15),
            'applied_at' => now()->subSeconds(10),
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateAccepted, [
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'update_name' => 'mark-approved',
            'arguments' => Serializer::serialize([true, 'api']),
        ], null, $command);
        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateApplied, [
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'update_name' => 'mark-approved',
            'arguments' => Serializer::serialize([true, 'api']),
            'sequence' => 1,
        ], null, $command);
        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateCompleted, [
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'update_name' => 'mark-approved',
            'sequence' => 1,
            'result' => Serializer::serialize([
                'approved' => true,
                'source' => 'api',
            ]),
        ], null, $command);

        return $command->refresh();
    }
}
