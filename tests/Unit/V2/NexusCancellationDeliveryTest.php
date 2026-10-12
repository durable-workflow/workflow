<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestNexusCancellationTargetWorkflow;
use Tests\Fixtures\V2\TestNexusWaitingCallerWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\CooperativeCancellationDelivery;
use Workflow\V2\Support\ServiceOperationCancellation;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\WorkflowStub;

final class NexusCancellationDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
            'workflows.v2.compatibility.current' => 'build-a',
            'workflows.v2.compatibility.supported' => ['build-a'],
        ]);
        Carbon::setTestNow('2026-10-12T08:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('callers')]
    public function testOriginalCallerWaitsForTargetCleanupAndColdReplayKeepsItsBoundary(bool $portable): void
    {
        $this->catalog();
        $caller = WorkflowStub::make(TestNexusWaitingCallerWorkflow::class, 'nexus-caller', 'caller');
        $caller->start('world');
        $bridge = $this->app->make(WorkflowTaskBridge::class);
        $initial = $this->ready($caller);
        if ($portable) {
            $this->assertTrue($bridge->claimStatus($initial->id, 'portable-caller')['claimed']);
            $this->assertTrue($bridge->complete($initial->id, [[
                'type' => 'start_service_operation',
                'endpoint_name' => 'php-service',
                'service_name' => 'greeter',
                'operation_name' => 'greet',
                'namespace' => 'service',
                'mode_override' => 'sync',
                'wait_for' => 'completed',
                'request_payload' => Serializer::serializeWithCodec('avro', ['world']),
                'payload_codec' => 'avro',
            ]])['completed']);
        } else {
            $this->runTask($initial);
        }
        $call = WorkflowServiceCall::query()->where('caller_workflow_run_id', $caller->runId())->sole();
        $this->assertSame('started', $call->status);
        // Changing the catalog cannot revoke the original call's stored policy.
        WorkflowServiceOperation::query()->update([
            'cancellation_policy' => [
                'allow_cancel' => false,
            ],
        ]);
        $deadline = $call->call_deadline_at->toISOString();
        $target = WorkflowStub::loadRun($call->linked_workflow_run_id);
        $this->runTask($this->ready($target));
        $root = $caller->requestCancellation('original cancellation', 30)
            ->cancellationContext();
        Carbon::setTestNow(now()->addSeconds(7));
        $duplicate = $caller->requestCancellation('replacement reason', 1)
            ->cancellationContext();
        $this->assertSame($root->toArray(), $duplicate->toArray());
        $waiting = $this->ready($caller);
        if ($portable) {
            $this->assertTrue($bridge->claimStatus($waiting->id, 'portable-caller')['claimed']);
            $reply = $bridge->deliverCancellation($waiting->id, $root->requestId, 1, 'service_operation');
            $this->assertFalse($reply['delivered']);
            $this->assertTrue($reply['claim_released']);
            $this->assertSame('cancellation_waiting_for_service_operation', $reply['reason']);
        } else {
            $this->runTask($waiting);
        }
        $this->assertSame(RunStatus::Waiting, $caller->run()->fresh()->status);
        $this->assertSame(0, $caller->run()->historyEvents()
            ->where('event_type', HistoryEventType::CooperativeCancellationDelivered->value)->count());
        $inherited = CooperativeCancellationDelivery::context($target->run()->fresh());
        $this->assertSame($root->rootRequestId, $inherited->rootRequestId);
        $this->assertSame($root->deadline()->toISOString(), $inherited->deadline()->toISOString());
        $this->assertSame($root->requester, $inherited->requester);
        $this->assertSame($root->reason, $inherited->reason);
        $this->assertCount(2, $inherited->lineage);
        $this->assertSame($call->id, $waiting->refresh()->payload['cancellation_service_wait']['service_call_id']);
        $this->assertSame('cancellation_wait_boundary_mismatch', ServiceOperationCancellation::validateBoundary(
            $caller->run()
                ->fresh(['historyEvents', 'tasks']),
            2,
            'service_operation'
        ));

        // A fresh job replays the target's original timer, enters cleanup,
        // then another replacement finishes only after its cleanup timer fires.
        $this->runTask($this->ready($target));
        $this->assertSame(RunStatus::Waiting, $target->run()->fresh()->status);
        $this->assertSame(RunStatus::Waiting, $caller->run()->fresh()->status);
        $this->assertSame('started', $call->refresh()->status);
        Carbon::setTestNow(now()->addSeconds(2));
        $this->runTask($this->ready($target, TaskType::Timer));
        $this->runTask($this->ready($target));
        $this->assertSame(RunStatus::Cancelled, $target->run()->fresh()->status);
        $this->assertSame('cancelled', $call->refresh()->status);
        $resume = $this->ready($caller);
        if ($portable) {
            $this->assertTrue($bridge->claimStatus($resume->id, 'replacement-caller')['claimed']);
            $reply = $bridge->deliverCancellation($resume->id, $root->requestId, 1, 'service_operation');
            $this->assertTrue($reply['delivered']);
            $this->assertTrue($bridge->complete($resume->id, [[
                'type' => 'complete_workflow',
            ]])['completed']);
        } else {
            $this->runTask($resume);
        }
        $this->assertSame(RunStatus::Cancelled, $caller->run()->fresh()->status);
        $this->assertTrue($caller->run()->fresh()->closed_at->lessThan($root->deadline()));
        $this->assertSame($deadline, $call->refresh()->call_deadline_at->toISOString());
        foreach ([$caller, $target] as $workflow) {
            foreach ([
                HistoryEventType::CooperativeCancellationRequested,
                HistoryEventType::CooperativeCancellationDelivered,
                HistoryEventType::WorkflowCancelled,
            ] as $type) {
                $this->assertSame(1, $workflow->run()->historyEvents()->where('event_type', $type->value)->count());
            }
            $terminal = $workflow->run()
                ->historyEvents()
                ->where('event_type', HistoryEventType::WorkflowCancelled->value)->sole();
            $this->assertSame('completed', $terminal->payload['cancellation_cleanup']['outcome']);
        }
        $this->assertSame(
            1,
            $caller->run()
                ->historyEvents()
                ->where('event_type', HistoryEventType::ServiceCallStarted->value)->count()
        );
        $this->assertSame(
            1,
            $caller->run()
                ->historyEvents()
                ->where('event_type', HistoryEventType::ServiceCallCancelled->value)->count()
        );
        try {
            WorkflowFiberRunner::forClass(
                TestNexusWaitingCallerWorkflow::class,
                $caller->id(),
                $caller->runId(),
                ['world'],
                'avro',
                $caller->run()
                    ->fresh('historyEvents')
                    ->historyEvents->toArray()
            )->step();
            $this->fail('Cold replay did not deliver original cancellation at the Nexus yield.');
        } catch (WorkflowCancellationRequestedException $cancel) {
            $this->assertSame($root->toArray(), $cancel->cancellation->toArray());
        }
    }

    public static function callers(): iterable
    {
        yield 'embedded' => [false];
        yield 'portable bridge' => [true];
    }

    public function testReturnedExistingRunDoesNotBecomeOwnedByTheCaller(): void
    {
        $target = WorkflowStub::make(TestNexusCancellationTargetWorkflow::class, 'existing-target', 'service');
        $target->start('original owner');
        $this->runTask($this->ready($target));
        $operation = $this->catalog();
        $operation->forceFill([
            'handler_binding' => [
                'workflow_instance_id' => $target->id(),
                'duplicate_start_policy' => 'return_existing_active',
            ],
        ])->save();
        $caller = WorkflowStub::make(TestNexusWaitingCallerWorkflow::class, 'nexus-caller', 'caller');
        $caller->start('world');
        $this->runTask($this->ready($caller));
        $call = WorkflowServiceCall::query()->where('caller_workflow_run_id', $caller->runId())->sole();
        $this->assertSame('returned_existing_active', $call->metadata['control_plane']['outcome']);
        $this->assertSame($target->runId(), $call->linked_workflow_run_id);
        $result = $caller->attemptRequestCancellation('caller cleanup', 30);
        $this->assertSame('nexus_cooperative_cancellation_not_supported', $result->rejectionReason());
        $this->assertNull($caller->run()->fresh()->cancellation_request_command_id);
        $this->assertNull($target->run()->fresh()->cancellation_request_command_id);
        foreach ([$caller, $target] as $workflow) {
            $this->assertSame(0, $workflow->run()->historyEvents()
                ->where('event_type', HistoryEventType::CooperativeCancellationRequested->value)->count());
            $this->assertSame(RunStatus::Waiting, $workflow->run()->fresh()->status);
        }
        $this->assertSame('started', $call->refresh()->status);
    }

    public function testCancellationBeforeAdmissionDispatchesNoCallOrTarget(): void
    {
        $this->catalog();
        $caller = WorkflowStub::make(TestNexusWaitingCallerWorkflow::class, 'nexus-caller', 'caller');
        $caller->start('world');
        $request = $caller->requestCancellation('cancel before dispatch', 30)
            ->cancellationContext();
        $this->runTask($this->ready($caller));
        $this->assertSame(RunStatus::Cancelled, $caller->run()->fresh()->status);
        $this->assertSame(0, WorkflowServiceCall::query()->count());
        $this->assertSame(1, $caller->run()->newQuery()->count());
        $delivery = $caller->run()
            ->historyEvents()
            ->where('event_type', HistoryEventType::CooperativeCancellationDelivered->value)->sole();
        $this->assertSame(1, $delivery->payload['sequence']);
        $this->assertSame('service_operation', $delivery->payload['call_kind']);
        $this->assertSame($request->requestId, $delivery->payload['workflow_command_id']);
    }

    public function testIndependentTargetRequestRefusesANewCallerRootBeforeCommit(): void
    {
        $this->catalog();
        $caller = WorkflowStub::make(TestNexusWaitingCallerWorkflow::class, 'nexus-caller', 'caller');
        $caller->start('world');
        $this->runTask($this->ready($caller));
        $call = WorkflowServiceCall::query()->where('caller_workflow_run_id', $caller->runId())->sole();
        $target = WorkflowStub::loadRun($call->linked_workflow_run_id);
        $original = $target->requestCancellation('independent owner', 20)
            ->cancellationContext();
        $refusal = $caller->attemptRequestCancellation('new root', 30);
        $this->assertSame('nexus_cooperative_cancellation_not_supported', $refusal->rejectionReason());
        $this->assertNull($caller->run()->fresh()->cancellation_request_command_id);
        $this->assertSame('nexus_cancellation_target_root_conflict', ServiceOperationCancellation::unsupportedReason(
            $caller->run()
                ->fresh('historyEvents')
        )['unavailable_reason']);
        $this->assertSame(
            $original->toArray(),
            CooperativeCancellationDelivery::context($target->run()->fresh())->toArray()
        );
    }

    private function catalog(): WorkflowServiceOperation
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'service',
            'endpoint_name' => 'php-service',
        ]);
        $service = WorkflowService::query()->create([
            'namespace' => 'service',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'greeter',
        ]);
        return WorkflowServiceOperation::query()->create([
            'namespace' => 'service',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'operation_name' => 'greet',
            'operation_mode' => 'sync',
            'handler_binding_kind' => 'start_workflow',
            'handler_target_reference' => TestNexusCancellationTargetWorkflow::class,
            'deadline_policy' => [
                'timeout_seconds' => 120,
            ],
            'cancellation_policy' => [
                'allow_cancel' => true,
                'propagate_to_linked_workflow' => true,
            ],
        ]);
    }

    private function ready(WorkflowStub $workflow, TaskType $type = TaskType::Workflow): WorkflowTask
    {
        return $workflow->run()
            ->tasks()
            ->where('task_type', $type->value)
            ->where('status', TaskStatus::Ready->value)->sole();
    }

    private function runTask(WorkflowTask $task): void
    {
        $job = $task->task_type === TaskType::Timer ? new RunTimerTask($task->id) : new RunWorkflowTask($task->id);
        $this->app->call([$job, 'handle']);
    }
}
