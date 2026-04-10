<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Tests\TestCase;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\OperatorMetrics;
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
        $this->assertSame(120000, $repairScopes->get('redis:default:any')['max_task_candidate_age_ms']);
        $this->assertSame(1, $repairScopes->get('sync:default:any')['existing_task_candidates']);
        $this->assertTrue($repairScopes->contains(
            static fn (array $scope): bool => $scope['missing_task_candidates'] === 1
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
        $this->assertFalse(TaskRepairPolicy::dispatchOverdue($claimFailedTask->fresh()));
        $this->assertTrue(TaskRepairPolicy::readyTaskNeedsRedispatch($claimFailedTask->fresh()));
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
