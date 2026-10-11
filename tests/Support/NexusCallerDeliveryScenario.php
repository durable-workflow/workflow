<?php

declare(strict_types=1);

namespace Tests\Support;

use Mockery;
use Symfony\Component\Uid\Ulid;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

trait NexusCallerDeliveryScenario
{
    /**
     * @param class-string<Workflow> $workflowClass
     * @param array<int, mixed> $arguments
     * @param array<string, mixed> $expected
     */
    protected function assertWaitingNexusCallerReceivesResult(
        string $workflowClass,
        array $arguments,
        array $expected
    ): void {
        $previousDispatchMode = config('workflows.v2.task_dispatch_mode');
        $previousControlPlane = $this->app->make(ServiceControlPlane::class);
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);

        try {
            $instance = WorkflowInstance::query()->create([
                'namespace' => 'service',
                'workflow_type' => 'nexus.service',
                'workflow_class' => 'nexus.service',
                'run_count' => 1,
            ]);
            $serviceRun = WorkflowRun::query()->create([
                'workflow_instance_id' => $instance->id,
                'namespace' => 'service',
                'run_number' => 1,
                'workflow_type' => 'nexus.service',
                'workflow_class' => 'nexus.service',
                'status' => RunStatus::Waiting->value,
                'payload_codec' => 'avro',
                'arguments' => Serializer::serializeWithCodec('avro', []),
                'connection' => 'sync',
                'queue' => 'default',
                'started_at' => now(),
            ]);
            $endpoint = WorkflowServiceEndpoint::query()->create([
                'namespace' => 'service',
                'endpoint_name' => 'php-service',
            ]);
            $service = WorkflowService::query()->create([
                'namespace' => 'service',
                'workflow_service_endpoint_id' => $endpoint->id,
                'service_name' => 'greeter',
            ]);
            WorkflowServiceOperation::query()->create([
                'namespace' => 'service',
                'workflow_service_endpoint_id' => $endpoint->id,
                'workflow_service_id' => $service->id,
                'operation_name' => 'greet',
                'operation_mode' => 'async',
                'handler_binding_kind' => 'workflow_run',
                'handler_target_reference' => 'nexus.service',
            ]);
            $control = Mockery::mock(WorkflowControlPlane::class);
            $control->shouldReceive('start')
                ->once()
                ->andReturn([
                    'started' => true,
                    'workflow_instance_id' => $serviceRun->workflow_instance_id,
                    'workflow_run_id' => $serviceRun->id,
                ]);
            $this->app->instance(ServiceControlPlane::class, new DefaultServiceControlPlane($control));
            $workflow = WorkflowStub::make($workflowClass, 'waiting-caller-' . new Ulid(), 'caller');
            $this->assertTrue($workflow->attemptStart(...$arguments)->accepted());
            $run = WorkflowRun::query()->findOrFail($workflow->runId());
            $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->sole();
            $this->app->call([new RunWorkflowTask($task->id), 'handle']);
            $this->assertSame(RunStatus::Waiting, $run->refresh()->status);
            $this->assertNull($workflow->refresh()->output());
            $call = WorkflowServiceCall::query()->where('caller_workflow_run_id', $run->id)->sole();
            $this->assertSame('started', $call->status);

            WorkflowHistoryEvent::record($serviceRun, HistoryEventType::WorkflowCompleted, [
                'output' => Serializer::serializeWithCodec('avro', $expected),
                'payload_codec' => 'avro',
            ]);
            $resume = WorkflowTask::query()
                ->where('workflow_run_id', $run->id)
                ->where('status', TaskStatus::Ready->value)
                ->sole();
            $this->assertSame($call->id, $resume->payload['service_call_id']);
            $this->assertSame('caller', $resume->namespace);
            $this->app->call([new RunWorkflowTask($resume->id), 'handle']);
            $this->assertSame(RunStatus::Completed, $run->refresh()->status);
            $this->assertSame($expected, $workflow->refresh()->output());
            $this->assertSame('completed', $call->refresh()->status);
            $this->assertSame(1, WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::ServiceCallCompleted->value)
                ->count());
        } finally {
            config([
                'workflows.v2.task_dispatch_mode' => $previousDispatchMode,
            ]);
            $this->app->instance(ServiceControlPlane::class, $previousControlPlane);
        }
    }
}
