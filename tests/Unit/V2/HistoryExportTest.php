<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\HistoryExportRedactor;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\WorkflowStub;

final class HistoryExportTest extends TestCase
{
    public function testItBuildsVersionedReplayBundleFromTypedHistoryAndProjections(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $instance = WorkflowInstance::query()->create([
            'id' => 'history-export-instance',
            'workflow_class' => 'App\\Workflows\\ExportWorkflow',
            'workflow_type' => 'export.workflow',
            'run_count' => 1,
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\ExportWorkflow',
            'workflow_type' => 'export.workflow',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'compatibility' => 'build-export',
            'payload_codec' => 'workflow-serializer',
            'arguments' => Serializer::serialize(['order-123']),
            'output' => Serializer::serialize(['ok' => true]),
            'connection' => 'redis',
            'queue' => 'workflow',
            'started_at' => now()->subMinutes(5),
            'closed_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->forceFill(['current_run_id' => $run->id])->save();

        WorkflowRunSummary::query()->create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'App\\Workflows\\ExportWorkflow',
            'workflow_type' => 'export.workflow',
            'status' => RunStatus::Completed->value,
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
            'connection' => 'redis',
            'queue' => 'workflow',
            'started_at' => $run->started_at,
            'closed_at' => $run->closed_at,
            'duration_ms' => 240000,
            'exception_count' => 0,
            'history_event_count' => 2,
            'history_size_bytes' => 256,
            'continue_as_new_recommended' => false,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinute(),
        ]);

        $command = WorkflowCommand::record($instance, $run, [
            'command_type' => CommandType::Start->value,
            'target_scope' => 'instance',
            'payload_codec' => config('workflows.serializer'),
            'payload' => Serializer::serialize(['arguments' => ['order-123']]),
            'source' => 'php',
            'status' => CommandStatus::Accepted->value,
            'outcome' => CommandOutcome::StartedNew->value,
            'accepted_at' => now()->subMinutes(5),
            'applied_at' => now()->subMinutes(5),
        ]);

        $signalCommand = WorkflowCommand::record($instance, $run, [
            'command_type' => CommandType::Signal->value,
            'target_scope' => 'instance',
            'payload_codec' => config('workflows.serializer'),
            'payload' => Serializer::serialize([
                'name' => 'approved-by',
                'arguments' => ['Taylor'],
            ]),
            'source' => 'webhook',
            'status' => CommandStatus::Accepted->value,
            'outcome' => CommandOutcome::SignalReceived->value,
            'accepted_at' => now()->subMinutes(4),
            'applied_at' => now()->subMinutes(4),
        ]);

        $signal = WorkflowSignal::query()->create([
            'workflow_command_id' => $signalCommand->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'target_scope' => 'instance',
            'resolved_workflow_run_id' => $run->id,
            'signal_name' => 'approved-by',
            'signal_wait_id' => 'signal-wait-export',
            'status' => 'applied',
            'outcome' => 'signal_received',
            'command_sequence' => $signalCommand->command_sequence,
            'workflow_sequence' => 1,
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize(['Taylor']),
            'received_at' => now()->subMinutes(4),
            'applied_at' => now()->subMinutes(4),
            'closed_at' => now()->subMinutes(4),
        ]);

        WorkflowHistoryEvent::record(
            $run,
            HistoryEventType::StartAccepted,
            ['workflow_type' => 'export.workflow'],
            command: $command,
        );
        WorkflowHistoryEvent::record(
            $run,
            HistoryEventType::WorkflowCompleted,
            ['result_available' => true],
        );

        $task = WorkflowTask::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Completed->value,
            'payload' => ['reason' => 'start'],
            'connection' => 'redis',
            'queue' => 'workflow',
            'compatibility' => 'build-export',
            'available_at' => now()->subMinutes(5),
            'last_dispatched_at' => now()->subMinutes(5),
        ]);

        $activity = ActivityExecution::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'App\\Activities\\ExportActivity',
            'activity_type' => 'export.activity',
            'status' => 'completed',
            'arguments' => Serializer::serialize(['order-123']),
            'result' => Serializer::serialize('ok'),
            'connection' => 'redis',
            'queue' => 'activities',
            'attempt_count' => 1,
            'started_at' => now()->subMinutes(4),
            'closed_at' => now()->subMinutes(3),
        ]);

        $attempt = ActivityAttempt::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'activity_execution_id' => $activity->id,
            'workflow_task_id' => $task->id,
            'attempt_number' => 1,
            'status' => 'completed',
            'lease_owner' => 'worker-a',
            'started_at' => now()->subMinutes(4),
            'closed_at' => now()->subMinutes(3),
        ]);

        $activity->forceFill(['current_attempt_id' => $attempt->id])->save();

        $childInstance = WorkflowInstance::query()->create([
            'id' => 'history-export-child',
            'workflow_class' => 'App\\Workflows\\ChildExportWorkflow',
            'workflow_type' => 'export.child',
            'run_count' => 1,
        ]);

        $childRun = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\ChildExportWorkflow',
            'workflow_type' => 'export.child',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'payload_codec' => 'workflow-serializer',
            'connection' => 'redis',
            'queue' => 'workflow',
            'started_at' => now()->subMinutes(4),
            'closed_at' => now()->subMinutes(3),
            'last_progress_at' => now()->subMinutes(3),
        ]);
        $childInstance->forceFill(['current_run_id' => $childRun->id])->save();

        $childCallId = (string) Str::ulid();
        WorkflowLink::query()->create([
            'id' => $childCallId,
            'link_type' => 'child_workflow',
            'sequence' => 2,
            'parent_workflow_instance_id' => $instance->id,
            'parent_workflow_run_id' => $run->id,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'is_primary_parent' => true,
        ]);

        $run->refresh();

        $bundle = HistoryExport::forRun($run, Carbon::parse('2026-04-09 12:05:00'));

        $this->assertSame(HistoryExport::SCHEMA, $bundle['schema']);
        $this->assertSame(HistoryExport::SCHEMA_VERSION, $bundle['schema_version']);
        $this->assertSame('2026-04-09T12:05:00.000000Z', $bundle['exported_at']);
        $this->assertSame($run->id.':2:2026-04-09T12:00:00.000000Z', $bundle['dedupe_key']);
        $this->assertTrue($bundle['history_complete']);
        $this->assertSame($instance->id, $bundle['workflow']['instance_id']);
        $this->assertSame($run->id, $bundle['workflow']['run_id']);
        $this->assertSame('completed', $bundle['workflow']['status']);
        $this->assertSame('completed', $bundle['workflow']['status_bucket']);
        $this->assertSame('build-export', $bundle['workflow']['compatibility']);
        $this->assertSame('workflow-serializer', $bundle['payloads']['codec']);
        $this->assertSame($run->arguments, $bundle['payloads']['arguments']['data']);
        $this->assertSame($run->output, $bundle['payloads']['output']['data']);
        $this->assertFalse($bundle['redaction']['applied']);
        $this->assertSame(2, $bundle['summary']['history_event_count']);
        $this->assertSame(['StartAccepted', 'WorkflowCompleted'], array_column($bundle['history_events'], 'type'));
        $this->assertSame($command->id, $bundle['commands'][0]['id']);
        $this->assertSame('started_new', $bundle['commands'][0]['outcome']);
        $this->assertSame($signal->id, $bundle['signals'][0]['id']);
        $this->assertSame('approved-by', $bundle['signals'][0]['name']);
        $this->assertSame('applied', $bundle['signals'][0]['status']);
        $this->assertSame($task->id, $bundle['tasks'][0]['id']);
        $this->assertSame($activity->id, $bundle['activities'][0]['id']);
        $this->assertSame($attempt->id, $bundle['activities'][0]['attempts'][0]['id']);
        $this->assertSame($childCallId, $bundle['links']['children'][0]['child_call_id']);

        $stubBundle = WorkflowStub::loadRun($run->id)->historyExport();

        $this->assertSame($bundle['history_events'], $stubBundle['history_events']);
        $this->assertSame($bundle['workflow']['run_id'], $stubBundle['workflow']['run_id']);
    }

    public function testItAppliesConfiguredRedactionPolicyToPayloadAndDiagnosticSlots(): void
    {
        config()->set('workflows.v2.history_export.redactor', new class() implements HistoryExportRedactor {
            /**
             * @param array<string, mixed> $context
             *
             * @return array<string, mixed>
             */
            public function redact(mixed $value, array $context): array
            {
                return [
                    'redacted' => true,
                    'path' => $context['path'],
                    'category' => $context['category'],
                ];
            }
        });

        $instance = WorkflowInstance::query()->create([
            'id' => 'history-export-redacted',
            'workflow_class' => 'App\\Workflows\\RedactedExportWorkflow',
            'workflow_type' => 'export.redacted',
            'run_count' => 1,
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\RedactedExportWorkflow',
            'workflow_type' => 'export.redacted',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'payload_codec' => 'workflow-serializer',
            'arguments' => Serializer::serialize(['secret-order']),
            'output' => Serializer::serialize(['secret' => true]),
            'started_at' => now()->subMinutes(5),
            'closed_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->forceFill(['current_run_id' => $run->id])->save();

        $command = WorkflowCommand::record($instance, $run, [
            'command_type' => CommandType::Start->value,
            'target_scope' => 'instance',
            'payload_codec' => config('workflows.serializer'),
            'payload' => Serializer::serialize(['arguments' => ['secret-order']]),
            'context' => ['workflow' => ['parent_instance_id' => 'secret-parent']],
            'source' => 'php',
            'status' => CommandStatus::Accepted->value,
            'outcome' => CommandOutcome::StartedNew->value,
            'accepted_at' => now()->subMinutes(5),
            'applied_at' => now()->subMinutes(5),
        ]);

        $signalCommand = WorkflowCommand::record($instance, $run, [
            'command_type' => CommandType::Signal->value,
            'target_scope' => 'instance',
            'payload_codec' => config('workflows.serializer'),
            'payload' => Serializer::serialize([
                'name' => 'approved-by',
                'arguments' => ['secret-signal-value'],
            ]),
            'source' => 'webhook',
            'status' => CommandStatus::Accepted->value,
            'outcome' => CommandOutcome::SignalReceived->value,
            'accepted_at' => now()->subMinutes(4),
        ]);

        WorkflowSignal::query()->create([
            'workflow_command_id' => $signalCommand->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'target_scope' => 'instance',
            'resolved_workflow_run_id' => $run->id,
            'signal_name' => 'approved-by',
            'signal_wait_id' => 'signal-wait-redacted',
            'status' => 'received',
            'outcome' => 'signal_received',
            'command_sequence' => $signalCommand->command_sequence,
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize(['secret-signal-value']),
            'received_at' => now()->subMinutes(4),
        ]);

        WorkflowHistoryEvent::record(
            $run,
            HistoryEventType::WorkflowStarted,
            ['arguments' => ['secret-order']],
            command: $command,
        );

        WorkflowTask::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Completed->value,
            'payload' => ['secret' => 'task'],
            'available_at' => now()->subMinutes(5),
            'last_dispatched_at' => now()->subMinutes(5),
        ]);

        ActivityExecution::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'App\\Activities\\RedactedExportActivity',
            'activity_type' => 'export.redacted.activity',
            'status' => 'completed',
            'arguments' => Serializer::serialize(['secret-order']),
            'result' => Serializer::serialize(['activity-secret' => true]),
        ]);

        WorkflowFailure::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'source_kind' => 'workflow',
            'source_id' => $run->id,
            'propagation_kind' => 'terminal',
            'handled' => false,
            'exception_class' => RuntimeException::class,
            'message' => 'secret failure message',
            'file' => '/app/secret.php',
            'line' => 10,
            'trace_preview' => 'secret trace',
        ]);

        $bundle = HistoryExport::forRun($run->refresh());

        $this->assertTrue($bundle['redaction']['applied']);
        $this->assertIsString($bundle['redaction']['policy']);
        $this->assertContains('payloads.arguments.data', $bundle['redaction']['paths']);
        $this->assertContains('history_events.0.payload', $bundle['redaction']['paths']);
        $this->assertContains('commands.0.payload', $bundle['redaction']['paths']);
        $this->assertContains('commands.0.context', $bundle['redaction']['paths']);
        $this->assertContains('signals.0.arguments', $bundle['redaction']['paths']);
        $this->assertContains('tasks.0.payload', $bundle['redaction']['paths']);
        $this->assertContains('activities.0.arguments', $bundle['redaction']['paths']);
        $this->assertContains('failures.0.message', $bundle['redaction']['paths']);
        $this->assertSame('payloads.arguments.data', $bundle['payloads']['arguments']['data']['path']);
        $this->assertSame('workflow_payload', $bundle['payloads']['arguments']['data']['category']);
        $this->assertSame('history_events.0.payload', $bundle['history_events'][0]['payload']['path']);
        $this->assertSame('failure_diagnostic', $bundle['failures'][0]['message']['category']);

        $stubBundle = WorkflowStub::loadRun($run->id)->historyExport(new class() implements HistoryExportRedactor {
            /**
             * @param array<string, mixed> $context
             */
            public function redact(mixed $value, array $context): string
            {
                return 'inline-redacted:'.$context['path'];
            }
        });

        $this->assertSame('inline-redacted:payloads.arguments.data', $stubBundle['payloads']['arguments']['data']);
    }
}
