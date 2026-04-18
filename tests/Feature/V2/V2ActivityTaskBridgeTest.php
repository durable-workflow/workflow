<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultActivityTaskBridge;

final class V2ActivityTaskBridgeTest extends TestCase
{
    private ActivityTaskBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        $this->bridge = $this->app->make(ActivityTaskBridge::class);
    }

    public function testBridgeIsResolvableFromContainer(): void
    {
        $bridge = $this->app->make(ActivityTaskBridge::class);

        $this->assertInstanceOf(DefaultActivityTaskBridge::class, $bridge);
    }

    public function testPollReturnsReadyActivityTasks(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $results = $this->bridge->poll('redis', 'default');

        $this->assertCount(1, $results);
        $this->assertSame($task->id, $results[0]['task_id']);
        $this->assertSame($run->id, $results[0]['workflow_run_id']);
        $this->assertSame($execution->id, $results[0]['activity_execution_id']);
        $this->assertSame('redis', $results[0]['connection']);
        $this->assertSame('default', $results[0]['queue']);
    }

    public function testPollExcludesNonActivityTasks(): void
    {
        $run = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $results = $this->bridge->poll('redis', 'default');

        $this->assertCount(0, $results);
    }

    public function testPollExcludesFutureAvailableTasks(): void
    {
        [$run, $execution] = $this->createActivityExecution();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->addMinutes(5),
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $results = $this->bridge->poll('redis', 'default');

        $this->assertCount(0, $results);
    }

    public function testPollFiltersByQueue(): void
    {
        $this->createActivityTask();

        $results = $this->bridge->poll('redis', 'other-queue');

        $this->assertCount(0, $results);
    }

    public function testPollFiltersByCompatibility(): void
    {
        $this->createActivityTask([
            'compatibility' => 'build-a',
        ]);

        $results = $this->bridge->poll(null, null, 1, 'build-b');

        $this->assertCount(0, $results);
    }

    public function testPollWithNullFiltersReturnsAllReadyTasks(): void
    {
        $this->createActivityTask();

        $results = $this->bridge->poll(null, null);

        $this->assertCount(1, $results);
    }

    public function testPollRespectsLimit(): void
    {
        $this->createActivityTask();
        $this->createActivityTask();
        $this->createActivityTask();

        $results = $this->bridge->poll(null, null, 2);

        $this->assertCount(2, $results);
    }

    public function testClaimStatusClaimsReadyTask(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $result = $this->bridge->claimStatus($task->id, 'server-worker-1');

        $this->assertTrue($result['claimed']);
        $this->assertSame($task->id, $result['task_id']);
        $this->assertSame($run->id, $result['workflow_run_id']);
        $this->assertSame($execution->id, $result['activity_execution_id']);
        $this->assertNotNull($result['activity_attempt_id']);
        $this->assertSame(1, $result['attempt_number']);
        $this->assertSame('server-worker-1', $result['lease_owner']);
        $this->assertNotNull($result['lease_expires_at']);
        $this->assertNull($result['reason']);
    }

    public function testClaimStatusRejectsNonExistentTask(): void
    {
        $result = $this->bridge->claimStatus('nonexistent-task-id');

        $this->assertFalse($result['claimed']);
        $this->assertNotNull($result['reason']);
    }

    public function testClaimStatusRejectsAlreadyLeasedTask(): void
    {
        [$run, $execution, $task] = $this->createActivityTask([
            'status' => TaskStatus::Leased->value,
            'lease_owner' => 'other-worker',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);

        $result = $this->bridge->claimStatus($task->id);

        $this->assertFalse($result['claimed']);
    }

    public function testClaimReturnsNullOnFailure(): void
    {
        $result = $this->bridge->claim('nonexistent-task-id');

        $this->assertNull($result);
    }

    public function testClaimReturnsPayloadOnSuccess(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $result = $this->bridge->claim($task->id, 'server-worker-1');

        $this->assertNotNull($result);
        $this->assertSame($task->id, $result['task_id']);
        $this->assertSame($run->id, $result['workflow_run_id']);
        $this->assertSame($execution->id, $result['activity_execution_id']);
        $this->assertNotNull($result['activity_attempt_id']);
        $this->assertSame(1, $result['attempt_number']);
        $this->assertSame('server-worker-1', $result['lease_owner']);
        $this->assertArrayNotHasKey('reason', $result);
    }

    public function testCompleteRecordsActivityResult(): void
    {
        Queue::fake();

        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $result = $this->bridge->complete($claim['activity_attempt_id'], 'Hello, World!');

        $this->assertTrue($result['recorded']);
        $this->assertNull($result['reason']);

        $this->assertIsString($result['next_task_id']);

        /** @var WorkflowTask $resumeTask */
        $resumeTask = WorkflowTask::query()->findOrFail($result['next_task_id']);

        $this->assertSame('activity', $resumeTask->payload['workflow_wait_kind'] ?? null);
        $this->assertSame(sprintf('activity:%s', $execution->id), $resumeTask->payload['open_wait_id'] ?? null);
        $this->assertSame('activity_execution', $resumeTask->payload['resume_source_kind'] ?? null);
        $this->assertSame($execution->id, $resumeTask->payload['resume_source_id'] ?? null);
        $this->assertSame($execution->id, $resumeTask->payload['activity_execution_id'] ?? null);
        $this->assertSame($claim['activity_attempt_id'], $resumeTask->payload['activity_attempt_id'] ?? null);
        $this->assertSame('test-greeting-activity', $resumeTask->payload['activity_type'] ?? null);
        $this->assertSame(1, $resumeTask->payload['workflow_sequence'] ?? null);
        $this->assertSame(
            HistoryEventType::ActivityCompleted->value,
            $resumeTask->payload['workflow_event_type'] ?? null
        );
    }

    public function testCompleteRejectsUnknownAttempt(): void
    {
        $result = $this->bridge->complete('nonexistent-attempt', 'result');

        $this->assertFalse($result['recorded']);
    }

    public function testFailRecordsActivityFailure(): void
    {
        Queue::fake();

        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $result = $this->bridge->fail($claim['activity_attempt_id'], 'Something went wrong');

        $this->assertTrue($result['recorded']);
        $this->assertIsString($result['next_task_id']);

        /** @var WorkflowTask $resumeTask */
        $resumeTask = WorkflowTask::query()->findOrFail($result['next_task_id']);

        $this->assertSame('activity', $resumeTask->payload['workflow_wait_kind'] ?? null);
        $this->assertSame(sprintf('activity:%s', $execution->id), $resumeTask->payload['open_wait_id'] ?? null);
        $this->assertSame('activity_execution', $resumeTask->payload['resume_source_kind'] ?? null);
        $this->assertSame($execution->id, $resumeTask->payload['resume_source_id'] ?? null);
        $this->assertSame($execution->id, $resumeTask->payload['activity_execution_id'] ?? null);
        $this->assertSame($claim['activity_attempt_id'], $resumeTask->payload['activity_attempt_id'] ?? null);
        $this->assertSame('test-greeting-activity', $resumeTask->payload['activity_type'] ?? null);
        $this->assertSame(1, $resumeTask->payload['workflow_sequence'] ?? null);
        $this->assertSame(
            HistoryEventType::ActivityFailed->value,
            $resumeTask->payload['workflow_event_type'] ?? null
        );
    }

    public function testFailAcceptsArrayPayload(): void
    {
        Queue::fake();

        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $result = $this->bridge->fail($claim['activity_attempt_id'], [
            'message' => 'External failure',
            'class' => \RuntimeException::class,
        ]);

        $this->assertTrue($result['recorded']);
    }

    public function testCompleteAfterCancelledRunClosesAttemptAndReportsIgnoredOutcome(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $run->forceFill([
            'status' => RunStatus::Cancelled->value,
        ])->save();

        $result = $this->bridge->complete($claim['activity_attempt_id'], 'too late');

        $this->assertFalse($result['recorded']);
        $this->assertSame('run_cancelled', $result['reason']);
        $this->assertNull($result['next_task_id']);

        /** @var ActivityExecution $execution */
        $execution = $execution->fresh();
        /** @var WorkflowTask $task */
        $task = $task->fresh();
        /** @var ActivityAttempt $attempt */
        $attempt = ActivityAttempt::query()->findOrFail($claim['activity_attempt_id']);

        $this->assertSame(ActivityStatus::Cancelled, $execution->status);
        $this->assertSame(ActivityAttemptStatus::Cancelled, $attempt->status);
        $this->assertSame(TaskStatus::Cancelled, $task->status);

        $this->assertDatabaseHas((new WorkflowHistoryEvent())->getTable(), [
            'workflow_run_id' => $run->id,
            'event_type' => HistoryEventType::ActivityCancelled->value,
        ]);
    }

    public function testFailAfterTerminatedRunClosesAttemptAndReportsIgnoredOutcome(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $run->forceFill([
            'status' => RunStatus::Terminated->value,
        ])->save();

        $result = $this->bridge->fail($claim['activity_attempt_id'], [
            'message' => 'too late',
            'type' => 'ExternalError',
        ]);

        $this->assertFalse($result['recorded']);
        $this->assertSame('run_terminated', $result['reason']);
        $this->assertNull($result['next_task_id']);

        /** @var ActivityExecution $execution */
        $execution = $execution->fresh();
        /** @var WorkflowTask $task */
        $task = $task->fresh();
        /** @var ActivityAttempt $attempt */
        $attempt = ActivityAttempt::query()->findOrFail($claim['activity_attempt_id']);

        $this->assertSame(ActivityStatus::Cancelled, $execution->status);
        $this->assertSame(ActivityAttemptStatus::Cancelled, $attempt->status);
        $this->assertSame(TaskStatus::Cancelled, $task->status);

        $this->assertDatabaseHas((new WorkflowHistoryEvent())->getTable(), [
            'workflow_run_id' => $run->id,
            'event_type' => HistoryEventType::ActivityCancelled->value,
        ]);
    }

    public function testStatusReturnsAttemptState(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $status = $this->bridge->status($claim['activity_attempt_id']);

        $this->assertTrue($status['can_continue']);
        $this->assertFalse($status['cancel_requested']);
        $this->assertNull($status['reason']);
        $this->assertFalse($status['heartbeat_recorded']);
        $this->assertSame($claim['activity_attempt_id'], $status['activity_attempt_id']);
        $this->assertSame($run->id, $status['workflow_run_id']);
    }

    public function testStatusRejectsUnknownAttempt(): void
    {
        $status = $this->bridge->status('nonexistent-attempt');

        $this->assertFalse($status['can_continue']);
        $this->assertSame('attempt_not_found', $status['reason']);
    }

    public function testHeartbeatRenewsLeaseAndRecords(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $result = $this->bridge->heartbeat($claim['activity_attempt_id']);

        $this->assertTrue($result['can_continue']);
        $this->assertFalse($result['cancel_requested']);
        $this->assertTrue($result['heartbeat_recorded']);
        $this->assertNotNull($result['last_heartbeat_at']);
        $this->assertNotNull($result['lease_expires_at']);
    }

    public function testHeartbeatWithProgress(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $result = $this->bridge->heartbeat($claim['activity_attempt_id'], [
            'current' => 50,
            'total' => 100,
            'message' => 'Halfway done',
        ]);

        $this->assertTrue($result['can_continue']);
        $this->assertTrue($result['heartbeat_recorded']);
    }

    public function testHeartbeatDetectsCancelledRun(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $run->forceFill([
            'status' => RunStatus::Cancelled->value,
        ])->save();

        $result = $this->bridge->heartbeat($claim['activity_attempt_id']);

        $this->assertFalse($result['can_continue']);
        $this->assertTrue($result['cancel_requested']);
    }

    public function testHeartbeatDetectsTerminatedRun(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $run->forceFill([
            'status' => RunStatus::Terminated->value,
        ])->save();

        $result = $this->bridge->heartbeat($claim['activity_attempt_id']);

        $this->assertFalse($result['can_continue']);
        $this->assertTrue($result['cancel_requested']);
    }

    // -- Helpers --

    /**
     * @param array<string, mixed> $taskOverrides
     * @return array{0: WorkflowRun, 1: ActivityExecution, 2: WorkflowTask}
     */
    private function createActivityTask(array $taskOverrides = []): array
    {
        [$run, $execution] = $this->createActivityExecution();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create(array_merge([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
            'connection' => 'redis',
            'queue' => 'default',
            'attempt_count' => 0,
        ], $taskOverrides));

        return [$run, $execution, $task];
    }

    /**
     * @return array{0: WorkflowRun, 1: ActivityExecution}
     */
    private function createActivityExecution(): array
    {
        $run = $this->createWaitingRun();

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => 'test-greeting-activity',
            'sequence' => 1,
            'status' => ActivityStatus::Pending->value,
            'arguments' => Serializer::serialize(['World']),
            'connection' => 'redis',
            'queue' => 'default',
            'attempt_count' => 0,
            'started_at' => now(),
        ]);

        return [$run, $execution];
    }

    private function createWaitingRun(): WorkflowRun
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }
}
