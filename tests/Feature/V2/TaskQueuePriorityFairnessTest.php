<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\InMemoryTaskFairnessState;
use Workflow\V2\Support\TaskFairnessKey;
use Workflow\V2\Support\TaskFairnessScheduler;
use Workflow\V2\Support\TaskFairnessState;
use Workflow\V2\Support\TaskPriority;
use Workflow\V2\Webhooks;

/**
 * Contract tests for the priority + fairness dispatch surface.
 *
 * The package owns three pieces of the contract:
 *   1. Bridge `poll()` orders ready tasks by (priority asc, available_at asc, id),
 *      so urgent tasks lead a polling worker's batch.
 *   2. The scheduling-fields helper inherits a run's priority and fairness key
 *      onto child tasks (and lets ActivityOptions override per-call).
 *   3. The fairness scheduler reorders a same-priority batch so dispatch is
 *      rebalanced across distinct fairness classes — preventing a noisy class
 *      from starving the rest under saturation.
 *
 * The wire surface lives in StartOptions and ActivityOptions; this suite asserts
 * normalization there as well so SDKs can rely on the same validation.
 */
final class TaskQueuePriorityFairnessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Each test starts from a fresh fairness counter so dispatch-recording
        // assertions are not contaminated by prior tests in the suite.
        $this->app->instance(TaskFairnessState::class, new InMemoryTaskFairnessState(halfLifeSeconds: 60.0));
    }

    public function testPriorityClampOnStartOptions(): void
    {
        $this->expectException(\LogicException::class);

        TaskPriority::normalize(11);
    }

    public function testFairnessKeyNormalizationLowercasesAndRejectsBadChars(): void
    {
        $this->assertSame('tenant-a', TaskFairnessKey::normalize('Tenant-A'));
        $this->assertNull(TaskFairnessKey::normalize(null));
        $this->assertNull(TaskFairnessKey::normalize('   '));

        $this->expectException(\LogicException::class);
        TaskFairnessKey::normalize('tenant a/spaces');
    }

    public function testWorkflowTaskPollOrdersByPriorityWithinAvailability(): void
    {
        $run = $this->createReadyRun();

        $low = $this->seedTask($run, priority: 9, secondsAgo: 10);
        $high = $this->seedTask($run, priority: 0, secondsAgo: 1);
        $mid = $this->seedTask($run, priority: 5, secondsAgo: 5);

        /** @var WorkflowTaskBridge $bridge */
        $bridge = $this->app->make(WorkflowTaskBridge::class);
        $tasks = $bridge->poll(null, 'default', limit: 10);

        $this->assertSame(
            [$high->id, $mid->id, $low->id],
            array_column($tasks, 'task_id'),
            'Ready tasks must be dispatched lowest-priority-number-first regardless of FIFO age.',
        );

        // Each candidate carries the dispatch-shaping fields so the caller can
        // make scheduling decisions (e.g. the fairness reorder pass) without a
        // second DB hit.
        foreach ($tasks as $entry) {
            $this->assertArrayHasKey('priority', $entry);
            $this->assertArrayHasKey('fairness_key', $entry);
            $this->assertArrayHasKey('fairness_weight', $entry);
        }
    }

    public function testFairnessSchedulerInterleavesDistinctClassesWithinPriorityTier(): void
    {
        $candidates = [];

        // Five candidates from class "alpha" landed first; without a fairness
        // pass the next pollers would drain them all before "beta" gets a turn.
        for ($i = 0; $i < 5; $i++) {
            $candidates[] = [
                'task_id' => sprintf('alpha-%d', $i),
                'priority' => TaskPriority::DEFAULT,
                'fairness_key' => 'alpha',
                'fairness_weight' => 1,
            ];
        }

        for ($i = 0; $i < 5; $i++) {
            $candidates[] = [
                'task_id' => sprintf('beta-%d', $i),
                'priority' => TaskPriority::DEFAULT,
                'fairness_key' => 'beta',
                'fairness_weight' => 1,
            ];
        }

        $scheduler = new TaskFairnessScheduler(new InMemoryTaskFairnessState(halfLifeSeconds: 60.0));
        $reordered = $scheduler->reorder('default', 'default', $candidates);

        $first = array_slice(array_column($reordered, 'task_id'), 0, 4);

        $this->assertSame(
            ['alpha-0', 'beta-0', 'alpha-1', 'beta-1'],
            $first,
            'Same-priority candidates from two fairness classes must alternate so neither class starves.',
        );
    }

    public function testFairnessSchedulerNeverViolatesPriorityOrder(): void
    {
        $candidates = [
            [
                'task_id' => 'low-tenant-a',
                'priority' => 9,
                'fairness_key' => 'tenant-a',
                'fairness_weight' => 1,
            ],
            [
                'task_id' => 'high-tenant-b',
                'priority' => 0,
                'fairness_key' => 'tenant-b',
                'fairness_weight' => 1,
            ],
            [
                'task_id' => 'mid-tenant-a',
                'priority' => 5,
                'fairness_key' => 'tenant-a',
                'fairness_weight' => 1,
            ],
        ];

        $scheduler = new TaskFairnessScheduler(new InMemoryTaskFairnessState());
        $reordered = $scheduler->reorder('default', 'default', $candidates);

        $this->assertSame(
            ['high-tenant-b', 'mid-tenant-a', 'low-tenant-a'],
            array_column($reordered, 'task_id'),
            'Fairness reordering must honor priority tiers — urgent work always leads, fairness only redistributes within a tier.',
        );
    }

    public function testFairnessSchedulerWeightAllowsClassToTakeProportionallyMore(): void
    {
        $candidates = [];

        for ($i = 0; $i < 4; $i++) {
            $candidates[] = [
                'task_id' => sprintf('weighted-%d', $i),
                'priority' => TaskPriority::DEFAULT,
                'fairness_key' => 'weighted',
                'fairness_weight' => 3,
            ];
        }

        for ($i = 0; $i < 4; $i++) {
            $candidates[] = [
                'task_id' => sprintf('plain-%d', $i),
                'priority' => TaskPriority::DEFAULT,
                'fairness_key' => 'plain',
                'fairness_weight' => 1,
            ];
        }

        $scheduler = new TaskFairnessScheduler(new InMemoryTaskFairnessState(halfLifeSeconds: 60.0));
        $reordered = $scheduler->reorder('default', 'default', $candidates);
        $order = array_column($reordered, 'task_id');

        $weightedCountInFirstFour = count(array_filter(
            array_slice($order, 0, 4),
            static fn (string $id): bool => str_starts_with($id, 'weighted-'),
        ));

        $this->assertGreaterThanOrEqual(
            3,
            $weightedCountInFirstFour,
            'A class with weight 3 should claim a proportionally larger share of the first slots.',
        );
    }

    public function testActivityBridgePollOrdersByPriority(): void
    {
        $run = $this->createReadyRun();

        $high = $this->seedActivityTask($run, priority: 1);
        $low = $this->seedActivityTask($run, priority: 8);
        $mid = $this->seedActivityTask($run, priority: 5);

        /** @var ActivityTaskBridge $bridge */
        $bridge = $this->app->make(ActivityTaskBridge::class);
        $tasks = $bridge->poll(null, 'default', limit: 10);

        $this->assertSame(
            [$high->id, $mid->id, $low->id],
            array_column($tasks, 'task_id'),
            'Activity tasks dispatch lowest-priority-number-first.',
        );
    }

    public function testWorkflowTaskPollEndpointAppliesFairnessReorderAndRecordsDispatch(): void
    {
        $this->bootWebhookRoutes();

        $run = $this->createReadyRun();

        // Five "alpha" tasks land first; without the fairness pass on the
        // dispatch path subsequent polls of the same batch would drain alpha
        // entirely before beta sees any dispatch share.
        for ($i = 0; $i < 5; $i++) {
            WorkflowTask::query()->create([
                'workflow_run_id' => $run->id,
                'task_type' => TaskType::Workflow->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => now()
                    ->subSeconds(10 - $i),
                'payload' => [],
                'connection' => 'redis',
                'queue' => 'default',
                'priority' => TaskPriority::DEFAULT,
                'fairness_key' => 'alpha',
            ]);
        }

        for ($i = 0; $i < 5; $i++) {
            WorkflowTask::query()->create([
                'workflow_run_id' => $run->id,
                'task_type' => TaskType::Workflow->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => now()
                    ->subSeconds(5 - $i),
                'payload' => [],
                'connection' => 'redis',
                'queue' => 'default',
                'priority' => TaskPriority::DEFAULT,
                'fairness_key' => 'beta',
            ]);
        }

        $response = $this->getJson('/webhooks/workflow-tasks/poll?queue=default&limit=10');
        $response->assertOk();
        $tasks = $response->json('tasks');

        $this->assertIsArray($tasks);
        $this->assertCount(10, $tasks);

        $firstFour = array_slice(array_column($tasks, 'fairness_key'), 0, 4);
        $this->assertSame(
            ['alpha', 'beta', 'alpha', 'beta'],
            $firstFour,
            'The webhook poll path must apply the fairness reorder pass so a noisy class yields to its peers within a tier.',
        );

        // The poll path must also record each chosen dispatch back to the
        // shared fairness state so subsequent polls (in this process or
        // another) see the deficit and continue rebalancing.
        /** @var TaskFairnessState $state */
        $state = $this->app->make(TaskFairnessState::class);
        $snapshot = $state->snapshot('workflow_task', 'default', ['alpha', 'beta']);

        $this->assertEqualsWithDelta(5.0, $snapshot['alpha'], 0.05);
        $this->assertEqualsWithDelta(5.0, $snapshot['beta'], 0.05);
    }

    public function testActivityTaskPollEndpointAppliesFairnessReorderAndRecordsDispatch(): void
    {
        $this->bootWebhookRoutes();

        $run = $this->createReadyRun();

        for ($i = 0; $i < 3; $i++) {
            WorkflowTask::query()->create([
                'workflow_run_id' => $run->id,
                'task_type' => TaskType::Activity->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => now()
                    ->subSeconds(10 - $i),
                'payload' => [],
                'connection' => 'redis',
                'queue' => 'default',
                'priority' => TaskPriority::DEFAULT,
                'fairness_key' => 'tenant-a',
            ]);
        }

        for ($i = 0; $i < 3; $i++) {
            WorkflowTask::query()->create([
                'workflow_run_id' => $run->id,
                'task_type' => TaskType::Activity->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => now()
                    ->subSeconds(5 - $i),
                'payload' => [],
                'connection' => 'redis',
                'queue' => 'default',
                'priority' => TaskPriority::DEFAULT,
                'fairness_key' => 'tenant-b',
            ]);
        }

        $response = $this->getJson('/webhooks/activity-tasks/poll?queue=default&limit=10');
        $response->assertOk();

        $keys = array_slice(array_column($response->json('tasks'), 'fairness_key'), 0, 4);
        $this->assertSame(['tenant-a', 'tenant-b', 'tenant-a', 'tenant-b'], $keys);

        /** @var TaskFairnessState $state */
        $state = $this->app->make(TaskFairnessState::class);
        $snapshot = $state->snapshot('activity_task', 'default', ['tenant-a', 'tenant-b']);

        $this->assertEqualsWithDelta(3.0, $snapshot['tenant-a'], 0.05);
        $this->assertEqualsWithDelta(3.0, $snapshot['tenant-b'], 0.05);

        // Workflow-task and activity-task buckets must remain disjoint so a
        // noisy workflow class does not borrow against an activity budget.
        $crossover = $state->snapshot('workflow_task', 'default', ['tenant-a', 'tenant-b']);
        $this->assertSame(0.0, $crossover['tenant-a']);
        $this->assertSame(0.0, $crossover['tenant-b']);
    }

    public function testPriorityFairnessObservabilityEndpointReturnsTieredCountsAndRecentDispatchSnapshot(): void
    {
        $this->bootWebhookRoutes();

        $run = $this->createReadyRun();

        // Two ready tasks at urgent priority, one keyed and one unkeyed.
        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'priority' => 1,
            'fairness_key' => 'tenant-a',
        ]);
        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'priority' => 1,
        ]);

        // One activity task at default priority for a different tenant so the
        // surfaces are reported on separately.
        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'priority' => TaskPriority::DEFAULT,
            'fairness_key' => 'tenant-b',
        ]);

        // Seed a recent-dispatch counter so the snapshot section is non-empty.
        /** @var TaskFairnessState $state */
        $state = $this->app->make(TaskFairnessState::class);
        $state->recordDispatch('workflow_task', 'default', 'tenant-a', 1);

        $response = $this->getJson('/webhooks/task-queues/default/priority-fairness');
        $response->assertOk();

        $response->assertJsonPath('queue', 'default');
        $response->assertJsonPath('workflow_task.ready_tasks', 2);
        $response->assertJsonPath('activity_task.ready_tasks', 1);

        $tiers = $response->json('workflow_task.priority_tiers');
        $this->assertIsArray($tiers);
        $this->assertCount(1, $tiers);
        $this->assertSame(1, $tiers[0]['priority']);
        $this->assertSame(2, $tiers[0]['count']);

        $classKeys = array_column($tiers[0]['classes'], 'fairness_key');
        $this->assertContains('tenant-a', $classKeys);
        $this->assertContains(null, $classKeys, 'Unkeyed tasks must surface as the implicit default class.');
        $this->assertCount(2, $classKeys);

        $recent = $response->json('workflow_task.recent_dispatch');
        $this->assertIsArray($recent);
        $this->assertNotEmpty($recent);
        $tenantA = array_values(array_filter(
            $recent,
            static fn (array $entry): bool => $entry['fairness_key'] === 'tenant-a',
        ));
        $this->assertNotEmpty($tenantA);
        $this->assertGreaterThan(0.0, $tenantA[0]['score']);
    }

    public function testPriorityFairnessObservabilityEndpointReturnsEmptySurfacesWhenNoReadyTasks(): void
    {
        $this->bootWebhookRoutes();

        $response = $this->getJson('/webhooks/task-queues/default/priority-fairness');

        $response->assertOk();
        $response->assertJsonPath('queue', 'default');
        $response->assertJsonPath('workflow_task.ready_tasks', 0);
        $response->assertJsonPath('workflow_task.priority_tiers', []);
        $response->assertJsonPath('workflow_task.recent_dispatch', []);
        $response->assertJsonPath('activity_task.ready_tasks', 0);
    }

    private function createReadyRun(): WorkflowRun
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
            'status' => RunStatus::Running->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'priority' => TaskPriority::DEFAULT,
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

    private function seedTask(WorkflowRun $run, int $priority, int $secondsAgo): WorkflowTask
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSeconds($secondsAgo),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'priority' => $priority,
        ]);

        return $task;
    }

    private function seedActivityTask(WorkflowRun $run, int $priority): WorkflowTask
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'priority' => $priority,
        ]);

        return $task;
    }

    private function bootWebhookRoutes(): void
    {
        config([
            'workflows.webhook_auth.method' => 'none',
            'workflows.v2.types.workflows' => [
                'test-greeting-workflow' => TestGreetingWorkflow::class,
            ],
        ]);

        Queue::fake();

        Webhooks::routes([TestGreetingWorkflow::class]);
    }
}
