<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\HistoryExportRedactor;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowRunTimerEntry;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\ActivitySnapshot;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\RunLineageProjector;
use Workflow\V2\Support\RunSummaryProjector;
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
            'output' => Serializer::serialize([
                'ok' => true,
            ]),
            'connection' => 'redis',
            'queue' => 'workflow',
            'started_at' => now()
                ->subMinutes(5),
            'closed_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

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
            'created_at' => now()
                ->subMinutes(5),
            'updated_at' => now()
                ->subMinute(),
        ]);

        $command = WorkflowCommand::record($instance, $run, [
            'command_type' => CommandType::Start->value,
            'target_scope' => 'instance',
            'payload_codec' => config('workflows.serializer'),
            'payload' => Serializer::serialize([
                'arguments' => ['order-123'],
            ]),
            'source' => 'php',
            'status' => CommandStatus::Accepted->value,
            'outcome' => CommandOutcome::StartedNew->value,
            'accepted_at' => now()
                ->subMinutes(5),
            'applied_at' => now()
                ->subMinutes(5),
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
            'accepted_at' => now()
                ->subMinutes(4),
            'applied_at' => now()
                ->subMinutes(4),
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
            'received_at' => now()
                ->subMinutes(4),
            'applied_at' => now()
                ->subMinutes(4),
            'closed_at' => now()
                ->subMinutes(4),
        ]);

        WorkflowHistoryEvent::record(
            $run,
            HistoryEventType::StartAccepted,
            [
                'workflow_type' => 'export.workflow',
            ],
            command: $command,
        );
        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowCompleted, [
            'result_available' => true,
        ],);

        $task = WorkflowTask::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Completed->value,
            'payload' => [
                'reason' => 'start',
            ],
            'connection' => 'redis',
            'queue' => 'workflow',
            'compatibility' => 'build-export',
            'available_at' => now()
                ->subMinutes(5),
            'last_dispatched_at' => now()
                ->subMinutes(5),
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
            'retry_policy' => [
                'snapshot_version' => 1,
                'max_attempts' => 3,
                'backoff_seconds' => [1, 5],
            ],
            'attempt_count' => 1,
            'started_at' => now()
                ->subMinutes(4),
            'closed_at' => now()
                ->subMinutes(3),
        ]);

        $attempt = ActivityAttempt::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'activity_execution_id' => $activity->id,
            'workflow_task_id' => $task->id,
            'attempt_number' => 1,
            'status' => 'completed',
            'lease_owner' => 'worker-a',
            'started_at' => now()
                ->subMinutes(4),
            'closed_at' => now()
                ->subMinutes(3),
        ]);

        $activity->forceFill([
            'current_attempt_id' => $attempt->id,
        ])->save();

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
            'started_at' => now()
                ->subMinutes(4),
            'closed_at' => now()
                ->subMinutes(3),
            'last_progress_at' => now()
                ->subMinutes(3),
        ]);
        $childInstance->forceFill([
            'current_run_id' => $childRun->id,
        ])->save();

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
        $this->assertSame($run->id . ':2:2026-04-09T12:00:00.000000Z', $bundle['dedupe_key']);
        $this->assertTrue($bundle['history_complete']);
        $this->assertSame($instance->id, $bundle['workflow']['instance_id']);
        $this->assertSame($run->id, $bundle['workflow']['run_id']);
        $this->assertSame('run_order_fallback', $bundle['workflow']['current_run_source']);
        $this->assertSame('completed', $bundle['workflow']['status']);
        $this->assertSame('completed', $bundle['workflow']['status_bucket']);
        $this->assertSame('build-export', $bundle['workflow']['compatibility']);
        $this->assertSame('workflow-serializer', $bundle['payloads']['codec']);
        $this->assertSame($run->arguments, $bundle['payloads']['arguments']['data']);
        $this->assertSame($run->output, $bundle['payloads']['output']['data']);
        $this->assertFalse($bundle['redaction']['applied']);
        $this->assertSame('json-recursive-ksort-v1', $bundle['integrity']['canonicalization']);
        $this->assertSame('sha256', $bundle['integrity']['checksum_algorithm']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $bundle['integrity']['checksum']);
        $this->assertNull($bundle['integrity']['signature_algorithm']);
        $this->assertNull($bundle['integrity']['signature']);
        $this->assertNull($bundle['integrity']['key_id']);
        $unsignedBundle = $bundle;
        unset($unsignedBundle['integrity']);
        $this->assertSame(hash('sha256', self::canonicalJson($unsignedBundle)), $bundle['integrity']['checksum']);
        $this->assertSame(2, $bundle['summary']['history_event_count']);
        $this->assertSame('workflow_run_waits_rebuilt', $bundle['selected_run']['waits_projection_source']);
        $this->assertSame(
            'workflow_run_timeline_entries_rebuilt',
            $bundle['selected_run']['timeline_projection_source']
        );
        $this->assertSame('workflow_run_timer_entries', $bundle['selected_run']['timers_projection_source']);
        $this->assertSame('workflow_run_lineage_entries_rebuilt', $bundle['selected_run']['lineage_projection_source']);
        $this->assertSame(['activity', 'child'], array_column($bundle['waits'], 'kind'));
        $this->assertSame(['unsupported', 'unsupported'], array_column($bundle['waits'], 'status'));
        $this->assertSame(
            'terminal_activity_row_without_typed_history',
            $bundle['waits'][0]['history_unsupported_reason']
        );
        $this->assertSame(
            'terminal_child_link_without_typed_parent_history',
            $bundle['waits'][1]['history_unsupported_reason']
        );
        $this->assertSame(['StartAccepted', 'WorkflowCompleted'], array_column($bundle['timeline'], 'type'));
        $this->assertSame(['StartAccepted', 'WorkflowCompleted'], array_column($bundle['history_events'], 'type'));
        $this->assertSame($command->id, $bundle['commands'][0]['id']);
        $this->assertSame('started_new', $bundle['commands'][0]['outcome']);
        $this->assertSame($signal->id, $bundle['signals'][0]['id']);
        $this->assertSame('approved-by', $bundle['signals'][0]['name']);
        $this->assertSame('applied', $bundle['signals'][0]['status']);
        $this->assertSame($task->id, $bundle['tasks'][0]['id']);
        $this->assertSame($activity->id, $bundle['activities'][0]['id']);
        $this->assertSame($activity->id, $bundle['activities'][0]['idempotency_key']);
        $this->assertTrue($bundle['activities'][0]['diagnostic_only']);
        $this->assertSame($activity->retry_policy, $bundle['activities'][0]['retry_policy']);
        $this->assertSame($attempt->id, $bundle['activities'][0]['attempts'][0]['id']);
        $this->assertSame($childCallId, $bundle['links']['children'][0]['child_call_id']);

        $stubBundle = WorkflowStub::loadRun($run->id)->historyExport();

        $this->assertSame($bundle['history_events'], $stubBundle['history_events']);
        $this->assertSame($bundle['workflow']['run_id'], $stubBundle['workflow']['run_id']);
    }

    public function testItExportsTypedFailureSnapshotsWhenFailureRowsAreMissing(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'history-export-failure-history-only',
            'workflow_class' => 'App\\Workflows\\FailedExportWorkflow',
            'workflow_type' => 'export.failed',
            'run_count' => 1,
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\FailedExportWorkflow',
            'workflow_type' => 'export.failed',
            'status' => RunStatus::Failed->value,
            'closed_reason' => 'failed',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'workflow',
            'started_at' => now()
                ->subMinutes(2),
            'closed_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        WorkflowRunSummary::query()->create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'App\\Workflows\\FailedExportWorkflow',
            'workflow_type' => 'export.failed',
            'status' => RunStatus::Failed->value,
            'status_bucket' => 'failed',
            'closed_reason' => 'failed',
            'connection' => 'redis',
            'queue' => 'workflow',
            'started_at' => $run->started_at,
            'closed_at' => $run->closed_at,
            'duration_ms' => 60000,
            'exception_count' => 1,
            'history_event_count' => 1,
            'history_size_bytes' => 128,
            'continue_as_new_recommended' => false,
            'created_at' => now()
                ->subMinutes(2),
            'updated_at' => now()
                ->subMinute(),
        ]);

        WorkflowHistoryEvent::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowFailed->value,
            'payload' => [
                'failure_id' => '01JTESTFAILUREHISTORYONLY000001',
                'source_kind' => 'workflow_run',
                'source_id' => $run->id,
                'exception_type' => 'runtime.failure',
                'exception_class' => RuntimeException::class,
                'message' => 'history-only boom',
                'exception' => [
                    'type' => 'runtime.failure',
                    'class' => RuntimeException::class,
                    'message' => 'history-only boom',
                    'code' => 99,
                    'file' => __FILE__,
                    'line' => 222,
                    'trace' => [[
                        'class' => 'App\\Workflows\\FailedExportWorkflow',
                        'type' => '->',
                        'function' => 'execute',
                        'file' => __FILE__,
                        'line' => 221,
                    ]],
                    'properties' => [],
                ],
            ],
            'recorded_at' => now()
                ->subMinute(),
        ]);

        $bundle = HistoryExport::forRun($run->fresh(['summary']));

        $this->assertCount(1, $bundle['failures']);
        $this->assertSame('01JTESTFAILUREHISTORYONLY000001', $bundle['failures'][0]['id']);
        $this->assertSame('workflow_run', $bundle['failures'][0]['source_kind']);
        $this->assertSame($run->id, $bundle['failures'][0]['source_id']);
        $this->assertSame('terminal', $bundle['failures'][0]['propagation_kind']);
        $this->assertSame('typed_history', $bundle['failures'][0]['history_authority']);
        $this->assertFalse($bundle['failures'][0]['diagnostic_only']);
        $this->assertSame('runtime.failure', $bundle['failures'][0]['exception_type']);
        $this->assertSame(RuntimeException::class, $bundle['failures'][0]['exception_class']);
        $this->assertFalse($bundle['failures'][0]['exception_replay_blocked']);
        $this->assertSame('history-only boom', $bundle['failures'][0]['message']);
        $this->assertSame(__FILE__, $bundle['failures'][0]['file']);
        $this->assertSame(222, $bundle['failures'][0]['line']);
        $this->assertNotSame('', $bundle['failures'][0]['trace_preview']);
    }

    public function testItExportsLineageLinksFromTypedHistoryWhenLinkRowsAreMissing(): void
    {
        $parentInstance = WorkflowInstance::query()->create([
            'id' => 'history-export-parent',
            'workflow_class' => 'App\\Workflows\\ParentExportWorkflow',
            'workflow_type' => 'export.parent',
            'run_count' => 1,
        ]);

        /** @var WorkflowRun $parentRun */
        $parentRun = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $parentInstance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\ParentExportWorkflow',
            'workflow_type' => 'export.parent',
            'status' => RunStatus::Waiting->value,
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'workflow',
            'started_at' => now()
                ->subMinutes(2),
            'last_progress_at' => now()
                ->subMinute(),
        ]);
        $parentInstance->forceFill([
            'current_run_id' => $parentRun->id,
        ])->save();

        $childInstance = WorkflowInstance::query()->create([
            'id' => 'history-export-child-history-only',
            'workflow_class' => 'App\\Workflows\\ChildExportWorkflow',
            'workflow_type' => 'export.child',
            'run_count' => 1,
        ]);

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\ChildExportWorkflow',
            'workflow_type' => 'export.child',
            'status' => RunStatus::Waiting->value,
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'workflow',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(30),
        ]);
        $childInstance->forceFill([
            'current_run_id' => $childRun->id,
        ])->save();

        $childCallId = (string) Str::ulid();
        $link = WorkflowLink::query()->create([
            'id' => $childCallId,
            'link_type' => 'child_workflow',
            'sequence' => 1,
            'parent_workflow_instance_id' => $parentInstance->id,
            'parent_workflow_run_id' => $parentRun->id,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'is_primary_parent' => true,
        ]);

        WorkflowHistoryEvent::record($parentRun, HistoryEventType::WorkflowStarted, [
            'workflow_type' => 'export.parent',
        ]);
        WorkflowHistoryEvent::record($parentRun->refresh(), HistoryEventType::ChildWorkflowScheduled, [
            'sequence' => 1,
            'workflow_link_id' => $link->id,
            'child_call_id' => $childCallId,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'child_workflow_class' => $childRun->workflow_class,
            'child_workflow_type' => $childRun->workflow_type,
            'child_run_number' => $childRun->run_number,
        ]);
        WorkflowHistoryEvent::record($parentRun->refresh(), HistoryEventType::ChildRunStarted, [
            'sequence' => 1,
            'workflow_link_id' => $link->id,
            'child_call_id' => $childCallId,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'child_workflow_class' => $childRun->workflow_class,
            'child_workflow_type' => $childRun->workflow_type,
            'child_run_number' => $childRun->run_number,
            'child_status' => $childRun->status->value,
        ]);
        WorkflowHistoryEvent::record($childRun, HistoryEventType::WorkflowStarted, [
            'workflow_type' => 'export.child',
            'workflow_link_id' => $link->id,
            'parent_workflow_instance_id' => $parentInstance->id,
            'parent_workflow_run_id' => $parentRun->id,
            'parent_sequence' => 1,
            'child_call_id' => $childCallId,
        ]);

        $link->delete();

        RunSummaryProjector::project($parentRun->fresh());
        RunSummaryProjector::project($childRun->fresh());

        WorkflowRunLineageEntry::query()
            ->whereIn('workflow_run_id', [$parentRun->id, $childRun->id])
            ->delete();

        $parentBundle = HistoryExport::forRun($parentRun->fresh(['historyEvents', 'childLinks']));
        $childBundle = HistoryExport::forRun($childRun->fresh(['historyEvents', 'parentLinks']));

        $this->assertSame('workflow_run_lineage_entries_rebuilt', $parentBundle['links']['projection_source']);
        $this->assertCount(1, $parentBundle['links']['children']);
        $this->assertSame($childCallId, $parentBundle['links']['children'][0]['id']);
        $this->assertSame('child_workflow', $parentBundle['links']['children'][0]['type']);
        $this->assertSame($parentInstance->id, $parentBundle['links']['children'][0]['parent_workflow_instance_id']);
        $this->assertSame($parentRun->id, $parentBundle['links']['children'][0]['parent_workflow_run_id']);
        $this->assertSame($childInstance->id, $parentBundle['links']['children'][0]['child_workflow_instance_id']);
        $this->assertSame($childRun->id, $parentBundle['links']['children'][0]['child_workflow_run_id']);
        $this->assertSame($childCallId, $parentBundle['links']['children'][0]['child_call_id']);
        $this->assertSame(1, $parentBundle['links']['children'][0]['sequence']);
        $this->assertTrue($parentBundle['links']['children'][0]['is_primary_parent']);

        $this->assertSame('workflow_run_lineage_entries_rebuilt', $childBundle['links']['projection_source']);
        $this->assertCount(1, $childBundle['links']['parents']);
        $this->assertSame($childCallId, $childBundle['links']['parents'][0]['id']);
        $this->assertSame('child_workflow', $childBundle['links']['parents'][0]['type']);
        $this->assertSame($parentInstance->id, $childBundle['links']['parents'][0]['parent_workflow_instance_id']);
        $this->assertSame($parentRun->id, $childBundle['links']['parents'][0]['parent_workflow_run_id']);
        $this->assertSame($childInstance->id, $childBundle['links']['parents'][0]['child_workflow_instance_id']);
        $this->assertSame($childRun->id, $childBundle['links']['parents'][0]['child_workflow_run_id']);
        $this->assertSame($childCallId, $childBundle['links']['parents'][0]['child_call_id']);
        $this->assertSame(1, $childBundle['links']['parents'][0]['sequence']);
    }

    public function testItKeepsResolvedChildLineageMetadataFromTypedHistoryWhenChildRunDrifts(): void
    {
        $parentInstance = WorkflowInstance::query()->create([
            'id' => 'history-export-child-drift-parent',
            'workflow_class' => 'App\\Workflows\\ParentExportWorkflow',
            'workflow_type' => 'export.parent',
            'run_count' => 1,
        ]);

        /** @var WorkflowRun $parentRun */
        $parentRun = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $parentInstance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\ParentExportWorkflow',
            'workflow_type' => 'export.parent',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'workflow',
            'started_at' => now()
                ->subMinutes(5),
            'closed_at' => now()
                ->subMinutes(4),
            'last_progress_at' => now()
                ->subMinutes(4),
        ]);
        $parentInstance->forceFill([
            'current_run_id' => $parentRun->id,
        ])->save();

        $childInstance = WorkflowInstance::query()->create([
            'id' => 'history-export-child-drift-child',
            'workflow_class' => 'App\\Workflows\\ChildExportWorkflow',
            'workflow_type' => 'export.child',
            'run_count' => 1,
        ]);

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\ChildExportWorkflow',
            'workflow_type' => 'export.child',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize([]),
            'output' => Serializer::serialize([
                'ok' => true,
            ]),
            'connection' => 'redis',
            'queue' => 'workflow',
            'started_at' => now()
                ->subMinutes(4),
            'closed_at' => now()
                ->subMinutes(3),
            'last_progress_at' => now()
                ->subMinutes(3),
        ]);
        $childInstance->forceFill([
            'current_run_id' => $childRun->id,
        ])->save();

        $childCallId = (string) Str::ulid();
        WorkflowLink::query()->create([
            'id' => $childCallId,
            'link_type' => 'child_workflow',
            'sequence' => 1,
            'parent_workflow_instance_id' => $parentInstance->id,
            'parent_workflow_run_id' => $parentRun->id,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'is_primary_parent' => true,
            'created_at' => now()
                ->subMinutes(4),
            'updated_at' => now()
                ->subMinutes(4),
        ]);

        WorkflowHistoryEvent::record($parentRun, HistoryEventType::ChildWorkflowScheduled, [
            'sequence' => 1,
            'child_call_id' => $childCallId,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'child_workflow_class' => $childRun->workflow_class,
            'child_workflow_type' => $childRun->workflow_type,
            'child_run_number' => $childRun->run_number,
        ]);
        WorkflowHistoryEvent::record($parentRun->refresh(), HistoryEventType::ChildRunCompleted, [
            'sequence' => 1,
            'child_call_id' => $childCallId,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'child_workflow_class' => $childRun->workflow_class,
            'child_workflow_type' => $childRun->workflow_type,
            'child_run_number' => $childRun->run_number,
            'child_status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
        ]);

        $childRun->forceFill([
            'status' => RunStatus::Waiting,
            'closed_reason' => null,
            'closed_at' => null,
        ])->save();

        WorkflowRunLineageEntry::query()
            ->where('workflow_run_id', $parentRun->id)
            ->delete();

        $bundle = HistoryExport::forRun($parentRun->fresh(['historyEvents', 'childLinks']));

        $this->assertSame('workflow_run_lineage_entries_rebuilt', $bundle['links']['projection_source']);
        $this->assertCount(1, $bundle['links']['children']);
        $this->assertSame($childCallId, $bundle['links']['children'][0]['id']);
        $this->assertSame('child_workflow', $bundle['links']['children'][0]['type']);
        $this->assertSame($childInstance->id, $bundle['links']['children'][0]['child_workflow_instance_id']);
        $this->assertSame($childRun->id, $bundle['links']['children'][0]['child_workflow_run_id']);
        $this->assertSame($childRun->workflow_type, $bundle['links']['children'][0]['workflow_type']);
        $this->assertSame($childRun->workflow_class, $bundle['links']['children'][0]['class']);
        $this->assertSame($childRun->run_number, $bundle['links']['children'][0]['run_number']);
        $this->assertSame('completed', $bundle['links']['children'][0]['status']);
        $this->assertSame('completed', $bundle['links']['children'][0]['status_bucket']);
        $this->assertSame('completed', $bundle['links']['children'][0]['closed_reason']);
    }

    public function testItExportsLineageDiagnosticsFromSelectedRunProjectionWithoutReReadingLiveLinks(): void
    {
        $parentInstance = WorkflowInstance::query()->create([
            'id' => 'history-export-lineage-diagnostic-parent',
            'workflow_class' => 'App\\Workflows\\ParentExportWorkflow',
            'workflow_type' => 'export.parent',
            'run_count' => 1,
        ]);

        /** @var WorkflowRun $parentRun */
        $parentRun = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $parentInstance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\ParentExportWorkflow',
            'workflow_type' => 'export.parent',
            'status' => RunStatus::Waiting->value,
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'workflow',
            'started_at' => now()
                ->subMinutes(5),
            'last_progress_at' => now()
                ->subMinutes(4),
        ]);
        $parentInstance->forceFill([
            'current_run_id' => $parentRun->id,
        ])->save();

        $childInstance = WorkflowInstance::query()->create([
            'id' => 'history-export-lineage-diagnostic-child',
            'workflow_class' => 'App\\Workflows\\ChildExportWorkflow',
            'workflow_type' => 'export.child',
            'run_count' => 1,
        ]);

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\ChildExportWorkflow',
            'workflow_type' => 'export.child',
            'status' => RunStatus::Waiting->value,
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'workflow',
            'started_at' => now()
                ->subMinutes(4),
            'last_progress_at' => now()
                ->subMinutes(3),
        ]);
        $childInstance->forceFill([
            'current_run_id' => $childRun->id,
        ])->save();

        $childCallId = (string) Str::ulid();
        WorkflowLink::query()->create([
            'id' => $childCallId,
            'link_type' => 'child_workflow',
            'sequence' => 1,
            'parent_workflow_instance_id' => $parentInstance->id,
            'parent_workflow_run_id' => $parentRun->id,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'is_primary_parent' => true,
            'created_at' => now()
                ->subMinutes(3),
            'updated_at' => now()
                ->subMinutes(3),
        ]);

        RunLineageProjector::project($parentRun->fresh(['historyEvents', 'childLinks.childRun.summary']));

        WorkflowRunLineageEntry::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('lineage_id', $childCallId)
            ->update([
                'linked_at' => null,
            ]);

        $bundle = HistoryExport::forRun($parentRun->fresh(['historyEvents', 'lineageEntries', 'childLinks']));

        $this->assertSame('workflow_run_lineage_entries', $bundle['links']['projection_source']);
        $this->assertCount(1, $bundle['links']['children']);
        $this->assertSame($childCallId, $bundle['links']['children'][0]['id']);
        $this->assertSame('mutable_open_fallback', $bundle['links']['children'][0]['history_authority']);
        $this->assertTrue($bundle['links']['children'][0]['diagnostic_only']);
        $this->assertNull($bundle['links']['children'][0]['created_at']);
    }

    public function testItExportsActivitySnapshotsFromTypedHistoryWhenExecutionRowIsMissing(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $run = $this->createMinimalCompletedRun('history-export-activity-history');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Leased->value,
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'activities',
            'available_at' => now()
                ->subSeconds(40),
            'leased_at' => now()
                ->subSeconds(35),
            'lease_owner' => 'worker-a',
            'lease_expires_at' => now()
                ->addMinutes(5),
            'attempt_count' => 1,
        ]);

        /** @var ActivityExecution $activity */
        $activity = ActivityExecution::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 3,
            'activity_class' => 'App\\Activities\\HistoryOnlyActivity',
            'activity_type' => 'history.only.activity',
            'status' => 'pending',
            'arguments' => Serializer::serialize(['order-123']),
            'connection' => 'redis',
            'queue' => 'activities',
            'retry_policy' => [
                'snapshot_version' => 1,
                'max_attempts' => 2,
                'backoff_seconds' => [5],
            ],
            'attempt_count' => 0,
        ]);

        WorkflowHistoryEvent::record($run->refresh(), HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => $activity->id,
            'activity_class' => $activity->activity_class,
            'activity_type' => $activity->activity_type,
            'sequence' => $activity->sequence,
            'activity' => ActivitySnapshot::fromExecution($activity),
        ], $task);

        /** @var ActivityAttempt $attempt */
        $attempt = ActivityAttempt::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'activity_execution_id' => $activity->id,
            'workflow_task_id' => $task->id,
            'attempt_number' => 1,
            'status' => 'running',
            'lease_owner' => 'worker-a',
            'started_at' => now()
                ->subSeconds(30),
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);

        $activity->forceFill([
            'status' => 'running',
            'attempt_count' => 1,
            'current_attempt_id' => $attempt->id,
            'started_at' => now()
                ->subSeconds(30),
        ])->save();

        WorkflowHistoryEvent::record($run->refresh(), HistoryEventType::ActivityStarted, [
            'activity_execution_id' => $activity->id,
            'activity_attempt_id' => $attempt->id,
            'activity_class' => $activity->activity_class,
            'activity_type' => $activity->activity_type,
            'sequence' => $activity->sequence,
            'attempt_number' => 1,
            'activity' => ActivitySnapshot::fromExecution($activity),
        ], $task);

        $activity->forceFill([
            'status' => 'completed',
            'result' => Serializer::serialize('ok'),
            'closed_at' => now()
                ->subSeconds(10),
        ])->save();

        WorkflowHistoryEvent::record($run->refresh(), HistoryEventType::ActivityCompleted, [
            'activity_execution_id' => $activity->id,
            'activity_attempt_id' => $attempt->id,
            'activity_class' => $activity->activity_class,
            'activity_type' => $activity->activity_type,
            'sequence' => $activity->sequence,
            'attempt_number' => 1,
            'result' => $activity->result,
            'activity' => ActivitySnapshot::fromExecution($activity),
        ], $task);

        $activityId = $activity->id;
        $attemptId = $attempt->id;
        $taskId = $task->id;
        $arguments = $activity->arguments;
        $result = $activity->result;

        $attempt->delete();
        $activity->delete();

        $bundle = HistoryExport::forRun($run->fresh(['historyEvents', 'activityExecutions.attempts']));

        $this->assertCount(1, $bundle['activities']);
        $this->assertSame($activityId, $bundle['activities'][0]['id']);
        $this->assertSame($activityId, $bundle['activities'][0]['idempotency_key']);
        $this->assertSame(3, $bundle['activities'][0]['sequence']);
        $this->assertSame('history.only.activity', $bundle['activities'][0]['activity_type']);
        $this->assertSame('App\\Activities\\HistoryOnlyActivity', $bundle['activities'][0]['activity_class']);
        $this->assertSame('completed', $bundle['activities'][0]['status']);
        $this->assertFalse($bundle['activities'][0]['diagnostic_only']);
        $this->assertSame($arguments, $bundle['activities'][0]['arguments']);
        $this->assertSame($result, $bundle['activities'][0]['result']);
        $this->assertSame(1, $bundle['activities'][0]['attempt_count']);
        $this->assertSame($attemptId, $bundle['activities'][0]['current_attempt_id']);
        $this->assertSame($attemptId, $bundle['activities'][0]['attempts'][0]['id']);
        $this->assertSame($activityId, $bundle['activities'][0]['attempts'][0]['activity_execution_id']);
        $this->assertSame(1, $bundle['activities'][0]['attempts'][0]['attempt_number']);
        $this->assertSame('completed', $bundle['activities'][0]['attempts'][0]['status']);
        $this->assertSame($taskId, $bundle['activities'][0]['attempts'][0]['workflow_task_id']);
        $this->assertSame('worker-a', $bundle['activities'][0]['attempts'][0]['lease_owner']);
        $this->assertNull($bundle['activities'][0]['attempts'][0]['lease_expires_at']);
    }

    public function testItExportsTimerSnapshotsFromTypedHistoryWhenTimerRowIsMissing(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $run = $this->createMinimalCompletedRun('history-export-timer-history');
        $fireAt = now()
            ->addMinute();
        $firedAt = now()
            ->addMinute()
            ->addSecond();

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 3,
            'status' => TimerStatus::Pending->value,
            'delay_seconds' => 60,
            'fire_at' => $fireAt,
            'created_at' => now(),
        ]);

        WorkflowHistoryEvent::record($run->refresh(), HistoryEventType::TimerScheduled, [
            'timer_id' => $timer->id,
            'sequence' => $timer->sequence,
            'delay_seconds' => $timer->delay_seconds,
            'fire_at' => $fireAt->toJSON(),
        ]);

        $timer->forceFill([
            'status' => TimerStatus::Fired,
            'fired_at' => $firedAt,
        ])->save();

        WorkflowHistoryEvent::record($run->refresh(), HistoryEventType::TimerFired, [
            'timer_id' => $timer->id,
            'sequence' => $timer->sequence,
            'delay_seconds' => $timer->delay_seconds,
            'fire_at' => $fireAt->toJSON(),
            'fired_at' => $firedAt->toJSON(),
        ]);

        $timerId = $timer->id;
        $timer->delete();

        $bundle = HistoryExport::forRun($run->fresh(['historyEvents', 'timers']));

        $this->assertCount(1, $bundle['timers']);
        $this->assertSame($timerId, $bundle['timers'][0]['id']);
        $this->assertSame(3, $bundle['timers'][0]['sequence']);
        $this->assertSame('fired', $bundle['timers'][0]['status']);
        $this->assertFalse($bundle['timers'][0]['diagnostic_only']);
        $this->assertSame(60, $bundle['timers'][0]['delay_seconds']);
        $this->assertSame($fireAt->toJSON(), $bundle['timers'][0]['fire_at']);
        $this->assertSame($firedAt->toJSON(), $bundle['timers'][0]['fired_at']);
    }

    public function testItExportsCancelledTimerSnapshotsFromTypedHistoryWhenTimerRowIsMissing(): void
    {
        Carbon::setTestNow('2026-04-09 12:10:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $run = $this->createMinimalCompletedRun('history-export-cancelled-timer-history');
        $fireAt = now()
            ->addMinute();
        $cancelledAt = now()
            ->addSeconds(10);

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 4,
            'status' => TimerStatus::Pending->value,
            'delay_seconds' => 60,
            'fire_at' => $fireAt,
            'created_at' => now(),
        ]);

        WorkflowHistoryEvent::record($run->refresh(), HistoryEventType::TimerScheduled, [
            'timer_id' => $timer->id,
            'sequence' => $timer->sequence,
            'delay_seconds' => $timer->delay_seconds,
            'fire_at' => $fireAt->toJSON(),
            'timer_kind' => 'condition_timeout',
            'condition_wait_id' => 'condition:4',
        ]);

        $timer->forceFill([
            'status' => TimerStatus::Cancelled,
        ])->save();

        WorkflowHistoryEvent::record($run->refresh(), HistoryEventType::TimerCancelled, [
            'timer_id' => $timer->id,
            'sequence' => $timer->sequence,
            'delay_seconds' => $timer->delay_seconds,
            'fire_at' => $fireAt->toJSON(),
            'cancelled_at' => $cancelledAt->toJSON(),
            'timer_kind' => 'condition_timeout',
            'condition_wait_id' => 'condition:4',
        ]);

        $timerId = $timer->id;
        $timer->delete();

        $bundle = HistoryExport::forRun($run->fresh(['historyEvents', 'timers']));

        $this->assertCount(1, $bundle['timers']);
        $this->assertSame($timerId, $bundle['timers'][0]['id']);
        $this->assertSame(4, $bundle['timers'][0]['sequence']);
        $this->assertSame('cancelled', $bundle['timers'][0]['status']);
        $this->assertFalse($bundle['timers'][0]['diagnostic_only']);
        $this->assertSame(60, $bundle['timers'][0]['delay_seconds']);
        $this->assertSame($fireAt->toJSON(), $bundle['timers'][0]['fire_at']);
        $this->assertNull($bundle['timers'][0]['fired_at']);
        $this->assertSame($cancelledAt->toJSON(), $bundle['timers'][0]['cancelled_at']);
        $this->assertSame('condition_timeout', $bundle['timers'][0]['timer_kind']);
        $this->assertSame('condition:4', $bundle['timers'][0]['condition_wait_id']);
    }

    public function testItLabelsTerminalTimerRowsWithoutTypedHistoryAsUnsupportedInExports(): void
    {
        Carbon::setTestNow('2026-04-09 12:20:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $run = $this->createMinimalCompletedRun('history-export-row-only-timer');
        $fireAt = now()
            ->subMinute();

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'status' => TimerStatus::Fired->value,
            'delay_seconds' => 60,
            'fire_at' => $fireAt,
            'fired_at' => now(),
            'created_at' => now()
                ->subMinutes(2),
        ]);

        $bundle = HistoryExport::forRun($run->fresh(['historyEvents', 'timers']));

        $this->assertCount(1, $bundle['timers']);
        $this->assertSame($timer->id, $bundle['timers'][0]['id']);
        $this->assertSame('unsupported', $bundle['timers'][0]['status']);
        $this->assertTrue($bundle['timers'][0]['diagnostic_only']);
        $this->assertSame('fired', $bundle['timers'][0]['source_status']);
        $this->assertSame('fired', $bundle['timers'][0]['row_status']);
        $this->assertSame('unsupported_terminal_without_history', $bundle['timers'][0]['history_authority']);
        $this->assertSame(
            'terminal_timer_row_without_typed_history',
            $bundle['timers'][0]['history_unsupported_reason'],
        );
        $this->assertSame([], $bundle['timers'][0]['history_event_types']);
        $this->assertNull($bundle['timers'][0]['fired_at']);
    }

    public function testItRebuildsLegacyProjectedTimerRowsWithoutRowStatusInExports(): void
    {
        Carbon::setTestNow('2026-04-09 12:25:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $run = $this->createMinimalCompletedRun('history-export-row-only-timer-projection-compat');
        $fireAt = now()
            ->subMinute();

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'status' => TimerStatus::Fired->value,
            'delay_seconds' => 60,
            'fire_at' => $fireAt,
            'fired_at' => now(),
            'created_at' => now()
                ->subMinutes(2),
        ]);

        $initialBundle = HistoryExport::forRun($run->fresh(['historyEvents', 'timers']));
        $entry = WorkflowRunTimerEntry::query()
            ->where('workflow_run_id', $run->id)
            ->where('timer_id', $timer->id)
            ->firstOrFail();

        $payload = $entry->payload;
        unset($payload['row_status']);

        $entry->forceFill([
            'schema_version' => WorkflowRunTimerEntry::LEGACY_SCHEMA_VERSION,
            'payload' => $payload,
        ])->save();

        $bundle = HistoryExport::forRun($run->fresh(['historyEvents', 'timers', 'timerEntries']));

        $this->assertSame('workflow_run_timer_entries_rebuilt', $bundle['selected_run']['timers_projection_source']);
        $this->assertSame(['legacy_schema'], $bundle['selected_run']['timers_projection_rebuild_reasons']);
        $this->assertCount(1, $bundle['timers']);
        $this->assertSame($timer->id, $bundle['timers'][0]['id']);
        $this->assertSame('unsupported', $bundle['timers'][0]['status']);
        $this->assertSame('fired', $bundle['timers'][0]['source_status']);
        $this->assertSame('fired', $bundle['timers'][0]['row_status']);
        $this->assertTrue($bundle['timers'][0]['diagnostic_only']);
        $this->assertSame('unsupported_terminal_without_history', $bundle['timers'][0]['history_authority']);

        $this->assertSame($initialBundle['timers'][0], $bundle['timers'][0]);

        $this->assertDatabaseHas('workflow_run_timer_entries', [
            'workflow_run_id' => $run->id,
            'timer_id' => $timer->id,
            'schema_version' => WorkflowRunTimerEntry::CURRENT_SCHEMA_VERSION,
        ]);
    }

    public function testItLabelsTerminalActivityRowsWithoutTypedHistoryAsUnsupportedInExports(): void
    {
        Carbon::setTestNow('2026-04-09 12:30:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $run = $this->createMinimalCompletedRun('history-export-row-only-activity');
        $completedAttemptId = (string) Str::ulid();
        $cancelledAttemptId = (string) Str::ulid();

        /** @var ActivityExecution $activity */
        $activity = ActivityExecution::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'activity_class' => 'App\\Activities\\RowOnlyActivity',
            'activity_type' => 'row.only.activity',
            'status' => ActivityStatus::Completed->value,
            'arguments' => Serializer::serialize(['order-123']),
            'result' => Serializer::serialize('mutable-result'),
            'connection' => 'redis',
            'queue' => 'activities',
            'attempt_count' => 1,
            'current_attempt_id' => $completedAttemptId,
            'started_at' => now()
                ->subMinutes(2),
            'closed_at' => now()
                ->subMinute(),
        ]);

        /** @var ActivityExecution $cancelledActivity */
        $cancelledActivity = ActivityExecution::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 3,
            'activity_class' => 'App\\Activities\\RowOnlyCancelledActivity',
            'activity_type' => 'row.only.cancelled-activity',
            'status' => ActivityStatus::Cancelled->value,
            'arguments' => Serializer::serialize(['order-456']),
            'connection' => 'redis',
            'queue' => 'activities',
            'attempt_count' => 1,
            'current_attempt_id' => $cancelledAttemptId,
            'started_at' => now()
                ->subMinutes(2),
            'closed_at' => now()
                ->subMinute(),
        ]);

        $bundle = HistoryExport::forRun($run->fresh(['historyEvents', 'activityExecutions.attempts']));

        $this->assertCount(2, $bundle['activities']);
        $this->assertSame($activity->id, $bundle['activities'][0]['id']);
        $this->assertSame(2, $bundle['activities'][0]['sequence']);
        $this->assertSame('row.only.activity', $bundle['activities'][0]['activity_type']);
        $this->assertSame('unsupported', $bundle['activities'][0]['status']);
        $this->assertSame('completed', $bundle['activities'][0]['source_status']);
        $this->assertSame('completed', $bundle['activities'][0]['row_status']);
        $this->assertSame('unsupported_terminal_without_history', $bundle['activities'][0]['history_authority']);
        $this->assertTrue($bundle['activities'][0]['diagnostic_only']);
        $this->assertSame(
            'terminal_activity_row_without_typed_history',
            $bundle['activities'][0]['history_unsupported_reason'],
        );
        $this->assertSame([], $bundle['activities'][0]['history_event_types']);
        $this->assertSame($activity->arguments, $bundle['activities'][0]['arguments']);
        $this->assertSame($completedAttemptId, $bundle['activities'][0]['current_attempt_id']);
        $this->assertCount(1, $bundle['activities'][0]['attempts']);
        $this->assertSame($completedAttemptId, $bundle['activities'][0]['attempts'][0]['id']);
        $this->assertSame($activity->id, $bundle['activities'][0]['attempts'][0]['activity_execution_id']);
        $this->assertSame('completed', $bundle['activities'][0]['attempts'][0]['status']);
        $this->assertNull($bundle['activities'][0]['result']);
        $this->assertNull($bundle['activities'][0]['closed_at']);

        $this->assertSame($cancelledActivity->id, $bundle['activities'][1]['id']);
        $this->assertSame(3, $bundle['activities'][1]['sequence']);
        $this->assertSame('row.only.cancelled-activity', $bundle['activities'][1]['activity_type']);
        $this->assertSame('unsupported', $bundle['activities'][1]['status']);
        $this->assertSame('cancelled', $bundle['activities'][1]['source_status']);
        $this->assertSame('cancelled', $bundle['activities'][1]['row_status']);
        $this->assertSame('unsupported_terminal_without_history', $bundle['activities'][1]['history_authority']);
        $this->assertTrue($bundle['activities'][1]['diagnostic_only']);
        $this->assertSame(
            'terminal_activity_row_without_typed_history',
            $bundle['activities'][1]['history_unsupported_reason'],
        );
        $this->assertSame([], $bundle['activities'][1]['history_event_types']);
        $this->assertSame($cancelledActivity->arguments, $bundle['activities'][1]['arguments']);
        $this->assertSame($cancelledAttemptId, $bundle['activities'][1]['current_attempt_id']);
        $this->assertCount(1, $bundle['activities'][1]['attempts']);
        $this->assertSame($cancelledAttemptId, $bundle['activities'][1]['attempts'][0]['id']);
        $this->assertSame($cancelledActivity->id, $bundle['activities'][1]['attempts'][0]['activity_execution_id']);
        $this->assertSame('cancelled', $bundle['activities'][1]['attempts'][0]['status']);
        $this->assertNull($bundle['activities'][1]['result']);
        $this->assertNull($bundle['activities'][1]['closed_at']);
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
            'output' => Serializer::serialize([
                'secret' => true,
            ]),
            'started_at' => now()
                ->subMinutes(5),
            'closed_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        $command = WorkflowCommand::record($instance, $run, [
            'command_type' => CommandType::Start->value,
            'target_scope' => 'instance',
            'payload_codec' => config('workflows.serializer'),
            'payload' => Serializer::serialize([
                'arguments' => ['secret-order'],
            ]),
            'context' => [
                'workflow' => [
                    'parent_instance_id' => 'secret-parent',
                ],
            ],
            'source' => 'php',
            'status' => CommandStatus::Accepted->value,
            'outcome' => CommandOutcome::StartedNew->value,
            'accepted_at' => now()
                ->subMinutes(5),
            'applied_at' => now()
                ->subMinutes(5),
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
            'accepted_at' => now()
                ->subMinutes(4),
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
            'received_at' => now()
                ->subMinutes(4),
        ]);

        WorkflowHistoryEvent::record(
            $run,
            HistoryEventType::WorkflowStarted,
            [
                'arguments' => ['secret-order'],
            ],
            command: $command,
        );

        WorkflowTask::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Completed->value,
            'payload' => [
                'secret' => 'task',
            ],
            'available_at' => now()
                ->subMinutes(5),
            'last_dispatched_at' => now()
                ->subMinutes(5),
        ]);

        ActivityExecution::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'App\\Activities\\RedactedExportActivity',
            'activity_type' => 'export.redacted.activity',
            'status' => 'completed',
            'arguments' => Serializer::serialize(['secret-order']),
            'result' => Serializer::serialize([
                'activity-secret' => true,
            ]),
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
        $this->assertContains('timeline.0.command.payload', $bundle['redaction']['paths']);
        $this->assertContains('timeline.0.command.context', $bundle['redaction']['paths']);
        $this->assertContains('commands.0.payload', $bundle['redaction']['paths']);
        $this->assertContains('commands.0.context', $bundle['redaction']['paths']);
        $this->assertContains('signals.0.arguments', $bundle['redaction']['paths']);
        $this->assertContains('tasks.0.payload', $bundle['redaction']['paths']);
        $this->assertContains('activities.0.arguments', $bundle['redaction']['paths']);
        $this->assertContains('failures.0.message', $bundle['redaction']['paths']);
        $this->assertSame('payloads.arguments.data', $bundle['payloads']['arguments']['data']['path']);
        $this->assertSame('workflow_payload', $bundle['payloads']['arguments']['data']['category']);
        $this->assertSame('history_events.0.payload', $bundle['history_events'][0]['payload']['path']);
        $this->assertSame('timeline.0.command.payload', $bundle['timeline'][0]['command']['payload']['path']);
        $this->assertSame('timeline.0.command.context', $bundle['timeline'][0]['command']['context']['path']);
        $this->assertSame('failure_diagnostic', $bundle['failures'][0]['message']['category']);

        $stubBundle = WorkflowStub::loadRun($run->id)->historyExport(new class() implements HistoryExportRedactor {
            /**
             * @param array<string, mixed> $context
             */
            public function redact(mixed $value, array $context): string
            {
                return 'inline-redacted:' . $context['path'];
            }
        });

        $this->assertSame('inline-redacted:payloads.arguments.data', $stubBundle['payloads']['arguments']['data']);
    }

    public function testItEmbedsAvroWrapperSchemaWhenBundleContainsAvroPayloads(): void
    {
        if (! class_exists(\Apache\Avro\Schema\AvroSchema::class)) {
            $this->markTestSkipped('apache/avro package is not installed in this environment.');
        }

        config()->set('workflows.serializer', 'avro');
        $run = $this->createMinimalCompletedRun('history-export-avro');
        $run->forceFill(['payload_codec' => 'avro'])->save();

        $bundle = HistoryExport::forRun($run->refresh(), Carbon::parse('2026-04-09 13:00:00'));

        $this->assertArrayHasKey('codec_schemas', $bundle);
        $this->assertArrayHasKey('avro', $bundle['codec_schemas']);
        $schema = json_decode($bundle['codec_schemas']['avro']['wrapper_schema'], true);
        $this->assertSame('record', $schema['type']);
        $this->assertSame('Payload', $schema['name']);
        $this->assertSame('durable_workflow', $schema['namespace']);
        $this->assertSame('00', $bundle['codec_schemas']['avro']['wrapper_prefix_hex']);
        $this->assertSame('01', $bundle['codec_schemas']['avro']['typed_prefix_hex']);
    }

    public function testItOmitsAvroSchemasWhenBundleHasNoAvroPayloads(): void
    {
        config()->set('workflows.serializer', 'json');
        $run = $this->createMinimalCompletedRun('history-export-json');
        $run->forceFill(['payload_codec' => 'json'])->save();

        $bundle = HistoryExport::forRun($run->refresh(), Carbon::parse('2026-04-09 13:00:00'));

        $this->assertArrayHasKey('codec_schemas', $bundle);
        $this->assertSame([], $bundle['codec_schemas']);
    }

    public function testItSignsHistoryExportIntegrityWhenSigningKeyIsConfigured(): void
    {
        config()->set('workflows.v2.history_export.signing_key', 'history-export-secret');
        config()
            ->set('workflows.v2.history_export.signing_key_id', '2026-04-primary');

        $run = $this->createMinimalCompletedRun('history-export-signed');

        $bundle = HistoryExport::forRun($run, Carbon::parse('2026-04-09 13:00:00'));
        $unsignedBundle = $bundle;
        unset($unsignedBundle['integrity']);
        $canonicalJson = self::canonicalJson($unsignedBundle);

        $this->assertSame('json-recursive-ksort-v1', $bundle['integrity']['canonicalization']);
        $this->assertSame('sha256', $bundle['integrity']['checksum_algorithm']);
        $this->assertSame(hash('sha256', $canonicalJson), $bundle['integrity']['checksum']);
        $this->assertSame('hmac-sha256', $bundle['integrity']['signature_algorithm']);
        $this->assertSame(
            hash_hmac('sha256', $canonicalJson, 'history-export-secret'),
            $bundle['integrity']['signature']
        );
        $this->assertSame('2026-04-primary', $bundle['integrity']['key_id']);
    }

    private function createMinimalCompletedRun(string $instanceId): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => 'App\\Workflows\\SignedExportWorkflow',
            'workflow_type' => 'export.signed',
            'run_count' => 1,
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\SignedExportWorkflow',
            'workflow_type' => 'export.signed',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize(['signed']),
            'output' => Serializer::serialize([
                'ok' => true,
            ]),
            'started_at' => now()
                ->subMinutes(2),
            'closed_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_type' => 'export.signed',
        ]);
        WorkflowHistoryEvent::record($run->refresh(), HistoryEventType::WorkflowCompleted, [
            'result_available' => true,
        ]);

        return $run->refresh();
    }

    private static function canonicalJson(mixed $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::canonicalize($item), $value);
        }

        $canonical = [];

        foreach ($value as $key => $item) {
            $canonical[$key] = self::canonicalize($item);
        }

        ksort($canonical, SORT_STRING);

        return $canonical;
    }
}
