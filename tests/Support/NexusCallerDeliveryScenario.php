<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Carbon;
use Mockery;
use Symfony\Component\Uid\Ulid;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Contracts\WorkflowTaskBridge;
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
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

trait NexusCallerDeliveryScenario
{
    /**
     * @param array<string, mixed> $fixture
     */
    protected function assertNexusCallerSdkAttribution(array $fixture): void
    {
        $previous = $this->app->make(ServiceControlPlane::class);
        $previousDispatchMode = config('workflows.v2.task_dispatch_mode');
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        try {
            $workflow = WorkflowStub::make($fixture['workflow']['type'], 'sdk-attribution-caller', 'caller');
            $this->assertTrue($workflow->attemptStart(...$fixture['workflow']['arguments'])->accepted());
            $run = WorkflowRun::query()->findOrFail($workflow->runId());
            $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->sole();
            $bridge = $this->app->make(WorkflowTaskBridge::class);
            $this->assertTrue($bridge->claimStatus($task->id, 'sdk-caller')['claimed']);
            $service = Mockery::mock(ServiceControlPlane::class);
            $service->shouldReceive('execute')
                ->once()
                ->withArgs(function (
                    string $endpoint,
                    string $service,
                    string $operation,
                    array $options
                ) use ($fixture, $run): bool {
                    $this->assertSame(['billing', 'invoices', 'create'], [$endpoint, $service, $operation]);
                    $this->assertSame($fixture['caller_sdk_language'], $options['metadata']['caller_sdk_language']);
                    $this->assertSame($run->workflow_instance_id, $options['metadata']['caller_workflow_instance_id']);
                    $this->assertSame($run->id, $options['metadata']['caller_workflow_run_id']);
                    $this->assertSame(1, $options['metadata']['workflow_sequence']);

                    return true;
                })->andReturn([
                    'service_call_id' => 'attributed-call',
                    'accepted' => true,
                    'status' => 'started',
                ]);
            $this->app->instance(ServiceControlPlane::class, $service);
            $completed = $bridge->complete($task->id, [[
                'type' => 'start_service_operation',
                'endpoint_name' => 'billing',
                'service_name' => 'invoices',
                'operation_name' => 'create',
                'request_payload' => Serializer::serialize([
                    'invoice_id' => 42,
                ]),
                'metadata' => [
                    'caller_sdk_language' => $fixture['caller_sdk_language'],
                    'caller_workflow_instance_id' => 'forged-owner',
                    'caller_workflow_run_id' => 'forged-run',
                    'workflow_sequence' => 99,
                ],
            ]]);
            $this->assertTrue($completed['completed']);
            $event = $run->historyEvents()
                ->where('event_type', HistoryEventType::ServiceCallStarted->value)->sole();
            $this->assertSame($fixture['caller_sdk_language'], $event->payload['caller_sdk_language']);
            $this->assertSame($run->id, $event->payload['caller_workflow_run_id']);
            $this->assertSame(1, $event->payload['sequence']);
        } finally {
            config([
                'workflows.v2.task_dispatch_mode' => $previousDispatchMode,
            ]);
            $this->app->instance(ServiceControlPlane::class, $previous);
        }
    }

    /**
     * @param class-string<Workflow> $workflowClass
     * @param array<int, mixed> $arguments
     * @param array<string, mixed> $expected
     * @param array<string, mixed>|null $deadlinePolicy
     */
    protected function assertWaitingNexusCallerReceivesResult(
        string $workflowClass,
        array $arguments,
        array $expected,
        ?array $deadlinePolicy = null,
    ): void {
        $previousDispatchMode = config('workflows.v2.task_dispatch_mode');
        $previousControlPlane = $this->app->make(ServiceControlPlane::class);
        $previousNow = Carbon::getTestNow();
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);

        try {
            if ($deadlinePolicy !== null) {
                Carbon::setTestNow('2026-10-11T00:00:00.123456Z');
            }
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
                'deadline_policy' => $deadlinePolicy,
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

            if ($deadlinePolicy !== null) {
                $this->assertSame('2026-10-11T00:00:01.123456Z', $call->call_deadline_at?->toJSON());
                // A fresh control plane and repair pass read only durable state.
                // No describe/wait polling is allowed to cause expiration.
                $this->app->instance(ServiceControlPlane::class, new DefaultServiceControlPlane());
                Carbon::setTestNow('2026-10-11T00:00:01.123456Z');
                $report = TaskWatchdog::runPass(runIds: [$run->id]);
                $this->assertSame(1, $report['service_call_timeouts_enforced']);
                $this->assertSame('timed_out', $call->refresh()->outcome?->value);
                $this->assertSame('ServiceCallTimedOut', $call->outcome_metadata['caller_observed_error_type']);
            }

            WorkflowHistoryEvent::record($serviceRun, HistoryEventType::WorkflowCompleted, [
                'output' => Serializer::serializeWithCodec('avro', $deadlinePolicy === null ? $expected : [
                    'late' => true,
                ]),
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
            $this->assertSame($deadlinePolicy === null ? 'completed' : 'failed', $call->refresh()->status);
            $this->assertSame(1, WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->where('event_type', $deadlinePolicy === null
                    ? HistoryEventType::ServiceCallCompleted->value
                    : HistoryEventType::ServiceCallFailed->value)
                ->count());
            if ($deadlinePolicy !== null) {
                $failed = $run->historyEvents()
                    ->where('event_type', HistoryEventType::ServiceCallFailed->value)->sole();
                $this->assertSame($call->id, $failed->payload['service_call_id']);
                $this->assertSame(1, $failed->payload['sequence']);
                $this->assertSame('ServiceCallTimedOut', $failed->payload['exception_type']);
                $this->assertSame(0, $run->historyEvents()
                    ->where('event_type', HistoryEventType::ServiceCallCompleted->value)->count());
                $cold = WorkflowFiberRunner::forClass(
                    $workflowClass,
                    $run->workflow_instance_id,
                    $run->id,
                    $arguments,
                    'avro',
                    $run->fresh('historyEvents')
                        ->historyEvents->toArray(),
                )->step();
                $this->assertTrue($cold->completed);
                $this->assertSame($expected, $cold->result);
                $again = TaskWatchdog::runPass(runIds: [$run->id]);
                $this->assertSame(0, $again['service_call_timeouts_enforced']);
                $this->assertSame(1, $run->historyEvents()
                    ->where('event_type', HistoryEventType::ServiceCallFailed->value)->count());
            }
        } finally {
            Carbon::setTestNow($previousNow);
            config([
                'workflows.v2.task_dispatch_mode' => $previousDispatchMode,
            ]);
            $this->app->instance(ServiceControlPlane::class, $previousControlPlane);
        }
    }
}
