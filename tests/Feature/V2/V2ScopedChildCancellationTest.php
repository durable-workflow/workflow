<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\CancellationScopeDelivery;
use Workflow\V2\Support\CancellationScopeHistory;
use Workflow\V2\Support\CancellationScopeRequests;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\Support\ScopedChildCancellation;
use Workflow\V2\WorkflowStub;

final class V2ScopedChildCancellationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        Carbon::setTestNow('2026-10-03T00:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testPreparationFreezesAllPoliciesWithoutTouchingChildrenSiblingsOrShields(): void
    {
        [$run, $task, $scope, $sibling, $shield] = $this->tree();
        $target = [];
        foreach (['try_cancel', 'wait_cancellation_completed', 'abandon'] as $index => $policy) {
            $target[] = $this->child($run, $task, $scope, 4 + $index, $policy);
        }
        $other = $this->child($run, $task, $sibling, 7);
        $shielded = $this->child($run, $task, $shield, 8);
        $before = $task->fresh()
            ->getAttributes();
        $prepared = $this->prepare($run, $task, $scope);
        $this->assertSame(array_column($target, 'child_workflow_run_id'), array_column(
            $prepared['child_members'],
            'child_workflow_run_id'
        ));
        $this->assertSame(['try_cancel', 'wait_cancellation_completed', 'abandon'], array_column(
            $prepared['child_members'],
            'cancellation_policy'
        ));
        foreach ([...$target, $other, $shielded] as $child) {
            $this->assertNull(
                WorkflowRun::query()->findOrFail($child['child_workflow_run_id'])->cancellation_request_command_id
            );
        }
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertNull($run->fresh()->cancellation_request_command_id);
        $this->assertFalse($run->fresh()->status->isTerminal());
        $timeline = collect(HistoryTimeline::fromHistory($run->fresh()))
            ->firstWhere('id', $prepared['preparation_history_event_id']);
        $this->assertSame($prepared['child_members'], ScopedChildCancellation::normalizeMembers(
            $timeline['cancellation_scope']['child_members']
        ));
    }

    public function testColdPreparationIgnoresMissingProjectionsAndChangedCurrentRun(): void
    {
        [$run, $task, $scope] = $this->tree();
        $child = $this->child($run, $task, $scope, 4);
        $prepared = $this->prepare($run, $task, $scope);
        WorkflowChildCall::query()->where('parent_workflow_run_id', $run->id)->delete();
        WorkflowLink::query()->where('parent_workflow_run_id', $run->id)->delete();
        $target = WorkflowRun::query()->findOrFail($child['child_workflow_run_id']);
        $target->instance()
            ->firstOrFail()
            ->forceFill([
                'current_run_id' => null,
            ])->save();
        $cold = CancellationScopeDelivery::prepared($run->fresh(), $scope);
        $this->assertSame($prepared['preparation_history_event_id'], $cold->id);
        $this->assertSame($prepared['child_members'], ScopedChildCancellation::normalizeMembers(
            $cold->payload['child_members']
        ));
        $this->assertSame($prepared, $this->prepare($run->fresh(), $task->fresh(), $scope));
    }

    public function testOnlyChildStartsCommittedBeforePreparationSelectItsOriginalRun(): void
    {
        [$run, $task, $scope] = $this->tree();
        $child = $this->child($run, $task, $scope, 4);
        $this->started($run, $task, $child, 'continued-before-preparation');
        $prepared = $this->prepare($run, $task, $scope);
        $this->assertSame('continued-before-preparation', $prepared['child_members'][0]['child_workflow_run_id']);
        $this->started($run, $task, $child, 'continued-after-preparation');
        $this->assertSame('continued-after-preparation', ScopedChildCancellation::members(
            $run->fresh(),
            $scope
        )[0]['child_workflow_run_id']);
        $this->assertSame($prepared, $this->prepare($run->fresh(), $task->fresh(), $scope));
        $this->assertSame($prepared['child_members'], ScopedChildCancellation::normalizeMembers(
            CancellationScopeDelivery::prepared($run->fresh(), $scope)->payload['child_members']
        ));
    }

    public function testNaturalChildCompletionKeepsTheOriginalPreparedTargetAndPolicy(): void
    {
        [$run, $task, $scope] = $this->tree();
        $child = $this->child($run, $task, $scope, 4);
        $prepared = $this->prepare($run, $task, $scope);
        WorkflowHistoryEvent::record($run->fresh(), HistoryEventType::ChildRunCompleted, [
            ...array_intersect_key($child, array_flip([
                'sequence', 'child_call_id', 'workflow_link_id', 'child_workflow_instance_id',
                'child_workflow_run_id', 'child_workflow_class', 'child_workflow_type',
            ])),
            'result' => Serializer::serializeWithCodec('avro', ['done']),
        ], $task);
        $this->assertSame($prepared, $this->prepare($run->fresh(), $task->fresh(), $scope));
        $this->assertSame(1, $run->historyEvents()->where('event_type', HistoryEventType::ChildRunCompleted)->count());
    }

    #[DataProvider('changedMemberFields')]
    public function testColdReplayRejectsAlteredOriginalChildMembership(string $field, mixed $value): void
    {
        [$run, $task, $scope] = $this->tree();
        $this->child($run, $task, $scope, 4);
        $prepared = $this->prepare($run, $task, $scope);
        $event = WorkflowHistoryEvent::query()->findOrFail($prepared['preparation_history_event_id']);
        $payload = $event->payload;
        $payload['child_members'][0][$field] = $value;
        $event->forceFill([
            'payload' => $payload,
        ])->save();
        $this->expectExceptionMessage('cancellation_scope_delivery_history_invalid');
        CancellationScopeDelivery::prepared($run->fresh(), $scope);
    }

    public static function changedMemberFields(): iterable
    {
        yield 'sequence' => ['sequence', 99];
        yield 'call' => ['child_call_id', 'other-call'];
        yield 'instance' => ['child_workflow_instance_id', 'other-instance'];
        yield 'run' => ['child_workflow_run_id', 'other-run'];
        yield 'policy' => ['cancellation_policy', 'abandon'];
        yield 'hash' => ['descriptor_hash', str_repeat('a', 64)];
        yield 'unknown policy' => ['cancellation_policy', 'invalid'];
        yield 'extra field' => ['extra', 'value'];
        yield 'empty identity' => ['child_call_id', ''];
        yield 'wrong identity type' => ['child_workflow_run_id', 42];
    }

    public function testReplacementClaimReplaysOriginalDeadlineAndDatabaseKeyOrder(): void
    {
        [$run, $task, $scope] = $this->tree();
        $this->child($run, $task, $scope, 4);
        $prepared = $this->prepare($run, $task, $scope);
        $event = WorkflowHistoryEvent::query()->findOrFail($prepared['preparation_history_event_id']);
        $payload = $event->payload;
        $payload['child_members'][0] = array_reverse($payload['child_members'][0], true);
        $event->forceFill([
            'payload' => $payload,
        ])->save();
        Carbon::setTestNow('2026-10-03T00:00:20Z');
        $task->forceFill([
            'lease_owner' => 'replacement-child-owner',
            'attempt_count' => 2,
        ])->save();
        $this->assertSame($prepared, $this->prepare($run->fresh(), $task->fresh(), $scope));
        $this->assertSame('2026-10-03T00:00:30.000000Z', $prepared['authority_deadline_at']);
    }

    public function testChildSnapshotCannotBeMistakenForCommittedCancellation(): void
    {
        [$run, $task, $scope] = $this->tree();
        $child = $this->child($run, $task, $scope, 4);
        $prepared = $this->prepare($run, $task, $scope);
        $before = $run->historyEvents()
            ->count();
        $response = app(DefaultWorkflowTaskBridge::class)->deliverCancellationScope(
            $task->id,
            $task->lease_owner,
            $task->attempt_count,
            $scope,
            $prepared['request_id'],
            4,
            'child',
            protocolVersion: '1.20'
        );
        $this->assertFalse($response['delivered']);
        $this->assertSame(['scoped_child_delivery'], $response['unavailable']);
        try {
            CancellationScopeDelivery::record(
                $run->fresh(),
                $task->fresh(),
                $scope,
                $prepared['request_id'],
                4,
                'child',
                '1.20'
            );
            $this->fail('A child membership snapshot was treated as cancellation proof.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_child_delivery_not_established', $error->getMessage());
        }
        $this->assertSame($before, $run->historyEvents()->count());
        $this->assertNull(
            WorkflowRun::query()->findOrFail($child['child_workflow_run_id'])->cancellation_request_command_id
        );
        $this->assertNull(CancellationScopeDelivery::recorded($run->fresh(), $scope));
    }

    /**
     * @return array{WorkflowRun, WorkflowTask, string, string, string}
     */
    private function tree(): array
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class);
        $workflow->start();
        $run = $workflow->run();
        $task = $run->tasks()
            ->where('task_type', TaskType::Workflow)->sole();
        $task->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'child-scope-owner',
            'attempt_count' => 1,
            'lease_expires_at' => now()
                ->addMinutes(5),
        ])->save();
        $scope = CancellationScopeHistory::open($run, $task, 1, '1.20')->payload['scope_id'];
        $sibling = CancellationScopeHistory::open($run, $task, 2, '1.20')->payload['scope_id'];
        $shield = CancellationScopeHistory::open($run, $task, 3, '1.20', $scope, true)->payload['scope_id'];
        return [$run, $task->refresh(), $scope, $sibling, $shield];
    }

    /**
     * @return array<string, mixed>
     */
    private function child(
        WorkflowRun $run,
        WorkflowTask $task,
        string $scope,
        int $sequence,
        string $policy = 'try_cancel'
    ): array {
        $result = app(DefaultWorkflowTaskBridge::class)->checkpointCancellationScopePrefix(
            $task->id,
            $task->lease_owner,
            $task->attempt_count,
            'child-' . $sequence,
            $sequence,
            [[
                'type' => 'start_child_workflow',
                'workflow_type' => 'scoped-child-fixture',
                'arguments' => Serializer::serializeWithCodec('avro', []),
                'payload_codec' => 'avro',
                'cancellation_scope_id' => $scope,
                'cancellation_policy' => $policy,
            ]],
            '1.20'
        );
        $this->assertTrue($result['checkpointed'], $result['reason'] ?? '');
        return $run->historyEvents()
            ->where('event_type', HistoryEventType::ChildWorkflowScheduled)
            ->where('payload->sequence', $sequence)
            ->sole()
->payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function prepare(WorkflowRun $run, WorkflowTask $task, string $scope): array
    {
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $result = app(DefaultWorkflowTaskBridge::class)->prepareCancellationScopeDelivery(
            $task->id,
            $task->lease_owner,
            $task->attempt_count,
            $scope,
            $request->payload['request_id'],
            4,
            'child',
            protocolVersion: '1.20'
        );
        $this->assertTrue($result['prepared'], $result['reason'] ?? '');
        return $result;
    }

    /**
     * @param array<string, mixed> $child
     */
    private function started(WorkflowRun $run, WorkflowTask $task, array $child, string $childRunId): void
    {
        WorkflowHistoryEvent::record($run->fresh(), HistoryEventType::ChildRunStarted, [
            ...array_diff_key($child, [
                'task' => true,
            ]),
            'child_workflow_run_id' => $childRunId,
        ], $task);
    }
}
