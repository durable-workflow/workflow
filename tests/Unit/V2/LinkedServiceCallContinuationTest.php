<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ContinuedRunMessageHandoff;
use Workflow\V2\Support\LinkedServiceCallCloser;
use Workflow\V2\TaskWatchdog;

final class LinkedServiceCallContinuationTest extends TestCase
{
    public function testCanonicalContinuationKeepsTheCallAndDeadlineUntilTheSuccessorCompletes(): void
    {
        Queue::fake();
        $run = $this->createRun();
        $call = $this->createServiceCall($run);
        $deadline = $call->call_deadline_at->toJSON();
        $accepted = $call->accepted_at->toJSON();
        $next = $this->continueRun($run);
        $this->assertSame('started', $call->refresh()->status);
        $this->assertSame($next->id, $call->linked_workflow_run_id);
        $this->assertSame($next->id, $call->resolved_target_reference);
        $this->assertSame($run->id, $call->metadata['linked_initial_workflow_run_id']);
        $this->assertSame($deadline, $call->call_deadline_at->toJSON());
        $this->assertSame($accepted, $call->accepted_at->toJSON());
        $this->assertSame(1, WorkflowServiceCall::query()->count());
        WorkflowHistoryEvent::record($next, HistoryEventType::WorkflowCompleted, [
            'output' => Serializer::serializeWithCodec('avro', [
                'answer' => false,
                'source' => 'Ω',
            ]),
            'payload_codec' => 'avro',
        ]);
        $this->assertSame('completed', $call->refresh()->status);
        $this->assertSame([
            'answer' => false,
            'source' => 'Ω',
        ], Serializer::unserializeWithCodec('avro', $call->metadata['nexus_result']['blob']));
        $before = $call->getRawOriginal();
        LinkedServiceCallCloser::reconcile($call);
        $this->assertSame($before, $call->fresh()->getRawOriginal());
    }

    public function testContinuationAndCompletionBeforeAdmissionAreReconciledFromCanonicalHistory(): void
    {
        Queue::fake();
        $run = $this->createRun();
        $next = $this->continueRun($run);
        WorkflowHistoryEvent::record($next, HistoryEventType::WorkflowCompleted, [
            'output' => Serializer::serializeWithCodec('avro', null),
            'payload_codec' => 'avro',
        ]);
        $call = $this->createServiceCall($run);
        LinkedServiceCallCloser::reconcile($call);
        $this->assertSame('completed', $call->status);
        $this->assertSame($next->id, $call->linked_workflow_run_id);
        $this->assertSame(Serializer::serializeWithCodec('avro', null), $call->metadata['nexus_result']['blob']);
    }

    public function testLongContinuationHistoryIsBoundedAndTheNormalRepairPassFinishesIt(): void
    {
        Queue::fake();
        $first = $this->createRun();
        $last = $first;
        for ($i = 0; $i < 40; $i++) {
            $last = $this->continueRun($last);
        }
        WorkflowHistoryEvent::record($last, HistoryEventType::WorkflowCompleted, [
            'output' => Serializer::serializeWithCodec('avro', 'done'),
            'payload_codec' => 'avro',
        ]);
        $call = $this->createServiceCall($first);
        LinkedServiceCallCloser::reconcile($call);
        $this->assertSame('started', $call->status);
        $this->assertNotSame($first->id, $call->linked_workflow_run_id);
        $this->assertNotSame($last->id, $call->linked_workflow_run_id);
        $report = TaskWatchdog::runPass('sync', 'default', runIds: [$call->linked_workflow_run_id]);
        $this->assertSame([], $report['service_call_linked_failures']);
        $this->assertSame(1, $report['service_call_links_reconciled']);
        $this->assertSame('completed', $call->refresh()->status);
        $this->assertSame($last->id, $call->linked_workflow_run_id);
    }

