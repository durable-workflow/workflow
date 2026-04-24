<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowRunTimerEntry;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Support\HealthCheck;
use Workflow\V2\Support\RunSummaryProjector;

final class HealthCheckTest extends TestCase
{
    public function testSnapshotFailsReadinessWhenBackendCapabilitiesAreUnsupported(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        config()
            ->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');

        $snapshot = HealthCheck::snapshot();

        $this->assertSame('2026-04-09T12:00:00.000000Z', $snapshot['generated_at']);
        $this->assertSame('error', $snapshot['status']);
        $this->assertFalse($snapshot['healthy']);
        $this->assertSame(503, HealthCheck::httpStatus($snapshot));
        $this->assertSame('backend_capabilities', $snapshot['checks'][0]['name']);
        $this->assertSame('error', $snapshot['checks'][0]['status']);
        $this->assertContains('queue_sync_unsupported', array_column(
            $snapshot['checks'][0]['data']['issues'],
            'code',
        ));
    }

    public function testSnapshotWarnsWhenRunSummaryProjectionNeedsRebuild(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('cache.default', 'array');
        config()
            ->set('cache.stores.array.driver', 'array');

        $instance = WorkflowInstance::query()->create([
            'id' => 'health-missing-summary',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'id' => 'health-missing-summary-run',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'running',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSecond(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        $snapshot = HealthCheck::snapshot();
        $projection = collect($snapshot['checks'])->firstWhere('name', 'run_summary_projection');

        $this->assertSame('warning', $snapshot['status']);
        $this->assertTrue($snapshot['healthy']);
        $this->assertSame(200, HealthCheck::httpStatus($snapshot));
        $this->assertSame('warning', $projection['status']);
        $this->assertSame(1, $projection['data']['needs_rebuild']);
        $this->assertSame(1, $projection['data']['missing']);
        $this->assertSame(0, $projection['data']['orphaned']);
        $this->assertSame(0, $projection['data']['stale']);
    }

    public function testSnapshotWarnsWhenRunSummaryProjectionIsStale(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('cache.default', 'array');
        config()
            ->set('cache.stores.array.driver', 'array');

        $instance = WorkflowInstance::query()->create([
            'id' => 'health-stale-summary',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'id' => 'health-stale-run-000001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'failed',
            'closed_reason' => 'failed',
            'started_at' => now()
                ->subMinute(),
            'closed_at' => now()
                ->subSecond(),
            'last_progress_at' => now()
                ->subSecond(),
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
            'projection_schema_version' => RunSummaryProjector::SCHEMA_VERSION,
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'started_at' => now()
                ->subMinute(),
            'liveness_state' => 'waiting_for_signal',
            'created_at' => now()
                ->subMinute(),
            'updated_at' => now(),
        ]);

        $snapshot = HealthCheck::snapshot();
        $projection = collect($snapshot['checks'])->firstWhere('name', 'run_summary_projection');

        $this->assertSame('warning', $snapshot['status']);
        $this->assertSame('warning', $projection['status']);
        $this->assertSame(1, $projection['data']['needs_rebuild']);
        $this->assertSame(0, $projection['data']['missing']);
        $this->assertSame(0, $projection['data']['orphaned']);
        $this->assertSame(1, $projection['data']['stale']);
    }

    public function testSnapshotWarnsWhenSelectedRunProjectionsNeedRebuild(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('cache.default', 'array');
        config()
            ->set('cache.stores.array.driver', 'array');

        $instance = WorkflowInstance::query()->create([
            'id' => 'health-selected-projection',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => '01JHEALTHSELECTPROJ00001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSecond(),
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
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'started_at' => now()
                ->subMinute(),
            'open_wait_id' => 'signal:missing',
            'liveness_state' => 'waiting_for_signal',
            'created_at' => now()
                ->subMinute(),
            'updated_at' => now(),
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_run_id' => $run->id,
        ]);
        WorkflowRunWait::query()->create([
            'id' => 'health-selected-wait-orphan',
            'workflow_run_id' => '01JHEALTHSELECTMISS001',
            'workflow_instance_id' => 'health-selected-missing-instance',
            'wait_id' => 'signal:orphan',
            'position' => 0,
            'kind' => 'signal',
            'status' => 'open',
            'source_status' => 'open',
            'task_backed' => false,
            'external_only' => true,
        ]);
        WorkflowTimelineEntry::query()->create([
            'id' => 'health-selected-timeline-orphan',
            'workflow_run_id' => '01JHEALTHSELECTMISS001',
            'workflow_instance_id' => 'health-selected-missing-instance',
            'history_event_id' => '01JHEALTHSELECTEVENT01',
            'sequence' => 1,
            'type' => 'WorkflowStarted',
            'kind' => 'workflow',
            'entry_kind' => 'point',
            'summary' => 'Orphaned timeline row.',
            'recorded_at' => now(),
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::TimerScheduled, [
            'timer_id' => 'health-selected-timer',
            'sequence' => 3,
            'delay_seconds' => 60,
            'fire_at' => now()
                ->addMinute()
                ->toJSON(),
        ]);
        WorkflowRunTimerEntry::query()->create([
            'id' => 'health-selected-timer-orphan',
            'workflow_run_id' => '01JHEALTHSELECTMISS001',
            'workflow_instance_id' => 'health-selected-missing-instance',
            'timer_id' => 'health-selected-timer-orphan',
            'schema_version' => WorkflowRunTimerEntry::CURRENT_SCHEMA_VERSION - 1,
            'position' => 0,
            'status' => 'pending',
            'source_status' => 'pending',
            'history_authority' => 'typed_history',
            'payload' => [
                'id' => 'health-selected-timer-orphan',
                'status' => 'pending',
                'source_status' => 'pending',
                'history_authority' => 'typed_history',
                'history_event_types' => [],
            ],
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowContinuedAsNew, [
            'sequence' => 2,
            'workflow_link_id' => 'health-lineage-missing',
            'continued_to_run_id' => 'health-lineage-current',
        ]);
        WorkflowRunLineageEntry::query()->create([
            'id' => 'health-selected-lineage-orphan',
            'workflow_run_id' => '01JHEALTHSELECTMISS001',
            'workflow_instance_id' => 'health-selected-missing-instance',
            'direction' => 'child',
            'lineage_id' => 'health-lineage-orphan',
            'position' => 0,
            'link_type' => 'continue_as_new',
            'related_workflow_instance_id' => 'health-selected-missing-instance',
            'related_workflow_run_id' => 'health-lineage-current',
            'payload' => [],
        ]);

        $snapshot = HealthCheck::snapshot();
        $projection = collect($snapshot['checks'])->firstWhere('name', 'selected_run_projections');

        $this->assertSame('warning', $snapshot['status']);
        $this->assertSame('warning', $projection['status']);
        $this->assertSame(8, $projection['data']['needs_rebuild']);
        $this->assertSame(2, $projection['data']['run_waits_needs_rebuild']);
        $this->assertSame(1, $projection['data']['run_waits_missing_runs_with_waits']);
        $this->assertSame(1, $projection['data']['run_waits_missing_current_open_waits']);
        $this->assertSame(0, $projection['data']['run_waits_stale_projected_runs']);
        $this->assertSame(1, $projection['data']['run_waits_orphaned']);
        $this->assertSame(2, $projection['data']['timeline_needs_rebuild']);
        $this->assertSame(1, $projection['data']['timeline_missing_runs_with_history']);
        $this->assertSame(3, $projection['data']['timeline_missing_history_events']);
        $this->assertSame(0, $projection['data']['timeline_stale_projected_runs']);
        $this->assertSame(1, $projection['data']['timeline_orphaned']);
        $this->assertSame(2, $projection['data']['timer_needs_rebuild']);
        $this->assertSame(1, $projection['data']['timer_missing_runs_with_timers']);
        $this->assertSame(0, $projection['data']['timer_stale_projected_runs']);
        $this->assertSame(0, $projection['data']['timer_schema_version_mismatch_runs']);
        $this->assertSame(1, $projection['data']['timer_schema_version_mismatch_rows']);
        $this->assertSame(1, $projection['data']['timer_orphaned']);
        $this->assertSame(2, $projection['data']['lineage_needs_rebuild']);
        $this->assertSame(1, $projection['data']['lineage_missing_runs_with_lineage']);
        $this->assertSame(0, $projection['data']['lineage_stale_projected_runs']);
        $this->assertSame(1, $projection['data']['lineage_orphaned']);
    }

    public function testSnapshotWarnsWhenHistoryEventsOutliveTheirRun(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('cache.default', 'array');
        config()
            ->set('cache.stores.array.driver', 'array');

        WorkflowHistoryEvent::query()->create([
            'id' => '01JHEALTHHISTORYORPHAN001',
            'workflow_run_id' => '01JHEALTHHISTORYGONE0001',
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowStarted->value,
            'payload' => [
                'workflow_run_id' => '01JHEALTHHISTORYGONE0001',
            ],
            'recorded_at' => now(),
        ]);

        $snapshot = HealthCheck::snapshot();
        $history = collect($snapshot['checks'])->firstWhere('name', 'history_retention_invariant');

        $this->assertSame('warning', $snapshot['status']);
        $this->assertSame('warning', $history['status']);
        $this->assertSame(1, $history['data']['history_orphan_total']);
        $this->assertSame(1, $history['data']['events']);
    }

    public function testSnapshotWarnsWhenSelectedRunProjectionPayloadsAreStale(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('cache.default', 'array');
        config()
            ->set('cache.stores.array.driver', 'array');

        $waitInstance = WorkflowInstance::query()->create([
            'id' => 'health-stale-wait-instance',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        /** @var WorkflowRun $waitRun */
        $waitRun = WorkflowRun::query()->create([
            'id' => '01JHEALTHSTALEWAITRUN0001',
            'workflow_instance_id' => $waitInstance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSecond(),
        ]);

        $waitInstance->forceFill([
            'current_run_id' => $waitRun->id,
        ])->save();

        ActivityExecution::query()->create([
            'id' => 'health-stale-activity-0001',
            'workflow_run_id' => $waitRun->id,
            'sequence' => 1,
            'activity_class' => 'HealthWaitActivity',
            'activity_type' => 'health.wait',
            'status' => 'pending',
            'arguments' => serialize([]),
            'attempt_count' => 0,
            'created_at' => now()
                ->subMinute(),
            'updated_at' => now(),
        ]);

        RunSummaryProjector::project($waitRun->refresh());

        WorkflowRunWait::query()
            ->where('workflow_run_id', $waitRun->id)
            ->update([
                'summary' => 'Stale wait row.',
            ]);

        $lineageInstance = WorkflowInstance::query()->create([
            'id' => 'health-stale-lineage-instance',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        /** @var WorkflowRun $lineageRun */
        $lineageRun = WorkflowRun::query()->create([
            'id' => '01JHEALTHSTALELINEAGERUN1',
            'workflow_instance_id' => $lineageInstance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'started_at' => now()
                ->subMinute(),
            'closed_at' => now()
                ->subSecond(),
            'last_progress_at' => now()
                ->subSecond(),
        ]);

        $lineageInstance->forceFill([
            'current_run_id' => $lineageRun->id,
        ])->save();

        WorkflowHistoryEvent::record($lineageRun, HistoryEventType::WorkflowStarted, [
            'workflow_run_id' => $lineageRun->id,
        ]);
        WorkflowHistoryEvent::record(
            $lineageRun->refresh(),
            HistoryEventType::WorkflowContinuedAsNew,
            [
                'sequence' => 2,
                'workflow_link_id' => 'health-stale-lineage-link',
                'continued_to_run_id' => 'health-stale-child-run',
            ],
        );

        RunSummaryProjector::project($lineageRun->refresh());

        WorkflowTimelineEntry::query()
            ->where('workflow_run_id', $lineageRun->id)
            ->where('type', HistoryEventType::WorkflowStarted->value)
            ->update([
                'summary' => 'Stale timeline row.',
            ]);

        WorkflowRunLineageEntry::query()
            ->where('workflow_run_id', $lineageRun->id)
            ->update([
                'related_workflow_run_id' => 'health-stale-child-run-alt',
            ]);

        $snapshot = HealthCheck::snapshot();
        $projection = collect($snapshot['checks'])->firstWhere('name', 'selected_run_projections');

        $this->assertSame('warning', $snapshot['status']);
        $this->assertSame('warning', $projection['status']);
        $this->assertSame(3, $projection['data']['needs_rebuild']);
        $this->assertSame(1, $projection['data']['run_waits_needs_rebuild']);
        $this->assertSame(0, $projection['data']['run_waits_missing_runs_with_waits']);
        $this->assertSame(0, $projection['data']['run_waits_missing_current_open_waits']);
        $this->assertSame(1, $projection['data']['run_waits_stale_projected_runs']);
        $this->assertSame(0, $projection['data']['run_waits_orphaned']);
        $this->assertSame(1, $projection['data']['timeline_needs_rebuild']);
        $this->assertSame(0, $projection['data']['timeline_missing_runs_with_history']);
        $this->assertSame(0, $projection['data']['timeline_missing_history_events']);
        $this->assertSame(1, $projection['data']['timeline_stale_projected_runs']);
        $this->assertSame(0, $projection['data']['timeline_orphaned']);
        $this->assertSame(1, $projection['data']['lineage_needs_rebuild']);
        $this->assertSame(0, $projection['data']['lineage_missing_runs_with_lineage']);
        $this->assertSame(1, $projection['data']['lineage_stale_projected_runs']);
        $this->assertSame(0, $projection['data']['lineage_orphaned']);
    }

    public function testSnapshotWarnsWhenOpenRunHasNoDurableResumePath(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('cache.default', 'array');
        config()
            ->set('cache.stores.array.driver', 'array');

        $instance = WorkflowInstance::query()->create([
            'id' => 'health-repair-needed-instance',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'id' => '01JHEALTHREPAIRRUN00000000',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'started_at' => now()
                ->subMinutes(10),
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
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'started_at' => now()
                ->subMinutes(10),
            'wait_started_at' => now()
                ->subMinutes(5),
            'liveness_state' => 'repair_needed',
            'liveness_reason' => 'Run is non-terminal but has no durable next-resume source.',
            'created_at' => now()
                ->subMinutes(10),
            'updated_at' => now(),
        ]);

        $snapshot = HealthCheck::snapshot();
        $taskTransport = collect($snapshot['checks'])->firstWhere('name', 'task_transport');
        $resumePaths = collect($snapshot['checks'])->firstWhere('name', 'durable_resume_paths');

        $this->assertSame('warning', $snapshot['status']);
        $this->assertTrue($snapshot['healthy']);
        $this->assertSame(200, HealthCheck::httpStatus($snapshot));
        $this->assertSame('ok', $taskTransport['status']);
        $this->assertSame(0, $taskTransport['data']['unhealthy_tasks']);
        $this->assertSame(1, $taskTransport['data']['repair_needed_runs']);
        $this->assertSame('warning', $resumePaths['status']);
        $this->assertSame(1, $resumePaths['data']['repair_needed_runs']);
        $this->assertSame(1, $resumePaths['data']['missing_task_candidates']);
        $this->assertSame(1, $resumePaths['data']['selected_missing_task_candidates']);
        $this->assertSame('2026-04-09T11:55:00.000000Z', $resumePaths['data']['oldest_missing_run_started_at']);
        $this->assertSame(300000, $resumePaths['data']['max_missing_run_age_ms']);
    }

    public function testSnapshotWarnsWhenRunSummaryProjectionSchemaIsOutdated(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('cache.default', 'array');
        config()
            ->set('cache.stores.array.driver', 'array');

        $instance = WorkflowInstance::query()->create([
            'id' => 'health-schema-outdated',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'id' => '01JHEALTHSCHEMAOUTDATED01',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'started_at' => now()
                ->subMinute(),
            'closed_at' => now()
                ->subSecond(),
            'last_progress_at' => now()
                ->subSecond(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        RunSummaryProjector::project($run->refresh());

        WorkflowRunSummary::query()
            ->where('id', $run->id)
            ->update([
                'projection_schema_version' => null,
            ]);

        $snapshot = HealthCheck::snapshot();
        $projection = collect($snapshot['checks'])->firstWhere('name', 'run_summary_projection');

        $this->assertSame('warning', $snapshot['status']);
        $this->assertTrue($snapshot['healthy']);
        $this->assertSame(200, HealthCheck::httpStatus($snapshot));
        $this->assertSame('warning', $projection['status']);
        $this->assertSame(1, $projection['data']['needs_rebuild']);
        $this->assertSame(0, $projection['data']['missing']);
        $this->assertSame(0, $projection['data']['orphaned']);
        $this->assertSame(0, $projection['data']['stale']);
        $this->assertSame(1, $projection['data']['schema_outdated']);
        $this->assertSame(RunSummaryProjector::SCHEMA_VERSION, $projection['data']['projection_schema_version']);
    }

    public function testSnapshotClassifiesEveryCheckAsCorrectnessOrAcceleration(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('cache.default', 'array');
        config()
            ->set('cache.stores.array.driver', 'array');

        $snapshot = HealthCheck::snapshot();

        $this->assertNotEmpty($snapshot['checks']);
        $allowed = [HealthCheck::CATEGORY_CORRECTNESS, HealthCheck::CATEGORY_ACCELERATION];

        foreach ($snapshot['checks'] as $check) {
            $this->assertArrayHasKey('category', $check, sprintf(
                'HealthCheck entry %s must expose a category field so operators can separate correctness from acceleration.',
                $check['name'] ?? 'unknown',
            ));
            $this->assertContains($check['category'], $allowed, sprintf(
                'HealthCheck entry %s has invalid category %s; must be correctness or acceleration.',
                $check['name'] ?? 'unknown',
                (string) ($check['category'] ?? ''),
            ));
        }

        $this->assertArrayHasKey('categories', $snapshot);
        $this->assertArrayHasKey('correctness', $snapshot['categories']);
        $this->assertArrayHasKey('acceleration', $snapshot['categories']);

        $correctnessChecks = collect($snapshot['checks'])
            ->where('category', HealthCheck::CATEGORY_CORRECTNESS)
            ->count();
        $accelerationChecks = collect($snapshot['checks'])
            ->where('category', HealthCheck::CATEGORY_ACCELERATION)
            ->count();

        $this->assertSame($correctnessChecks, $snapshot['categories']['correctness']['check_count']);
        $this->assertSame($accelerationChecks, $snapshot['categories']['acceleration']['check_count']);
        $this->assertGreaterThan(0, $correctnessChecks);
        $this->assertGreaterThan(0, $accelerationChecks);

        $wake = collect($snapshot['checks'])->firstWhere('name', 'long_poll_wake_acceleration');
        $this->assertNotNull($wake, 'HealthCheck must expose a long_poll_wake_acceleration check.');
        $this->assertSame(HealthCheck::CATEGORY_ACCELERATION, $wake['category']);
    }

    public function testLongPollWakeAccelerationCheckNeverEscalatesAboveWarning(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('cache.default', 'file');
        config()
            ->set('cache.stores.file.driver', 'file');
        config()
            ->set('workflows.v2.long_poll.multi_node', true);

        $snapshot = HealthCheck::snapshot();
        $wake = collect($snapshot['checks'])->firstWhere('name', 'long_poll_wake_acceleration');

        $this->assertNotNull($wake);
        $this->assertContains(
            $wake['status'],
            ['ok', 'warning'],
            'Acceleration-layer check must never escalate to error; correctness remains independent of the acceleration layer.',
        );
        $this->assertSame('warning', $wake['status']);
        $this->assertFalse($wake['data']['safe']);
        $this->assertNotNull($wake['data']['reason']);
    }

    public function testLongPollWakeAccelerationReportsOkWhenBackendIsCapable(): void
    {
        config()->set('cache.default', 'array');
        config()
            ->set('cache.stores.array.driver', 'array');
        config()
            ->set('workflows.v2.long_poll.multi_node', false);

        $snapshot = HealthCheck::snapshot();
        $wake = collect($snapshot['checks'])->firstWhere('name', 'long_poll_wake_acceleration');

        $this->assertNotNull($wake);
        $this->assertSame('ok', $wake['status']);
        $this->assertSame(HealthCheck::CATEGORY_ACCELERATION, $wake['category']);
        $this->assertArrayHasKey('backend', $wake['data']);
    }
}
