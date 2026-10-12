<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestCancellationContextWorkflow;
use Tests\Fixtures\V2\TestNexusWaitingCallerWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Support\CooperativeCancellationDelivery;
use Workflow\V2\WorkflowStub;

final class ServiceCallCancellationOriginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        Carbon::setTestNow('2026-10-12T08:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testOwnedCrossNamespaceCallInheritsCanonicalIdentityAndOriginalBudget(): void
    {
        [$caller, $target, $call] = $this->ownedCall();
        $originalDeadline = $call->call_deadline_at->toISOString();
        $root = $caller->requestCancellation('maintenance', 30)
            ->cancellationContext();
        Carbon::setTestNow(now()->addSeconds(7));
        $first = $target->attemptRequestCancellationFromServiceCall($call->id);
        $duplicate = $target->attemptRequestCancellationFromServiceCall($call->id);
        $this->assertTrue($first->accepted());
        $this->assertTrue($duplicate->accepted());
        $context = $first->cancellationContext();
        $this->assertSame($root->rootRequestId, $context->rootRequestId);
        $this->assertSame($root->requestId, $context->parentRequestId);
        $this->assertSame($root->requester, $context->requester);
        $this->assertSame($root->source, $context->source);
        $this->assertSame($root->reason, $context->reason);
        $this->assertSame($root->deadline()->toISOString(), $context->deadline()->toISOString());
        $this->assertSame($context->toArray(), $duplicate->cancellationContext()->toArray());
        $this->assertCount(2, $context->lineage);
        $this->assertFalse($target->run()->fresh()->status->isTerminal());
        $this->assertSame($originalDeadline, $call->refresh()->call_deadline_at->toISOString());
        $this->assertSame('started', $call->status);
        $this->assertSame(1, $target->run()->historyEvents()
            ->where('event_type', HistoryEventType::CooperativeCancellationRequested->value)->count());
        $this->assertSame(
            $context->toArray(),
            CooperativeCancellationDelivery::context($target->run()->fresh())->toArray()
        );
    }

    #[DataProvider('unownedCalls')]
    public function testUnsupportedOrUnownedCallCannotSupplyCancellationAuthority(array $changes, string $reason): void
    {
        [$caller, $target, $call] = $this->ownedCall();
        $caller->requestCancellation('maintenance', 30);
        $call->forceFill($changes)
            ->save();
        $result = $target->attemptRequestCancellationFromServiceCall($call->id);
        $this->assertTrue($result->rejected());
        $this->assertSame($reason, $result->rejectionReason());
        $this->assertNull($target->run()->fresh()->cancellation_request_command_id);
        $this->assertSame(0, $target->run()->historyEvents()
            ->where('event_type', HistoryEventType::CooperativeCancellationRequested->value)->count());
    }

    public static function unownedCalls(): iterable
    {
        foreach (['workflow_query', 'workflow_update', 'workflow_signal', 'activity_execution'] as $kind) {
            yield $kind => [[
                'resolved_binding_kind' => $kind,
            ], 'nexus_cancellation_binding_or_policy_not_supported'];
        }
        foreach ([[], [
            'allow_cancel' => true,
        ], [
            'propagate_to_linked_workflow' => true,
        ],
            [
                'allow_cancel' => false,
                'propagate_to_linked_workflow' => true,
            ],
            [
                'allow_cancel' => true,
                'propagate_to_linked_workflow' => false,
            ],
            [
                'allow_cancel' => 'true',
                'propagate_to_linked_workflow' => true,
            ]] as $index => $policy) {
            yield 'policy ' . $index => [[
                'cancellation_policy' => $policy,
            ], 'nexus_cancellation_binding_or_policy_not_supported'];
        }
        foreach (['caller_namespace', 'caller_workflow_instance_id', 'target_namespace',
            'linked_workflow_instance_id', 'linked_workflow_run_id'] as $field) {
            yield $field => [[
                $field => 'another-owner',
            ], 'nexus_cancellation_owner_target_mismatch'];
        }
        yield 'missing caller' => [[
            'caller_workflow_run_id' => 'missing-run',
        ], 'nexus_cancellation_owner_unavailable'];
    }

    public function testMissingRecordedAdmissionCannotAuthorizeTargetCancellation(): void
    {
        [$caller, $target, $call] = $this->ownedCall();
        $caller->requestCancellation('maintenance', 30);
        $caller->run()
            ->historyEvents()
            ->where('event_type', HistoryEventType::ServiceCallStarted->value)->delete();
        $result = $target->attemptRequestCancellationFromServiceCall($call->id);
        $this->assertSame('nexus_cancellation_original_admission_missing', $result->rejectionReason());
        $this->assertNull($target->run()->fresh()->cancellation_request_command_id);
    }

    public function testCallWithoutCanonicalCallerRequestCannotCreateANewRoot(): void
    {
        [, $target, $call] = $this->ownedCall();
        $result = $target->attemptRequestCancellationFromServiceCall($call->id);
        $this->assertSame('nexus_cancellation_context_mismatch', $result->rejectionReason());
        $this->assertNull($target->run()->fresh()->cancellation_request_command_id);
    }

    public function testUnknownCallCannotAuthorizeCancellation(): void
    {
        [, $target] = $this->ownedCall();
        $result = $target->attemptRequestCancellationFromServiceCall('unknown-call');
        $this->assertSame('nexus_cancellation_owner_unavailable', $result->rejectionReason());
        $this->assertNull($target->run()->fresh()->cancellation_request_command_id);
    }

    public function testSelfReferenceCannotExtendCancellationLineage(): void
    {
        [$caller, , $call] = $this->ownedCall();
        $root = $caller->requestCancellation('maintenance', 30)
            ->cancellationContext();
        $call->forceFill([
            'target_namespace' => 'caller',
            'linked_workflow_instance_id' => $caller->id(),
            'linked_workflow_run_id' => $caller->runId(),
        ])->save();
        $result = $caller->attemptRequestCancellationFromServiceCall($call->id);
        $this->assertSame('nexus_cancellation_lineage_cycle', $result->rejectionReason());
        $this->assertSame(
            $root->toArray(),
            CooperativeCancellationDelivery::context($caller->run()->fresh())->toArray()
        );
    }

    public function testOriginalTargetRequestWinsAnIndependentRootConflict(): void
    {
        [$caller, $target, $call] = $this->ownedCall();
        $original = $target->requestCancellation('target owner', 20)
            ->cancellationContext();
        $caller->requestCancellation('caller owner', 30);
        $result = $target->attemptRequestCancellationFromServiceCall($call->id);
        $this->assertSame('cancellation_root_conflict', $result->rejectionReason());
        $this->assertSame(
            $original->toArray(),
            CooperativeCancellationDelivery::context($target->run()->fresh())->toArray()
        );
    }

    public function testAcceptedCommandWithoutMatchingCanonicalHistoryCannotPropagate(): void
    {
        [$caller, $target, $call] = $this->ownedCall();
        $caller->requestCancellation('maintenance', 30);
        $caller->run()
            ->historyEvents()
            ->where('event_type', HistoryEventType::CooperativeCancellationRequested->value)->delete();
        $result = $target->attemptRequestCancellationFromServiceCall($call->id);
        $this->assertSame('nexus_cancellation_context_mismatch', $result->rejectionReason());
        $this->assertNull($target->run()->fresh()->cancellation_request_command_id);
    }

    #[DataProvider('admissionModes')]
    public function testAdmissionOnlyCallsDoNotOwnAWaitingCancellationBoundary(string $mode, string $waitFor): void
    {
        [$caller, $target, $call] = $this->ownedCall();
        $event = $caller->run()
            ->historyEvents()
            ->where('event_type', HistoryEventType::ServiceCallStarted->value)->sole();
        $event->forceFill([
            'payload' => [
                ...$event->payload,
                'operation_mode' => $mode,
                'wait_for' => $waitFor,
            ],
        ])->save();
        $caller->requestCancellation('maintenance', 30);
        $result = $target->attemptRequestCancellationFromServiceCall($call->id);
        $this->assertSame('nexus_cancellation_original_admission_missing', $result->rejectionReason());
        $this->assertNull($target->run()->fresh()->cancellation_request_command_id);
    }

    public static function admissionModes(): iterable
    {
        yield 'async' => ['async', 'completed'];
        yield 'accepted' => ['sync', 'accepted'];
    }

    /**
     * @return array{WorkflowStub, WorkflowStub, WorkflowServiceCall}
     */
    private function ownedCall(): array
    {
        $caller = WorkflowStub::make(TestNexusWaitingCallerWorkflow::class, 'nexus-caller', 'caller');
        $caller->start('world');
        $target = WorkflowStub::make(TestCancellationContextWorkflow::class, 'nexus-target', 'target');
        $target->start();
        $call = WorkflowServiceCall::query()->create([
            'namespace' => 'target',
            'caller_namespace' => 'caller',
            'target_namespace' => 'target',
            'endpoint_name' => 'greeter',
            'service_name' => 'shared',
            'operation_name' => 'greet',
            'caller_workflow_instance_id' => $caller->id(),
            'caller_workflow_run_id' => $caller->runId(),
            'linked_workflow_instance_id' => $target->id(),
            'linked_workflow_run_id' => $target->runId(),
            'resolved_binding_kind' => 'workflow_run',
            'operation_mode' => 'sync',
            'status' => 'started',
            'cancellation_policy' => [
                'allow_cancel' => true,
                'propagate_to_linked_workflow' => true,
            ],
            'call_deadline_at' => now()
                ->addSeconds(120),
        ]);
        WorkflowHistoryEvent::record($caller->run(), HistoryEventType::ServiceCallStarted, [
            'sequence' => 1,
            'service_call_id' => $call->id,
            'operation_mode' => 'sync',
            'wait_for' => 'completed',
        ]);

        return [$caller, $target, $call];
    }
}
