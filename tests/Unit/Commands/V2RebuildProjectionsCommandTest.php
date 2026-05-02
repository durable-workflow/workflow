<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;
use Workflow\V2\Contracts\HistoryProjectionMaintenanceRole;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowRunTimerEntry;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\DefaultHistoryProjectionRole;
use Workflow\V2\Support\RunSummaryProjector;

final class V2RebuildProjectionsCommandTest extends TestCase
{
    public function testItUsesTheHistoryProjectionRoleBindingForRebuilds(): void
    {
        [, $run] = $this->createCompletedRun('projection-command-history-role');

        $customRole = new class(new DefaultHistoryProjectionRole()) implements HistoryProjectionMaintenanceRole {
            /**
             * @var list<string>
             */
            public array $projectedRunIds = [];

            public function __construct(
                private readonly DefaultHistoryProjectionRole $delegate,
            ) {
            }

            public function projectRun(WorkflowRun $run): WorkflowRunSummary
            {
                $this->projectedRunIds[] = $run->id;

                return $this->delegate->projectRun($run);
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                ActivityExecution $execution,
                ActivityAttempt $attempt,
                WorkflowTask $task,
            ): WorkflowRunSummary {
                return $this->delegate->recordActivityStarted($run, $execution, $attempt, $task);
            }

            public function pruneStaleProjections(
                array $runIds = [],
                ?string $instanceId = null,
                bool $dryRun = false
            ): array {
                return $this->delegate->pruneStaleProjections($runIds, $instanceId, $dryRun);
            }

            public function pruneStaleProjectionRowsForRun(
                string $projectionModel,
                string $runId,
                array $seenProjectionIds,
            ): void {
                $this->delegate->pruneStaleProjectionRowsForRun($projectionModel, $runId, $seenProjectionIds);
            }
        };

        $this->app->instance(HistoryProjectionMaintenanceRole::class, $customRole);

        $this->artisan('workflow:v2:rebuild-projections', [
            '--run-id' => [$run->id],
        ])
            ->expectsOutput('Rebuilt 1 run-summary projection row(s).')
            ->assertSuccessful();

        $this->assertSame([$run->id], $customRole->projectedRunIds);
        $this->assertDatabaseHas('workflow_run_summaries', [
            'id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'status' => RunStatus::Completed->value,
        ]);
    }