    #[DataProvider('invalidSuccessors')]
    public function testContinuationCannotFollowAnUnrelatedRun(string $changedField, mixed $value): void
    {
        Queue::fake();
        $run = $this->createRun();
        $next = $this->createRun();
        if ($changedField === 'workflow_instance_id') {
            $value = $next->workflow_instance_id;
        }
        $next->forceFill([
            'workflow_instance_id' => $run->workflow_instance_id,
            'run_number' => 2,
            $changedField => $value,
        ])->save();
        $call = $this->createServiceCall($run);
        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowContinuedAsNew, [
            'continued_to_run_id' => $next->id,
            'continued_to_run_number' => 2,
            'closed_reason' => 'continued',
        ]);
        LinkedServiceCallCloser::reconcile($call);
        $this->assertSame('started', $call->refresh()->status);
        $this->assertSame($run->id, $call->linked_workflow_run_id);
        $this->assertNull($call->completed_at);
    }

    public function testAReplacementCurrentRunIsNotACanonicalContinuation(): void
    {
        Queue::fake();
        $run = $this->createRun();
        $replacement = $run->replicate();
        $replacement->forceFill([
            'run_number' => 2,
        ])->save();
        $run->instance->forceFill([
            'current_run_id' => $replacement->id,
            'run_count' => 2,
        ])->save();
        $call = $this->createServiceCall($run);
        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowFailed, [
            'exception_class' => 'OriginalFailure',
            'message' => 'original failed',
        ]);
        WorkflowHistoryEvent::record($replacement, HistoryEventType::WorkflowCompleted, [
            'output' => Serializer::serializeWithCodec('avro', 'unrelated replacement'),
            'payload_codec' => 'avro',
        ]);
        LinkedServiceCallCloser::reconcile($call);
        $this->assertSame('failed', $call->refresh()->status);
        $this->assertSame($run->id, $call->linked_workflow_run_id);
        $this->assertSame('OriginalFailure', $call->outcome_metadata['service_error_type']);
        $this->assertArrayNotHasKey('nexus_result', $call->metadata);
    }

    public static function invalidSuccessors(): iterable
    {
        yield 'namespace' => ['namespace', 'foreign'];
        yield 'instance' => ['workflow_instance_id', 'foreign-instance'];
        yield 'type' => ['workflow_type', 'foreign-type'];
        yield 'backwards run number' => ['run_number', 0];
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
            'status' => RunStatus::Waiting,
            'payload_codec' => 'avro',
            'arguments' => Serializer::serializeWithCodec('avro', []),
            'connection' => 'sync',
            'queue' => 'default',
            'started_at' => now(),
        ]);
    }

    private function createServiceCall(WorkflowRun $run): WorkflowServiceCall
    {
        return WorkflowServiceCall::query()->create([
            'namespace' => 'service',
            'target_namespace' => 'service',
            'caller_namespace' => 'caller',
            'endpoint_name' => 'endpoint',
            'service_name' => 'service',
            'operation_name' => 'operation',
            'resolved_binding_kind' => 'workflow_run',
            'resolved_target_reference' => $run->id,
            'linked_workflow_instance_id' => $run->workflow_instance_id,
            'linked_workflow_run_id' => $run->id,
            'status' => 'started',
            'outcome' => 'accepted',
            'operation_mode' => 'sync',
            'payload_codec' => 'avro',
            'accepted_at' => now(),
            'started_at' => now(),
            'call_deadline_at' => now()
                ->addHour(),
        ]);
    }

    private function continueRun(WorkflowRun $run): WorkflowRun
    {
        return $run->getConnection()
            ->transaction(static function () use ($run): WorkflowRun {
                $next = $run->replicate();
                $next->forceFill([
                    'run_number' => $run->run_number + 1,
                    'last_history_sequence' => 0,
                ])->save();
                $run->instance->forceFill([
                    'current_run_id' => $next->id,
                    'run_count' => $next->run_number,
                ])->save();
                $link = WorkflowLink::query()->create([
                    'link_type' => 'continue_as_new',
                    'sequence' => 1,
                    'is_primary_parent' => true,
                    'parent_workflow_instance_id' => $run->workflow_instance_id,
                    'parent_workflow_run_id' => $run->id,
                    'child_workflow_instance_id' => $next->workflow_instance_id,
                    'child_workflow_run_id' => $next->id,
                ]);
                $task = WorkflowTask::query()->create([
                    'workflow_run_id' => $run->id,
                    'namespace' => $run->namespace,
                    'task_type' => TaskType::Workflow,
                    'status' => TaskStatus::Completed,
                    'connection' => 'sync',
                    'queue' => 'default',
                ]);
                ContinuedRunMessageHandoff::closePredecessor($run, $next, $task, 1, $link->id, now());
                return $next;
            });
    }
}
