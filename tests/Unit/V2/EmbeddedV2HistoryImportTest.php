<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
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
use Workflow\V2\Support\EmbeddedV2HistoryImport;
use Workflow\V2\Support\EmbeddedV2ImportContract;
use Workflow\V2\Support\HistoryExport;

final class EmbeddedV2HistoryImportTest extends TestCase
{
    public function testContractPublishesImportRulesAndAuditMarkers(): void
    {
        $manifest = EmbeddedV2ImportContract::manifest();

        $this->assertSame('durable-workflow.v2.embedded-v2-import.contract', $manifest['schema']);
        $this->assertSame(HistoryExport::SCHEMA, $manifest['history_export']['schema']);
        $this->assertSame('workflow:v2:history-import {bundle}', $manifest['import']['command']);
        $this->assertSame(['embedded'], $manifest['eligibility']['supported_source_runtimes']);
        $this->assertSame('out_of_scope', $manifest['eligibility']['v1_history']);
        $this->assertContains('import_source', $manifest['visibility_and_audit']['workflow_run_columns']);
        $this->assertSame('embedded_v2_import', $manifest['visibility_and_audit']['summary_engine_source']);
    }

    public function testItImportsAnEligibleRunningEmbeddedRunAndRebuildsServerState(): void
    {
        Carbon::setTestNow('2026-05-05 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $bundle = $this->runningBundleWithOpenWork();
        $runId = $bundle['workflow']['run_id'];
        $this->clearWorkflowState();

        $report = EmbeddedV2HistoryImport::import($bundle, [
            'namespace' => 'server-production',
            'import_id' => 'operator-import-001',
        ]);

        $this->assertSame('imported', $report['status']);
        $this->assertSame('operator-import-001', $report['import_id']);
        $this->assertSame(1, $report['rows']['workflow_runs']);
        $this->assertSame(2, $report['rows']['workflow_tasks']);
        $this->assertSame(1, $report['rows']['activity_executions']);
        $this->assertSame(1, $report['rows']['workflow_run_timers']);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        $this->assertSame('server-production', $run->namespace);
        $this->assertSame('embedded_v2', $run->import_source);
        $this->assertSame('operator-import-001', $run->import_id);
        $this->assertSame($bundle['dedupe_key'], $run->import_dedupe_key);
        $this->assertNotNull($run->imported_at);

        /** @var WorkflowRunSummary $summary */
        $summary = WorkflowRunSummary::query()->findOrFail($runId);
        $this->assertSame('embedded_v2_import', $summary->engine_source);
        $this->assertSame('server-production', $summary->namespace);
        $this->assertTrue((bool) $summary->is_current_run);
        $this->assertSame(1, ActivityExecution::query()->where('workflow_run_id', $runId)->count());
        $this->assertSame(1, WorkflowTimer::query()->where('workflow_run_id', $runId)->count());

        $again = EmbeddedV2HistoryImport::import($bundle, [
            'namespace' => 'server-production',
        ]);

        $this->assertSame('already_imported', $again['status']);
    }

    public function testItRejectsLeasedEmbeddedTasksWithoutWritingRows(): void
    {
        $bundle = $this->runningBundleWithOpenWork(TaskStatus::Leased);
        $runId = $bundle['workflow']['run_id'];
        $this->clearWorkflowState();

        $report = EmbeddedV2HistoryImport::import($bundle);

        $this->assertSame('rejected', $report['status']);
        $this->assertContains(
            'tasks.leased_task_present',
            array_column($report['eligibility']['errors'], 'rule'),
        );
        $this->assertFalse(WorkflowRun::query()->whereKey($runId)->exists());
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->count());
    }

    public function testItRejectsStandaloneServerExports(): void
    {
        config([
            'server.topology.shape' => 'standalone_server',
            'server.topology.process_class' => 'server_http_node',
        ]);

        $bundle = $this->runningBundleWithOpenWork();
        $runId = $bundle['workflow']['run_id'];
        $this->assertSame('standalone_server', $bundle['workflow']['source_runtime']);
        $this->clearWorkflowState();

        $report = EmbeddedV2HistoryImport::import($bundle);

        $this->assertSame('rejected', $report['status']);
        $this->assertContains(
            'workflow.source_runtime_unsupported',
            array_column($report['eligibility']['errors'], 'rule'),
        );
        $this->assertFalse(WorkflowRun::query()->whereKey($runId)->exists());
    }

    /**
     * @return array<string, mixed>
     */
    private function runningBundleWithOpenWork(TaskStatus $workflowTaskStatus = TaskStatus::Ready): array
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'embedded-import-instance-' . Str::lower((string) Str::ulid()),
            'workflow_class' => 'App\\Workflows\\EmbeddedImportWorkflow',
            'workflow_type' => 'orders.import',
            'namespace' => 'embedded-production',
            'business_key' => 'order-123',
            'run_count' => 1,
            'started_at' => now()
                ->subMinutes(10),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\EmbeddedImportWorkflow',
            'workflow_type' => 'orders.import',
            'namespace' => 'embedded-production',
            'business_key' => 'order-123',
            'status' => RunStatus::Waiting->value,
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize(['order-123']),
            'connection' => 'redis',
            'queue' => 'orders',
            'started_at' => now()
                ->subMinutes(10),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_class' => 'App\\Workflows\\EmbeddedImportWorkflow',
            'workflow_type' => 'orders.import',
        ]);

        WorkflowTask::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'namespace' => 'embedded-production',
            'task_type' => TaskType::Workflow->value,
            'status' => $workflowTaskStatus->value,
            'payload' => [
                'reason' => 'import-test',
            ],
            'connection' => 'redis',
            'queue' => 'orders',
            'available_at' => now()
                ->subMinute(),
            'leased_at' => $workflowTaskStatus === TaskStatus::Leased ? now() : null,
            'lease_owner' => $workflowTaskStatus === TaskStatus::Leased ? 'embedded-worker-1' : null,
            'lease_expires_at' => $workflowTaskStatus === TaskStatus::Leased ? now()->addMinute() : null,
        ]);

        $activity = ActivityExecution::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'App\\Activities\\ReserveInventory',
            'activity_type' => 'orders.reserve-inventory',
            'status' => ActivityStatus::Pending->value,
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize(['order-123']),
            'connection' => 'redis',
            'queue' => 'orders',
            'attempt_count' => 0,
        ]);

        WorkflowTask::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'namespace' => 'embedded-production',
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'payload' => [
                'activity_execution_id' => $activity->id,
            ],
            'connection' => 'redis',
            'queue' => 'orders',
            'available_at' => now()
                ->subMinute(),
        ]);

        WorkflowTimer::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'status' => TimerStatus::Pending->value,
            'delay_seconds' => 60,
            'fire_at' => now()
                ->addMinute(),
        ]);

        return HistoryExport::forRun($run->fresh());
    }

    private function clearWorkflowState(): void
    {
        foreach ([
            'workflow_run_summaries',
            'workflow_run_waits',
            'workflow_run_timeline_entries',
            'workflow_run_timer_entries',
            'workflow_run_lineage_entries',
            'workflow_search_attributes',
            'workflow_memos',
            'workflow_history_events',
            'workflow_tasks',
            'activity_attempts',
            'activity_executions',
            'workflow_run_timers',
            'workflow_failures',
            'workflow_links',
            'workflow_signal_records',
            'workflow_updates',
            'workflow_commands',
            'workflow_runs',
            'workflow_instances',
        ] as $table) {
            DB::table($table)->delete();
        }
    }
}
