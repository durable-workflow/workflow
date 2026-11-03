<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\RecordingExternalPayloadStorage;
use Tests\Fixtures\V2\TestUpdateWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\DefaultWorkflowControlPlane;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\ServiceCallResultEnvelope;

final class ServiceCallWorkflowLifecycleTest extends TestCase
{
    private WorkflowRun $target;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()
            ->set('queue.default', 'database');
        $started = (new DefaultWorkflowControlPlane())->start(TestUpdateWorkflow::class, 'lifecycle-target', [
            'namespace' => 'billing',
            'queue' => 'orders',
            'arguments' => Serializer::serialize([]),
        ]);
        $this->target = WorkflowRun::query()->findOrFail($started['workflow_run_id']);
        $this->app->call([new RunWorkflowTask($started['task_id']), 'handle']);
    }

    #[DataProvider('updates')]
    public function testAcceptedUpdatesCloseOnTheirActualTerminalEvent(
        string $name,
        array $arguments,
        bool $failed,
        bool $lateLink,
        bool $external = false
    ): void {
        if ($external) {
            $this->app->instance(
                ExternalPayloadStoragePolicy::class,
                new RecordingExternalPayloadStorage(threshold: 32)
            );
        }
        $this->operation('workflow_update', $name);
        $workflows = new DefaultWorkflowControlPlane();
        if ($lateLink) {
            $adapter = Mockery::mock(WorkflowControlPlane::class);
            $adapter->shouldReceive('update')
                ->once()
                ->andReturnUsing(function (string $id, string $name, array $options) use ($workflows): array {
                    $result = $workflows->update($id, $name, $options);
                    $this->runReadyTargetTask();
                    return $result;
                });
            $workflows = $adapter;
        }
        $result = (new DefaultServiceControlPlane($workflows))->execute('billing', 'service', 'operation', [
            'namespace' => 'billing',
            'arguments' => $arguments,
            'wait_for' => 'accepted',
        ]);
        $this->assertSame(! ($failed && $lateLink), $result['accepted']);
        $call = WorkflowServiceCall::query()->sole();
        $foreign = null;
        if (! $lateLink) {
            $this->assertSame('started', $call->status);
            $foreign = $call->replicate();
            $foreign->forceFill([
                'namespace' => 'foreign',
                'target_namespace' => 'foreign',
            ])->save();
            $this->runReadyTargetTask();
        }
        $this->assertSame($failed ? 'failed' : 'completed', $call->refresh()->status);
        $this->assertNotNull($call->linked_workflow_update_id);
        if ($failed) {
            $this->assertSame(\RuntimeException::class, $call->outcome_metadata['service_error_type']);
            $this->assertSame('fixture update failure', $call->failure_message);
            $this->assertNull(ServiceCallResultEnvelope::fromCall($call));
        } else {
            $envelope = ServiceCallResultEnvelope::fromCall($call);
            $this->assertNotNull($envelope);
            $blob = ExternalPayloads::payloadBlob($envelope, $envelope['codec'], 'billing');
            $this->assertIsString($blob);
            $value = Serializer::unserializeWithCodec($envelope['codec'], $blob);
            $this->assertSame($external, $call->output_payload_reference !== null);
            $this->assertSame($name === 'largeResult' ? [
                'length' => 2048,
                'value' => str_repeat('u', 2048),
            ] : [
                'approved' => true,
                'events' => ['started', 'approved:yes:source Ω'],
            ], $value);
        }
        if ($foreign !== null) {
            $this->assertSame('started', $foreign->refresh()->status);
            $this->assertArrayNotHasKey('nexus_result', $foreign->metadata);
        }
        $this->assertFalse($this->target->refresh()->status->isTerminal());
    }

    public static function updates(): iterable
    {
        foreach ([false, true] as $late) {
            yield 'approval-' . (int) $late => ['approve', [true, 'source Ω'], false, $late];
            yield 'large-' . (int) $late => ['largeResult', [2048], false, $late];
            yield 'failure-' . (int) $late => ['explode', ['fixture update failure'], true, $late];
            yield 'external-' . (int) $late => ['largeResult', [2048], false, $late, true];
        }
    }

    public function testSignalCompletesWithADurableReceiptBeforeTheWorkflowAppliesIt(): void
    {
        $this->operation('workflow_signal', 'name-provided');
        $control = new DefaultServiceControlPlane();
        $result = $control->execute('billing', 'service', 'operation', [
            'namespace' => 'billing',
            'arguments' => ['name Ω'],
            'wait_for' => 'accepted',
        ]);
        $this->assertTrue($result['accepted']);
        $call = WorkflowServiceCall::query()->sole();
        $this->assertSame('completed', $call->status);
        $this->assertFalse($this->target->refresh()->status->isTerminal());
        $signal = WorkflowSignal::query()->sole();
        $this->assertSame('received', $signal->status->value);
        $this->assertSame($signal->workflow_command_id, $call->resolved_target_reference);
        $envelope = ServiceCallResultEnvelope::fromCall($call);
        $this->assertSame([
            'codec' => 'avro',
            'blob' => Serializer::serializeWithCodec('avro', null),
        ], $envelope);
        $before = $call->getRawOriginal();
        $control->execute('billing', 'service', 'operation', [
            'namespace' => 'billing',
            'service_call_id' => $call->id,
        ]);
        $this->assertSame($before, $call->fresh()->getRawOriginal());
        $this->assertSame(1, WorkflowSignal::query()->count());
    }

    private function runReadyTargetTask(): void
    {
        $tasks = WorkflowTask::query()->where('workflow_run_id', $this->target->id)->where(
            'status',
            TaskStatus::Ready->value
        )->get();
        $this->assertCount(1, $tasks);
        $this->app->call([new RunWorkflowTask($tasks->sole()->id), 'handle']);
    }

    private function operation(string $kind, string $name): void
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'billing',
            'endpoint_name' => 'billing',
        ]);
        $service = WorkflowService::query()->create([
            'namespace' => 'billing',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'service',
        ]);
        WorkflowServiceOperation::query()->create([
            'namespace' => 'billing',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'operation_name' => 'operation',
            'operation_mode' => 'async',
            'handler_binding_kind' => $kind,
            'handler_target_reference' => $name,
            'handler_binding' => [
                'workflow_instance_id' => $this->target->workflow_instance_id,
            ],
        ]);
    }
}
