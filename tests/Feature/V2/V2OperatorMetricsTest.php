<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Tests\Fixtures\V2\TestCommandTargetWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunTimerEntry;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Support\OperatorMetrics;
use Workflow\V2\Support\TaskRepairCandidates;
use Workflow\V2\Support\TaskRepairPolicy;
use Workflow\V2\Support\WorkerCompatibilityFleet;

final class V2OperatorMetricsTest extends TestCase
{
    public function testSnapshotSummarizesDurableBacklogRepairCompatibilityAndWorkerFleet(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
            WorkerCompatibilityFleet::clear();
        });

        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
        config()
            ->set('workflows.v2.compatibility.namespace', 'metrics-test');
        config()
            ->set('workflows.v2.history_budget.continue_as_new_event_threshold', 5);
        config()
            ->set('workflows.v2.history_budget.continue_as_new_size_bytes_threshold', 5000);
        config()
            ->set('workflows.v2.update_wait.completion_timeout_seconds', 9);
        config()
            ->set('workflows.v2.update_wait.poll_interval_milliseconds', 25);
        config()
            ->set('workflows.v2.task_repair.redispatch_after_seconds', 7);
        config()
            ->set('workflows.v2.task_repair.loop_throttle_seconds', 11);
        config()
            ->set('workflows.v2.task_repair.scan_limit', 13);
        config()
            ->set('workflows.v2.task_repair.failure_backoff_max_seconds', 31);
        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('cache.default', 'array');
        config()
            ->set('cache.stores.array.driver', 'array');
        WorkerCompatibilityFleet::clear();

        $run = $this->createRunWithSummary(
            instanceId: 'metrics-instance-a',
            runId: '01JMETRICSFLOWRUN000000001',
            status: 'pending',
            statusBucket: 'running',
            livenessState: 'repair_needed',
            historyEventCount: 7,
            historySizeBytes: 4096,
            continueAsNewRecommended: true,
        );
        $this->createStartCommand($run, now()->subSeconds(30));
        $this->createRunWithSummary(
            instanceId: 'metrics-instance-b',
            runId: '01JMETRICSFLOWRUN000000002',
            status: 'waiting',
            statusBucket: 'running',
            livenessState: 'workflow_task_waiting_for_compatible_worker',
        );
        $this->createRunWithSummary(
            instanceId: 'metrics-instance-c',
            runId: '01JMETRICSFLOWRUN000000003',
            status: 'completed',
            statusBucket: 'completed',
            livenessState: 'closed',
        );
        $claimFailedRun = $this->createRunWithSummary(
            instanceId: 'metrics-instance-d',
            runId: '01JMETRICSFLOWRUN000000004',
            status: 'waiting',
            statusBucket: 'running',
            livenessState: 'workflow_task_claim_failed',
        );

        $this->createTask($run, '01JMETRICSTASK000000000001', TaskStatus::Ready->value, [
            'available_at' => now()
                ->subSecond(),
            'created_at' => now(),
        ]);
        $this->createTask($run, '01JMETRICSTASK000000000002', TaskStatus::Ready->value, [
            'available_at' => now()
                ->addMinute(),
        ]);
        $this->createTask($run, '01JMETRICSTASK000000000003', TaskStatus::Leased->value, [
            'leased_at' => now(),
            'lease_expires_at' => now()
                ->addMinute(),
        ]);
        $this->createTask($run, '01JMETRICSTASK000000000004', TaskStatus::Leased->value, [
            'leased_at' => now()
                ->subMinutes(2),
            'lease_expires_at' => now()
                ->subMinute(),
            'created_at' => now()
                ->subMinutes(2),
        ]);
        $this->createTask($run, '01JMETRICSTASK000000000005', TaskStatus::Ready->value, [
            'available_at' => now()
                ->subSeconds(10),
            'last_dispatch_attempt_at' => now()
                ->subSecond(),
            'last_dispatch_error' => 'Queue transport unavailable.',
        ]);
        $this->createTask($run, '01JMETRICSTASK000000000006', TaskStatus::Ready->value, [
            'available_at' => now()
                ->subSeconds(10),
            'last_dispatched_at' => now()
                ->subSeconds(10),
        ]);
        $claimFailedTask = $this->createTask($claimFailedRun, '01JMETRICSTASK000000000007', TaskStatus::Ready->value, [
            'available_at' => now()
                ->subSecond(),
            'connection' => 'sync',
            'last_dispatched_at' => now()
                ->subSeconds(10),
            'last_claim_failed_at' => now()
                ->subSeconds(10),
            'last_claim_error' => 'Workflow v2 backend capabilities are unsupported: [queue_sync_unsupported] sync.',
        ]);

        WorkerCompatibilityFleet::record(['build-a'], 'redis', 'default', 'worker-a');
        WorkerCompatibilityFleet::record(['build-b'], 'redis', 'imports', 'worker-b');

        $snapshot = OperatorMetrics::snapshot();

        $this->assertSame('2026-04-09T12:00:00.000000Z', $snapshot['generated_at']);
        $this->assertSame(4, $snapshot['runs']['total']);
        $this->assertSame(3, $snapshot['runs']['running']);
        $this->assertSame(1, $snapshot['runs']['completed']);
        $this->assertSame(1, $snapshot['runs']['repair_needed']);
        $this->assertSame(1, $snapshot['runs']['claim_failed']);
        $this->assertSame(1, $snapshot['runs']['compatibility_blocked']);
        $this->assertSame(7, $snapshot['tasks']['open']);
        $this->assertSame(5, $snapshot['tasks']['ready']);
        $this->assertSame(4, $snapshot['tasks']['ready_due']);
        $this->assertSame(1, $snapshot['tasks']['delayed']);
        $this->assertSame(2, $snapshot['tasks']['leased']);
        $this->assertSame(1, $snapshot['tasks']['dispatch_failed']);
        $this->assertSame(1, $snapshot['tasks']['claim_failed']);
        $this->assertSame(1, $snapshot['tasks']['dispatch_overdue']);
        $this->assertSame(1, $snapshot['tasks']['lease_expired']);
        $this->assertSame(4, $snapshot['tasks']['unhealthy']);
        $this->assertSame(4, $snapshot['backlog']['runnable_tasks']);
        $this->assertSame(1, $snapshot['backlog']['delayed_tasks']);
        $this->assertSame(2, $snapshot['backlog']['leased_tasks']);
        $this->assertSame(4, $snapshot['backlog']['unhealthy_tasks']);
        $this->assertSame(1, $snapshot['backlog']['repair_needed_runs']);
        $this->assertSame(1, $snapshot['backlog']['claim_failed_runs']);
        $this->assertSame(1, $snapshot['backlog']['compatibility_blocked_runs']);
        $this->assertSame(4, $snapshot['repair']['existing_task_candidates']);
        $this->assertSame(1, $snapshot['repair']['missing_task_candidates']);
        $this->assertSame(5, $snapshot['repair']['total_candidates']);
        $this->assertSame(13, $snapshot['repair']['scan_limit']);
        $this->assertSame('scope_fair_round_robin', $snapshot['repair']['scan_strategy']);
        $this->assertSame(4, $snapshot['repair']['selected_existing_task_candidates']);
        $this->assertSame(1, $snapshot['repair']['selected_missing_task_candidates']);
        $this->assertSame(5, $snapshot['repair']['selected_total_candidates']);
        $this->assertFalse($snapshot['repair']['existing_task_scan_limit_reached']);
        $this->assertFalse($snapshot['repair']['missing_task_scan_limit_reached']);
        $this->assertFalse($snapshot['repair']['scan_pressure']);
        $this->assertSame('2026-04-09T11:58:00.000000Z', $snapshot['repair']['oldest_task_candidate_created_at']);
        $this->assertSame('2026-04-09T11:50:00.000000Z', $snapshot['repair']['oldest_missing_run_started_at']);
        $this->assertSame(120000, $snapshot['repair']['max_task_candidate_age_ms']);
        $this->assertSame(600000, $snapshot['repair']['max_missing_run_age_ms']);
        $repairScopes = collect($snapshot['repair']['scopes'])->keyBy('scope_key');
        $this->assertGreaterThanOrEqual(2, $repairScopes->count());
        $this->assertSame(5, $repairScopes->sum('total_candidates'));
        $this->assertSame(4, $repairScopes->sum('existing_task_candidates'));
        $this->assertSame(1, $repairScopes->sum('missing_task_candidates'));
        $this->assertSame(3, $repairScopes->get('redis:default:any')['existing_task_candidates']);
        $this->assertSame(3, $repairScopes->get('redis:default:any')['selected_existing_task_candidates']);
        $this->assertSame(120000, $repairScopes->get('redis:default:any')['max_task_candidate_age_ms']);
        $this->assertSame(1, $repairScopes->get('sync:default:any')['existing_task_candidates']);
        $this->assertSame(1, $repairScopes->get('sync:default:any')['selected_existing_task_candidates']);
        $this->assertTrue($repairScopes->contains(
            static fn (array $scope): bool => $scope['missing_task_candidates'] === 1
                && $scope['selected_missing_task_candidates'] === 1
                && $scope['max_missing_run_age_ms'] === 600000
        ));
        $this->assertFalse($repairScopes->get('redis:default:any')['scan_limited_by_global_policy']);
        $this->assertSame(1, $snapshot['starts']['pending_runs']);
        $this->assertSame(1, $snapshot['starts']['pending_commands']);
        $this->assertSame(3, $snapshot['starts']['ready_tasks']);
        $this->assertSame('2026-04-09T11:59:30.000000Z', $snapshot['starts']['oldest_pending_start_at']);
        $this->assertSame(30000, $snapshot['starts']['max_pending_ms']);
        $this->assertSame(1, $snapshot['history']['continue_as_new_recommended_runs']);
        $this->assertSame(7, $snapshot['history']['max_event_count']);
        $this->assertSame(4096, $snapshot['history']['max_size_bytes']);
        $this->assertSame(5, $snapshot['history']['event_threshold']);
        $this->assertSame(5000, $snapshot['history']['size_bytes_threshold']);
        $this->assertSame(4, $snapshot['projections']['run_summaries']['runs']);
        $this->assertSame(4, $snapshot['projections']['run_summaries']['summaries']);
        $this->assertSame(0, $snapshot['projections']['run_summaries']['missing']);
        $this->assertSame(0, $snapshot['projections']['run_summaries']['orphaned']);
        $this->assertSame(0, $snapshot['projections']['run_summaries']['stale']);
        $this->assertSame(0, $snapshot['projections']['run_summaries']['needs_rebuild']);
        $this->assertSame('metrics-test', $snapshot['workers']['compatibility_namespace']);
        $this->assertSame('build-a', $snapshot['workers']['required_compatibility']);
        $this->assertSame(2, $snapshot['workers']['active_workers']);
        $this->assertSame(2, $snapshot['workers']['active_worker_scopes']);
        $this->assertSame(1, $snapshot['workers']['active_workers_supporting_required']);
        $this->assertTrue($snapshot['backend']['supported']);
        $this->assertSame('redis', $snapshot['backend']['queue']['connection']);
        $this->assertSame('redis', $snapshot['backend']['queue']['driver']);
        $this->assertSame('array', $snapshot['backend']['cache']['store']);
        $this->assertSame([], $snapshot['backend']['issues']);
        $this->assertSame(9, $snapshot['update_wait']['completion_timeout_seconds']);
        $this->assertSame(25, $snapshot['update_wait']['poll_interval_milliseconds']);
        $this->assertSame(7, $snapshot['repair_policy']['redispatch_after_seconds']);
        $this->assertSame(11, $snapshot['repair_policy']['loop_throttle_seconds']);
        $this->assertSame(13, $snapshot['repair_policy']['scan_limit']);
        $this->assertSame('scope_fair_round_robin', $snapshot['repair_policy']['scan_strategy']);
        $this->assertSame(31, $snapshot['repair_policy']['failure_backoff_max_seconds']);
        $this->assertSame('exponential_by_repair_count', $snapshot['repair_policy']['failure_backoff_strategy']);
        $this->assertFalse(TaskRepairPolicy::dispatchOverdue($claimFailedTask->fresh()));
        $this->assertTrue(TaskRepairPolicy::readyTaskNeedsRedispatch($claimFailedTask->fresh()));
    }

    public function testSnapshotCountsStaleRunSummaryProjectionRows(): void
    {
        $run = $this->createRunWithSummary(
            instanceId: 'metrics-stale-instance',
            runId: '01JMETRICSSTALERUN000001',
            status: 'waiting',
            statusBucket: 'running',
            livenessState: 'waiting_for_signal',
        );

        $run->forceFill([
            'status' => 'failed',
            'closed_reason' => 'failed',
            'closed_at' => now(),
            'last_progress_at' => now(),
        ])->save();

        $snapshot = OperatorMetrics::snapshot();

        $this->assertSame(1, $snapshot['projections']['run_summaries']['runs']);
        $this->assertSame(1, $snapshot['projections']['run_summaries']['summaries']);
        $this->assertSame(0, $snapshot['projections']['run_summaries']['missing']);
        $this->assertSame(0, $snapshot['projections']['run_summaries']['orphaned']);
        $this->assertSame(1, $snapshot['projections']['run_summaries']['stale']);
        $this->assertSame(1, $snapshot['projections']['run_summaries']['needs_rebuild']);
    }

    public function testSnapshotCountsSelectedRunProjectionDrift(): void
    {
        $missingWaitRun = $this->createRunWithSummary(
            instanceId: 'metrics-wait-missing-instance',
            runId: '01JMETRICSPROJWAITMISS01',
            status: 'waiting',
            statusBucket: 'running',
            livenessState: 'waiting_for_signal',
        );
        $projectedWaitRun = $this->createRunWithSummary(
            instanceId: 'metrics-wait-projected-instance',
            runId: '01JMETRICSPROJWAITDONE01',
            status: 'waiting',
            statusBucket: 'running',
            livenessState: 'waiting_for_signal',
        );

        WorkflowRunSummary::query()
            ->whereKey($missingWaitRun->id)
            ->update(['open_wait_id' => 'signal:missing']);
        WorkflowRunSummary::query()
            ->whereKey($projectedWaitRun->id)
            ->update(['open_wait_id' => 'signal:projected']);

        WorkflowRunWait::query()->create([
            'id' => 'projection-wait-valid',
            'workflow_run_id' => $projectedWaitRun->id,
            'workflow_instance_id' => $projectedWaitRun->workflow_instance_id,
            'wait_id' => 'signal:projected',
            'position' => 0,
            'kind' => 'signal',
            'status' => 'open',
            'source_status' => 'open',
            'task_backed' => false,
            'external_only' => true,
        ]);
        WorkflowRunWait::query()->create([
            'id' => 'projection-wait-orphan',
            'workflow_run_id' => '01JMETRICSPROJWAITGONE01',
            'workflow_instance_id' => 'metrics-wait-orphan-instance',
            'wait_id' => 'signal:orphan',
            'position' => 0,
            'kind' => 'signal',
            'status' => 'open',
            'source_status' => 'open',
            'task_backed' => false,
            'external_only' => true,
        ]);

        WorkflowHistoryEvent::record(
            $missingWaitRun,
            HistoryEventType::WorkflowStarted,
            ['workflow_run_id' => $missingWaitRun->id],
        );
        $projectedTimelineEvent = WorkflowHistoryEvent::record(
            $projectedWaitRun,
            HistoryEventType::WorkflowStarted,
            ['workflow_run_id' => $projectedWaitRun->id],
        );

        WorkflowTimelineEntry::query()->create([
            'id' => 'projection-timeline-valid',
            'workflow_run_id' => $projectedWaitRun->id,
            'workflow_instance_id' => $projectedWaitRun->workflow_instance_id,
            'history_event_id' => $projectedTimelineEvent->id,
            'sequence' => $projectedTimelineEvent->sequence,
            'type' => $projectedTimelineEvent->event_type->value,
            'kind' => 'workflow',
            'entry_kind' => 'point',
            'summary' => 'Workflow run started.',
            'recorded_at' => $projectedTimelineEvent->recorded_at,
        ]);
        WorkflowTimelineEntry::query()->create([
            'id' => 'projection-timeline-orphan',
            'workflow_run_id' => '01JMETRICSPROJTIMELINE01',
            'workflow_instance_id' => 'metrics-timeline-orphan-instance',
            'history_event_id' => '01JMETRICSPROJEVENTMISS1',
            'sequence' => 1,
            'type' => 'WorkflowStarted',
            'kind' => 'workflow',
            'entry_kind' => 'point',
            'summary' => 'Orphaned timeline row.',
            'recorded_at' => now(),
        ]);
        WorkflowHistoryEvent::record(
            $missingWaitRun,
            HistoryEventType::TimerScheduled,
            [
                'timer_id' => 'projection-timer-missing',
                'sequence' => 11,
                'delay_seconds' => 60,
                'fire_at' => now()->addMinute()->toJSON(),
            ],
        );
        WorkflowHistoryEvent::record(
            $projectedWaitRun,
            HistoryEventType::TimerScheduled,
            [
                'timer_id' => 'projection-timer-projected',
                'sequence' => 12,
                'delay_seconds' => 90,
                'fire_at' => now()->addSeconds(90)->toJSON(),
            ],
        );
        WorkflowRunTimerEntry::query()->create([
            'id' => 'projection-timer-valid-row',
            'workflow_run_id' => $projectedWaitRun->id,
            'workflow_instance_id' => $projectedWaitRun->workflow_instance_id,
            'timer_id' => 'projection-timer-projected',
            'position' => 0,
            'sequence' => 12,
            'status' => 'fired',
            'source_status' => 'fired',
            'delay_seconds' => 90,
            'fire_at' => now()->addSeconds(90),
            'fired_at' => now(),
            'history_authority' => 'typed_history',
            'payload' => [
                'id' => 'projection-timer-projected',
                'sequence' => 12,
                'status' => 'fired',
                'source_status' => 'fired',
                'delay_seconds' => 90,
                'fire_at' => now()->addSeconds(90)->toJSON(),
                'fired_at' => now()->toJSON(),
                'history_authority' => 'typed_history',
                'history_event_types' => ['TimerScheduled'],
            ],
        ]);
        WorkflowRunTimerEntry::query()->create([
            'id' => 'projection-timer-orphan-row',
            'workflow_run_id' => '01JMETRICSPROJTIMERGONE01',
            'workflow_instance_id' => 'metrics-timer-orphan-instance',
            'timer_id' => 'projection-timer-orphan',
            'position' => 0,
            'status' => 'pending',
            'source_status' => 'pending',
            'history_authority' => 'typed_history',
            'payload' => [
                'id' => 'projection-timer-orphan',
                'status' => 'pending',
                'source_status' => 'pending',
                'history_authority' => 'typed_history',
                'history_event_types' => [],
            ],
        ]);
        WorkflowHistoryEvent::record(
            $missingWaitRun,
            HistoryEventType::WorkflowContinuedAsNew,
            [
                'sequence' => 3,
                'workflow_link_id' => 'projection-lineage-missing',
                'continued_to_run_id' => 'projection-lineage-current',
            ],
        );
        WorkflowHistoryEvent::record(
            $projectedWaitRun,
            HistoryEventType::WorkflowContinuedAsNew,
            [
                'sequence' => 4,
                'workflow_link_id' => 'projection-lineage-valid',
                'continued_to_run_id' => 'projection-lineage-valid-current',
            ],
        );
        WorkflowRunLineageEntry::query()->create([
            'id' => 'projection-lineage-valid-row',
            'workflow_run_id' => $projectedWaitRun->id,
            'workflow_instance_id' => $projectedWaitRun->workflow_instance_id,
            'direction' => 'child',
            'lineage_id' => 'projection-lineage-valid',
            'position' => 0,
            'link_type' => 'continue_as_new',
            'related_workflow_instance_id' => $projectedWaitRun->workflow_instance_id,
            'related_workflow_run_id' => '01JPROJLINEAGESTALERUN001',
            'payload' => [],
        ]);
        WorkflowRunLineageEntry::query()->create([
            'id' => 'projection-lineage-orphan-row',
            'workflow_run_id' => '01JMETRICSPROJLINEAGE001',
            'workflow_instance_id' => 'metrics-lineage-orphan-instance',
            'direction' => 'child',
            'lineage_id' => 'projection-lineage-orphan',
            'position' => 0,
            'link_type' => 'continue_as_new',
            'related_workflow_instance_id' => 'metrics-lineage-orphan-instance',
            'related_workflow_run_id' => '01JPROJLINEAGEORPHCURR001',
            'payload' => [],
        ]);

        $snapshot = OperatorMetrics::snapshot();

        $this->assertSame(2, $snapshot['projections']['run_waits']['runs']);
        $this->assertSame(2, $snapshot['projections']['run_waits']['rows']);
        $this->assertSame(2, $snapshot['projections']['run_waits']['projected_runs']);
        $this->assertSame(2, $snapshot['projections']['run_waits']['runs_with_waits']);
        $this->assertSame(1, $snapshot['projections']['run_waits']['projected_runs_with_waits']);
        $this->assertSame(1, $snapshot['projections']['run_waits']['missing_runs_with_waits']);
        $this->assertSame(2, $snapshot['projections']['run_waits']['summaries_with_open_waits']);
        $this->assertSame(1, $snapshot['projections']['run_waits']['projected_current_open_waits']);
        $this->assertSame(1, $snapshot['projections']['run_waits']['missing_current_open_waits']);
        $this->assertSame(1, $snapshot['projections']['run_waits']['stale_projected_runs']);
        $this->assertSame(1, $snapshot['projections']['run_waits']['orphaned']);
        $this->assertSame(3, $snapshot['projections']['run_waits']['needs_rebuild']);

        $this->assertSame(2, $snapshot['projections']['run_timeline_entries']['runs']);
        $this->assertSame(6, $snapshot['projections']['run_timeline_entries']['history_events']);
        $this->assertSame(2, $snapshot['projections']['run_timeline_entries']['rows']);
        $this->assertSame(2, $snapshot['projections']['run_timeline_entries']['projected_runs']);
        $this->assertSame(2, $snapshot['projections']['run_timeline_entries']['runs_with_history']);
        $this->assertSame(1, $snapshot['projections']['run_timeline_entries']['projected_runs_with_history']);
        $this->assertSame(1, $snapshot['projections']['run_timeline_entries']['missing_runs_with_history']);
        $this->assertSame(5, $snapshot['projections']['run_timeline_entries']['missing_history_events']);
        $this->assertSame(1, $snapshot['projections']['run_timeline_entries']['stale_projected_runs']);
        $this->assertSame(1, $snapshot['projections']['run_timeline_entries']['orphaned']);
        $this->assertSame(3, $snapshot['projections']['run_timeline_entries']['needs_rebuild']);

        $this->assertSame(2, $snapshot['projections']['run_timer_entries']['runs']);
        $this->assertSame(2, $snapshot['projections']['run_timer_entries']['rows']);
        $this->assertSame(2, $snapshot['projections']['run_timer_entries']['projected_runs']);
        $this->assertSame(2, $snapshot['projections']['run_timer_entries']['runs_with_timers']);
        $this->assertSame(1, $snapshot['projections']['run_timer_entries']['projected_runs_with_timers']);
        $this->assertSame(1, $snapshot['projections']['run_timer_entries']['missing_runs_with_timers']);
        $this->assertSame(1, $snapshot['projections']['run_timer_entries']['stale_projected_runs']);
        $this->assertSame(1, $snapshot['projections']['run_timer_entries']['orphaned']);
        $this->assertSame(3, $snapshot['projections']['run_timer_entries']['needs_rebuild']);

        $this->assertSame(2, $snapshot['projections']['run_lineage_entries']['runs']);
        $this->assertSame(2, $snapshot['projections']['run_lineage_entries']['rows']);
        $this->assertSame(2, $snapshot['projections']['run_lineage_entries']['projected_runs']);
        $this->assertSame(2, $snapshot['projections']['run_lineage_entries']['runs_with_lineage']);
        $this->assertSame(1, $snapshot['projections']['run_lineage_entries']['projected_runs_with_lineage']);
        $this->assertSame(1, $snapshot['projections']['run_lineage_entries']['missing_runs_with_lineage']);
        $this->assertSame(1, $snapshot['projections']['run_lineage_entries']['stale_projected_runs']);
        $this->assertSame(1, $snapshot['projections']['run_lineage_entries']['orphaned']);
        $this->assertSame(3, $snapshot['projections']['run_lineage_entries']['needs_rebuild']);
    }

    public function testRepairCandidatesRespectDurableFailureBackoff(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        config()->set('workflows.v2.task_repair.redispatch_after_seconds', 5);
        config()->set('workflows.v2.task_repair.failure_backoff_max_seconds', 60);

        $run = $this->createRunWithSummary(
            instanceId: 'repair-backoff-instance',
            runId: '01JBACKOFFRUN000000000001',
            status: 'waiting',
            statusBucket: 'running',
            livenessState: 'workflow_task_ready',
        );

        $backingOffTask = $this->createTask($run, '01JBACKOFFTASK00000000001', TaskStatus::Ready->value, [
            'available_at' => now()->subMinute(),
            'last_dispatch_attempt_at' => now()->subSecond(),
            'last_dispatch_error' => 'Queue transport unavailable.',
            'repair_count' => 2,
            'repair_available_at' => now()->addSeconds(10),
        ]);
        $readyTask = $this->createTask($run, '01JBACKOFFTASK00000000002', TaskStatus::Ready->value, [
            'available_at' => now()->subMinute(),
            'last_dispatch_attempt_at' => now()->subSeconds(20),
            'last_dispatch_error' => 'Queue transport unavailable.',
            'repair_count' => 2,
            'repair_available_at' => now()->subSecond(),
        ]);

        $snapshot = TaskRepairCandidates::snapshot();
        $taskIds = TaskRepairCandidates::taskIds();

        $this->assertFalse(TaskRepairPolicy::readyTaskNeedsRedispatch($backingOffTask->fresh()));
        $this->assertTrue(TaskRepairPolicy::readyTaskNeedsRedispatch($readyTask->fresh()));
        $this->assertSame([$readyTask->id], $taskIds);
        $this->assertSame(1, $snapshot['existing_task_candidates']);
        $this->assertSame(1, $snapshot['selected_existing_task_candidates']);
    }

    public function testRepairCandidateScanIsScopeFairAcrossConnectionQueueAndCompatibility(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        config()->set('workflows.v2.task_repair.redispatch_after_seconds', 1);
        config()->set('workflows.v2.task_repair.scan_limit', 3);

        $hotTaskRun = $this->createRunWithSummary(
            instanceId: 'fair-task-hot',
            runId: '01JFAIRSCANRUN00000000001',
            status: 'waiting',
            statusBucket: 'running',
            livenessState: 'workflow_task_ready',
            connection: 'redis',
            queue: 'default',
            compatibility: 'build-a',
        );
        $importsTaskRun = $this->createRunWithSummary(
            instanceId: 'fair-task-imports',
            runId: '01JFAIRSCANRUN00000000002',
            status: 'waiting',
            statusBucket: 'running',
            livenessState: 'workflow_task_ready',
            connection: 'redis',
            queue: 'imports',
            compatibility: 'build-a',
        );
        $syncTaskRun = $this->createRunWithSummary(
            instanceId: 'fair-task-sync',
            runId: '01JFAIRSCANRUN00000000003',
            status: 'waiting',
            statusBucket: 'running',
            livenessState: 'workflow_task_ready',
            connection: 'sync',
            queue: 'default',
        );

        $hotTaskIds = [];

        for ($i = 1; $i <= 5; $i++) {
            $hotTaskIds[] = sprintf('01JFAIRTASKHOT%011d', $i);
            $this->createTask($hotTaskRun, $hotTaskIds[$i - 1], TaskStatus::Ready->value, [
                'connection' => 'redis',
                'queue' => 'default',
                'compatibility' => 'build-a',
                'available_at' => now()->subMinutes(10),
                'last_dispatched_at' => now()->subMinutes(10),
                'created_at' => now()->subMinutes(10)->addSeconds($i),
            ]);
        }

        $importsTaskId = '01JFAIRTASKIMPORTS000001';
        $syncTaskId = '01JFAIRTASKSYNC00000001';

        $this->createTask($importsTaskRun, $importsTaskId, TaskStatus::Ready->value, [
            'connection' => 'redis',
            'queue' => 'imports',
            'compatibility' => 'build-a',
            'available_at' => now()->subMinutes(9),
            'last_dispatched_at' => now()->subMinutes(9),
            'created_at' => now()->subMinutes(9),
        ]);
        $this->createTask($syncTaskRun, $syncTaskId, TaskStatus::Ready->value, [
            'connection' => 'sync',
            'queue' => 'default',
            'available_at' => now()->subMinutes(8),
            'last_dispatched_at' => now()->subMinutes(8),
            'created_at' => now()->subMinutes(8),
        ]);

        $hotMissingRunIds = [];

        for ($i = 1; $i <= 4; $i++) {
            $hotMissingRunIds[] = sprintf('01JFAIRMISSRUNHOT%09d', $i);
            $this->createRunWithSummary(
                instanceId: sprintf('fair-missing-hot-%d', $i),
                runId: $hotMissingRunIds[$i - 1],
                status: 'waiting',
                statusBucket: 'running',
                livenessState: 'repair_needed',
                connection: 'redis',
                queue: 'default',
                compatibility: 'build-a',
            );
        }

        $importsMissingRunId = '01JFAIRMISSRUNIMPORTS001';
        $syncMissingRunId = '01JFAIRMISSRUNSYNC000001';

        $this->createRunWithSummary(
            instanceId: 'fair-missing-imports',
            runId: $importsMissingRunId,
            status: 'waiting',
            statusBucket: 'running',
            livenessState: 'repair_needed',
            connection: 'redis',
            queue: 'imports',
            compatibility: 'build-a',
        );
        $this->createRunWithSummary(
            instanceId: 'fair-missing-sync',
            runId: $syncMissingRunId,
            status: 'waiting',
            statusBucket: 'running',
            livenessState: 'repair_needed',
            connection: 'sync',
            queue: 'default',
        );

        $taskIds = TaskRepairCandidates::taskIds();
        $runIds = TaskRepairCandidates::runIds();

        $this->assertCount(3, $taskIds);
        $this->assertCount(1, array_intersect($taskIds, $hotTaskIds));
        $this->assertContains($importsTaskId, $taskIds);
        $this->assertContains($syncTaskId, $taskIds);
        $this->assertCount(3, $runIds);
        $this->assertCount(1, array_intersect($runIds, $hotMissingRunIds));
        $this->assertContains($importsMissingRunId, $runIds);
        $this->assertContains($syncMissingRunId, $runIds);

        $snapshot = TaskRepairCandidates::snapshot();
        $scopes = collect($snapshot['scopes'])->keyBy('scope_key');

        $this->assertSame('scope_fair_round_robin', $snapshot['scan_strategy']);
        $this->assertSame(7, $snapshot['existing_task_candidates']);
        $this->assertSame(6, $snapshot['missing_task_candidates']);
        $this->assertSame(3, $snapshot['selected_existing_task_candidates']);
        $this->assertSame(3, $snapshot['selected_missing_task_candidates']);
        $this->assertSame(6, $snapshot['selected_total_candidates']);
        $this->assertTrue($snapshot['scan_pressure']);
        $this->assertSame(5, $scopes->get('redis:default:build-a')['existing_task_candidates']);
        $this->assertSame(4, $scopes->get('redis:default:build-a')['missing_task_candidates']);
        $this->assertSame(1, $scopes->get('redis:default:build-a')['selected_existing_task_candidates']);
        $this->assertSame(1, $scopes->get('redis:default:build-a')['selected_missing_task_candidates']);
        $this->assertTrue($scopes->get('redis:default:build-a')['scan_limited_by_global_policy']);
        $this->assertSame(1, $scopes->get('redis:imports:build-a')['selected_existing_task_candidates']);
        $this->assertSame(1, $scopes->get('redis:imports:build-a')['selected_missing_task_candidates']);
        $this->assertFalse($scopes->get('redis:imports:build-a')['scan_limited_by_global_policy']);
        $this->assertSame(1, $scopes->get('sync:default:any')['selected_existing_task_candidates']);
        $this->assertSame(1, $scopes->get('sync:default:any')['selected_missing_task_candidates']);
        $this->assertFalse($scopes->get('sync:default:any')['scan_limited_by_global_policy']);
    }

    public function testSnapshotCountsCommandContractSnapshotsThatStillNeedBackfill(): void
    {
        $availableInstance = WorkflowInstance::query()->create([
            'id' => 'metrics-contract-available',
            'workflow_class' => TestCommandTargetWorkflow::class,
            'workflow_type' => 'test-command-target-workflow',
            'run_count' => 1,
        ]);

        /** @var WorkflowRun $availableRun */
        $availableRun = WorkflowRun::query()->create([
            'id' => '01JMETRICSCONTRACTAVAIL01',
            'workflow_instance_id' => $availableInstance->id,
            'run_number' => 1,
            'workflow_class' => TestCommandTargetWorkflow::class,
            'workflow_type' => 'test-command-target-workflow',
            'status' => 'waiting',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSecond(),
        ]);

        $availableInstance->forceFill([
            'current_run_id' => $availableRun->id,
        ])->save();

        WorkflowHistoryEvent::record($availableRun, HistoryEventType::WorkflowStarted, [
            'workflow_class' => TestCommandTargetWorkflow::class,
            'workflow_type' => 'test-command-target-workflow',
            'declared_queries' => ['approval-stage', 'approvalMatches'],
            'declared_query_contracts' => [
                [
                    'name' => 'approval-stage',
                    'parameters' => [],
                ],
            ],
            'declared_signals' => ['approved-by', 'rejected-by'],
            'declared_signal_contracts' => [
                [
                    'name' => 'approved-by',
                    'parameters' => [
                        [
                            'name' => 'actor',
                            'position' => 0,
                            'required' => true,
                            'variadic' => false,
                            'default_available' => false,
                            'default' => null,
                            'type' => 'string',
                            'allows_null' => false,
                        ],
                    ],
                ],
            ],
            'declared_updates' => ['mark-approved'],
            'declared_update_contracts' => [
                [
                    'name' => 'mark-approved',
                    'parameters' => [
                        [
                            'name' => 'approved',
                            'position' => 0,
                            'required' => true,
                            'variadic' => false,
                            'default_available' => false,
                            'default' => null,
                            'type' => 'bool',
                            'allows_null' => false,
                        ],
                    ],
                ],
            ],
        ]);

        $unavailableInstance = WorkflowInstance::query()->create([
            'id' => 'metrics-contract-unavailable',
            'workflow_class' => 'Missing\\Workflow\\CommandTargetWorkflow',
            'workflow_type' => 'missing-command-target-workflow',
            'run_count' => 1,
        ]);

        /** @var WorkflowRun $unavailableRun */
        $unavailableRun = WorkflowRun::query()->create([
            'id' => '01JMETRICSCONTRACTUNAVL01',
            'workflow_instance_id' => $unavailableInstance->id,
            'run_number' => 1,
            'workflow_class' => 'Missing\\Workflow\\CommandTargetWorkflow',
            'workflow_type' => 'missing-command-target-workflow',
            'status' => 'waiting',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSecond(),
        ]);

        $unavailableInstance->forceFill([
            'current_run_id' => $unavailableRun->id,
        ])->save();

        WorkflowHistoryEvent::record($unavailableRun, HistoryEventType::WorkflowStarted, [
            'workflow_class' => 'Missing\\Workflow\\CommandTargetWorkflow',
            'workflow_type' => 'missing-command-target-workflow',
            'declared_signals' => ['approved-by', 'rejected-by'],
            'declared_updates' => ['mark-approved'],
        ]);

        $snapshot = OperatorMetrics::snapshot();

        $this->assertSame(2, $snapshot['command_contracts']['backfill_needed_runs']);
        $this->assertSame(1, $snapshot['command_contracts']['backfill_available_runs']);
        $this->assertSame(1, $snapshot['command_contracts']['backfill_unavailable_runs']);
    }

    private function createRunWithSummary(
        string $instanceId,
        string $runId,
        string $status,
        string $statusBucket,
        string $livenessState,
        int $historyEventCount = 0,
        int $historySizeBytes = 0,
        bool $continueAsNewRecommended = false,
        ?string $connection = null,
        ?string $queue = null,
        ?string $compatibility = null,
    ): WorkflowRun {
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => $runId,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => $status,
            'connection' => $connection,
            'queue' => $queue,
            'compatibility' => $compatibility,
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
            'status' => $status,
            'status_bucket' => $statusBucket,
            'started_at' => now()
                ->subMinutes(10),
            'liveness_state' => $livenessState,
            'connection' => $connection,
            'queue' => $queue,
            'compatibility' => $compatibility,
            'history_event_count' => $historyEventCount,
            'history_size_bytes' => $historySizeBytes,
            'continue_as_new_recommended' => $continueAsNewRecommended,
            'created_at' => now()
                ->subMinutes(10),
            'updated_at' => now(),
        ]);

        return $run;
    }

    private function createStartCommand(WorkflowRun $run, Carbon $acceptedAt): WorkflowCommand
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->findOrFail($run->workflow_instance_id);

        return WorkflowCommand::record($instance, $run, [
            'command_type' => CommandType::Start->value,
            'target_scope' => 'instance',
            'status' => CommandStatus::Accepted->value,
            'outcome' => CommandOutcome::StartedNew->value,
            'accepted_at' => $acceptedAt,
            'applied_at' => $acceptedAt,
            'created_at' => $acceptedAt,
            'updated_at' => $acceptedAt,
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createTask(WorkflowRun $run, string $id, string $status, array $attributes = []): WorkflowTask
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create(array_merge([
            'id' => $id,
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => $status,
            'available_at' => now(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return $task;
    }
}
