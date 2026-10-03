<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\CancellationScopeDelivery;
use Workflow\V2\Support\CancellationScopeHistory;
use Workflow\V2\Support\CancellationScopeRequests;
use Workflow\V2\Support\DefaultActivityTaskBridge;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\Support\ParallelChildGroup;
use Workflow\V2\Support\ScopedActivityCancellation;
use Workflow\V2\Support\ScopedTimerCancellation;
use Workflow\V2\Support\WorkflowStepHistory;
use Workflow\V2\WorkflowStub;

final class V2CancellationScopeDeliveryTest extends TestCase
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

    #[DataProvider('callKinds')]
    public function testUnscheduledCallRecordsScopeBoundaryWithoutRunCancellationOrClaimMutation(string $kind): void
    {
        [, $run, $task, , $scope] = $this->scopeTree();
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30, 'release one scope');
        $claimBefore = $task->fresh()
            ->getAttributes();
        $event = $this->deliver($run, $task, $scope, $kind);
        $this->assertSame(HistoryEventType::CancellationScopeDelivered, $event->event_type);
        $this->assertSame($request->payload['request_id'], $event->payload['request_id']);
        $this->assertSame($scope, $event->payload['scope_id']);
        $this->assertSame(3, $event->payload['sequence']);
        $this->assertSame(4, WorkflowStepHistory::nextDurableCommandSequence($run->fresh()));
        $this->assertSameJsonObject($request->payload['cancellation'], $event->payload['cancellation']);
        $this->assertSame('2026-10-03T00:00:30.000000Z', $event->payload['authority_deadline_at']);
        $this->assertSame($claimBefore, $task->fresh()->getAttributes());
        $this->assertSame(1, $run->tasks()->count());
        $this->assertNull($run->fresh()->cancellation_request_command_id);
        $this->assertNull($run->fresh()->cancellation_delivery_sequence);
        $this->assertNull($run->fresh()->cancellation_delivered_at);
        $this->assertFalse($run->fresh()->status->isTerminal());
        $timeline = collect(HistoryTimeline::fromHistory($run->fresh()))->firstWhere('id', $event->id);
        $this->assertSame('cancellation_scope', $timeline['kind']);
        $this->assertSameJsonObject($event->payload['cancellation'], $timeline['cancellation_scope']['cancellation']);
        $this->assertSame(3, $timeline['cancellation_scope']['sequence']);
    }

    public static function callKinds(): iterable
    {
        foreach (['activity', 'local_activity', 'timer', 'condition', 'signal', 'child'] as $kind) {
            yield $kind => [$kind];
        }
    }

    public function testResponseLossAndReplacementClaimReplayOriginalBoundaryAndClockWithoutNewBudget(): void
    {
        [, $run, $task, , $scope] = $this->scopeTree();
        CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $event = $this->deliver($run, $task, $scope);
        $original = $event->payload;
        Carbon::setTestNow('2026-10-03T00:00:11Z');
        $replacement = $task->fresh();
        $replacement->forceFill([
            'lease_owner' => 'replacement',
            'attempt_count' => 2,
            'lease_expires_at' => now()
                ->addSeconds(10),
        ])->save();
        $replayed = $this->deliver($run->fresh(), $replacement, $scope);
        $this->assertSame($event->id, $replayed->id);
        $this->assertSameJsonObject($original, $replayed->payload);
        $this->assertSame('2026-10-03T00:00:00.000000Z', $replayed->recorded_at->toISOString());
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeDelivered)->count()
        );
        $this->expectExceptionMessage('cancellation_scope_workflow_claim_mismatch');
        $this->deliver($run->fresh(), $task, $scope);
    }

    #[DataProvider('changedBoundaries')]
    public function testDuplicateCannotMoveOrRelabelOriginalBoundary(int $sequence, string $kind, int $span): void
    {
        [, $run, $task, , $scope] = $this->scopeTree();
        $request = CancellationScopeRequests::request($run, $scope, '1.20');
        $this->deliver($run, $task, $scope);
        $this->assertRefusedWithoutMutation($run, $task, 'cancellation_scope_delivery_mismatch', static fn () =>
            CancellationScopeDelivery::record(
                $run,
                $task,
                $scope,
                $request->payload['request_id'],
                $sequence,
                $kind,
                '1.20',
                $span
            ));
    }

    public static function changedBoundaries(): iterable
    {
        yield 'move earlier' => [2, 'activity', 1];
        yield 'move later' => [4, 'activity', 1];
        yield 'change call' => [3, 'timer', 1];
        yield 'change range' => [3, 'parallel', 2];
    }

    public function testScopeAddressesHaveIndependentDeliveryRecordsAndIdentities(): void
    {
        [, $run, $task, $parent, $child] = $this->scopeTree();
        CancellationScopeRequests::request($run, $parent, '1.20', 30);
        $first = $this->deliver($run, $task, $parent);
        CancellationScopeRequests::request($run, $child, '1.20', 30);
        $request = CancellationScopeRequests::context($run, $child);
        CancellationScopeDelivery::prepare($run, $task, $child, $request->requestId, 4, 'timer', '1.20');
        $second = CancellationScopeDelivery::record($run, $task, $child, $request->requestId, 4, 'timer', '1.20');
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->payload['request_id'], $second->payload['request_id']);
        $this->assertSame($first->id, CancellationScopeDelivery::recorded($run->fresh(), $parent)->id);
        $this->assertSame($second->id, CancellationScopeDelivery::recorded($run->fresh(), $child)->id);
        $this->assertSame(5, WorkflowStepHistory::nextDurableCommandSequence($run->fresh()));
    }

    #[DataProvider('inheritedShields')]
    public function testInheritedRequestRetainsOriginalRootAndObeysParentShield(bool $shield): void
    {
        [, $run, $task, $parent, $child] = $this->scopeTree($shield);
        $root = CancellationScopeRequests::request($run, $parent, '1.20', 30, 'original');
        Carbon::setTestNow('2026-10-03T00:00:07Z');
        CancellationScopeRequests::request($run, $child, '1.20', 300, parentScopeId: $parent);
        if ($shield) {
            $this->assertRefusedWithoutMutation($run, $task, 'cancellation_scope_parent_shielded', fn () =>
                $this->deliver($run, $task, $child));
            return;
        }
        $event = $this->deliver($run, $task, $child);
        $this->assertSame(
            $root->payload['request_id'],
            $event->payload['cancellation']['root_context']['root_request_id']
        );
        $this->assertSame([$parent, $child], array_column($event->payload['cancellation']['lineage'], 'scope_id'));
        $this->assertSame('2026-10-03T00:00:30.000000Z', $event->payload['authority_deadline_at']);
    }

    public static function inheritedShields(): iterable
    {
        yield 'unshielded' => [false];
        yield 'shielded' => [true];
    }

    public function testDirectShieldRequestDeliversButCurrentAncestorCeilingStillFencesRetries(): void
    {
        [$workflow, $run, $task, , $scope] = $this->scopeTree(true);
        $accepted = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $workflow->requestCancellation('run ceiling', 12);
        $event = $this->deliver($run, $task, $scope);
        $this->assertSameJsonObject($accepted->payload['cancellation'], $event->payload['cancellation']);
        $this->assertSame('2026-10-03T00:00:12.000000Z', $event->payload['authority_deadline_at']);
        Carbon::setTestNow('2026-10-03T00:00:12Z');
        $this->assertRefusedWithoutMutation($run, $task, 'cancellation_scope_authority_expired', fn () =>
            $this->deliver($run, $task, $scope));
        $this->assertSame($event->id, CancellationScopeDelivery::recorded($run->fresh(), $scope)->id);
    }

    public function testLaterAncestorDeadlineDoesNotRewriteAnEarlierDeliveryReceipt(): void
    {
        [$workflow, $run, $task, , $scope] = $this->scopeTree();
        CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $event = $this->deliver($run, $task, $scope);
        Carbon::setTestNow('2026-10-03T00:00:01Z');
        $workflow->requestCancellation('shorter run budget', 5);
        $duplicate = $this->deliver($run, $task, $scope);
        $this->assertSame($event->id, $duplicate->id);
        $this->assertSame('2026-10-03T00:00:30.000000Z', $duplicate->payload['authority_deadline_at']);
        $this->assertSame(
            '2026-10-03T00:00:06.000000Z',
            CancellationScopeRequests::authority($run, $scope)['deadline_at']
        );
        Carbon::setTestNow('2026-10-03T00:00:06Z');
        $this->assertRefusedWithoutMutation($run, $task, 'cancellation_scope_authority_expired', fn () =>
            $this->deliver($run, $task, $scope));
    }

    #[DataProvider('memberships')]
    public function testRecordedOperationMustBelongToTheExactScope(string $layout, bool $valid): void
    {
        [, $run, $task, $parent, $scope] = $this->scopeTree();
        $address = $valid ? $scope : $parent;
        $payload = [
            'sequence' => 3,
        ];
        if ($layout !== 'nested') {
            $payload['cancellation_scope_id'] = $address;
        }
        if ($layout !== 'flat') {
            $payload['activity'] = [
                'cancellation_scope_id' => $layout === 'contradictory' ? $parent : $address,
            ];
        }
        if ($layout === 'contradictory') {
            // Deliberately inject contradictory stored metadata, bypassing the
            // normal admission schema, to exercise the cold-history fence.
            $event = WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
                'sequence' => 3,
                'activity' => [
                    'cancellation_scope_id' => $scope,
                ],
            ]);
            $event->forceFill([
                'payload' => $payload,
            ])->save();
            $run->refresh();
        } else {
            $this->schedule(
                $run,
                $layout === 'flat' ? HistoryEventType::TimerScheduled : HistoryEventType::ActivityScheduled,
                $payload
            );
        }
        CancellationScopeRequests::request($run, $scope, '1.20');
        if (! $valid || $layout === 'contradictory') {
            $this->assertRefusedWithoutMutation($run, $task, 'cancellation_scope_delivery_membership_mismatch', fn () =>
                $this->deliver($run, $task, $scope, $layout === 'flat' ? 'timer' : 'activity'));
            return;
        }
        $this->assertSame(
            3,
            $this->deliver($run, $task, $scope, $layout === 'flat' ? 'timer' : 'activity')
->payload['sequence']
        );
    }

    public static function memberships(): iterable
    {
        foreach (['flat', 'nested'] as $layout) {
            yield $layout . ' matching' => [$layout, true];
            yield $layout . ' different' => [$layout, false];
        }
        yield 'contradictory' => ['contradictory', true];
    }

    public function testHistoricalRootOperationCannotBeReparentedByDelivery(): void
    {
        [, $run, $task, , $scope] = $this->scopeTree();
        $this->schedule($run, HistoryEventType::ActivityScheduled, [
            'sequence' => 3,
        ]);
        CancellationScopeRequests::request($run, $scope, '1.20');
        $this->assertRefusedWithoutMutation($run, $task, 'cancellation_scope_delivery_membership_mismatch', fn () =>
            $this->deliver($run, $task, $scope));
    }

    #[DataProvider('resolutionOrdering')]
    public function testCallResolutionUsesCanonicalHistoryOrderInsteadOfHostClock(bool $resolvedFirst): void
    {
        [, $run, $task, , $scope] = $this->scopeTree();
        $this->schedule($run, HistoryEventType::ActivityScheduled, [
            'sequence' => 3,
            'activity' => [
                'cancellation_scope_id' => $scope,
            ],
        ]);
        if ($resolvedFirst) {
            $this->schedule($run, HistoryEventType::ActivityCompleted, [
                'sequence' => 3,
            ]);
        }
        CancellationScopeRequests::request($run, $scope, '1.20');
        if (! $resolvedFirst) {
            $this->schedule($run, HistoryEventType::ActivityCompleted, [
                'sequence' => 3,
            ]);
            $this->assertSame(3, $this->deliver($run, $task, $scope)->payload['sequence']);
            return;
        }
        $this->assertRefusedWithoutMutation($run, $task, 'cancellation_delivery_not_eligible', fn () =>
            $this->deliver($run, $task, $scope));
    }

    public static function resolutionOrdering(): iterable
    {
        yield 'completion before request' => [true];
        yield 'completion after request' => [false];
    }

    #[DataProvider('parallelBoundaries')]
    public function testParallelAndSelectionBoundariesRequireEveryMemberInTheAddress(bool $selection, bool $mixed): void
    {
        [, $run, $task, $parent, $scope] = $this->scopeTree();
        for ($index = 0; $index < 2; ++$index) {
            $this->schedule($run, HistoryEventType::ActivityScheduled, [
                'sequence' => 3 + $index,
                'activity' => [
                    'cancellation_scope_id' => $mixed && $index === 1 ? $parent : $scope,
                ],
                ...ParallelChildGroup::itemMetadata(3, 2, $index, 'activity'),
            ]);
        }
        $request = CancellationScopeRequests::request($run, $scope, '1.20');
        $delivery = static fn () => CancellationScopeDelivery::record(
            $run,
            $task,
            $scope,
            $request->payload['request_id'],
            $selection ? 5 : 3,
            $selection ? 'selection_handle' : 'parallel',
            '1.20',
            $selection ? 1 : 2,
            $selection ? 3 : null,
            $selection ? 2 : 1
        );
        if ($mixed) {
            $this->assertRefusedWithoutMutation(
                $run,
                $task,
                'cancellation_scope_delivery_membership_mismatch',
                $delivery
            );
            return;
        }
        CancellationScopeDelivery::prepare(
            $run,
            $task,
            $scope,
            $request->payload['request_id'],
            $selection ? 5 : 3,
            $selection ? 'selection_handle' : 'parallel',
            '1.20',
            $selection ? 1 : 2,
            $selection ? 3 : null,
            $selection ? 2 : 1
        );
        $this->fenceActivities($run, $task, $scope);
        $event = $delivery();
        $this->assertSame($selection ? 5 : 3, $event->payload['sequence']);
        $this->assertSame($selection ? 6 : 5, WorkflowStepHistory::nextDurableCommandSequence($run->fresh()));
    }

    public static function parallelBoundaries(): iterable
    {
        yield 'parallel same scope' => [false, false];
        yield 'parallel mixed scopes' => [false, true];
        yield 'selection same scope' => [true, false];
        yield 'selection mixed scopes' => [true, true];
    }

    #[DataProvider('claimFailures')]
    public function testClaimFencesApplyBeforeMutation(string $kind): void
    {
        [, $run, $task, , $scope] = $this->scopeTree();
        CancellationScopeRequests::request($run, $scope, '1.20');
        $task->forceFill(match ($kind) {
            'expired' => [
                'lease_expires_at' => now(),
            ],
            'ready' => [
                'status' => TaskStatus::Ready,
            ],
            'activity' => [
                'task_type' => TaskType::Activity,
            ],
            'owner' => [
                'lease_owner' => '',
            ],
            'attempt' => [
                'attempt_count' => 0,
            ],
            'namespace' => [
                'namespace' => 'foreign',
            ],
        })->save();
        $this->assertRefusedWithoutMutation($run, $task, 'cancellation_scope_workflow_claim_mismatch', fn () =>
            $this->deliver($run, $task, $scope));
    }

    public static function claimFailures(): iterable
    {
        foreach (['expired', 'ready', 'activity', 'owner', 'attempt', 'namespace'] as $kind) {
            yield $kind => [$kind];
        }
    }

    public function testRunClosureRefusesDeliveryButKeepsColdReceiptInspectable(): void
    {
        [, $run, $task, , $scope] = $this->scopeTree();
        CancellationScopeRequests::request($run, $scope, '1.20');
        $event = $this->deliver($run, $task, $scope);
        $run->forceFill([
            'status' => RunStatus::Completed,
            'closed_at' => now(),
        ])->save();
        $this->assertSame($event->id, CancellationScopeDelivery::recorded($run->fresh(), $scope)->id);
        $this->assertRefusedWithoutMutation($run, $task, 'cancellation_scope_authority_expired', fn () =>
            $this->deliver($run, $task, $scope));
    }

    #[DataProvider('invalidRequests')]
    public function testWrongIdentityAndPublishedProtocolCannotCreateDelivery(string $kind, string $reason): void
    {
        [, $run, $task, , $scope] = $this->scopeTree();
        $request = CancellationScopeRequests::request($run, $scope, '1.20');
        $this->assertRefusedWithoutMutation($run, $task, $reason, static fn () => CancellationScopeDelivery::record(
            $run,
            $task,
            $kind === 'unknown' ? 'foreign-scope' : $scope,
            $kind === 'identity' ? 'foreign-request' : $request->payload['request_id'],
            3,
            'activity',
            $kind === 'protocol' ? '1.19' : '1.20'
        ));
    }

    public static function invalidRequests(): iterable
    {
        yield 'foreign request' => ['identity', 'cancellation_scope_request_mismatch'];
        yield 'foreign scope' => ['unknown', 'cancellation_scope_not_recorded'];
        yield 'published protocol' => ['protocol', 'cancellation_scope_requires_protocol_1_20'];
    }

    #[DataProvider('corruptReceipts')]
    public function testColdReadRejectsContradictoryDelivery(string $field, mixed $value): void
    {
        [, $run, $task, , $scope] = $this->scopeTree();
        CancellationScopeRequests::request($run, $scope, '1.20');
        $event = $this->deliver($run, $task, $scope);
        $payload = $event->payload;
        $payload[$field] = $value;
        $event->forceFill([
            'payload' => $payload,
        ])->save();
        $this->expectExceptionMessage('cancellation_scope_delivery_history_invalid');
        CancellationScopeDelivery::recorded($run->fresh(), $scope);
    }

    public static function corruptReceipts(): iterable
    {
        yield 'foreign run' => ['workflow_run_id', 'other-run'];
        yield 'foreign request' => ['request_id', 'other-request'];
        yield 'wrong schema' => ['schema', 'other-schema'];
        yield 'invalid sequence' => ['sequence', 0];
        yield 'invalid kind' => ['call_kind', 'unknown'];
        yield 'extended ceiling' => ['authority_deadline_at', '2026-10-03T00:05:00.000000Z'];
        yield 'noncanonical clock' => ['authority_deadline_at', 'tomorrow'];
    }

    /**
     * @return array{WorkflowStub, WorkflowRun, WorkflowTask, string, string}
     */
    private function scopeTree(bool $shield = false): array
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'scope-delivery');
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
        $child = CancellationScopeHistory::open($run, $task, 2, '1.20', $parent, $shield)->payload['scope_id'];
        return [$workflow, $run, $task, $parent, $child];
    }

    private function deliver(
        WorkflowRun $run,
        WorkflowTask $task,
        string $scope,
        string $kind = 'activity'
    ): WorkflowHistoryEvent {
        CancellationScopeDelivery::prepare(
            $run,
            $task,
            $scope,
            CancellationScopeRequests::context($run, $scope)->requestId,
            3,
            $kind,
            '1.20'
        );
        $this->fenceActivities($run, $task, $scope);
        return CancellationScopeDelivery::record(
            $run,
            $task,
            $scope,
            CancellationScopeRequests::context($run, $scope)->requestId,
            3,
            $kind,
            '1.20'
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function schedule(WorkflowRun $run, HistoryEventType $type, array $payload): void
    {
        if ($type === HistoryEventType::ActivityScheduled) {
            $scope = $payload['activity']['cancellation_scope_id'] ?? $payload['cancellation_scope_id'] ?? 'root';
            $execution = ActivityExecution::query()->create([
                'workflow_run_id' => $run->id,
                'sequence' => $payload['sequence'],
                'activity_type' => TestGreetingActivity::class,
                'activity_class' => TestGreetingActivity::class,
                'queue' => 'scope-delivery-activities',
                'status' => ActivityStatus::Pending,
                'payload_codec' => 'avro',
                'arguments' => Serializer::serializeWithCodec('avro', []),
                'activity_options' => [
                    'cancellation_scope_id' => $scope,
                ],
            ]);
            $payload['activity_execution_id'] = $execution->id;
            $payload['activity'] = [
                'id' => $execution->id,
                ...($payload['activity'] ?? []),
            ];
            WorkflowTask::query()->create([
                'workflow_run_id' => $run->id,
                'namespace' => $run->namespace,
                'task_type' => TaskType::Activity,
                'status' => TaskStatus::Ready,
                'queue' => 'scope-delivery-activities',
                'available_at' => now(),
                'payload' => [
                    'activity_execution_id' => $execution->id,
                ],
            ]);
        } elseif ($type === HistoryEventType::TimerScheduled) {
            $timer = WorkflowTimer::query()->create([
                'workflow_run_id' => $run->id,
                'sequence' => $payload['sequence'],
                'status' => TimerStatus::Pending,
                'delay_seconds' => 60,
                'fire_at' => now()
                    ->addMinute(),
            ]);
            $payload = [
                ...$payload,
                'timer_id' => $timer->id,
                'delay_seconds' => 60,
                'fire_at' => $timer->fire_at->toISOString(),
            ];
            WorkflowTask::query()->create([
                'workflow_run_id' => $run->id,
                'namespace' => $run->namespace,
                'task_type' => TaskType::Timer,
                'status' => TaskStatus::Ready,
                'available_at' => $timer->fire_at,
                'payload' => [
                    'timer_id' => $timer->id,
                ],
            ]);
        } elseif ($type === HistoryEventType::ActivityCompleted) {
            $execution = $run->activityExecutions()
                ->where('sequence', $payload['sequence'])->sole();
            $task = $run->tasks()
                ->where('task_type', TaskType::Activity)
                ->where('payload->activity_execution_id', $execution->id)
                ->sole();
            $bridge = app(DefaultActivityTaskBridge::class);
            $claim = $bridge->claimStatus($task->id, 'activity-owner');
            $this->assertTrue($claim['claimed'], $claim['reason'] ?? '');
            $result = $bridge->complete($claim['activity_attempt_id'], Serializer::serializeWithCodec('avro', 'done'));
            $this->assertTrue($result['recorded'], $result['reason'] ?? '');
            $run->refresh();
            return;
        }
        WorkflowHistoryEvent::record($run, $type, $payload);
        $run->refresh();
    }

    private function fenceActivities(WorkflowRun $run, WorkflowTask $task, string $scope): void
    {
        foreach (CancellationScopeDelivery::prepared($run->fresh(), $scope)->payload['timer_members'] as $member) {
            ScopedTimerCancellation::fence(
                $run,
                $task,
                $member['timer_id'],
                $scope,
                CancellationScopeRequests::context($run, $scope)->requestId,
                '1.20'
            );
        }
        foreach ($run->activityExecutions()->get() as $execution) {
            if ($execution->activity_options['cancellation_scope_id'] === $scope
                && $execution->status === ActivityStatus::Pending) {
                $this->assertTrue(ScopedActivityCancellation::fence(
                    $run,
                    $task,
                    $execution->id,
                    $scope,
                    CancellationScopeRequests::context($run, $scope)->requestId,
                    '1.20'
                )['fenced']);
            }
        }
    }

    private function assertRefusedWithoutMutation(
        WorkflowRun $run,
        WorkflowTask $task,
        string $reason,
        callable $action
    ): void {
        $history = $run->historyEvents()
            ->count();
        $claim = $task->fresh()
            ->getAttributes();
        try {
            $action();
            $this->fail('Scoped delivery should have been refused.');
        } catch (LogicException $error) {
            $this->assertSame($reason, $error->getMessage());
        }
        $this->assertSame($history, $run->historyEvents()->count());
        $this->assertSame($claim, $task->fresh()->getAttributes());
    }
}
