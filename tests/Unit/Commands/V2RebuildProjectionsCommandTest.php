<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

final class V2RebuildProjectionsCommandTest extends TestCase
{
    public function testItRebuildsMissingRunSummariesAndPrunesStaleRows(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        [$instance, $run] = $this->createCompletedRun('projection-command-instance');
        $staleRunId = (string) Str::ulid();

        WorkflowRunSummary::query()->create([
            'id' => $staleRunId,
            'workflow_instance_id' => $instance->id,
            'run_number' => 99,
            'is_current_run' => false,
            'engine_source' => 'v2',
            'class' => 'App\\Workflows\\DeletedWorkflow',
            'workflow_type' => 'deleted.workflow',
            'status' => RunStatus::Completed->value,
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
            'started_at' => now()->subHour(),
            'closed_at' => now()->subMinutes(50),
            'duration_ms' => 600000,
            'exception_count' => 0,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subMinutes(50),
        ]);

        $this->artisan('workflow:v2:rebuild-projections', [
            '--prune-stale' => true,
        ])
            ->expectsOutput('Rebuilt 1 run-summary projection row(s).')
            ->expectsOutput('Pruned 1 stale run-summary projection row(s).')
            ->assertSuccessful();

        $this->assertDatabaseHas('workflow_run_summaries', [
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'status' => RunStatus::Completed->value,
            'status_bucket' => 'completed',
            'history_event_count' => 2,
        ]);
        $this->assertGreaterThan(
            0,
            (int) WorkflowRunSummary::query()->whereKey($run->id)->value('history_size_bytes'),
        );
        $this->assertDatabaseMissing('workflow_run_summaries', [
            'id' => $staleRunId,
        ]);
    }

    public function testDryRunReportsMatchedRowsWithoutMutatingProjectionTables(): void
    {
        [$instance, $run] = $this->createCompletedRun('projection-command-dry-run');

        $expected = [
            'dry_run' => true,
            'runs_matched' => 1,
            'run_summaries_rebuilt' => 0,
            'run_summaries_would_rebuild' => 1,
            'run_summaries_pruned' => 0,
            'run_summaries_would_prune' => 0,
            'failures' => [],
        ];

        $this->artisan('workflow:v2:rebuild-projections', [
            '--instance-id' => $instance->id,
            '--missing' => true,
            '--dry-run' => true,
            '--json' => true,
        ])
            ->expectsOutput(json_encode($expected, JSON_UNESCAPED_SLASHES))
            ->assertSuccessful();

        $this->assertDatabaseMissing('workflow_run_summaries', [
            'id' => $run->id,
        ]);
    }

    /**
     * @return array{WorkflowInstance, WorkflowRun}
     */
    private function createCompletedRun(string $instanceId): array
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => 'App\\Workflows\\ProjectionWorkflow',
            'workflow_type' => 'projection.workflow',
            'run_count' => 1,
            'reserved_at' => now()->subMinutes(5),
            'started_at' => now()->subMinutes(5),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\ProjectionWorkflow',
            'workflow_type' => 'projection.workflow',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'started_at' => now()->subMinutes(5),
            'closed_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->forceFill(['current_run_id' => $run->id])->save();

        WorkflowHistoryEvent::record(
            $run,
            HistoryEventType::WorkflowStarted,
            ['workflow_type' => 'projection.workflow'],
        );
        WorkflowHistoryEvent::record(
            $run->refresh(),
            HistoryEventType::WorkflowCompleted,
            ['result_available' => true],
        );

        return [$instance->refresh(), $run->refresh()];
    }
}
