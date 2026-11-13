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
use Workflow\V2\EntityReferences\EntityAccessDenied;
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
use Workflow\V2\Support\ActivityRecovery;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

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
                    'entity_class' => Order::class,
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

        OrderRepository::save(new Order('o-1', 'shipped'));
        $this->runReadyTask($runId, TaskType::Activity);
        $this->runReadyTask($runId, TaskType::Workflow);

        // The activity saw the persisted state, not the workflow's copy.
        $this->assertSame('o-1:shipped:checked', $workflow->refresh()->output());
        $this->assertSame(1, OrderRepository::$loads);
        $context = OrderRepository::$contexts[0];
        $run = WorkflowRun::query()->findOrFail($runId);
        $this->assertSame($run->namespace, $context->namespace);
        $this->assertSame($run->workflow_instance_id, $context->workflowInstanceId);
        $this->assertSame($runId, $context->workflowRunId);
        $this->assertSame($execution->id, $context->activityExecutionId);
        $this->assertSame(1, $context->attemptNumber);
        $this->assertSame(0, $context->argumentPosition);
        $this->assertSame(Order::class, $context->parameterType);
        $this->assertSame([0], OrderRepository::$transactionLevels);
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

    public function testAnOrdinaryReferenceShapedMapIsNeverResolved(): void
    {
        $workflow = WorkflowStub::make(OrderStatusWorkflow::class, 'entity-reference-map');
        $workflow->start('o-map', 'map');
        $this->runReadyTask($workflow->runId(), TaskType::Workflow);
        $execution = ActivityExecution::query()->where('workflow_run_id', $workflow->runId())->sole();
        $this->assertArrayNotHasKey('entity_reference_positions', $execution->activity_options ?? []);
        $this->runReadyTask($workflow->runId(), TaskType::Activity);
        $this->runReadyTask($workflow->runId(), TaskType::Workflow);
        $this->assertSame('o-map:checked', $workflow->refresh()->output());
        $this->assertSame(0, OrderRepository::$loads);
    }

    public function testEntityResolutionPreservesMethodDependencyInjection(): void
    {
        OrderRepository::save(new Order('o-di', 'paid'));
        $workflow = WorkflowStub::make(OrderStatusWorkflow::class, 'entity-reference-di');
        $workflow->start('o-di', 'injected');
        $this->runReadyTask($workflow->runId(), TaskType::Workflow);
        $this->runReadyTask($workflow->runId(), TaskType::Activity);
        $this->runReadyTask($workflow->runId(), TaskType::Workflow);
        $this->assertSame('o-di:paid:checked', $workflow->refresh()->output());
        $this->assertSame(Order::class, OrderRepository::$contexts[0]->parameterType);
    }

    public function testAParameterMismatchIsRefusedBeforeRepositoryAccess(): void
    {
        $workflow = WorkflowStub::make(OrderStatusWorkflow::class, 'entity-reference-mismatch');
        $workflow->start('o-mismatch', 'mismatch');
        $this->runReadyTask($workflow->runId(), TaskType::Workflow);
        $this->runReadyTask($workflow->runId(), TaskType::Activity);
        $failed = WorkflowHistoryEvent::query()->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::ActivityFailed->value)->sole();
        $this->assertSame(EntityReferenceRefused::class, $failed->payload['exception_class']);
        $this->assertTrue($failed->payload['non_retryable']);
        $this->assertSame([], OrderRepository::$contexts);
    }

    public function testAnUnsupportedVersionIsRefusedBeforeRepositoryAccess(): void
    {
        $workflow = WorkflowStub::make(OrderStatusWorkflow::class, 'entity-reference-version');
        $workflow->start('o-version');
        $this->runReadyTask($workflow->runId(), TaskType::Workflow);
        $execution = ActivityExecution::query()->where('workflow_run_id', $workflow->runId())->sole();
        $arguments = $execution->activityArguments();
        $arguments[0]['version'] = 2;
        $execution->forceFill([
            'arguments' => Serializer::serializeWithCodec($execution->payload_codec, $arguments),
        ])->save();
        $this->runReadyTask($workflow->runId(), TaskType::Activity);
        $failed = WorkflowHistoryEvent::query()->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::ActivityFailed->value)->sole();
        $this->assertSame(EntityReferenceRefused::class, $failed->payload['exception_class']);
        $this->assertTrue($failed->payload['non_retryable']);
        $this->assertSame([], OrderRepository::$contexts);
    }

    public function testAuthorizationUsesTheResolverAndNeverEntersTheActivity(): void
    {
        OrderRepository::$deny = true;
        $workflow = WorkflowStub::make(OrderStatusWorkflow::class, 'entity-reference-denied');
        $workflow->start('o-denied');
        $this->runReadyTask($workflow->runId(), TaskType::Workflow);
        $this->runReadyTask($workflow->runId(), TaskType::Activity);
        $failed = WorkflowHistoryEvent::query()->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::ActivityFailed->value)->sole();
        $this->assertSame(EntityAccessDenied::class, $failed->payload['exception_class']);
        $this->assertSame(0, OrderRepository::$loads);
        $this->assertCount(1, OrderRepository::$contexts);
    }

    public function testEachRetryLoadsCurrentStateAgain(): void
    {
        OrderRepository::save(new Order('o-retry', 'paid'));
        $workflow = WorkflowStub::make(OrderStatusWorkflow::class, 'entity-reference-retry');
        $workflow->start('o-retry', 'retry');
        $this->runReadyTask($workflow->runId(), TaskType::Workflow);
        $this->runReadyTask($workflow->runId(), TaskType::Activity);
        OrderRepository::save(new Order('o-retry', 'refunded'));
        $this->runReadyTask($workflow->runId(), TaskType::Activity);
        $this->runReadyTask($workflow->runId(), TaskType::Workflow);
        $this->assertSame('o-retry:refunded:checked', $workflow->refresh()->output());
        $this->assertSame(
            [1, 2],
            array_map(static fn ($context) => $context->attemptNumber, OrderRepository::$contexts)
        );
        $this->assertSame([0, 0], OrderRepository::$transactionLevels);
    }

    public function testALocalActivityResolvesOutsideTheWorkflowTransactionAndNeverDuringReplay(): void
    {
        OrderRepository::save(new Order('o-local', 'paid'));
        $workflow = WorkflowStub::make(OrderStatusWorkflow::class, 'entity-reference-local');
        $workflow->start('o-local', 'local');
        $this->runReadyTask($workflow->runId(), TaskType::Workflow);

        $this->assertSame('o-local:paid:checked', $workflow->refresh()->output());
        $this->assertSame(1, OrderRepository::$loads);
        $this->assertSame([0], OrderRepository::$transactionLevels);
        $execution = ActivityExecution::query()->where('workflow_run_id', $workflow->runId())->sole();
        $this->assertSame([[
            'type' => 'orders.order',
            'id' => 'o-local',
            'version' => 1,
        ], 'checked'], Serializer::unserialize($execution->arguments));
        $this->assertSame([0], $execution->activity_options['entity_reference_positions'] ?? null);
        $this->assertSame($execution->id, OrderRepository::$contexts[0]->activityExecutionId);
        $this->assertSame(1, OrderRepository::$contexts[0]->attemptNumber);
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Activity->value)->count());

        (new WorkflowReplayer())->replay(WorkflowRun::query()->findOrFail($workflow->runId()));

        $this->assertSame(1, OrderRepository::$loads);
    }

    public function testHistoryRecoveryAndExportRetainReferencePositions(): void
    {
        OrderRepository::save(new Order('o-recovered', 'paid'));
        $workflow = WorkflowStub::make(OrderStatusWorkflow::class, 'entity-reference-recovery');
        $workflow->start('o-recovered');
        $this->runReadyTask($workflow->runId(), TaskType::Workflow);
        $execution = ActivityExecution::query()->where('workflow_run_id', $workflow->runId())->sole();
        $id = $execution->id;
        $bundle = HistoryExport::forRun(WorkflowRun::query()->findOrFail($workflow->runId()));
        $this->assertSame([0], $bundle['activities'][0]['entity_reference_positions'] ?? null);
        $execution->delete();
        $restored = ActivityRecovery::restore(WorkflowRun::query()->findOrFail($workflow->runId()), $id);
        $this->assertSame([0], $restored?->activity_options['entity_reference_positions'] ?? null);
        $this->runReadyTask($workflow->runId(), TaskType::Activity);
        $this->runReadyTask($workflow->runId(), TaskType::Workflow);
        $this->assertSame('o-recovered:paid:checked', $workflow->refresh()->output());
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
