<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\PreparedLocalActivityGroupTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Support\CancellationScopeHistory;
use Workflow\V2\Support\CancellationScopeRequests;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\ParallelChildGroup;
use Workflow\V2\WorkflowStub;

final class V2ScopedLocalControlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        Carbon::setTestNow('2026-10-05T00:00:00.000000Z');
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('requests')]
    public function testOriginalScalarControlPreparesThenFencesTheAcceptedScope(bool $runRequest, bool $nested): void
    {
        [$workflow, $run, $task, $parent, $scope, $sequence, $local, $context] = $this->claim($runRequest, $nested);
        Carbon::setTestNow(now()->addSecond());
        $before = $task->fresh()
            ->getAttributes();
        $control = $this->bridge()
            ->controlLocalActivity($local['activity_attempt_id'], 'original', 1, true, '1.20');
        $this->assertSame('cancellation_scope_requested', $control['reason']);
        $this->assertFalse($control['active']);
        $this->assertFalse($control['renewed']);
        $this->assertTrue($control['fenced']);
        $this->assertArrayNotHasKey('_scope_control', $control);
        $this->assertSameJsonObject($context->toArray(), $control['cancellation_scope']['cancellation']);
        $this->assertSame($scope, $control['cancellation_scope']['scope_id']);
        $this->assertSame($before, $task->fresh()->getAttributes());
        $preparation = $run->historyEvents()
            ->where('event_type', HistoryEventType::CancellationScopeDeliveryPrepared)->sole();
        $fence = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityCancelled)->sole();
        $this->assertSame($parent, $preparation->payload['scope_id']);
        $this->assertSame($sequence, $preparation->payload['sequence']);
        $this->assertSame('local_activity', $preparation->payload['call_kind']);
        $this->assertLessThan($fence->sequence, $preparation->sequence);
        $this->assertSame($context->requestId, $fence->workflow_command_id);
        $this->assertSame(
            $context->rootContext->rootRequestId,
            $fence->payload['cancellation_scope']['cancellation']['root_context']['root_request_id']
        );
        $again = $this->bridge()
            ->controlLocalActivity($local['activity_attempt_id'], 'original', 1, true, '1.20');
        $this->assertSame($control['cancellation_scope'], $again['cancellation_scope']);
        $this->assertSame(1, $run->historyEvents()->where('event_type', HistoryEventType::ActivityCancelled)->count());
        $this->assertTrue($this->bridge()->acknowledgeLocalActivityCancellation(
            $local['activity_attempt_id'],
            'original',
            $context->requestId,
            1,
            '1.20'
        )['acknowledged']);
        $parentContext = CancellationScopeRequests::context($run, $parent);
        $delivered = $this->bridge()
            ->deliverCancellationScope(
                $task->id,
                'original',
                1,
                $parent,
                $parentContext->requestId,
                $sequence,
                'local_activity',
                protocolVersion: '1.20'
            );
        $this->assertTrue($delivered['delivered'], $delivered['reason'] ?? '');
        $this->assertFalse($delivered['claim_released']);
        $this->assertSame(TaskStatus::Leased, $task->fresh()->status);
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityHeartbeatRecorded)->count()
        );
    }

    public static function requests(): iterable
    {
        foreach ([false, true] as $run) {
            foreach ([false, true] as $nested) {
                yield ($run ? 'run' : 'direct') . '-' . ($nested ? 'nested' : 'scalar') => [$run, $nested];
            }
        }
    }

    #[DataProvider('invalidClaims')]
    public function testInvalidClaimCannotPrepareOrFenceTheScope(string $fault): void
    {
        [, $run, $task, , , , $local] = $this->claim(true, false);
        $owner = 'original';
        $epoch = 1;
        if ($fault === 'owner') {
            $owner = 'other';
        }
        if ($fault === 'epoch') {
            $epoch = 2;
        }
        if ($fault === 'expired') {
            $task->forceFill([
                'lease_expires_at' => now()
                    ->subSecond(),
            ])->save();
        }
        $before = $task->fresh()
            ->getAttributes();
        $operation = fn () => $this->bridge()
            ->controlLocalActivity($local['activity_attempt_id'], $owner, $epoch, true, '1.20');
        $control = $fault === 'outer transaction' ? DB::transaction($operation) : $operation();
        $this->assertFalse($control['active']);
        $this->assertFalse($control['renewed']);
        $this->assertArrayNotHasKey('_scope_control', $control);
        $this->assertSame(
            $fault === 'expired' ? 'workflow_claim_expired'
            : ($fault === 'outer transaction' ? 'cancellation_scope_preparation_requires_own_transaction' : 'local_activity_preparation_mismatch'),
            $control['reason']
        );
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame(0, $run->historyEvents()->whereIn('event_type', [
            HistoryEventType::CancellationScopeDeliveryPrepared, HistoryEventType::ActivityCancelled,
        ])->count());
    }

    public static function invalidClaims(): iterable
    {
        foreach (['owner', 'epoch', 'expired', 'outer transaction'] as $fault) {
            yield $fault => [$fault];
        }
    }

    public function testTakeoverBetweenPreparationAndFencingCannotBorrowTheReplacementClaim(): void
    {
        [, $run, $task, , , , $local] = $this->claim(true, false);
        $original = WorkflowHistoryEvent::getEventDispatcher();
        $this->assertNotNull($original);
        $dispatcher = clone $original;
        $dispatcher->listen(
            'eloquent.created: ' . WorkflowHistoryEvent::class,
            static function (WorkflowHistoryEvent $event) use ($task): void {
                if ($event->event_type === HistoryEventType::CancellationScopeDeliveryPrepared) {
                    $task->forceFill([
                        'lease_owner' => 'replacement',
                        'attempt_count' => 2,
                        'lease_expires_at' => now()
                            ->addSeconds(20),
                    ])->save();
                }
            }
        );
        WorkflowHistoryEvent::setEventDispatcher($dispatcher);
        try {
            $control = $this->bridge()
                ->controlLocalActivity($local['activity_attempt_id'], 'original', 1, true, '1.20');
        } finally {
            WorkflowHistoryEvent::setEventDispatcher($original);
        }
        $this->assertSame('cancellation_scope_workflow_claim_mismatch', $control['reason']);
        $this->assertFalse($control['active']);
        $this->assertFalse($control['renewed']);
        $this->assertArrayNotHasKey('_scope_control', $control);
        $this->assertSame('replacement', $task->fresh()->lease_owner);
        $this->assertSame(2, $task->fresh()->attempt_count);
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeDeliveryPrepared)->count()
        );
        $this->assertSame(0, $run->historyEvents()->where('event_type', HistoryEventType::ActivityCancelled)->count());
    }

    public function testAGroupMemberCannotFreezeAScalarBoundaryOrRevokeItsSibling(): void
    {
        [, $run, $task, , , , $local] = $this->claim(true, false, true);
        $before = $task->fresh()
            ->getAttributes();
        $control = $this->bridge()
            ->controlLocalActivity($local['activity_attempt_id'], 'original', 1, true, '1.20');
        $this->assertFalse($control['active']);
        $this->assertFalse($control['renewed']);
        $this->assertSame('cancellation_scope_local_group_control_not_supported', $control['reason']);
        $this->assertArrayNotHasKey('_scope_control', $control);
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame(0, $run->historyEvents()->whereIn('event_type', [
            HistoryEventType::CancellationScopeDeliveryPrepared, HistoryEventType::ActivityCancelled,
        ])->count());
        $this->assertSame(2, $run->activityExecutions()->count());
    }

    private function claim(bool $runRequest, bool $nested, bool $group = false): array
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'scope-local-control');
        $workflow->start();
        $run = $workflow->run()
            ->fresh();
        $task = $run->tasks()
            ->where('task_type', TaskType::Workflow)->sole();
        $task->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'original',
            'attempt_count' => 1,
            'lease_expires_at' => now()
                ->addSeconds(60),
        ])->save();
        $parent = CancellationScopeHistory::open($run, $task, 1, '1.20')->payload['scope_id'];
        $scope = $nested ? CancellationScopeHistory::open(
            $run,
            $task,
            2,
            '1.20',
            $parent
        )->payload['scope_id'] : $parent;
        $sequence = $nested ? 3 : 2;
        $descriptor = [
            'type' => 'record_local_activity',
            'activity_type' => 'scoped-work',
            'arguments' => Serializer::serializeWithCodec('avro', []),
            'payload_codec' => 'avro',
            'cancellation_scope_id' => $scope,
            'cancellation_policy' => 'wait_cancellation_completed',
        ];
        if ($group) {
            $commands = [];
            foreach ([0, 1] as $index) {
                $commands[] = [
                    ...$descriptor,
                    'type' => 'prepare_local_activity',
                    ...ParallelChildGroup::itemMetadata($sequence, 2, $index, 'mixed'),
                ];
            }
            $checkpoint = app(PreparedLocalActivityGroupTaskBridge::class)->checkpointLocalActivityGroup(
                $task->id,
                'original',
                1,
                'scoped-group',
                $sequence,
                $commands,
                '1.20'
            );
            $this->assertTrue($checkpoint['checkpointed'], $checkpoint['reason'] ?? '');
            $descriptor = [
                ...$commands[0],
                'type' => 'record_local_activity',
            ];
        }
        $local = $this->bridge()
            ->prepareLocalActivity($task->id, 'original', 1, $sequence, 'local-original', $descriptor, '1.20');
        $this->assertTrue($local['prepared'], $local['reason'] ?? '');
        if ($runRequest) {
            $workflow->requestCancellation('finish the run', 30);
        } else {
            CancellationScopeRequests::request($run, $parent, '1.20', 30, 'stop only this subtree');
            if ($nested) {
                CancellationScopeRequests::request($run, $scope, '1.20', 30, parentScopeId: $parent);
            }
        }
        $run->refresh();
        $context = CancellationScopeRequests::context($run, $scope);
        $this->assertNotNull($context);

        return [$workflow, $run, $task, $parent, $scope, $sequence, $local, $context];
    }

    private function bridge(): DefaultWorkflowTaskBridge
    {
        return app(DefaultWorkflowTaskBridge::class);
    }
}
