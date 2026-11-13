<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\EntityReferences\Order;
use Tests\Fixtures\V2\EntityReferences\OrderReferenceAdapter;
use Tests\Fixtures\V2\EntityReferences\OrderReferenceResolver;
use Tests\Fixtures\V2\EntityReferences\OrderRepository;
use Tests\Fixtures\V2\EntityReferences\OrderStatusWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\EntityReferences\EntityNotFound;
use Workflow\V2\EntityReferences\EntityReferenceRefused;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

/**
 * Domain entity references in activity arguments (#996). Draft: these pin the
 * accepted behavior and fail until the adapter/resolver implementation lands.
 */
final class V2EntityReferenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()
            ->set('queue.default', 'redis');
        config()
            ->set('workflows.v2.entity_references', [
                'orders.order' => [
                    'adapter' => OrderReferenceAdapter::class,
                    'resolver' => OrderReferenceResolver::class,
                ],
            ]);
        OrderRepository::reset();
    }

    public function testAnActivityReceivesTheCurrentEntityResolvedFromAPortableReference(): void
    {
        OrderRepository::save(new Order('o-1', 'paid'));
        $workflow = WorkflowStub::make(OrderStatusWorkflow::class, 'entity-reference-current');
        $workflow->start('o-1');
        $runId = $workflow->runId();

        $this->runReadyTask($runId, TaskType::Workflow);

        // History holds the reference and its recorded position, not the entity.
        $scheduled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ActivityScheduled->value)
            ->sole();
        $execution = ActivityExecution::query()->where('workflow_run_id', $runId)->sole();
        $this->assertSame([[
            'type' => 'orders.order',
            'id' => 'o-1',
            'version' => 1,
        ], 'checked'], Serializer::unserialize($execution->arguments));
        $this->assertSame([0], $execution->activity_options['entity_reference_positions'] ?? null);
        $this->assertSame([0], $scheduled->payload['activity']['entity_reference_positions'] ?? null);

        $this->runReadyTask($runId, TaskType::Activity);
        $this->runReadyTask($runId, TaskType::Workflow);

        // The activity saw the persisted state, not the workflow's copy.
        $this->assertSame('o-1:paid:checked', $workflow->refresh()->output());
        $this->assertSame(1, OrderRepository::$loads);
    }

    public function testReplayNeverResolvesTheReference(): void
    {
        OrderRepository::save(new Order('o-2', 'paid'));
        $workflow = WorkflowStub::make(OrderStatusWorkflow::class, 'entity-reference-replay');
        $workflow->start('o-2');
        $runId = $workflow->runId();
        $this->runReadyTask($runId, TaskType::Workflow);
        $this->runReadyTask($runId, TaskType::Activity);
        $this->runReadyTask($runId, TaskType::Workflow);
        $loads = OrderRepository::$loads;

        (new WorkflowReplayer())->replay(WorkflowRun::query()->findOrFail($runId));

        $this->assertSame($loads, OrderRepository::$loads);
    }

    public function testAMissingEntityFailsTheAttemptWithEntityNotFound(): void
    {
        $workflow = WorkflowStub::make(OrderStatusWorkflow::class, 'entity-reference-missing');
        $workflow->start('o-missing');
        $runId = $workflow->runId();
        $this->runReadyTask($runId, TaskType::Workflow);
        $this->runReadyTask($runId, TaskType::Activity);

        $failed = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ActivityFailed->value)
            ->sole();
        $this->assertSame(EntityNotFound::class, $failed->payload['exception_class'] ?? null);
    }

    public function testAnUnknownRecordedTypeIsRefusedBeforeTheActivityRunsAndNotRetried(): void
    {
        OrderRepository::save(new Order('o-3', 'paid'));
        $workflow = WorkflowStub::make(OrderStatusWorkflow::class, 'entity-reference-unknown-type');
        $workflow->start('o-3');
        $runId = $workflow->runId();
        $this->runReadyTask($runId, TaskType::Workflow);

        // The worker that runs the attempt no longer knows the type.
        config()
            ->set('workflows.v2.entity_references', []);
        $this->runReadyTask($runId, TaskType::Activity);

        $failed = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ActivityFailed->value)
            ->sole();
        $this->assertSame(EntityReferenceRefused::class, $failed->payload['exception_class'] ?? null);
        $this->assertTrue((bool) ($failed->payload['non_retryable'] ?? false));
        $this->assertSame(0, OrderRepository::$loads);
    }

    public function testAnUnregisteredObjectStillFailsWithoutObjectSerialization(): void
    {
        config()
            ->set('workflows.v2.entity_references', []);
        $workflow = WorkflowStub::make(OrderStatusWorkflow::class, 'entity-reference-unregistered');
        $workflow->start('o-4');
        $runId = $workflow->runId();
        $this->runReadyTask($runId, TaskType::Workflow);

        $this->assertSame(0, ActivityExecution::query()->where('workflow_run_id', $runId)->count());
        $this->assertTrue(WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::WorkflowFailed->value)
            ->exists());
    }

    private function runReadyTask(string $runId, TaskType $taskType): void
    {
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', $taskType->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();

        $job = $taskType === TaskType::Activity
            ? new RunActivityTask($task->id)
            : new RunWorkflowTask($task->id);

        $this->app->call([$job, 'handle']);
    }
}
