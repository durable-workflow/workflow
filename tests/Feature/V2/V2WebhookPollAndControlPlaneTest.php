<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Webhooks;

final class V2WebhookPollAndControlPlaneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'workflows.webhook_auth.method' => 'none',
            'workflows.v2.compatibility.current' => 'build-a',
            'workflows.v2.compatibility.supported' => ['build-a'],
            'workflows.v2.types.workflows' => [
                'test-greeting-workflow' => TestGreetingWorkflow::class,
            ],
        ]);

        Queue::fake();

        Webhooks::routes([
            TestGreetingWorkflow::class,
        ]);
    }

    // ── Workflow task poll ────────────────────────────────────────────

    public function testWorkflowTaskPollReturnsEmptyWhenNoReadyTasks(): void
    {
        $response = $this->getJson('/webhooks/workflow-tasks/poll');

        $response->assertOk();
        $response->assertJsonPath('tasks', []);
    }

    public function testWorkflowTaskPollReturnsReadyWorkflowTasks(): void
    {
        $run = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $response = $this->getJson('/webhooks/workflow-tasks/poll?connection=redis&queue=default');

        $response->assertOk();
        $response->assertJsonCount(1, 'tasks');
        $response->assertJsonPath('tasks.0.workflow_run_id', $run->id);
        $response->assertJsonPath('tasks.0.workflow_type', 'test-greeting-workflow');
        $response->assertJsonPath('tasks.0.connection', 'redis');
        $response->assertJsonPath('tasks.0.queue', 'default');
    }

    public function testWorkflowTaskPollRespectsCompatibilityFilter(): void
    {
        $run = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $response = $this->getJson('/webhooks/workflow-tasks/poll?compatibility=build-b');

        $response->assertOk();
        $response->assertJsonCount(0, 'tasks');
    }

    public function testWorkflowTaskPollRespectsLimitParameter(): void
    {
        $run = $this->createWaitingRun();

        for ($i = 0; $i < 3; $i++) {
            WorkflowTask::query()->create([
                'workflow_run_id' => $run->id,
                'task_type' => TaskType::Workflow->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => now()->subSecond(),
                'payload' => [],
                'connection' => 'redis',
                'queue' => 'default',
            ]);
        }

        $response = $this->getJson('/webhooks/workflow-tasks/poll?limit=2');

        $response->assertOk();
        $response->assertJsonCount(2, 'tasks');
    }

    public function testWorkflowTaskPollExcludesActivityTasks(): void
    {
        $run = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $response = $this->getJson('/webhooks/workflow-tasks/poll');

        $response->assertOk();
        $response->assertJsonCount(0, 'tasks');
    }

    // ── Activity task poll ───────────────────────────────────────────

    public function testActivityTaskPollReturnsEmptyWhenNoReadyTasks(): void
    {
        $response = $this->getJson('/webhooks/activity-tasks/poll');

        $response->assertOk();
        $response->assertJsonPath('tasks', []);
    }

    public function testActivityTaskPollReturnsReadyActivityTasks(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $response = $this->getJson('/webhooks/activity-tasks/poll?connection=redis&queue=default');

        $response->assertOk();
        $response->assertJsonCount(1, 'tasks');
        $response->assertJsonPath('tasks.0.task_id', $task->id);
        $response->assertJsonPath('tasks.0.workflow_run_id', $run->id);
        $response->assertJsonPath('tasks.0.activity_execution_id', $execution->id);
    }

    public function testActivityTaskPollExcludesWorkflowTasks(): void
    {
        $run = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $response = $this->getJson('/webhooks/activity-tasks/poll');

        $response->assertOk();
        $response->assertJsonCount(0, 'tasks');
    }

    // ── Control-plane start ──────────────────────────────────────────

    public function testControlPlaneStartCreatesWorkflow(): void
    {
        $response = $this->postJson('/webhooks/control-plane/start', [
            'workflow_type' => 'test-greeting-workflow',
            'instance_id' => 'cp-start-1',
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('started', true);
        $response->assertJsonPath('workflow_instance_id', 'cp-start-1');
        $response->assertJsonPath('workflow_type', 'test-greeting-workflow');
        $response->assertJsonPath('outcome', 'started_new');
        $this->assertNotNull($response->json('workflow_run_id'));
        $this->assertNotNull($response->json('task_id'));
    }

    public function testControlPlaneStartRejectsDuplicate(): void
    {
        $this->postJson('/webhooks/control-plane/start', [
            'workflow_type' => 'test-greeting-workflow',
            'instance_id' => 'cp-dup-1',
            'arguments' => Serializer::serialize(['Taylor']),
        ])->assertStatus(202);

        $response = $this->postJson('/webhooks/control-plane/start', [
            'workflow_type' => 'test-greeting-workflow',
            'instance_id' => 'cp-dup-1',
            'arguments' => Serializer::serialize(['Taylor']),
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('started', false);
        $response->assertJsonPath('outcome', 'rejected_duplicate');
    }

    public function testControlPlaneStartReturnsExistingActive(): void
    {
        $this->postJson('/webhooks/control-plane/start', [
            'workflow_type' => 'test-greeting-workflow',
            'instance_id' => 'cp-reuse-1',
            'arguments' => Serializer::serialize(['Taylor']),
        ])->assertStatus(202);

        $response = $this->postJson('/webhooks/control-plane/start', [
            'workflow_type' => 'test-greeting-workflow',
            'instance_id' => 'cp-reuse-1',
            'arguments' => Serializer::serialize(['Taylor']),
            'duplicate_start_policy' => 'return_existing_active',
        ]);

        $response->assertOk();
        $response->assertJsonPath('started', true);
        $response->assertJsonPath('outcome', 'returned_existing_active');
    }

    public function testControlPlaneStartRequiresWorkflowType(): void
    {
        $response = $this->postJson('/webhooks/control-plane/start', [
            'instance_id' => 'cp-bad-1',
        ]);

        $response->assertStatus(422);
    }

    public function testControlPlaneStartRejectsInvalidInstanceId(): void
    {
        $response = $this->postJson('/webhooks/control-plane/start', [
            'workflow_type' => 'test-greeting-workflow',
            'instance_id' => str_repeat('x', 200),
        ]);

        $response->assertStatus(422);
    }

    public function testControlPlaneStartWithVisibilityMetadata(): void
    {
        $response = $this->postJson('/webhooks/control-plane/start', [
            'workflow_type' => 'test-greeting-workflow',
            'instance_id' => 'cp-vis-1',
            'arguments' => Serializer::serialize(['Taylor']),
            'business_key' => 'order-123',
            'labels' => ['tenant' => 'acme'],
            'memo' => ['source' => 'test'],
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('started', true);

        $instance = WorkflowInstance::query()->find('cp-vis-1');
        $this->assertNotNull($instance);
    }

    public function testControlPlaneStartWithAutoGeneratedInstanceId(): void
    {
        $response = $this->postJson('/webhooks/control-plane/start', [
            'workflow_type' => 'test-greeting-workflow',
            'arguments' => Serializer::serialize(['Taylor']),
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('started', true);
        $this->assertNotEmpty($response->json('workflow_instance_id'));
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function createWaitingRun(): WorkflowRun
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
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
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSeconds(30),
        ]);

        $instance->forceFill(['current_run_id' => $run->id])->save();

        return $run;
    }

    /**
     * @return array{0: WorkflowRun, 1: ActivityExecution, 2: WorkflowTask}
     */
    private function createActivityTask(): array
    {
        $run = $this->createWaitingRun();

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'activity_class' => 'Tests\\Fixtures\\V2\\TestGreetingActivity',
            'activity_type' => 'test-greeting-activity',
            'sequence' => 1,
            'status' => ActivityStatus::Pending->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'attempt_count' => 0,
            'started_at' => now(),
        ]);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()->subSecond(),
            'payload' => ['activity_execution_id' => $execution->id],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        return [$run, $execution, $task];
    }
}
