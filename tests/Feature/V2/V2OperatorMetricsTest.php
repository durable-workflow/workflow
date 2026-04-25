<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\ScheduleStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowRunTimerEntry;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Support\HealthCheck;
use Workflow\V2\Support\OperatorMetrics;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\TaskRepairCandidates;
use Workflow\V2\Support\TaskRepairPolicy;
use Workflow\V2\Support\WorkerCompatibilityFleet;

final class V2OperatorMetricsTest extends TestCase
{
    public function testWorkerFleetSnapshotFallsBackToCacheWhenHeartbeatTableIsUnavailable(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
            WorkerCompatibilityFleet::clear();
        });

        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.namespace', 'metrics-test');

        WorkerCompatibilityFleet::clear();
        Schema::dropIfExists('workflow_worker_compatibility_heartbeats');

        WorkerCompatibilityFleet::record(['build-a'], 'redis', 'default', 'worker-a');

        $snapshot = OperatorMetrics::snapshot();

        $this->assertSame(1, $snapshot['workers']['active_workers']);
        $this->assertSame(1, $snapshot['workers']['active_worker_scopes']);
        $this->assertSame(1, $snapshot['workers']['active_workers_supporting_required']);
        $this->assertCount(1, $snapshot['workers']['fleet']);
        $this->assertSame('worker-a', $snapshot['workers']['fleet'][0]['worker_id']);
        $this->assertSame('cache', $snapshot['workers']['fleet'][0]['source']);
        $this->assertTrue($snapshot['workers']['fleet'][0]['supports_required']);
    }

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
        config()
            ->set('workflows.serializer', 'avro');
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
        $compatibilityBlockedRun = $this->createRunWithSummary(
            instanceId: 'metrics-instance-b',
            runId: '01JMETRICSFLOWRUN000000002',
            status: 'waiting',
            statusBucket: 'running',
            livenessState: 'workflow_task_waiting_for_compatible_worker',
        );
        WorkflowRunSummary::query()
            ->whereKey($compatibilityBlockedRun->id)
            ->update([
                'wait_started_at' => now()
                    ->subMinutes(4),
            ]);
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
        $this->assertSame(1, $snapshot['runs']['waiting']);
        $this->assertSame(
            Carbon::parse('2026-04-09 12:00:00')
                ->subMinutes(4)
                ->toJSON(),
            $snapshot['runs']['oldest_wait_started_at'],
        );
        $this->assertSame(4 * 60 * 1000, $snapshot['runs']['max_wait_age_ms']);
        $this->assertSame(7, $snapshot['tasks']['open']);
        $this->assertSame(5, $snapshot['tasks']['ready']);
        $this->assertSame(4, $snapshot['tasks']['ready_due']);
        $this->assertSame(1, $snapshot['tasks']['delayed']);
        $this->assertSame(2, $snapshot['tasks']['leased']);
        $this->assertSame(1, $snapshot['tasks']['dispatch_failed']);
        $this->assertSame(1, $snapshot['tasks']['claim_failed']);
        $this->assertSame(1, $snapshot['tasks']['dispatch_overdue']);
        $this->assertSame(1, $snapshot['tasks']['lease_expired']);
        $this->assertSame(
            Carbon::parse('2026-04-09 12:00:00')
                ->subMinute()
                ->toJSON(),
            $snapshot['tasks']['oldest_lease_expired_at'],
        );
        $this->assertSame(60 * 1000, $snapshot['tasks']['max_lease_expired_age_ms']);
        $this->assertSame(
            Carbon::parse('2026-04-09 12:00:00')
                ->subSeconds(10)
                ->toJSON(),
            $snapshot['tasks']['oldest_ready_due_at'],
        );
        $this->assertSame(10 * 1000, $snapshot['tasks']['max_ready_due_age_ms']);
        $this->assertSame(
            Carbon::parse('2026-04-09 12:00:00')
                ->subSeconds(10)
                ->toJSON(),
            $snapshot['tasks']['oldest_dispatch_overdue_since'],
        );
        $this->assertSame(10 * 1000, $snapshot['tasks']['max_dispatch_overdue_age_ms']);
        $this->assertSame(
            Carbon::parse('2026-04-09 12:00:00')
                ->subSeconds(10)
                ->toJSON(),
            $snapshot['tasks']['oldest_claim_failed_at'],
        );
        $this->assertSame(10 * 1000, $snapshot['tasks']['max_claim_failed_age_ms']);
        $this->assertSame(4, $snapshot['tasks']['unhealthy']);
        $this->assertSame(
            Carbon::parse('2026-04-09 12:00:00')
                ->subMinute()
                ->toJSON(),
            $snapshot['tasks']['oldest_unhealthy_at'],
        );
        $this->assertSame(60 * 1000, $snapshot['tasks']['max_unhealthy_age_ms']);
        $this->assertSame(4, $snapshot['backlog']['runnable_tasks']);
        $this->assertSame(1, $snapshot['backlog']['delayed_tasks']);
        $this->assertSame(2, $snapshot['backlog']['leased_tasks']);
        $this->assertSame(4, $snapshot['backlog']['unhealthy_tasks']);
        $this->assertSame(1, $snapshot['backlog']['repair_needed_runs']);
        $this->assertSame(1, $snapshot['backlog']['claim_failed_runs']);
        $this->assertSame(1, $snapshot['backlog']['compatibility_blocked_runs']);
        $this->assertSame(
            Carbon::parse('2026-04-09 12:00:00')
                ->subMinutes(4)
                ->toJSON(),
            $snapshot['backlog']['oldest_compatibility_blocked_started_at'],
        );
        $this->assertSame(4 * 60 * 1000, $snapshot['backlog']['max_compatibility_blocked_age_ms']);
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
        $this->assertSame(0, $snapshot['history']['history_orphan_total']);
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
        $this->assertIsArray($snapshot['workers']['fleet']);
        $this->assertCount(2, $snapshot['workers']['fleet']);
        $fleetByWorker = collect($snapshot['workers']['fleet'])->keyBy('worker_id');
        $this->assertSame('metrics-test', $fleetByWorker['worker-a']['namespace']);
        $this->assertSame('redis', $fleetByWorker['worker-a']['connection']);
        $this->assertSame('default', $fleetByWorker['worker-a']['queue']);
        $this->assertSame(['build-a'], $fleetByWorker['worker-a']['supported']);
        $this->assertTrue($fleetByWorker['worker-a']['supports_required']);
        $this->assertSame('2026-04-09T12:00:00.000000Z', $fleetByWorker['worker-a']['recorded_at']);
        $this->assertIsString($fleetByWorker['worker-a']['expires_at']);
        $this->assertSame('database', $fleetByWorker['worker-a']['source']);
        $this->assertSame('imports', $fleetByWorker['worker-b']['queue']);
        $this->assertSame(['build-b'], $fleetByWorker['worker-b']['supported']);
        $this->assertFalse($fleetByWorker['worker-b']['supports_required']);
        $this->assertTrue($snapshot['backend']['supported']);
        $this->assertSame('redis', $snapshot['backend']['queue']['connection']);
        $this->assertSame('redis', $snapshot['backend']['queue']['driver']);
        $this->assertSame('array', $snapshot['backend']['cache']['store']);
        $this->assertSame([], $snapshot['backend']['issues']);
        $this->assertSame('ok', $snapshot['backend']['severity']);
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

        $healthSnapshot = HealthCheck::snapshot();
        $taskTransport = collect($healthSnapshot['checks'])->firstWhere('name', 'task_transport');
        $this->assertNotNull($taskTransport);
        $this->assertSame(4, $taskTransport['data']['unhealthy_tasks']);
        $this->assertSame(1, $taskTransport['data']['lease_expired_tasks']);
        $this->assertSame(
            Carbon::parse('2026-04-09 12:00:00')
                ->subMinute()
                ->toJSON(),
            $taskTransport['data']['oldest_lease_expired_at'],
        );
        $this->assertSame(60 * 1000, $taskTransport['data']['max_lease_expired_age_ms']);
        $this->assertSame(4, $taskTransport['data']['ready_due_tasks']);
        $this->assertSame(
            Carbon::parse('2026-04-09 12:00:00')
                ->subSeconds(10)
                ->toJSON(),
            $taskTransport['data']['oldest_ready_due_at'],
        );
        $this->assertSame(10 * 1000, $taskTransport['data']['max_ready_due_age_ms']);
        $this->assertSame(1, $taskTransport['data']['dispatch_overdue_tasks']);
        $this->assertSame(
            Carbon::parse('2026-04-09 12:00:00')
                ->subSeconds(10)
                ->toJSON(),
            $taskTransport['data']['oldest_dispatch_overdue_since'],
        );
        $this->assertSame(10 * 1000, $taskTransport['data']['max_dispatch_overdue_age_ms']);
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

    public function testSnapshotCountsHistoryEventsWhoseRunWasDeleted(): void
    {
        $run = $this->createRunWithSummary(
            instanceId: 'metrics-history-retained-i',
            runId: '01JMETRICSHISTORYLIVE001',
            status: 'completed',
            statusBucket: 'completed',
            livenessState: 'closed',
        );

        WorkflowHistoryEvent::query()->create([
            'id' => '01JMETRICSHISTORYRETAIN1',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowStarted->value,
            'payload' => [
                'workflow_run_id' => $run->id,
            ],
            'recorded_at' => now(),
        ]);
        WorkflowHistoryEvent::query()->create([
            'id' => '01JMETRICSHISTORYORPHAN1',
            'workflow_run_id' => '01JMETRICSHISTORYGONE001',
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowStarted->value,
            'payload' => [
                'workflow_run_id' => '01JMETRICSHISTORYGONE001',
            ],
            'recorded_at' => now(),
        ]);

        $snapshot = OperatorMetrics::snapshot();

        $this->assertSame(2, $snapshot['history']['events']);
        $this->assertSame(1, $snapshot['history']['history_orphan_total']);
    }

    public function testSnapshotCountsSelectedRunProjectionDrift(): void
    {
        $missingWaitRun = $this->createRunWithSummary(
            instanceId: 'metrics-wait-missing-i',
            runId: '01JMETRICSPROJWAITMISS01',
            status: 'waiting',
            statusBucket: 'running',
            livenessState: 'waiting_for_signal',
        );
        $projectedWaitRun = $this->createRunWithSummary(
            instanceId: 'metrics-wait-projected-i',
            runId: '01JMETRICSPROJWAITDONE01',
            status: 'waiting',
            statusBucket: 'running',
            livenessState: 'waiting_for_signal',
        );

        WorkflowRunSummary::query()
            ->whereKey($missingWaitRun->id)
            ->update([
                'open_wait_id' => 'signal:missing',
            ]);
        WorkflowRunSummary::query()
            ->whereKey($projectedWaitRun->id)
            ->update([
                'open_wait_id' => 'signal:projected',
            ]);

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
            [
                'workflow_run_id' => $missingWaitRun->id,
            ],
        );
        $projectedTimelineEvent = WorkflowHistoryEvent::record(
            $projectedWaitRun,
            HistoryEventType::WorkflowStarted,
            [
                'workflow_run_id' => $projectedWaitRun->id,
            ],
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
                'fire_at' => now()
                    ->addMinute()
                    ->toJSON(),
            ],
        );
        WorkflowHistoryEvent::record(
            $projectedWaitRun,
            HistoryEventType::TimerScheduled,
            [
                'timer_id' => 'projection-timer-projected',
                'sequence' => 12,
                'delay_seconds' => 90,
                'fire_at' => now()
                    ->addSeconds(90)
                    ->toJSON(),
            ],
        );
        WorkflowRunTimerEntry::query()->create([
            'id' => 'projection-timer-valid-row',
            'workflow_run_id' => $projectedWaitRun->id,
            'workflow_instance_id' => $projectedWaitRun->workflow_instance_id,
            'timer_id' => 'projection-timer-projected',
            'schema_version' => WorkflowRunTimerEntry::CURRENT_SCHEMA_VERSION - 1,
            'position' => 0,
            'sequence' => 12,
            'status' => 'fired',
            'source_status' => 'fired',
            'delay_seconds' => 90,
            'fire_at' => now()
                ->addSeconds(90),
            'fired_at' => now(),
            'history_authority' => 'typed_history',
            'payload' => [
                'id' => 'projection-timer-projected',
                'sequence' => 12,
                'status' => 'fired',
                'source_status' => 'fired',
                'delay_seconds' => 90,
                'fire_at' => now()
                    ->addSeconds(90)
                    ->toJSON(),
                'fired_at' => now()
                    ->toJSON(),
                'history_authority' => 'typed_history',
                'history_event_types' => ['TimerScheduled'],
            ],
        ]);
        WorkflowRunTimerEntry::query()->create([
            'id' => 'projection-timer-orphan-row',
            'workflow_run_id' => '01JMETRICSPROJTIMERGONE01',
            'workflow_instance_id' => 'metrics-timer-orphan-instance',
            'timer_id' => 'projection-timer-orphan',
            'schema_version' => WorkflowRunTimerEntry::CURRENT_SCHEMA_VERSION - 1,
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
        $this->assertSame(1, $snapshot['projections']['run_timer_entries']['schema_version_mismatch_runs']);
        $this->assertSame(2, $snapshot['projections']['run_timer_entries']['schema_version_mismatch_rows']);
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

        config()
            ->set('workflows.v2.task_repair.redispatch_after_seconds', 5);
        config()
            ->set('workflows.v2.task_repair.failure_backoff_max_seconds', 60);

        $run = $this->createRunWithSummary(
            instanceId: 'repair-backoff-instance',
            runId: '01JBACKOFFRUN000000000001',
            status: 'waiting',
            statusBucket: 'running',
            livenessState: 'workflow_task_ready',
        );

        $backingOffTask = $this->createTask($run, '01JBACKOFFTASK00000000001', TaskStatus::Ready->value, [
            'available_at' => now()
                ->subMinute(),
            'last_dispatch_attempt_at' => now()
                ->subSecond(),
            'last_dispatch_error' => 'Queue transport unavailable.',
            'repair_count' => 2,
            'repair_available_at' => now()
                ->addSeconds(10),
        ]);
        $readyTask = $this->createTask($run, '01JBACKOFFTASK00000000002', TaskStatus::Ready->value, [
            'available_at' => now()
                ->subMinute(),
            'last_dispatch_attempt_at' => now()
                ->subSeconds(20),
            'last_dispatch_error' => 'Queue transport unavailable.',
            'repair_count' => 2,
            'repair_available_at' => now()
                ->subSecond(),
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

        config()
            ->set('workflows.v2.task_repair.redispatch_after_seconds', 1);
        config()
            ->set('workflows.v2.task_repair.scan_limit', 3);

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
                'available_at' => now()
                    ->subMinutes(10),
                'last_dispatched_at' => now()
                    ->subMinutes(10),
                'created_at' => now()
                    ->subMinutes(10)
                    ->addSeconds($i),
            ]);
        }

        $importsTaskId = '01JFAIRTASKIMPORTS000001';
        $syncTaskId = '01JFAIRTASKSYNC00000001';

        $this->createTask($importsTaskRun, $importsTaskId, TaskStatus::Ready->value, [
            'connection' => 'redis',
            'queue' => 'imports',
            'compatibility' => 'build-a',
            'available_at' => now()
                ->subMinutes(9),
            'last_dispatched_at' => now()
                ->subMinutes(9),
            'created_at' => now()
                ->subMinutes(9),
        ]);
        $this->createTask($syncTaskRun, $syncTaskId, TaskStatus::Ready->value, [
            'connection' => 'sync',
            'queue' => 'default',
            'available_at' => now()
                ->subMinutes(8),
            'last_dispatched_at' => now()
                ->subMinutes(8),
            'created_at' => now()
                ->subMinutes(8),
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

    public function testSnapshotSurfacesSchedulerRoleHealth(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $now = Carbon::now();

        // Namespace 'alpha' — used by the snapshot under test.
        $this->createSchedule('sched-active-a', 'alpha', ScheduleStatus::Active, $now->copy()->addHour(), 5, 0);
        $this->createSchedule(
            'sched-missed-oldest-a',
            'alpha',
            ScheduleStatus::Active,
            $now->copy()
                ->subMinutes(12),
            3,
            2,
        );
        $this->createSchedule(
            'sched-missed-recent-a',
            'alpha',
            ScheduleStatus::Active,
            $now->copy()
                ->subSeconds(30),
            1,
            0,
        );
        $this->createSchedule('sched-paused-a', 'alpha', ScheduleStatus::Paused, null, 0, 0);
        $this->createSchedule('sched-deleted-a', 'alpha', ScheduleStatus::Deleted, null, 7, 9);

        // Noise in a different namespace to prove namespace scoping.
        $this->createSchedule(
            'sched-missed-foreign',
            'beta',
            ScheduleStatus::Active,
            $now->copy()
                ->subMinutes(30),
            99,
            99,
        );

        $snapshot = OperatorMetrics::snapshot($now, 'alpha');

        $this->assertArrayHasKey('schedules', $snapshot);
        $this->assertSame(3, $snapshot['schedules']['active']);
        $this->assertSame(1, $snapshot['schedules']['paused']);
        $this->assertSame(2, $snapshot['schedules']['missed']);
        $this->assertSame('2026-04-09T11:48:00.000000Z', $snapshot['schedules']['oldest_overdue_at']);
        $this->assertSame(12 * 60 * 1000, $snapshot['schedules']['max_overdue_ms']);
        $this->assertSame(9, $snapshot['schedules']['fires_total']);
        $this->assertSame(2, $snapshot['schedules']['failures_total']);
    }

    public function testSnapshotSurfacesRunWaitAgeForEveryRunningWaitNotJustCompatibilityBlocked(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $now = Carbon::now();

        // Signal-wait run parked the longest — earliest wait_started_at wins.
        $signalWait = $this->createRunWithSummary(
            instanceId: 'wait-age-instance-signal',
            runId: '01JWAITSIGNALRUN0000000001',
            status: 'waiting',
            statusBucket: 'running',
            livenessState: 'workflow_task_waiting_for_signal',
        );
        WorkflowRunSummary::query()
            ->whereKey($signalWait->id)
            ->update([
                'wait_started_at' => $now->copy()
                    ->subMinutes(7),
            ]);

        // Compatibility-blocked wait — counted under runs.waiting too.
        $compatibilityWait = $this->createRunWithSummary(
            instanceId: 'wait-age-instance-compat',
            runId: '01JWAITCOMPATRUN0000000002',
            status: 'waiting',
            statusBucket: 'running',
            livenessState: 'workflow_task_waiting_for_compatible_worker',
        );
        WorkflowRunSummary::query()
            ->whereKey($compatibilityWait->id)
            ->update([
                'wait_started_at' => $now->copy()
                    ->subMinutes(2),
            ]);

        // Running run that is actively executing — must not count.
        $this->createRunWithSummary(
            instanceId: 'wait-age-instance-active',
            runId: '01JWAITACTIVERUN0000000003',
            status: 'running',
            statusBucket: 'running',
            livenessState: 'running',
        );

        // Closed run that previously had a wait — must not count.
        $closedWait = $this->createRunWithSummary(
            instanceId: 'wait-age-instance-closed',
            runId: '01JWAITCLOSEDRUN0000000004',
            status: 'completed',
            statusBucket: 'completed',
            livenessState: 'closed',
        );
        WorkflowRunSummary::query()
            ->whereKey($closedWait->id)
            ->update([
                'wait_started_at' => $now->copy()
                    ->subHours(2),
            ]);

        $snapshot = OperatorMetrics::snapshot($now);

        $expectedOldestWaitAt = $now->copy()
            ->subMinutes(7)
            ->toJSON();

        $this->assertSame(2, $snapshot['runs']['waiting']);
        $this->assertSame($expectedOldestWaitAt, $snapshot['runs']['oldest_wait_started_at']);
        $this->assertSame(7 * 60 * 1000, $snapshot['runs']['max_wait_age_ms']);

        $healthSnapshot = HealthCheck::snapshot($now);
        $resumePaths = collect($healthSnapshot['checks'])->firstWhere('name', 'durable_resume_paths');
        $this->assertNotNull($resumePaths);
        $this->assertSame(2, $resumePaths['data']['waiting_runs']);
        $this->assertSame($expectedOldestWaitAt, $resumePaths['data']['oldest_wait_started_at']);
        $this->assertSame(7 * 60 * 1000, $resumePaths['data']['max_wait_age_ms']);
    }

    public function testSnapshotSurfacesDispatchOverdueAgeFromEarliestEffectiveDispatchMoment(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $now = Carbon::now();

        $run = $this->createRunWithSummary(
            instanceId: 'dispatch-overdue-age-instance',
            runId: '01JDSPTCHOVRRUN00000000001',
            status: 'running',
            statusBucket: 'running',
            livenessState: 'running',
        );

        // Worst-case: ready task whose last successful dispatch was 90s ago.
        $this->createTask($run, '01JDSPTCHOVRTASK0000000001', TaskStatus::Ready->value, [
            'available_at' => $now->copy()
                ->subSeconds(120),
            'last_dispatched_at' => $now->copy()
                ->subSeconds(90),
            'created_at' => $now->copy()
                ->subSeconds(150),
        ]);

        // Never-dispatched ready task created well past the cutoff — counted
        // as overdue but its effective age (created_at = 30s ago) is newer
        // than the 90s task above, so it must not win the "oldest since".
        $this->createTask($run, '01JDSPTCHOVRTASK0000000002', TaskStatus::Ready->value, [
            'available_at' => $now->copy()
                ->subSeconds(30),
            'created_at' => $now->copy()
                ->subSeconds(30),
        ]);

        // Ready task dispatched well within the cutoff — healthy, NOT overdue.
        $this->createTask($run, '01JDSPTCHOVRTASK0000000003', TaskStatus::Ready->value, [
            'available_at' => $now->copy()
                ->subSecond(),
            'last_dispatched_at' => $now->copy()
                ->subSecond(),
            'created_at' => $now->copy()
                ->subSecond(),
        ]);

        // Dispatch-failed ready task — applyDispatchHealthy excludes it from
        // the overdue set even though its created_at is well past cutoff.
        $this->createTask($run, '01JDSPTCHOVRTASK0000000004', TaskStatus::Ready->value, [
            'available_at' => $now->copy()
                ->subSeconds(120),
            'last_dispatch_attempt_at' => $now->copy()
                ->subSeconds(30),
            'last_dispatch_error' => 'Queue transport unavailable.',
            'created_at' => $now->copy()
                ->subSeconds(120),
        ]);

        $snapshot = OperatorMetrics::snapshot($now);

        $expectedOldestDispatchOverdueSince = $now->copy()
            ->subSeconds(90)
            ->toJSON();

        $this->assertSame(2, $snapshot['tasks']['dispatch_overdue']);
        $this->assertSame($expectedOldestDispatchOverdueSince, $snapshot['tasks']['oldest_dispatch_overdue_since']);
        $this->assertSame(90 * 1000, $snapshot['tasks']['max_dispatch_overdue_age_ms']);

        $healthSnapshot = HealthCheck::snapshot($now);
        $taskTransport = collect($healthSnapshot['checks'])->firstWhere('name', 'task_transport');
        $this->assertNotNull($taskTransport);
        $this->assertSame(2, $taskTransport['data']['dispatch_overdue_tasks']);
        $this->assertSame($expectedOldestDispatchOverdueSince, $taskTransport['data']['oldest_dispatch_overdue_since']);
        $this->assertSame(90 * 1000, $taskTransport['data']['max_dispatch_overdue_age_ms']);
    }

    public function testSnapshotReportsDispatchOverdueAgeAsZeroWhenNoTasksAreOverdue(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $now = Carbon::now();

        $run = $this->createRunWithSummary(
            instanceId: 'dispatch-overdue-none-instance',
            runId: '01JDSPTCHNONRUN00000000001',
            status: 'running',
            statusBucket: 'running',
            livenessState: 'running',
        );

        // Fresh ready task — dispatched within the cutoff, not overdue.
        $this->createTask($run, '01JDSPTCHNONTASK0000000001', TaskStatus::Ready->value, [
            'available_at' => $now->copy()
                ->subSecond(),
            'last_dispatched_at' => $now->copy()
                ->subSecond(),
            'created_at' => $now->copy()
                ->subSecond(),
        ]);

        $snapshot = OperatorMetrics::snapshot($now);

        $this->assertSame(0, $snapshot['tasks']['dispatch_overdue']);
        $this->assertNull($snapshot['tasks']['oldest_dispatch_overdue_since']);
        $this->assertSame(0, $snapshot['tasks']['max_dispatch_overdue_age_ms']);
    }

    public function testSnapshotSurfacesClaimFailedAgeFromOldestClaimFailure(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $now = Carbon::now();

        $run = $this->createRunWithSummary(
            instanceId: 'claim-failed-age-instance',
            runId: '01JCLMFAILRUN0000000000001',
            status: 'running',
            statusBucket: 'running',
            livenessState: 'running',
        );

        // Worst-case: ready task whose last claim failed 90s ago with an
        // uncleared error.
        $this->createTask($run, '01JCLMFAILTASK000000000001', TaskStatus::Ready->value, [
            'available_at' => $now->copy()
                ->subSeconds(120),
            'last_dispatched_at' => $now->copy()
                ->subSeconds(100),
            'last_claim_failed_at' => $now->copy()
                ->subSeconds(90),
            'last_claim_error' => 'Workflow v2 backend capabilities are unsupported: [queue_sync_unsupported] sync.',
            'created_at' => $now->copy()
                ->subSeconds(150),
        ]);

        // Newer claim failure — counted but must not win the "oldest at".
        $this->createTask($run, '01JCLMFAILTASK000000000002', TaskStatus::Ready->value, [
            'available_at' => $now->copy()
                ->subSeconds(30),
            'last_dispatched_at' => $now->copy()
                ->subSeconds(20),
            'last_claim_failed_at' => $now->copy()
                ->subSeconds(15),
            'last_claim_error' => 'No compatible worker available for required build id.',
            'created_at' => $now->copy()
                ->subSeconds(30),
        ]);

        // Healthy ready task — not counted, and its created_at must not win.
        $this->createTask($run, '01JCLMFAILTASK000000000003', TaskStatus::Ready->value, [
            'available_at' => $now->copy()
                ->subSecond(),
            'last_dispatched_at' => $now->copy()
                ->subSecond(),
            'created_at' => $now->copy()
                ->subSeconds(200),
        ]);

        // Claim error cleared (empty string) — excluded by applyClaimHealthy.
        $this->createTask($run, '01JCLMFAILTASK000000000004', TaskStatus::Ready->value, [
            'available_at' => $now->copy()
                ->subSeconds(60),
            'last_dispatched_at' => $now->copy()
                ->subSeconds(50),
            'last_claim_failed_at' => $now->copy()
                ->subSeconds(300),
            'last_claim_error' => '',
            'created_at' => $now->copy()
                ->subSeconds(60),
        ]);

        // Leased task with an older last_claim_failed_at — excluded because
        // the claim-failed query requires status=Ready.
        $this->createTask($run, '01JCLMFAILTASK000000000005', TaskStatus::Leased->value, [
            'available_at' => $now->copy()
                ->subSeconds(60),
            'leased_at' => $now->copy()
                ->subSeconds(5),
            'lease_owner' => 'worker-leased',
            'lease_expires_at' => $now->copy()
                ->addSeconds(10),
            'last_claim_failed_at' => $now->copy()
                ->subSeconds(400),
            'last_claim_error' => 'Previous claim attempt failed before lease grant.',
            'created_at' => $now->copy()
                ->subSeconds(60),
        ]);

        $snapshot = OperatorMetrics::snapshot($now);

        $expectedOldestClaimFailedAt = $now->copy()
            ->subSeconds(90)
            ->toJSON();

        $this->assertSame(2, $snapshot['tasks']['claim_failed']);
        $this->assertSame($expectedOldestClaimFailedAt, $snapshot['tasks']['oldest_claim_failed_at']);
        $this->assertSame(90 * 1000, $snapshot['tasks']['max_claim_failed_age_ms']);

        $healthSnapshot = HealthCheck::snapshot($now);
        $taskTransport = collect($healthSnapshot['checks'])->firstWhere('name', 'task_transport');
        $this->assertNotNull($taskTransport);
        $this->assertSame(2, $taskTransport['data']['claim_failed_tasks']);
        $this->assertSame($expectedOldestClaimFailedAt, $taskTransport['data']['oldest_claim_failed_at']);
        $this->assertSame(90 * 1000, $taskTransport['data']['max_claim_failed_age_ms']);
    }

    public function testSnapshotReportsClaimFailedAgeAsZeroWhenNoTasksFailedToClaim(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $now = Carbon::now();

        $run = $this->createRunWithSummary(
            instanceId: 'claim-failed-none-instance',
            runId: '01JCLMFNONRUN0000000000001',
            status: 'running',
            statusBucket: 'running',
            livenessState: 'running',
        );

        // Fresh healthy ready task — never failed to claim.
        $this->createTask($run, '01JCLMFNONTASK000000000001', TaskStatus::Ready->value, [
            'available_at' => $now->copy()
                ->subSecond(),
            'last_dispatched_at' => $now->copy()
                ->subSecond(),
            'created_at' => $now->copy()
                ->subSecond(),
        ]);

        $snapshot = OperatorMetrics::snapshot($now);

        $this->assertSame(0, $snapshot['tasks']['claim_failed']);
        $this->assertNull($snapshot['tasks']['oldest_claim_failed_at']);
        $this->assertSame(0, $snapshot['tasks']['max_claim_failed_age_ms']);

        $healthSnapshot = HealthCheck::snapshot($now);
        $taskTransport = collect($healthSnapshot['checks'])->firstWhere('name', 'task_transport');
        $this->assertNotNull($taskTransport);
        $this->assertSame(0, $taskTransport['data']['claim_failed_tasks']);
        $this->assertNull($taskTransport['data']['oldest_claim_failed_at']);
        $this->assertSame(0, $taskTransport['data']['max_claim_failed_age_ms']);
    }

    public function testSnapshotSurfacesDispatchFailedAgeFromOldestDispatchFailure(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $now = Carbon::now();

        $run = $this->createRunWithSummary(
            instanceId: 'dispatch-failed-age-instance',
            runId: '01JDSPFAILRUN0000000000001',
            status: 'running',
            statusBucket: 'running',
            livenessState: 'running',
        );

        // Worst-case: ready task whose last dispatch attempt failed 90s ago
        // and has not been superseded by a successful dispatch.
        $this->createTask($run, '01JDSPFAILTASK000000000001', TaskStatus::Ready->value, [
            'available_at' => $now->copy()
                ->subSeconds(120),
            'last_dispatched_at' => null,
            'last_dispatch_attempt_at' => $now->copy()
                ->subSeconds(90),
            'last_dispatch_error' => 'Workflow v2 backend capabilities are unsupported: [queue_sync_unsupported] sync.',
            'created_at' => $now->copy()
                ->subSeconds(150),
        ]);

        // Newer dispatch failure — counted but must not win the "oldest at".
        $this->createTask($run, '01JDSPFAILTASK000000000002', TaskStatus::Ready->value, [
            'available_at' => $now->copy()
                ->subSeconds(30),
            'last_dispatched_at' => null,
            'last_dispatch_attempt_at' => $now->copy()
                ->subSeconds(15),
            'last_dispatch_error' => 'Connection refused while broadcasting workflow task wake.',
            'created_at' => $now->copy()
                ->subSeconds(30),
        ]);

        // Healthy ready task — not counted, and its created_at must not win.
        $this->createTask($run, '01JDSPFAILTASK000000000003', TaskStatus::Ready->value, [
            'available_at' => $now->copy()
                ->subSecond(),
            'last_dispatched_at' => $now->copy()
                ->subSecond(),
            'created_at' => $now->copy()
                ->subSeconds(200),
        ]);

        // Older dispatch error superseded by a later successful dispatch —
        // excluded because applyDispatchFailed requires the failed attempt
        // to have happened after the most recent successful dispatch.
        $this->createTask($run, '01JDSPFAILTASK000000000004', TaskStatus::Ready->value, [
            'available_at' => $now->copy()
                ->subSeconds(360),
            'last_dispatch_attempt_at' => $now->copy()
                ->subSeconds(300),
            'last_dispatch_error' => 'Earlier dispatch attempt failed before redelivery.',
            'last_dispatched_at' => $now->copy()
                ->subSeconds(100),
            'created_at' => $now->copy()
                ->subSeconds(360),
        ]);

        // Dispatch error cleared (empty string) — excluded by applyDispatchFailed.
        $this->createTask($run, '01JDSPFAILTASK000000000005', TaskStatus::Ready->value, [
            'available_at' => $now->copy()
                ->subSeconds(60),
            'last_dispatched_at' => null,
            'last_dispatch_attempt_at' => $now->copy()
                ->subSeconds(300),
            'last_dispatch_error' => '',
            'created_at' => $now->copy()
                ->subSeconds(60),
        ]);

        // Leased task with an older last_dispatch_attempt_at — excluded
        // because applyDispatchFailed requires status=Ready.
        $this->createTask($run, '01JDSPFAILTASK000000000006', TaskStatus::Leased->value, [
            'available_at' => $now->copy()
                ->subSeconds(60),
            'leased_at' => $now->copy()
                ->subSeconds(5),
            'lease_owner' => 'worker-leased',
            'lease_expires_at' => $now->copy()
                ->addSeconds(10),
            'last_dispatch_attempt_at' => $now->copy()
                ->subSeconds(400),
            'last_dispatch_error' => 'Previous dispatch attempt failed before lease grant.',
            'created_at' => $now->copy()
                ->subSeconds(60),
        ]);

        $snapshot = OperatorMetrics::snapshot($now);

        $expectedOldestDispatchFailedAt = $now->copy()
            ->subSeconds(90)
            ->toJSON();

        $this->assertSame(2, $snapshot['tasks']['dispatch_failed']);
        $this->assertSame($expectedOldestDispatchFailedAt, $snapshot['tasks']['oldest_dispatch_failed_at']);
        $this->assertSame(90 * 1000, $snapshot['tasks']['max_dispatch_failed_age_ms']);

        $healthSnapshot = HealthCheck::snapshot($now);
        $taskTransport = collect($healthSnapshot['checks'])->firstWhere('name', 'task_transport');
        $this->assertNotNull($taskTransport);
        $this->assertSame(2, $taskTransport['data']['dispatch_failed_tasks']);
        $this->assertSame($expectedOldestDispatchFailedAt, $taskTransport['data']['oldest_dispatch_failed_at']);
        $this->assertSame(90 * 1000, $taskTransport['data']['max_dispatch_failed_age_ms']);
    }

    public function testSnapshotReportsDispatchFailedAgeAsZeroWhenNoTasksFailedToDispatch(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $now = Carbon::now();

        $run = $this->createRunWithSummary(
            instanceId: 'dispatch-failed-none-instance',
            runId: '01JDSPFNONRUN0000000000001',
            status: 'running',
            statusBucket: 'running',
            livenessState: 'running',
        );

        // Fresh healthy ready task — never failed to dispatch.
        $this->createTask($run, '01JDSPFNONTASK000000000001', TaskStatus::Ready->value, [
            'available_at' => $now->copy()
                ->subSecond(),
            'last_dispatched_at' => $now->copy()
                ->subSecond(),
            'created_at' => $now->copy()
                ->subSecond(),
        ]);

        $snapshot = OperatorMetrics::snapshot($now);

        $this->assertSame(0, $snapshot['tasks']['dispatch_failed']);
        $this->assertNull($snapshot['tasks']['oldest_dispatch_failed_at']);
        $this->assertSame(0, $snapshot['tasks']['max_dispatch_failed_age_ms']);

        $healthSnapshot = HealthCheck::snapshot($now);
        $taskTransport = collect($healthSnapshot['checks'])->firstWhere('name', 'task_transport');
        $this->assertNotNull($taskTransport);
        $this->assertSame(0, $taskTransport['data']['dispatch_failed_tasks']);
        $this->assertNull($taskTransport['data']['oldest_dispatch_failed_at']);
        $this->assertSame(0, $taskTransport['data']['max_dispatch_failed_age_ms']);
    }

    public function testSnapshotSurfacesUnhealthyAgeRollupAsEarliestOfTheFourContributingPaths(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $now = Carbon::now();

        $run = $this->createRunWithSummary(
            instanceId: 'unhealthy-age-rollup-instance',
            runId: '01JUNHEALRUN00000000000001',
            status: 'running',
            statusBucket: 'running',
            livenessState: 'running',
        );

        // Lease-expired task (-30s) — newer than the dispatch-failed worst case below.
        $this->createTask($run, '01JUNHEALTASK0000000000001', TaskStatus::Leased->value, [
            'leased_at' => $now->copy()
                ->subSeconds(120),
            'lease_owner' => 'worker-expired',
            'lease_expires_at' => $now->copy()
                ->subSeconds(30),
            'created_at' => $now->copy()
                ->subSeconds(120),
        ]);

        // Claim-failed task (-45s) — newer than the dispatch-failed worst case below.
        $this->createTask($run, '01JUNHEALTASK0000000000002', TaskStatus::Ready->value, [
            'available_at' => $now->copy()
                ->subSecond(),
            'connection' => 'sync',
            'last_dispatched_at' => $now->copy()
                ->subSeconds(60),
            'last_claim_failed_at' => $now->copy()
                ->subSeconds(45),
            'last_claim_error' => 'Workflow v2 backend capabilities are unsupported: [queue_sync_unsupported] sync.',
            'created_at' => $now->copy()
                ->subSeconds(60),
        ]);

        // Dispatch-overdue task (-20s) — newer than the dispatch-failed worst case below.
        $this->createTask($run, '01JUNHEALTASK0000000000003', TaskStatus::Ready->value, [
            'available_at' => $now->copy()
                ->subSeconds(20),
            'last_dispatched_at' => $now->copy()
                ->subSeconds(20),
            'created_at' => $now->copy()
                ->subSeconds(20),
        ]);

        // Dispatch-failed task (-90s) — the worst case across all four paths.
        $this->createTask($run, '01JUNHEALTASK0000000000004', TaskStatus::Ready->value, [
            'available_at' => $now->copy()
                ->subSeconds(120),
            'last_dispatched_at' => null,
            'last_dispatch_attempt_at' => $now->copy()
                ->subSeconds(90),
            'last_dispatch_error' => 'Connection refused while broadcasting workflow task wake.',
            'created_at' => $now->copy()
                ->subSeconds(150),
        ]);

        $snapshot = OperatorMetrics::snapshot($now);

        $this->assertSame(4, $snapshot['tasks']['unhealthy']);
        $this->assertSame($now->copy() ->subSeconds(90) ->toJSON(), $snapshot['tasks']['oldest_unhealthy_at']);
        $this->assertSame(90 * 1000, $snapshot['tasks']['max_unhealthy_age_ms']);
    }

    public function testSnapshotReportsUnhealthyAgeRollupAsZeroWhenNoTasksAreUnhealthy(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $now = Carbon::now();

        $run = $this->createRunWithSummary(
            instanceId: 'unhealthy-age-none-instance',
            runId: '01JUNHEALNONRUN00000000001',
            status: 'running',
            statusBucket: 'running',
            livenessState: 'running',
        );

        // Fresh healthy ready task — no transport failure, no expired lease.
        $this->createTask($run, '01JUNHEALNONTASK0000000001', TaskStatus::Ready->value, [
            'available_at' => $now->copy()
                ->subSecond(),
            'last_dispatched_at' => $now->copy()
                ->subSecond(),
            'created_at' => $now->copy()
                ->subSecond(),
        ]);

        $snapshot = OperatorMetrics::snapshot($now);

        $this->assertSame(0, $snapshot['tasks']['unhealthy']);
        $this->assertNull($snapshot['tasks']['oldest_unhealthy_at']);
        $this->assertSame(0, $snapshot['tasks']['max_unhealthy_age_ms']);
    }

    public function testSnapshotReportsRunWaitAgeAsZeroWhenNoRunsAreWaiting(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $now = Carbon::now();

        $this->createRunWithSummary(
            instanceId: 'no-wait-instance-active',
            runId: '01JWAITNONERUN00000000001',
            status: 'running',
            statusBucket: 'running',
            livenessState: 'running',
        );

        $snapshot = OperatorMetrics::snapshot($now);

        $this->assertSame(0, $snapshot['runs']['waiting']);
        $this->assertNull($snapshot['runs']['oldest_wait_started_at']);
        $this->assertSame(0, $snapshot['runs']['max_wait_age_ms']);
    }

    public function testSnapshotSurfacesRetryingActivityAgeFromOldestRetryingActivity(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $now = Carbon::now();

        $run = $this->createRunWithSummary(
            instanceId: 'retrying-activity-instance',
            runId: '01JRETRYACTRUN00000000001',
            status: 'running',
            statusBucket: 'running',
            livenessState: 'running',
        );

        // Worst-case: Pending activity in retry backoff for 90s (started_at 90s ago, attempt_count = 2).
        $this->createActivityExecution($run, '01JRETRYACTEXEC0000000001', [
            'sequence' => 1,
            'status' => ActivityStatus::Pending->value,
            'attempt_count' => 2,
            'started_at' => $now->copy()
                ->subSeconds(90),
        ]);

        // Newer Pending retry — attempt_count > 0, but started 30s ago, so it
        // must not win the "oldest retrying since".
        $this->createActivityExecution($run, '01JRETRYACTEXEC0000000002', [
            'sequence' => 2,
            'status' => ActivityStatus::Pending->value,
            'attempt_count' => 1,
            'started_at' => $now->copy()
                ->subSeconds(30),
        ]);

        // Pending first attempt — attempt_count = 0, NOT counted as retrying
        // even though it has been waiting longer than 90s.
        $this->createActivityExecution($run, '01JRETRYACTEXEC0000000003', [
            'sequence' => 3,
            'status' => ActivityStatus::Pending->value,
            'attempt_count' => 0,
            'started_at' => $now->copy()
                ->subSeconds(120),
        ]);

        // Running attempt — `retrying` predicate excludes Running so this is
        // not counted, even with attempt_count > 0.
        $this->createActivityExecution($run, '01JRETRYACTEXEC0000000004', [
            'sequence' => 4,
            'status' => ActivityStatus::Running->value,
            'attempt_count' => 3,
            'started_at' => $now->copy()
                ->subSeconds(150),
        ]);

        // Closed activity — Completed executions are not retrying.
        $this->createActivityExecution($run, '01JRETRYACTEXEC0000000005', [
            'sequence' => 5,
            'status' => ActivityStatus::Completed->value,
            'attempt_count' => 4,
            'started_at' => $now->copy()
                ->subSeconds(300),
            'closed_at' => $now->copy()
                ->subSeconds(60),
        ]);

        $snapshot = OperatorMetrics::snapshot($now);

        $expectedOldestRetryingStartedAt = $now->copy()
            ->subSeconds(90)
            ->toJSON();

        $this->assertSame(2, $snapshot['activities']['retrying']);
        $this->assertSame($expectedOldestRetryingStartedAt, $snapshot['activities']['oldest_retrying_started_at']);
        $this->assertSame(90 * 1000, $snapshot['activities']['max_retrying_age_ms']);
    }

    public function testSnapshotReportsRetryingActivityAgeAsZeroWhenNoActivitiesAreRetrying(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $now = Carbon::now();

        $run = $this->createRunWithSummary(
            instanceId: 'retrying-none-instance',
            runId: '01JRETRYNONERUN0000000001',
            status: 'running',
            statusBucket: 'running',
            livenessState: 'running',
        );

        // Pending first attempt — attempt_count = 0, not retrying.
        $this->createActivityExecution($run, '01JRETRYNONEEXEC000000001', [
            'sequence' => 1,
            'status' => ActivityStatus::Pending->value,
            'attempt_count' => 0,
            'started_at' => null,
        ]);

        // Running attempt — `retrying` predicate excludes Running.
        $this->createActivityExecution($run, '01JRETRYNONEEXEC000000002', [
            'sequence' => 2,
            'status' => ActivityStatus::Running->value,
            'attempt_count' => 2,
            'started_at' => $now->copy()
                ->subSeconds(45),
        ]);

        $snapshot = OperatorMetrics::snapshot($now);

        $this->assertSame(0, $snapshot['activities']['retrying']);
        $this->assertNull($snapshot['activities']['oldest_retrying_started_at']);
        $this->assertSame(0, $snapshot['activities']['max_retrying_age_ms']);
    }

    public function testSnapshotSurfacesMissingRunSummaryProjectionAgeFromOldestMissingRun(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $now = Carbon::now();

        // Worst-case: run with a summary already exists (not counted). Its
        // started_at must NOT win the "oldest missing run" selection even
        // though it is the oldest started_at overall.
        $this->createRunWithSummary(
            instanceId: 'missing-summary-healthy-i',
            runId: '01JMISSUMHEALTHYRUN000001',
            status: 'running',
            statusBucket: 'running',
            livenessState: 'running',
        );

        // Missing-summary run A — started 180s ago, created 200s ago.
        // Oldest started_at among missing runs — wins the selection.
        $oldestMissingInstance = WorkflowInstance::query()->create([
            'id' => 'missing-summary-oldest-i',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        WorkflowRun::query()->create([
            'id' => '01JMISSUMOLDESTRUN000001',
            'workflow_instance_id' => $oldestMissingInstance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'running',
            'started_at' => $now->copy()
                ->subSeconds(180),
            'created_at' => $now->copy()
                ->subSeconds(200),
            'updated_at' => $now->copy()
                ->subSeconds(180),
        ]);

        // Missing-summary run B — started 30s ago, counted but must not
        // win the "oldest at" selection.
        $newerMissingInstance = WorkflowInstance::query()->create([
            'id' => 'missing-summary-newer-i',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        WorkflowRun::query()->create([
            'id' => '01JMISSUMNEWERRUN000001',
            'workflow_instance_id' => $newerMissingInstance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'running',
            'started_at' => $now->copy()
                ->subSeconds(30),
            'created_at' => $now->copy()
                ->subSeconds(30),
            'updated_at' => $now->copy()
                ->subSeconds(30),
        ]);

        // Missing-summary run C — started_at NULL, created 240s ago.
        // Falls back to created_at for the age signal, but the 180s started
        // A is still the oldest because 180s < 240s is false... wait: 240s
        // IS older than 180s, so C must win the selection via the
        // COALESCE(started_at, created_at) fallback.
        $nullStartedInstance = WorkflowInstance::query()->create([
            'id' => 'missing-summary-null-started-i',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        WorkflowRun::query()->create([
            'id' => '01JMISSUMNULLRUN00000001',
            'workflow_instance_id' => $nullStartedInstance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'pending',
            'started_at' => null,
            'created_at' => $now->copy()
                ->subSeconds(240),
            'updated_at' => $now->copy()
                ->subSeconds(240),
        ]);

        $snapshot = OperatorMetrics::snapshot($now);

        $expectedOldestMissingAt = $now->copy()
            ->subSeconds(240)
            ->toJSON();

        $this->assertSame(3, $snapshot['projections']['run_summaries']['missing']);
        $this->assertSame(
            $expectedOldestMissingAt,
            $snapshot['projections']['run_summaries']['oldest_missing_run_started_at'],
        );
        $this->assertSame(240 * 1000, $snapshot['projections']['run_summaries']['max_missing_run_age_ms']);
    }

    public function testSnapshotReportsMissingRunSummaryProjectionAgeAsZeroWhenNoRunsAreMissing(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $now = Carbon::now();

        $this->createRunWithSummary(
            instanceId: 'no-missing-summary-i',
            runId: '01JNOMISSUMRUN0000000001',
            status: 'running',
            statusBucket: 'running',
            livenessState: 'running',
        );

        $snapshot = OperatorMetrics::snapshot($now);

        $this->assertSame(0, $snapshot['projections']['run_summaries']['missing']);
        $this->assertNull($snapshot['projections']['run_summaries']['oldest_missing_run_started_at']);
        $this->assertSame(0, $snapshot['projections']['run_summaries']['max_missing_run_age_ms']);
    }

    public function testSnapshotSurfacesBackendSeverityRollupAsErrorWhenAdmissionIsUnsupported(): void
    {
        config()->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');

        $snapshot = OperatorMetrics::snapshot();

        $this->assertFalse($snapshot['backend']['supported']);
        $this->assertSame('error', $snapshot['backend']['severity']);
        $this->assertNotEmpty($snapshot['backend']['issues']);
        $this->assertContains('error', array_column($snapshot['backend']['issues'], 'severity'));
    }

    public function testSnapshotReportsInWorkerMatchingRoleShapeByDefault(): void
    {
        config()->set('workflows.v2.matching_role.queue_wake_enabled', true);
        config()
            ->set('workflows.v2.task_dispatch_mode', 'queue');

        $snapshot = OperatorMetrics::snapshot();

        $this->assertArrayHasKey('matching_role', $snapshot);
        $this->assertSame(
            [
                'queue_wake_enabled' => true,
                'shape' => 'in_worker',
                'task_dispatch_mode' => 'queue',
            ],
            $snapshot['matching_role'],
        );
    }

    public function testSnapshotReportsDedicatedMatchingRoleShapeWhenQueueWakeIsDisabled(): void
    {
        config()->set('workflows.v2.matching_role.queue_wake_enabled', false);
        config()
            ->set('workflows.v2.task_dispatch_mode', 'poll');

        $snapshot = OperatorMetrics::snapshot();

        $this->assertSame(
            [
                'queue_wake_enabled' => false,
                'shape' => 'dedicated',
                'task_dispatch_mode' => 'poll',
            ],
            $snapshot['matching_role'],
        );
    }

    public function testSnapshotSurfacesSchedulerRoleHealthWhenNoSchedulesAreOverdue(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $now = Carbon::now();

        $this->createSchedule('sched-future', 'alpha', ScheduleStatus::Active, $now->copy()->addMinute(), 0, 0);
        $this->createSchedule('sched-never-fired', 'alpha', ScheduleStatus::Active, null, 0, 0);

        $snapshot = OperatorMetrics::snapshot($now, 'alpha');

        $this->assertSame(2, $snapshot['schedules']['active']);
        $this->assertSame(0, $snapshot['schedules']['paused']);
        $this->assertSame(0, $snapshot['schedules']['missed']);
        $this->assertNull($snapshot['schedules']['oldest_overdue_at']);
        $this->assertSame(0, $snapshot['schedules']['max_overdue_ms']);
        $this->assertSame(0, $snapshot['schedules']['fires_total']);
        $this->assertSame(0, $snapshot['schedules']['failures_total']);
    }

    private function createSchedule(
        string $scheduleId,
        string $namespace,
        ScheduleStatus $status,
        ?Carbon $nextFireAt,
        int $firesCount,
        int $failuresCount,
    ): WorkflowSchedule {
        /** @var WorkflowSchedule $schedule */
        $schedule = WorkflowSchedule::query()->create([
            'schedule_id' => $scheduleId,
            'namespace' => $namespace,
            'spec' => [
                'cron_expressions' => ['0 * * * *'],
                'timezone' => 'UTC',
            ],
            'action' => [
                'workflow_type' => 'test-scheduled-workflow',
                'workflow_class' => 'App\\TestWorkflow',
            ],
            'status' => $status->value,
            'overlap_policy' => 'skip',
            'jitter_seconds' => 0,
            'fires_count' => $firesCount,
            'failures_count' => $failuresCount,
            'next_fire_at' => $nextFireAt,
            'deleted_at' => $status === ScheduleStatus::Deleted ? Carbon::now() : null,
            'paused_at' => $status === ScheduleStatus::Paused ? Carbon::now() : null,
        ]);

        return $schedule;
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
            'projection_schema_version' => RunSummaryProjector::SCHEMA_VERSION,
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
    private function createActivityExecution(WorkflowRun $run, string $id, array $attributes = []): ActivityExecution
    {
        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create(array_merge([
            'id' => $id,
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'WorkflowActivityClass',
            'activity_type' => 'workflow.activity.test',
            'status' => ActivityStatus::Pending->value,
            'connection' => 'redis',
            'queue' => 'default',
            'attempt_count' => 0,
        ], $attributes));

        return $execution;
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
