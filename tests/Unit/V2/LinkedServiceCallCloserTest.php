<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\TestCase;
use Workflow\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\LinkedServiceCallCloser;

final class LinkedServiceCallCloserTest extends TestCase
{
    public function testCompletionClosesOriginalCallAndRetainsSerializedResult(): void
    {
        $run = $this->createRun();
        $call = $this->createCall($run);
        $blob = Serializer::serializeWithCodec('avro', ['greeting' => 'hello world']);
        $event = WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowCompleted, [
            'output' => $blob,
            'payload_codec' => 'avro',
        ]);

        $call->refresh();
        $this->assertSame('completed', $call->status);
        $this->assertSame(ServiceCallOutcome::Completed, $call->outcome);
        $this->assertTrue($event->recorded_at->equalTo($call->completed_at));
        $this->assertSame(['codec' => 'avro', 'blob' => $blob], $call->metadata['nexus_result']);
        $this->assertSame('caller', $call->caller_namespace);
        $this->assertSame($run->id, $call->linked_workflow_run_id);

        WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->delete();
        $view = (new DefaultServiceControlPlane())->describeCall($call->id, ['namespace' => 'service']);
        $this->assertSame(['codec' => 'avro', 'blob' => $blob], $view['result_envelope']);
        $this->assertSame('completed', $view['status']);
    }

    public function testLateBindingUsesOriginalTerminalTimestampAndNeverChangesTheWinner(): void
    {
        $run = $this->createRun();
        $first = WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowCompleted, [
            'output' => Serializer::serializeWithCodec('avro', 'first'),
            'payload_codec' => 'avro',
        ]);
        $call = $this->createCall($run);
        LinkedServiceCallCloser::reconcile($call);
        $saved = $call->getAttributes();

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowFailed, [
            'exception_type' => 'LateFailure',
            'message' => 'a later observation must not replace completion',
        ]);
        LinkedServiceCallCloser::reconcile($call);

        $this->assertSame($saved, $call->refresh()->getAttributes());
        $this->assertSame($first->id, $call->metadata['linked_terminal_event_id']);
        $this->assertTrue($first->recorded_at->equalTo($call->completed_at));
        $this->assertSame(0, (new DefaultServiceControlPlane())->describeCall(
            $call->id,
            ['namespace' => 'service'],
        )['retry_attempt_count']);
    }

    public function testFailureCancellationAndTerminationCloseWithoutLosingTypedReason(): void
    {
        foreach ([
            [HistoryEventType::WorkflowFailed, 'failed', ServiceCallOutcome::HandlerFailed, 'failed_at'],
            [HistoryEventType::WorkflowCancelled, 'cancelled', ServiceCallOutcome::Cancelled, 'cancelled_at'],
            [HistoryEventType::WorkflowTerminated, 'failed', ServiceCallOutcome::HandlerFailed, 'failed_at'],
        ] as [$type, $status, $outcome, $timestamp]) {
            $run = $this->createRun();
            $call = $this->createCall($run);
            $event = WorkflowHistoryEvent::record($run, $type, [
                'exception_class' => 'ServiceFailure',
                'message' => 'service stopped',
            ]);

            $call->refresh();
            $this->assertSame($status, $call->status);
            $this->assertSame($outcome, $call->outcome);
            $this->assertTrue($event->recorded_at->equalTo($call->{$timestamp}));
            $view = (new DefaultServiceControlPlane())->describeCall($call->id, ['namespace' => 'service']);
            $this->assertSame('ServiceFailure', $view['error_type']);
            $this->assertSame('service stopped', $view['failure_message']);
            $this->assertArrayNotHasKey('result_envelope', $view);
        }
    }

    public function testUnrelatedNamespaceRunInstanceAndBindingRemainOpen(): void
    {
        $run = $this->createRun();
        $another = $this->createRun();
        $calls = [
            $this->createCall($run, ['target_namespace' => 'another-namespace']),
            $this->createCall($run, ['linked_workflow_run_id' => $another->id]),
            $this->createCall($run, ['linked_workflow_instance_id' => $another->workflow_instance_id]),
            $this->createCall($run, ['resolved_binding_kind' => ServiceCallBindingKind::WorkflowUpdate->value]),
        ];
        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowCompleted, [
            'output' => Serializer::serializeWithCodec('avro', 'done'),
            'payload_codec' => 'avro',
        ]);

        foreach ($calls as $call) {
            LinkedServiceCallCloser::reconcile($call);
            $this->assertSame('started', $call->refresh()->status);
            $this->assertNull($call->completed_at);
        }
    }

    public function testExternalResultKeepsIntegrityMetadataWithoutCopyingProviderUriIntoMetadata(): void
    {
        $run = $this->createRun();
        $call = $this->createCall($run);
        $blob = Serializer::serializeWithCodec('avro', str_repeat('hello', 100));
        $reference = [
            'uri' => 'memory://service/result',
            'size_bytes' => strlen($blob),
            'sha256' => hash('sha256', $blob),
            'codec' => 'avro',
        ];
        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowCompleted, [
            'output' => ['codec' => 'avro', 'external_storage' => $reference],
            'payload_codec' => 'avro',
        ]);

        $call->refresh();
        $this->assertSame($reference['uri'], $call->output_payload_reference);
        $this->assertArrayNotHasKey('uri', $call->metadata['nexus_result']['external_reference']);
        $this->assertSame($reference['sha256'], $call->metadata['nexus_result']['external_reference']['sha256']);
        WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->delete();
        $view = (new DefaultServiceControlPlane())->describeCall($call->id, ['namespace' => 'service']);
        $this->assertSame(['codec' => 'avro', 'external_storage' => $reference], $view['result_envelope']);
    }

    private function createRun(): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'namespace' => 'service',
            'workflow_type' => 'nexus.service',
            'workflow_class' => 'nexus.service',
            'run_count' => 1,
        ]);

        return WorkflowRun::query()->create([
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
    }

    /** @param array<string, mixed> $overrides */
    private function createCall(WorkflowRun $run, array $overrides = []): WorkflowServiceCall
    {
        return WorkflowServiceCall::query()->create(array_merge([
            'namespace' => 'service',
            'endpoint_name' => 'php-service',
            'service_name' => 'greeter',
            'operation_name' => 'greet',
            'caller_namespace' => 'caller',
            'target_namespace' => 'service',
            'linked_workflow_instance_id' => $run->workflow_instance_id,
            'linked_workflow_run_id' => $run->id,
            'resolved_binding_kind' => ServiceCallBindingKind::WorkflowRun->value,
            'status' => 'started',
            'outcome' => ServiceCallOutcome::Accepted->value,
            'operation_mode' => 'async',
            'payload_codec' => 'avro',
            'accepted_at' => now(),
            'started_at' => now(),
        ], $overrides));
    }
}
