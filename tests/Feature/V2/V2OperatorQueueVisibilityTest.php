<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\Fixtures\V2\TestStandaloneWorkerRegistration;
use Tests\TestCase;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\OperatorQueueVisibility;
use Workflow\V2\Support\StandaloneWorkerVisibility;
use Workflow\V2\Support\WorkerCompatibilityFleet;

final class V2OperatorQueueVisibilityTest extends TestCase
{
    public function testForQueueSummarizesBacklogPollersLeasesAndRepairCandidates(): void
    {
        Carbon::setTestNow('2026-04-16 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        config()->set('workflows.v2.task_repair.redispatch_after_seconds', 7);

        $run = $this->createRun('queue-visibility-instance', '01JQUEUEVISIBLE00000000001', 'default');

        $this->createTask($run, '01JQUEUEVISIBLETASK000001', TaskType::Workflow, TaskStatus::Ready, [
            'available_at' => now()->subSeconds(45),
            'created_at' => now()->subSeconds(50),
        ]);
        $this->createTask($run, '01JQUEUEVISIBLETASK000002', TaskType::Activity, TaskStatus::Ready, [
            'available_at' => now()->subSeconds(20),
            'created_at' => now()->subSeconds(20),
            'last_dispatch_attempt_at' => now()->subSecond(),
            'last_dispatch_error' => 'Queue transport unavailable.',
        ]);
        $expiredWorkflowTask = $this->createTask($run, '01JQUEUEVISIBLETASK000003', TaskType::Workflow, TaskStatus::Leased, [
            'lease_owner' => 'worker-active',
            'lease_expires_at' => now()->subMinute(),
            'attempt_count' => 2,
        ]);
        $activityTask = $this->createTask($run, '01JQUEUEVISIBLETASK000004', TaskType::Activity, TaskStatus::Leased, [
            'lease_owner' => 'worker-active',
            'lease_expires_at' => now()->addMinute(),
        ]);

        ActivityAttempt::query()->create([
            'id' => '01JQUEUEVISIBLEATTEMPT001',
            'workflow_run_id' => $run->id,
            'activity_execution_id' => '01JQUEUEVISIBLEACTEXEC01',
            'workflow_task_id' => $activityTask->id,
            'attempt_number' => 3,
            'status' => ActivityAttemptStatus::Running->value,
            'started_at' => now()->subMinute(),
        ]);

        $detail = OperatorQueueVisibility::forQueue('default', 'critical', [
            [
                'worker_id' => 'worker-stale',
                'runtime' => 'python',
                'sdk_version' => '0.1.0',
                'build_id' => 'build-b',
                'last_heartbeat_at' => now()->subMinutes(5),
                'supported_workflow_types' => ['workflow.test'],
                'supported_activity_types' => ['activity.test'],
                'max_concurrent_workflow_tasks' => 4,
                'max_concurrent_activity_tasks' => 8,
            ],
            [
                'worker_id' => 'worker-active',
                'runtime' => 'php',
                'sdk_version' => '2.0.0',
                'build_id' => 'build-a',
                'last_heartbeat_at' => now(),
                'supported_workflow_types' => ['workflow.test'],
                'supported_activity_types' => [],
                'max_concurrent_workflow_tasks' => 10,
                'max_concurrent_activity_tasks' => 0,
            ],
        ], now(), 60);
        $payload = $detail->toArray();

        $this->assertSame('critical', $payload['name']);
        $this->assertSame(2, $payload['stats']['approximate_backlog_count']);
        $this->assertSame('45s', $payload['stats']['approximate_backlog_age']);
        $this->assertSame(45, $payload['stats']['approximate_backlog_age_seconds']);
        $this->assertSame('queue-visibility-instance', $payload['stats']['oldest_ready_task']['workflow_id']);
        $this->assertSame(1, $payload['stats']['workflow_tasks']['ready_count']);
        $this->assertSame(1, $payload['stats']['workflow_tasks']['leased_count']);
        $this->assertSame(1, $payload['stats']['workflow_tasks']['expired_lease_count']);
        $this->assertSame(1, $payload['stats']['activity_tasks']['ready_count']);
        $this->assertSame(1, $payload['stats']['activity_tasks']['leased_count']);
        $this->assertSame(0, $payload['stats']['activity_tasks']['expired_lease_count']);
        $this->assertSame(1, $payload['stats']['pollers']['active_count']);
        $this->assertSame(1, $payload['stats']['pollers']['stale_count']);
        $this->assertSame(60, $payload['stats']['pollers']['stale_after_seconds']);
        $this->assertSame('worker-active', $payload['pollers'][0]['worker_id']);
        $this->assertFalse($payload['pollers'][0]['is_stale']);
        $this->assertSame('worker-stale', $payload['pollers'][1]['worker_id']);
        $this->assertSame('stale', $payload['pollers'][1]['status']);
        $this->assertSame(3, $payload['repair']['candidates']);
        $this->assertSame(1, $payload['repair']['dispatch_failed']);
        $this->assertSame(1, $payload['repair']['expired_leases']);
        $this->assertSame(1, $payload['repair']['dispatch_overdue']);
        $this->assertTrue($payload['repair']['needs_attention']);
        $this->assertSame(7, $payload['repair']['policy']['redispatch_after_seconds']);
        $this->assertSame($expiredWorkflowTask->id, $payload['current_leases'][0]['task_id']);
        $this->assertSame('workflow', $payload['current_leases'][0]['task_type']);
        $this->assertTrue($payload['current_leases'][0]['is_expired']);
        $this->assertSame(2, $payload['current_leases'][0]['workflow_task_attempt']);
        $this->assertSame($activityTask->id, $payload['current_leases'][1]['task_id']);
        $this->assertSame('activity', $payload['current_leases'][1]['task_type']);
        $this->assertFalse($payload['current_leases'][1]['is_expired']);
        $this->assertSame(3, $payload['current_leases'][1]['attempt_number']);
    }

    public function testForNamespaceListsTaskQueuesFromTasksAndInjectedPollerOnlyQueues(): void
    {
        Carbon::setTestNow('2026-04-16 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $defaultRun = $this->createRun('queue-default-instance', '01JQUEUENSDEFAULT00000001', 'default');
        $otherRun = $this->createRun('queue-other-instance', '01JQUEUENSOTHER000000001', 'other');

        $this->createTask($defaultRun, '01JQUEUENSDEFAULTTASK001', TaskType::Workflow, TaskStatus::Ready, [
            'queue' => 'alpha',
        ]);
        $this->createTask($otherRun, '01JQUEUENSOTHERTASK0001', TaskType::Workflow, TaskStatus::Ready, [
            'queue' => 'beta',
        ]);

        $snapshot = OperatorQueueVisibility::forNamespace('default', [
            'poller-only' => [
                [
                    'worker_id' => 'worker-poller-only',
                    'runtime' => 'python',
                    'last_heartbeat_at' => now(),
                ],
            ],
        ], now(), 60)->toArray();

        $this->assertSame('default', $snapshot['namespace']);
        $this->assertSame(['alpha', 'poller-only'], array_column($snapshot['task_queues'], 'name'));
        $queues = collect($snapshot['task_queues'])->keyBy('name');
        $this->assertSame(1, $queues->get('alpha')['stats']['approximate_backlog_count']);
        $this->assertSame(1, $queues->get('poller-only')['stats']['pollers']['active_count']);
        $this->assertSame(0, $queues->get('poller-only')['stats']['approximate_backlog_count']);
        $this->assertFalse($queues->has('beta'));
    }

    public function testOldestReadyTaskPrefersNullAvailableAtOverScheduledTasks(): void
    {
        Carbon::setTestNow('2026-04-16 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $run = $this->createRun('queue-null-instance', '01JQUEUENULL000000000001', 'default');

        $this->createTask($run, '01JQUEUENULLTASK00000001', TaskType::Workflow, TaskStatus::Ready, [
            'available_at' => now()->subSeconds(90),
            'created_at' => now()->subSeconds(120),
        ]);
        $nullTask = $this->createTask($run, '01JQUEUENULLTASK00000002', TaskType::Activity, TaskStatus::Ready, [
            'available_at' => null,
            'created_at' => now()->subSeconds(30),
        ]);

        $detail = OperatorQueueVisibility::forQueue('default', 'critical', [], now())->toArray();

        $this->assertSame($nullTask->id, $detail['stats']['oldest_ready_task']['task_id']);
        $this->assertSame('activity', $detail['stats']['oldest_ready_task']['task_type']);
        $this->assertSame(30, $detail['stats']['approximate_backlog_age_seconds']);
        $this->assertSame(2, $detail['stats']['approximate_backlog_count']);
    }

    public function testStandaloneWorkerVisibilityBuildsQueueSnapshotFromWorkerRegistrationModel(): void
    {
        Carbon::setTestNow('2026-04-16 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });
        $this->createStandaloneWorkerRegistrationTable();

        TestStandaloneWorkerRegistration::query()->create([
            'worker_id' => 'worker-active',
            'namespace' => 'default',
            'task_queue' => 'external',
            'runtime' => 'python',
            'sdk_version' => '0.3.0',
            'build_id' => 'build-a',
            'supported_workflow_types' => ['workflow.test'],
            'supported_activity_types' => ['activity.test'],
            'max_concurrent_workflow_tasks' => 2,
            'max_concurrent_activity_tasks' => 4,
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);
        TestStandaloneWorkerRegistration::query()->create([
            'worker_id' => 'worker-stale',
            'namespace' => 'default',
            'task_queue' => 'external',
            'runtime' => 'php',
            'sdk_version' => '2.0.0',
            'build_id' => 'build-b',
            'supported_workflow_types' => ['workflow.test'],
            'supported_activity_types' => [],
            'max_concurrent_workflow_tasks' => 1,
            'max_concurrent_activity_tasks' => 0,
            'last_heartbeat_at' => now()->subMinutes(5),
            'status' => 'active',
        ]);
        TestStandaloneWorkerRegistration::query()->create([
            'worker_id' => 'worker-other',
            'namespace' => 'other',
            'task_queue' => 'other-queue',
            'runtime' => 'php',
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);

        $snapshot = StandaloneWorkerVisibility::queueSnapshot(
            'default',
            TestStandaloneWorkerRegistration::class,
            now(),
            60,
        )->toArray();
        $detail = StandaloneWorkerVisibility::queueDetail(
            'default',
            'external',
            TestStandaloneWorkerRegistration::class,
            now(),
            60,
        )->toArray();

        $this->assertSame('default', $snapshot['namespace']);
        $this->assertSame(['external'], array_column($snapshot['task_queues'], 'name'));
        $this->assertSame(1, $snapshot['task_queues'][0]['stats']['pollers']['active_count']);
        $this->assertSame(1, $snapshot['task_queues'][0]['stats']['pollers']['stale_count']);
        $this->assertSame('worker-active', $detail['pollers'][0]['worker_id']);
        $this->assertSame('python', $detail['pollers'][0]['runtime']);
        $this->assertSame('build-a', $detail['pollers'][0]['build_id']);
        $this->assertSame(['workflow.test'], $detail['pollers'][0]['supported_workflow_types']);
        $this->assertFalse($detail['pollers'][0]['is_stale']);
        $this->assertSame('worker-stale', $detail['pollers'][1]['worker_id']);
        $this->assertSame('stale', $detail['pollers'][1]['status']);
        $this->assertTrue($detail['pollers'][1]['is_stale']);
    }

    public function testStandaloneWorkerVisibilityRecordsAndSummarizesNamespaceCompatibility(): void
    {
        Carbon::setTestNow('2026-04-16 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
            WorkerCompatibilityFleet::clear();
        });
        WorkerCompatibilityFleet::clear();
        config()->set('workflows.v2.compatibility.namespace', 'embedded');

        StandaloneWorkerVisibility::recordCompatibility('default', 'worker-a', 'external', 'build-a');
        StandaloneWorkerVisibility::recordCompatibility('other', 'worker-b', 'other-queue', 'build-b');

        $summary = StandaloneWorkerVisibility::fleetSummary('default');
        $details = WorkerCompatibilityFleet::detailsForNamespace('default', 'build-a');

        $this->assertSame('embedded', config('workflows.v2.compatibility.namespace'));
        $this->assertSame('default', $summary['namespace']);
        $this->assertSame(1, $summary['active_workers']);
        $this->assertSame(1, $summary['active_worker_scopes']);
        $this->assertSame(['external'], $summary['queues']);
        $this->assertSame(['build-a'], $summary['build_ids']);
        $this->assertSame('worker-a', $summary['workers'][0]['worker_id']);
        $this->assertSame(['external'], $summary['workers'][0]['queues']);
        $this->assertSame(['build-a'], $summary['workers'][0]['build_ids']);
        $this->assertCount(1, $details);
        $this->assertSame('default', $details[0]['namespace']);
        $this->assertSame('external', $details[0]['queue']);
        $this->assertTrue($details[0]['supports_required']);
        $this->assertSame([], WorkerCompatibilityFleet::detailsForNamespace('missing', 'build-a'));
    }

    private function createRun(string $instanceId, string $runId, string $namespace): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'namespace' => $namespace,
            'run_count' => 1,
            'started_at' => now()->subMinutes(5),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => $runId,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'namespace' => $namespace,
            'status' => 'waiting',
            'started_at' => now()->subMinutes(5),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createTask(
        WorkflowRun $run,
        string $taskId,
        TaskType $taskType,
        TaskStatus $status,
        array $attributes = [],
    ): WorkflowTask {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create(array_merge([
            'id' => $taskId,
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => $taskType->value,
            'status' => $status->value,
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'critical',
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return $task;
    }

    private function createStandaloneWorkerRegistrationTable(): void
    {
        Schema::dropIfExists('test_standalone_worker_registrations');
        Schema::create('test_standalone_worker_registrations', static function (Blueprint $table): void {
            $table->id();
            $table->string('worker_id');
            $table->string('namespace');
            $table->string('task_queue')->nullable();
            $table->string('runtime')->nullable();
            $table->string('sdk_version')->nullable();
            $table->string('build_id')->nullable();
            $table->json('supported_workflow_types')->nullable();
            $table->json('supported_activity_types')->nullable();
            $table->integer('max_concurrent_workflow_tasks')->default(0);
            $table->integer('max_concurrent_activity_tasks')->default(0);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }
}