    public function testItReportsHistoryProjectionRoleFailures(): void
    {
        [, $run] = $this->createCompletedRun('projection-command-history-role-failure');

        $failingRole = new class() implements HistoryProjectionMaintenanceRole {
            public function projectRun(WorkflowRun $run): WorkflowRunSummary
            {
                throw new \RuntimeException('projection seam exploded');
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                ActivityExecution $execution,
                ActivityAttempt $attempt,
                WorkflowTask $task,
            ): WorkflowRunSummary {
                throw new \RuntimeException('unused');
            }

            public function pruneStaleProjections(
                array $runIds = [],
                ?string $instanceId = null,
                bool $dryRun = false
            ): array {
                throw new \RuntimeException('unused');
            }

            public function pruneStaleProjectionRowsForRun(
                string $projectionModel,
                string $runId,
                array $seenProjectionIds,
            ): void {
                throw new \RuntimeException('unused');
            }
        };

        $this->app->instance(HistoryProjectionMaintenanceRole::class, $failingRole);

        $this->artisan('workflow:v2:rebuild-projections', [
            '--run-id' => [$run->id],
        ])
            ->expectsOutput('Rebuilt 0 run-summary projection row(s).')
            ->expectsOutput(sprintf('Failed to rebuild run [%s]: projection seam exploded', $run->id))
            ->assertFailed();

        $this->assertDatabaseMissing('workflow_run_summaries', [
            'id' => $run->id,
        ]);
    }

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
            'started_at' => now()
                ->subHour(),
            'closed_at' => now()
                ->subMinutes(50),
            'duration_ms' => 600000,
            'exception_count' => 0,
            'created_at' => now()
                ->subHour(),
            'updated_at' => now()
                ->subMinutes(50),
        ]);

        WorkflowRunWait::query()->create([
            'id' => 'projection-command-stale-wait',
            'workflow_run_id' => $staleRunId,
            'workflow_instance_id' => $instance->id,
            'wait_id' => 'signal:deleted',
            'position' => 0,
            'kind' => 'signal',
            'status' => 'open',
            'source_status' => 'open',
            'task_backed' => false,
            'external_only' => true,
        ]);
        WorkflowTimelineEntry::query()->create([
            'id' => 'projection-command-stale-timeline',
            'workflow_run_id' => $staleRunId,
            'workflow_instance_id' => $instance->id,
            'history_event_id' => (string) Str::ulid(),
            'sequence' => 1,
            'type' => HistoryEventType::WorkflowStarted->value,
            'kind' => 'workflow',
            'entry_kind' => 'point',
            'summary' => 'Deleted run timeline row.',
            'recorded_at' => now(),
        ]);
        WorkflowRunTimerEntry::query()->create([
            'id' => 'projection-command-stale-timer',
            'workflow_run_id' => $staleRunId,
            'workflow_instance_id' => $instance->id,
            'timer_id' => 'deleted-timer',
            'position' => 0,
            'status' => 'pending',
            'source_status' => 'pending',
            'history_authority' => 'typed_history',
            'payload' => [
                'id' => 'deleted-timer',
                'status' => 'pending',
                'source_status' => 'pending',
                'history_authority' => 'typed_history',
                'history_event_types' => [],
            ],
        ]);
        WorkflowRunLineageEntry::query()->create([
            'id' => 'projection-command-stale-lineage',
            'workflow_run_id' => $staleRunId,
            'workflow_instance_id' => $instance->id,
            'direction' => 'child',
            'lineage_id' => 'continue_as_new:deleted-run',
            'position' => 0,
            'link_type' => 'continue_as_new',
            'related_workflow_instance_id' => $instance->id,
            'related_workflow_run_id' => 'deleted-run',
            'payload' => [],
        ]);

        $this->artisan('workflow:v2:rebuild-projections', [
            '--prune-stale' => true,
        ])
            ->expectsOutput('Rebuilt 1 run-summary projection row(s).')
            ->expectsOutput('Pruned 1 stale run-summary projection row(s).')
            ->expectsOutput('Pruned 1 stale wait projection row(s).')
            ->expectsOutput('Pruned 1 stale timeline projection row(s).')
            ->expectsOutput('Pruned 1 stale timer projection row(s).')
            ->expectsOutput('Pruned 1 stale lineage projection row(s).')
            ->assertSuccessful();

        $this->assertDatabaseHas('workflow_run_summaries', [
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'status' => RunStatus::Completed->value,
            'status_bucket' => 'completed',
            'history_event_count' => 2,
        ]);
        $this->assertDatabaseHas('workflow_run_timeline_entries', [
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'type' => HistoryEventType::WorkflowStarted->value,
            'kind' => 'workflow',
        ]);
        $this->assertDatabaseHas('workflow_run_timeline_entries', [
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'type' => HistoryEventType::WorkflowCompleted->value,
            'kind' => 'workflow',
        ]);
        $this->assertGreaterThan(
            0,
            (int) WorkflowRunSummary::query()->whereKey($run->id)->value('history_size_bytes'),
        );
        $this->assertDatabaseMissing('workflow_run_summaries', [
            'id' => $staleRunId,
        ]);
        $this->assertDatabaseMissing('workflow_run_waits', [
            'id' => 'projection-command-stale-wait',
        ]);
        $this->assertDatabaseMissing('workflow_run_timeline_entries', [
            'id' => 'projection-command-stale-timeline',
        ]);
        $this->assertDatabaseMissing('workflow_run_timer_entries', [
            'id' => 'projection-command-stale-timer',
        ]);
        $this->assertDatabaseMissing('workflow_run_lineage_entries', [
            'id' => 'projection-command-stale-lineage',
        ]);
    }

    public function testItUsesTheHistoryProjectionMaintenanceRoleBindingForPruneStale(): void
    {
        [$instance] = $this->createCompletedRun('projection-command-maintenance-role');
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
            'started_at' => now()
                ->subHour(),
            'closed_at' => now()
                ->subMinutes(50),
            'duration_ms' => 600000,
            'exception_count' => 0,
            'created_at' => now()
                ->subHour(),
            'updated_at' => now()
                ->subMinutes(50),
        ]);

        $customRole = new class(new DefaultHistoryProjectionRole()) implements HistoryProjectionMaintenanceRole {
            /**
             * @var list<array{run_ids: list<string>, instance_id: ?string, dry_run: bool}>
             */
            public array $pruneCalls = [];

            public function __construct(
                private readonly DefaultHistoryProjectionRole $delegate,
            ) {
            }

            public function projectRun(WorkflowRun $run): WorkflowRunSummary
            {
                return $this->delegate->projectRun($run);
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                ActivityExecution $execution,
                ActivityAttempt $attempt,
                WorkflowTask $task,
            ): WorkflowRunSummary {
                return $this->delegate->recordActivityStarted($run, $execution, $attempt, $task);
            }

            public function pruneStaleProjections(
                array $runIds = [],
                ?string $instanceId = null,
                bool $dryRun = false
            ): array {
                $this->pruneCalls[] = [
                    'run_ids' => $runIds,
                    'instance_id' => $instanceId,
                    'dry_run' => $dryRun,
                ];

                return $this->delegate->pruneStaleProjections($runIds, $instanceId, $dryRun);
            }

            public function pruneStaleProjectionRowsForRun(
                string $projectionModel,
                string $runId,
                array $seenProjectionIds,
            ): void {
                $this->delegate->pruneStaleProjectionRowsForRun($projectionModel, $runId, $seenProjectionIds);
            }
        };

        $this->app->instance(HistoryProjectionMaintenanceRole::class, $customRole);

        $this->artisan('workflow:v2:rebuild-projections', [
            '--instance-id' => $instance->id,
            '--prune-stale' => true,
        ])
            ->expectsOutput('Pruned 1 stale run-summary projection row(s).')
            ->assertSuccessful();

        $this->assertSame([[
            'run_ids' => [],
            'instance_id' => $instance->id,
            'dry_run' => false,
        ]], $customRole->pruneCalls);
    }

    public function testDryRunReportsMatchedRowsWithoutMutatingProjectionTables(): void
    {
        [$instance, $run] = $this->createCompletedRun('projection-command-dry-run');

        $this->artisan('workflow:v2:rebuild-projections', [
            '--instance-id' => $instance->id,
            '--missing' => true,
            '--dry-run' => true,
            '--json' => true,
        ])
            ->assertSuccessful();

        $this->assertDatabaseMissing('workflow_run_summaries', [
            'id' => $run->id,
        ]);
    }

    public function testNeedsRebuildOptionRebuildsMissingAndStaleRunSummaries(): void
    {
        [$missingInstance, $missingRun] = $this->createCompletedRun('projection-command-missing');
        [$staleInstance, $staleRun] = $this->createCompletedRun('projection-command-stale');
        [, $timelineDriftRun] = $this->createCompletedRun('projection-command-timeline-drift');
        [, $waitDriftRun] = $this->createWaitingRun('projection-command-wait-drift');
        [, $alignedRun] = $this->createCompletedRun('projection-command-aligned');
        $waitActivityId = (string) Str::ulid();

        ActivityExecution::query()->create([
            'id' => $waitActivityId,
            'workflow_run_id' => $waitDriftRun->id,
            'sequence' => 1,
            'activity_class' => 'ProjectionWaitActivity',
            'activity_type' => 'projection.wait',
            'status' => 'pending',
            'arguments' => serialize([]),
            'attempt_count' => 0,
            'created_at' => now()
                ->subMinutes(5),
            'updated_at' => now()
                ->subMinute(),
        ]);

        WorkflowRunSummary::query()->create([
            'id' => $staleRun->id,
            'workflow_instance_id' => $staleInstance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'App\\Workflows\\ProjectionWorkflow',
            'workflow_type' => 'projection.workflow',
            'status' => RunStatus::Waiting->value,
            'status_bucket' => 'running',
            'started_at' => now()
                ->subMinutes(5),
            'exception_count' => 0,
            'created_at' => now()
                ->subMinutes(5),
            'updated_at' => now()
                ->subMinutes(4),
        ]);
        WorkflowRunSummary::query()->create([
            'id' => $timelineDriftRun->id,
            'workflow_instance_id' => $timelineDriftRun->workflow_instance_id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'App\\Workflows\\ProjectionWorkflow',
            'workflow_type' => 'projection.workflow',
            'status' => RunStatus::Completed->value,
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
            'started_at' => $timelineDriftRun->started_at,
            'closed_at' => $timelineDriftRun->closed_at,
            'duration_ms' => 240000,
            'exception_count' => 0,
            'history_event_count' => 2,
            'created_at' => now()
                ->subMinutes(5),
            'updated_at' => now()
                ->subMinute(),
        ]);
        WorkflowRunSummary::query()->create([
            'id' => $waitDriftRun->id,
            'workflow_instance_id' => $waitDriftRun->workflow_instance_id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'App\\Workflows\\ProjectionWorkflow',
            'workflow_type' => 'projection.workflow',
            'status' => RunStatus::Waiting->value,
            'status_bucket' => 'running',
            'started_at' => $waitDriftRun->started_at,
            'open_wait_id' => "activity:{$waitActivityId}",
            'wait_kind' => 'activity',
            'wait_reason' => 'Waiting for activity projection.wait',
            'liveness_state' => 'waiting_for_activity',
            'exception_count' => 0,
            'created_at' => now()
                ->subMinutes(5),
            'updated_at' => now()
                ->subMinute(),
        ]);
        RunSummaryProjector::project($alignedRun);

        $this->artisan('workflow:v2:rebuild-projections', [
            '--needs-rebuild' => true,
        ])
            ->expectsOutputToContain('Rebuilt ')
            ->assertSuccessful();

        $this->assertDatabaseHas('workflow_run_summaries', [
            'id' => $missingRun->id,
            'workflow_instance_id' => $missingInstance->id,
            'status' => RunStatus::Completed->value,
            'status_bucket' => 'completed',
        ]);
        $this->assertDatabaseHas('workflow_run_summaries', [
            'id' => $staleRun->id,
            'workflow_instance_id' => $staleInstance->id,
            'status' => RunStatus::Completed->value,
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
        ]);
        $this->assertDatabaseHas('workflow_run_summaries', [
            'id' => $timelineDriftRun->id,
            'workflow_instance_id' => $timelineDriftRun->workflow_instance_id,
            'status' => RunStatus::Completed->value,
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
        ]);
        $this->assertDatabaseHas('workflow_run_timeline_entries', [
            'workflow_run_id' => $timelineDriftRun->id,
            'history_event_id' => WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $timelineDriftRun->id)
                ->orderBy('sequence')
                ->value('id'),
        ]);
        $this->assertDatabaseHas('workflow_run_summaries', [
            'id' => $waitDriftRun->id,
            'workflow_instance_id' => $waitDriftRun->workflow_instance_id,
            'status' => RunStatus::Waiting->value,
        ]);
        $this->assertDatabaseHas('workflow_run_waits', [
            'workflow_run_id' => $waitDriftRun->id,
            'wait_id' => "activity:{$waitActivityId}",
            'target_type' => 'projection.wait',
        ]);
    }

    public function testNeedsRebuildOptionIncludesSchemaOutdatedRunSummaries(): void
    {
        [$instance, $run] = $this->createCompletedRun('projection-command-schema-outdated');

        WorkflowRunSummary::query()->create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'projection_schema_version' => null,
            'class' => 'App\\Workflows\\ProjectionWorkflow',
            'workflow_type' => 'projection.workflow',
            'status' => RunStatus::Completed->value,
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
            'started_at' => $run->started_at,
            'closed_at' => $run->closed_at,
            'duration_ms' => 240000,
            'exception_count' => 0,
            'history_event_count' => 2,
            'created_at' => now()
                ->subMinutes(5),
            'updated_at' => now()
                ->subMinute(),
        ]);

        $this->artisan('workflow:v2:rebuild-projections', [
            '--needs-rebuild' => true,
        ])
            ->expectsOutputToContain('Rebuilt ')
            ->assertSuccessful();

        $this->assertDatabaseHas('workflow_run_summaries', [
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'status' => RunStatus::Completed->value,
            'projection_schema_version' => RunSummaryProjector::SCHEMA_VERSION,
        ]);
    }

    public function testNeedsRebuildOptionIncludesRunsMissingLineageProjectionRows(): void
    {
        [, $run] = $this->createWaitingRun('projection-command-lineage-drift');

        WorkflowHistoryEvent::record(
            $run,
            HistoryEventType::ChildWorkflowScheduled,
            [
                'sequence' => 1,
                'workflow_link_id' => 'projection-lineage-link',
                'child_call_id' => 'projection-lineage-link',
                'child_workflow_instance_id' => 'projection-child-instance',
                'child_workflow_run_id' => 'projection-child-run',
                'child_workflow_type' => 'projection.child',
                'child_workflow_class' => 'App\\Workflows\\ProjectionChildWorkflow',
                'child_run_number' => 1,
            ],
        );

        RunSummaryProjector::project($run->refresh());

        WorkflowRunLineageEntry::query()
            ->where('workflow_run_id', $run->id)
            ->delete();

        $this->artisan('workflow:v2:rebuild-projections', [
            '--needs-rebuild' => true,
        ])
            ->expectsOutput('Rebuilt 1 run-summary projection row(s).')
            ->assertSuccessful();

        $this->assertDatabaseHas('workflow_run_lineage_entries', [
            'workflow_run_id' => $run->id,
            'direction' => 'child',
            'lineage_id' => 'projection-lineage-link',
            'link_type' => 'child_workflow',
        ]);
    }

    public function testNeedsRebuildOptionIncludesRunsWithStaleSelectedRunProjectionPayloads(): void
    {
        [, $waitRun] = $this->createWaitingRun('proj-stale-wait-sel');
        [, $timelineRun] = $this->createCompletedRun('proj-stale-timeline-sel');
        [, $lineageRun] = $this->createCompletedRun('proj-stale-lineage-sel');
        [, $timerRun] = $this->createWaitingRun('proj-stale-timer-sel');

        ActivityExecution::query()->create([
            'id' => 'proj-stale-wait-activity',
            'workflow_run_id' => $waitRun->id,
            'sequence' => 1,
            'activity_class' => 'ProjectionSelectedWaitActivity',
            'activity_type' => 'projection.selected.wait',
            'status' => 'pending',
            'arguments' => serialize([]),
            'attempt_count' => 0,
            'created_at' => now()
                ->subMinutes(5),
            'updated_at' => now()
                ->subMinute(),
        ]);

        WorkflowHistoryEvent::record(
            $lineageRun->refresh(),
            HistoryEventType::WorkflowContinuedAsNew,
            [
                'sequence' => 3,
                'workflow_link_id' => 'projection-stale-lineage-link',
                'continued_to_run_id' => 'projection-stale-child-run',
            ],
        );
        WorkflowTimer::query()->create([
            'id' => 'proj-stale-timer',
            'workflow_run_id' => $timerRun->id,
            'sequence' => 1,
            'status' => 'pending',
            'delay_seconds' => 60,
            'fire_at' => now()
                ->addMinute(),
            'created_at' => now()
                ->subMinute(),
            'updated_at' => now()
                ->subMinute(),
        ]);

        RunSummaryProjector::project($waitRun->refresh());
        RunSummaryProjector::project($timelineRun->refresh());
        RunSummaryProjector::project($lineageRun->refresh());
        RunSummaryProjector::project($timerRun->refresh());

        WorkflowRunWait::query()
            ->where('workflow_run_id', $waitRun->id)
            ->update([
                'summary' => 'Stale wait row.',
            ]);
        WorkflowTimelineEntry::query()
            ->where('workflow_run_id', $timelineRun->id)
            ->where('type', HistoryEventType::WorkflowStarted->value)
            ->update([
                'summary' => 'Stale timeline row.',
            ]);
        WorkflowRunLineageEntry::query()
            ->where('workflow_run_id', $lineageRun->id)
            ->update([
                'related_workflow_run_id' => 'projection-stale-child-bad',
            ]);
        $timerEntry = WorkflowRunTimerEntry::query()
            ->where('workflow_run_id', $timerRun->id)
            ->firstOrFail();
        $timerPayload = $timerEntry->payload;
        unset($timerPayload['row_status']);
        $timerEntry->forceFill([
            'schema_version' => WorkflowRunTimerEntry::CURRENT_SCHEMA_VERSION - 1,
            'payload' => $timerPayload,
        ])->save();

        $this->artisan('workflow:v2:rebuild-projections', [
            '--needs-rebuild' => true,
        ])
            ->expectsOutput('Rebuilt 4 run-summary projection row(s).')
            ->assertSuccessful();

        $this->assertDatabaseMissing('workflow_run_waits', [
            'workflow_run_id' => $waitRun->id,
            'summary' => 'Stale wait row.',
        ]);
        $this->assertDatabaseHas('workflow_run_waits', [
            'workflow_run_id' => $waitRun->id,
            'wait_id' => 'activity:proj-stale-wait-activity',
            'target_type' => 'projection.selected.wait',
        ]);
        $this->assertDatabaseMissing('workflow_run_timeline_entries', [
            'workflow_run_id' => $timelineRun->id,
            'summary' => 'Stale timeline row.',
        ]);
        $this->assertDatabaseHas('workflow_run_timeline_entries', [
            'workflow_run_id' => $timelineRun->id,
            'type' => HistoryEventType::WorkflowStarted->value,
            'summary' => 'Workflow run started.',
        ]);
        $this->assertDatabaseMissing('workflow_run_lineage_entries', [
            'workflow_run_id' => $lineageRun->id,
            'related_workflow_run_id' => 'projection-stale-child-bad',
        ]);
        $this->assertDatabaseHas('workflow_run_lineage_entries', [
            'workflow_run_id' => $lineageRun->id,
            'lineage_id' => 'projection-stale-lineage-link',
            'related_workflow_run_id' => 'projection-stale-child-run',
        ]);
        $this->assertDatabaseHas('workflow_run_timer_entries', [
            'workflow_run_id' => $timerRun->id,
            'timer_id' => 'proj-stale-timer',
            'schema_version' => WorkflowRunTimerEntry::CURRENT_SCHEMA_VERSION,
        ]);
    }

    public function testItUsesConfiguredRunAndSummaryModels(): void
    {
        $this->createCustomProjectionTables();
        config()
            ->set('workflows.v2.run_model', ProjectionCommandWorkflowRun::class);
        config()
            ->set('workflows.v2.run_summary_model', ProjectionCommandWorkflowRunSummary::class);

        [$instance, $run] = $this->createCompletedRun(
            'projection-command-custom-model',
            ProjectionCommandWorkflowRun::class,
        );
        $staleRunId = (string) Str::ulid();

        ProjectionCommandWorkflowRunSummary::query()->create([
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
            'started_at' => now()
                ->subHour(),
            'closed_at' => now()
                ->subMinutes(50),
            'duration_ms' => 600000,
            'exception_count' => 0,
            'created_at' => now()
                ->subHour(),
            'updated_at' => now()
                ->subMinutes(50),
        ]);

        $this->artisan('workflow:v2:rebuild-projections', [
            '--prune-stale' => true,
        ])
            ->expectsOutput('Rebuilt 1 run-summary projection row(s).')
            ->expectsOutput('Pruned 1 stale run-summary projection row(s).')
            ->assertSuccessful();

        $this->assertDatabaseHas('projection_command_workflow_run_summaries', [
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'status' => RunStatus::Completed->value,
            'status_bucket' => 'completed',
            'history_event_count' => 2,
        ]);
        $this->assertDatabaseMissing('projection_command_workflow_run_summaries', [
            'id' => $staleRunId,
        ]);
        $this->assertDatabaseMissing('workflow_run_summaries', [
            'id' => $run->id,
        ]);
    }

    public function testNeedsRebuildOptionUsesConfiguredRunAndSummaryModels(): void
    {
        $this->createCustomProjectionTables();
        config()
            ->set('workflows.v2.run_model', ProjectionCommandWorkflowRun::class);
        config()
            ->set('workflows.v2.run_summary_model', ProjectionCommandWorkflowRunSummary::class);

        [$instance, $run] = $this->createCompletedRun(
            'projection-command-custom-needs-rebuild',
            ProjectionCommandWorkflowRun::class,
        );

        ProjectionCommandWorkflowRunSummary::query()->create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'App\\Workflows\\ProjectionWorkflow',
            'workflow_type' => 'projection.workflow',
            'status' => RunStatus::Waiting->value,
            'status_bucket' => 'running',
            'started_at' => now()
                ->subMinutes(5),
            'exception_count' => 0,
            'created_at' => now()
                ->subMinutes(5),
            'updated_at' => now()
                ->subMinutes(4),
        ]);

        $this->artisan('workflow:v2:rebuild-projections', [
            '--needs-rebuild' => true,
        ])
            ->expectsOutput('Rebuilt 1 run-summary projection row(s).')
            ->assertSuccessful();

        $this->assertDatabaseHas('projection_command_workflow_run_summaries', [
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'status' => RunStatus::Completed->value,
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
        ]);
        $this->assertDatabaseMissing('workflow_run_summaries', [
            'id' => $run->id,
        ]);
    }

    private function createCustomProjectionTables(): void
    {
        Schema::create('projection_command_workflow_runs', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_instance_id', 191)
                ->index();
            $table->unsignedInteger('run_number');
            $table->string('workflow_class');
            $table->string('workflow_type');
            $table->string('status');
            $table->string('closed_reason')
                ->nullable();
            $table->string('compatibility')
                ->nullable();
            $table->string('payload_codec')
                ->nullable();
            $table->longText('arguments')
                ->nullable();
            $table->longText('output')
                ->nullable();
            $table->string('connection')
                ->nullable();
            $table->string('queue')
                ->nullable();
            $table->unsignedInteger('last_history_sequence')
                ->default(0);
            $table->unsignedInteger('last_command_sequence')
                ->default(0);
            $table->timestamp('started_at', 6)
                ->nullable();
            $table->timestamp('closed_at', 6)
                ->nullable();
            $table->timestamp('archived_at', 6)
                ->nullable();
            $table->string('archive_command_id', 26)
                ->nullable();
            $table->string('archive_reason')
                ->nullable();
            $table->timestamp('last_progress_at', 6)
                ->nullable();
            $table->timestamps(6);
        });

        Schema::create('projection_command_workflow_run_summaries', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_instance_id', 191)
                ->index();
            $table->unsignedInteger('run_number');
            $table->boolean('is_current_run')
                ->default(false)
                ->index();
            $table->string('engine_source')
                ->default('v2');
            $table->unsignedSmallInteger('projection_schema_version')
                ->nullable()
                ->index();
            $table->string('class');
            $table->string('workflow_type');
            $table->string('namespace')
                ->nullable()
                ->index();
            $table->string('business_key')
                ->nullable()
                ->index();
            $table->json('visibility_labels')
                ->nullable();
            $table->json('search_attributes')
                ->nullable();
            $table->string('compatibility')
                ->nullable();
            $table->string('declared_entry_mode')
                ->nullable();
            $table->string('declared_contract_source')
                ->nullable();
            $table->string('status')
                ->index();
            $table->string('status_bucket')
                ->index();
            $table->string('closed_reason')
                ->nullable();
            $table->string('connection')
                ->nullable();
            $table->string('queue')
                ->nullable();
            $table->timestamp('started_at', 6)
                ->nullable();
            $table->timestamp('sort_timestamp', 6)
                ->nullable();
            $table->string('sort_key', 64)
                ->nullable();
            $table->timestamp('closed_at', 6)
                ->nullable();
            $table->timestamp('archived_at', 6)
                ->nullable();
            $table->string('archive_command_id', 26)
                ->nullable();
            $table->string('archive_reason')
                ->nullable();
            $table->bigInteger('duration_ms')
                ->nullable();
            $table->string('wait_kind')
                ->nullable();
            $table->text('wait_reason')
                ->nullable();
            $table->timestamp('wait_started_at', 6)
                ->nullable();
            $table->timestamp('wait_deadline_at', 6)
                ->nullable();
            $table->string('open_wait_id', 191)
                ->nullable();
            $table->string('resume_source_kind')
                ->nullable();
            $table->string('resume_source_id', 191)
                ->nullable();
            $table->timestamp('next_task_at', 6)
                ->nullable();
            $table->string('liveness_state')
                ->nullable();
            $table->text('liveness_reason')
                ->nullable();
            $table->string('repair_blocked_reason')
                ->nullable();
            $table->boolean('repair_attention')
                ->default(false);
            $table->boolean('task_problem')
                ->default(false);
            $table->string('next_task_id', 26)
                ->nullable();
            $table->string('next_task_type')
                ->nullable();
            $table->string('next_task_status')
                ->nullable();
            $table->timestamp('next_task_lease_expires_at', 6)
                ->nullable();
            $table->unsignedInteger('exception_count')
                ->default(0);
            $table->unsignedInteger('history_event_count')
                ->default(0);
            $table->unsignedBigInteger('history_size_bytes')
                ->default(0);
            $table->boolean('continue_as_new_recommended')
                ->default(false);
            $table->timestamps(6);
        });
    }

    /**
     * @param class-string<WorkflowRun> $runModel
     *
     * @return array{WorkflowInstance, WorkflowRun}
     */
    private function createCompletedRun(string $instanceId, string $runModel = WorkflowRun::class): array
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => 'App\\Workflows\\ProjectionWorkflow',
            'workflow_type' => 'projection.workflow',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinutes(5),
            'started_at' => now()
                ->subMinutes(5),
        ]);

        /** @var WorkflowRun $run */
        $run = $runModel::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\ProjectionWorkflow',
            'workflow_type' => 'projection.workflow',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
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

        WorkflowHistoryEvent::record(
            $run,
            HistoryEventType::WorkflowStarted,
            [
                'workflow_type' => 'projection.workflow',
            ],
        );
        WorkflowHistoryEvent::record(
            $run->refresh(),
            HistoryEventType::WorkflowCompleted,
            [
                'output' => [
                    'ok' => true,
                ],
            ],
        );

        return [$instance->refresh(), $run->refresh()];
    }

    /**
     * @return array{WorkflowInstance, WorkflowRun}
     */
    private function createWaitingRun(string $instanceId): array
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => 'App\\Workflows\\ProjectionWorkflow',
            'workflow_type' => 'projection.workflow',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinutes(5),
            'started_at' => now()
                ->subMinutes(5),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\ProjectionWorkflow',
            'workflow_type' => 'projection.workflow',
            'status' => RunStatus::Waiting->value,
            'started_at' => now()
                ->subMinutes(5),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return [$instance->refresh(), $run->refresh()];
    }
}

final class ProjectionCommandWorkflowRun extends WorkflowRun
{
    protected $table = 'projection_command_workflow_runs';
}

final class ProjectionCommandWorkflowRunSummary extends WorkflowRunSummary
{
    protected $table = 'projection_command_workflow_run_summaries';
}
