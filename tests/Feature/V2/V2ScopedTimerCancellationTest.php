<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\CancellationScopeDelivery;
use Workflow\V2\Support\CancellationScopeHistory;
use Workflow\V2\Support\CancellationScopeRequests;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\Support\ScopedTimerCancellation;
use Workflow\V2\WorkflowStub;

final class V2ScopedTimerCancellationTest extends TestCase
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

    public function testDispatchFencesEveryOriginalTimerAndPreservesSiblingAndHostingClaim(): void
    {
        [$run, $task, $scope, $sibling] = $this->tree();
        $target = $this->timer($run, $task, $scope, 3);
        $other = $this->timer($run, $task, $sibling, 4);
        $outsideCall = $this->timer($run, $task, $scope, 5);
        $before = $task->fresh()
            ->getAttributes();
        $otherBefore = $other->fresh()
            ->getAttributes();
        $prepared = $this->prepare($run, $task, $scope);
        $this->assertSame([$target->id, $outsideCall->id], array_column($prepared['timer_members'], 'timer_id'));
        $result = $this->dispatch($task, $scope, $prepared['request_id']);
        $this->assertTrue($result['delivered'], $result['reason'] ?? '');
        $this->assertCount(2, $result['timer_cancellations']);
        foreach ([$target, $outsideCall] as $timer) {
            $this->assertSame(TimerStatus::Cancelled, $timer->fresh()->status);
            $this->assertSame(TaskStatus::Cancelled, $this->timerTask($run, $timer)->status);
            $event = $run->historyEvents()
                ->where('event_type', HistoryEventType::TimerCancelled)
                ->where('payload->timer_id', $timer->id)
                ->sole();
            $this->assertSame(
                $prepared['preparation_history_event_id'],
                $event->payload['cancellation_scope']['preparation_history_event_id']
            );
            $this->assertSame(
                $prepared['authority_deadline_at'],
                $event->payload['cancellation_scope']['authority_deadline_at']
            );
        }
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertFalse($run->fresh()->status->isTerminal());
        $this->assertNull($run->fresh()->cancellation_request_command_id);
        $this->assertSame($result, $this->dispatch($task, $scope, $prepared['request_id']));
        $this->assertNotNull(CancellationScopeDelivery::recorded($run->fresh(), $scope));
        $timeline = collect(HistoryTimeline::fromHistory($run->fresh()))
            ->firstWhere('id', $prepared['preparation_history_event_id']);
        $this->assertCount(2, $timeline['cancellation_scope']['timer_members']);
    }

    #[DataProvider('projectionDrift')]
    public function testDelayedJobCannotFireOrResumeAfterFenceEvenWithProjectionDrift(string $drift): void
    {
        [$run, $task, $scope] = $this->tree();
        $timer = $this->timer($run, $task, $scope, 3, 1);
        $prepared = $this->prepare($run, $task, $scope);
        $this->assertTrue($this->dispatch($task, $scope, $prepared['request_id'])['delivered']);
        $timerTask = $this->timerTask($run, $timer);
        if ($drift === 'missing') {
            $timer->delete();
        } elseif ($drift === 'pending') {
            $timer->refresh()
                ->forceFill([
                    'status' => TimerStatus::Pending,
                ])->save();
        }
        $timerTask->forceFill([
            'status' => TaskStatus::Ready,
        ])->save();
        Carbon::setTestNow('2026-10-03T00:00:02Z');
        $before = $run->historyEvents()
            ->count();
        $this->app->call([new RunTimerTask($timerTask->id), 'handle']);
        $this->assertSame($before, $run->historyEvents()->count());
        $this->assertSame(0, $run->historyEvents()->where('event_type', HistoryEventType::TimerFired)->count());
        $this->assertSame(1, $run->tasks()->where('task_type', TaskType::Workflow)->count());
        $this->assertSame(TimerStatus::Cancelled, WorkflowTimer::query()->findOrFail($timer->id)->status);
        $this->assertSame(TaskStatus::Cancelled, $timerTask->fresh()->status);
        $this->assertNotNull(CancellationScopeDelivery::recorded($run->fresh(), $scope));
    }

    public static function projectionDrift(): iterable
    {
        yield 'intact' => ['intact'];
        yield 'missing row' => ['missing'];
        yield 'mutable status reverted' => ['pending'];
    }

    #[DataProvider('claimDrift')]
    public function testTimerClaimedBeforeFenceCannotFireInItsHandler(bool $drift): void
    {
        [$run, $task, $scope] = $this->tree();
        $timer = $this->timer($run, $task, $scope, 3, 1);
        $prepared = $this->prepare($run, $task, $scope);
        $timerTask = $this->timerTask($run, $timer);
        $fenced = false;
        Event::listen('eloquent.updated: ' . WorkflowTask::class, static function (WorkflowTask $updated) use (
            $run,
            $task,
            $scope,
            $prepared,
            $timerTask,
            $timer,
            $drift,
            &$fenced
        ): void {
            if ($updated->id === $timerTask->id && $updated->status === TaskStatus::Leased) {
                DB::afterCommit(static function () use (
                    $run,
                    $task,
                    $scope,
                    $prepared,
                    $timer,
                    $drift,
                    &$fenced
                ): void {
                    $fenced = ScopedTimerCancellation::fence(
                        $run,
                        $task,
                        $timer->id,
                        $scope,
                        $prepared['request_id'],
                        '1.20'
                    )['fenced'];
                    if ($drift) {
                        // A claimed job must honor durable cancellation even if
                        // its mutable projection changes after the fence.
                        $timer->refresh()
                            ->forceFill([
                                'status' => TimerStatus::Pending,
                            ])->save();
                    }
                });
            }
        });
        Carbon::setTestNow('2026-10-03T00:00:02Z');
        $this->app->call([new RunTimerTask($timerTask->id), 'handle']);
        $this->assertTrue($fenced);
        $this->assertSame(0, $run->historyEvents()->where('event_type', HistoryEventType::TimerFired)->count());
        $this->assertSame(1, $run->tasks()->where('task_type', TaskType::Workflow)->count());
        $this->assertTrue($this->dispatch($task, $scope, $prepared['request_id'])['delivered']);
    }

    public static function claimDrift(): iterable
    {
        yield 'intact projection' => [false];
        yield 'projection changed after fence' => [true];
    }

    public function testFireWinningBeforeFenceKeepsNaturalOutcomeAndCannotFireTwice(): void
    {
        [$run, $task, $scope] = $this->tree();
        $timer = $this->timer($run, $task, $scope, 3, 1);
        $prepared = $this->prepare($run, $task, $scope);
        $timerTask = $this->timerTask($run, $timer);
        Carbon::setTestNow('2026-10-03T00:00:02Z');
        $this->app->call([new RunTimerTask($timerTask->id), 'handle']);
        $before = $run->historyEvents()
            ->count();
        $result = $this->dispatch($task, $scope, $prepared['request_id']);
        $this->assertTrue($result['delivered'], $result['reason'] ?? '');
        $this->assertFalse($result['timer_cancellations'][0]['fenced']);
        $this->assertNull($result['timer_cancellations'][0]['history_event_id']);
        $this->assertSame(TimerStatus::Fired, $timer->fresh()->status);
        $this->assertSame(0, $run->historyEvents()->where('event_type', HistoryEventType::TimerCancelled)->count());
        $timer->refresh()
            ->forceFill([
                'status' => TimerStatus::Pending,
            ])->save();
        $timerTask->forceFill([
            'status' => TaskStatus::Ready,
        ])->save();
        $this->app->call([new RunTimerTask($timerTask->id), 'handle']);
        $this->assertSame($before + 1, $run->historyEvents()->count());
        $this->assertSame(1, $run->historyEvents()->where('event_type', HistoryEventType::TimerFired)->count());
        $this->assertSame(2, $run->tasks()->where('task_type', TaskType::Workflow)->count());
    }

    public function testPartialFenceReplacementReusesOriginalMembershipBoundaryAndDeadline(): void
    {
        [$run, $task, $scope] = $this->tree();
        $first = $this->timer($run, $task, $scope, 3);
        $second = $this->timer($run, $task, $scope, 4);
        $prepared = $this->prepare($run, $task, $scope);
        $receipt = ScopedTimerCancellation::fence($run, $task, $first->id, $scope, $prepared['request_id'], '1.20');
        $this->assertTrue($receipt['fenced']);
        $this->assertSame(TimerStatus::Pending, $second->fresh()->status);
        Carbon::setTestNow('2026-10-03T00:00:11Z');
        $replacement = $task->fresh();
        $replacement->forceFill([
            'lease_owner' => 'replacement',
            'attempt_count' => 2,
        ])->save();
        $result = $this->dispatch($replacement, $scope, $prepared['request_id']);
        $this->assertTrue($result['delivered'], $result['reason'] ?? '');
        $this->assertSame($receipt, $result['timer_cancellations'][0]);
        $this->assertSame($prepared['preparation_history_event_id'], $result['preparation_history_event_id']);
        $this->assertSame($prepared['authority_deadline_at'], $result['authority_deadline_at']);
        $this->assertSame($prepared['timer_members'], $result['timer_members']);
        $this->assertSame($prepared['cancellation'], $result['cancellation']);
        $this->assertSame($result, $this->dispatch($replacement, $scope, $prepared['request_id']));
        $this->assertSame(
            'cancellation_scope_workflow_claim_mismatch',
            $this->dispatch($task, $scope, $prepared['request_id'])['reason']
        );
    }

    #[DataProvider('refusals')]
    public function testActorRefusesStaleOrUnpreparedAuthorityWithoutEffects(string $mutation, string $reason): void
    {
        [$run, $task, $scope, $sibling] = $this->tree();
        $timer = $this->timer($run, $task, $scope, 3);
        $other = $this->timer($run, $task, $sibling, 4);
        $prepared = $this->prepare($run, $task, $scope);
        $requestId = $prepared['request_id'];
        $version = '1.20';
        if ($mutation === 'owner') {
            $task->fresh()
                ->forceFill([
                    'lease_owner' => 'replacement',
                ])->save();
        } elseif ($mutation === 'attempt') {
            $task->fresh()
                ->forceFill([
                    'attempt_count' => 2,
                ])->save();
        } elseif ($mutation === 'lease') {
            $task->fresh()
                ->forceFill([
                    'lease_expires_at' => now(),
                ])->save();
        } elseif ($mutation === 'deadline') {
            Carbon::setTestNow('2026-10-03T00:00:30Z');
        } elseif ($mutation === 'request') {
            $requestId = 'another-request';
        } elseif ($mutation === 'sibling') {
            $timer = $other;
        } elseif ($mutation === 'version') {
            $version = '1.19';
        } elseif ($mutation === 'namespace') {
            $this->timerTask($run, $timer)
                ->forceFill([
                    'namespace' => 'other-namespace',
                ])->save();
        } elseif ($mutation === 'projection') {
            $timer->forceFill([
                'status' => TimerStatus::Fired,
            ])->save();
        } elseif ($mutation === 'descriptor') {
            $event = $run->historyEvents()
                ->where('event_type', HistoryEventType::TimerScheduled)
                ->where('payload->timer_id', $timer->id)
                ->sole();
            $event->forceFill([
                'payload' => [
                    ...$event->payload,
                    'delay_seconds' => 61,
                ],
            ])->save();
        } elseif ($mutation === 'inventory') {
            $event = $run->historyEvents()
                ->findOrFail($prepared['preparation_history_event_id']);
            $event->forceFill([
                'payload' => [
                    ...$event->payload,
                    'timer_members' => [],
                ],
            ])->save();
        }
        $before = $run->historyEvents()
            ->count();
        try {
            ScopedTimerCancellation::fence($run->fresh(), $task, $timer->id, $scope, $requestId, $version);
            $this->fail('Invalid authority must not fence a timer.');
        } catch (LogicException $error) {
            $this->assertSame($reason, $error->getMessage());
        }
        $this->assertSame($before, $run->historyEvents()->count());
        $this->assertSame(0, $run->historyEvents()->where('event_type', HistoryEventType::TimerCancelled)->count());
        $this->assertSame(TimerStatus::Pending, $other->fresh()->status);
    }

    public static function refusals(): iterable
    {
        foreach (['owner', 'attempt', 'lease'] as $case) {
            yield $case => [$case, 'cancellation_scope_workflow_claim_mismatch'];
        }
        yield 'original deadline' => ['deadline', 'cancellation_scope_authority_expired'];
        yield 'wrong request' => ['request', 'cancellation_scope_request_mismatch'];
        yield 'sibling' => ['sibling', 'cancellation_scope_timer_not_prepared'];
        yield 'old protocol' => ['version', 'cancellation_scope_requires_protocol_1_20'];
        yield 'foreign timer task' => ['namespace', 'cancellation_scope_timer_task_mismatch'];
        yield 'mutable terminal without history' => [
            'projection',
            'cancellation_scope_timer_terminal_history_not_recorded',
        ];
        yield 'changed descriptor' => ['descriptor', 'cancellation_scope_delivery_history_invalid'];
        yield 'omitted original member' => ['inventory', 'cancellation_scope_delivery_history_invalid'];
    }

    public function testUnpreparedActorAndCallerOuterTransactionAreRefused(): void
    {
        [$run, $task, $scope] = $this->tree();
        $timer = $this->timer($run, $task, $scope, 3);
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        try {
            ScopedTimerCancellation::fence($run, $task, $timer->id, $scope, $request->payload['request_id'], '1.20');
            $this->fail('Preparation is required.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_delivery_not_prepared', $error->getMessage());
        }
        DB::transaction(function () use ($run, $task, $scope, $timer, $request): void {
            try {
                ScopedTimerCancellation::fence(
                    $run,
                    $task,
                    $timer->id,
                    $scope,
                    $request->payload['request_id'],
                    '1.20'
                );
                $this->fail('Timer lock ownership requires its own transaction.');
            } catch (LogicException $error) {
                $this->assertSame('cancellation_scope_timer_requires_own_transaction', $error->getMessage());
            }
        });
        $this->assertSame(TimerStatus::Pending, $timer->fresh()->status);
    }

    #[DataProvider('lockExpiry')]
    public function testFinalTimerRowLockDoesNotExtendClaimOrCancellationAuthority(int $seconds, string $reason): void
    {
        [$run, $task, $scope] = $this->tree();
        $timer = $this->timer($run, $task, $scope, 3);
        $prepared = $this->prepare($run, $task, $scope);
        if ($seconds === 5) {
            $task->forceFill([
                'lease_expires_at' => now()
                    ->addSeconds(5),
            ])->save();
        }
        $crossed = false;
        DB::listen(static function (QueryExecuted $query) use ($timer, $seconds, &$crossed): void {
            if (! $crossed && str_contains($query->sql, 'workflow_run_timers')
                && $query->bindings === [$timer->id]) {
                // Model the time spent acquiring the actor's final timer row.
                Carbon::setTestNow(Carbon::parse('2026-10-03T00:00:00Z')->addSeconds($seconds));
                $crossed = true;
            }
        });
        $before = $run->historyEvents()
            ->count();
        try {
            ScopedTimerCancellation::fence(
                $run->fresh(),
                $task,
                $timer->id,
                $scope,
                $prepared['request_id'],
                '1.20'
            );
            $this->fail('Time waiting for the final row lock must not renew authority.');
        } catch (LogicException $error) {
            $this->assertSame($reason, $error->getMessage());
        }
        $this->assertTrue($crossed);
        $this->assertSame($before, $run->historyEvents()->count());
        $this->assertSame(TimerStatus::Pending, $timer->fresh()->status);
        $this->assertSame(TaskStatus::Ready, $this->timerTask($run, $timer)->status);
    }

    public static function lockExpiry(): iterable
    {
        yield 'original cleanup deadline' => [30, 'cancellation_scope_authority_expired'];
        yield 'hosting claim expires first' => [5, 'cancellation_scope_workflow_claim_mismatch'];
    }

    public function testBarrierCannotReplaceFenceOrUseReceiptWrittenAfterDeliveryMarker(): void
    {
        [$run, $task, $scope] = $this->tree();
        $timer = $this->timer($run, $task, $scope, 3);
        $prepared = $this->prepare($run, $task, $scope);
        try {
            CancellationScopeDelivery::record($run, $task, $scope, $prepared['request_id'], 3, 'timer', '1.20');
            $this->fail('A marker cannot substitute for the timer fence.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_timer_fence_not_established', $error->getMessage());
        }
        $marker = WorkflowHistoryEvent::record($run, HistoryEventType::CancellationScopeDelivered, [
            'schema' => CancellationScopeDelivery::SCHEMA,
            'workflow_run_id' => $run->id,
            'scope_id' => $scope,
            'request_id' => $prepared['request_id'],
            'cancellation' => $prepared['cancellation'],
            'sequence' => 3,
            'call_kind' => 'timer',
            'sequence_span' => 1,
            'operation_sequence' => null,
            'operation_sequence_span' => 1,
            'authority_deadline_at' => $prepared['authority_deadline_at'],
            'preparation_history_event_id' => $prepared['preparation_history_event_id'],
        ], $task);
        $this->assertTrue(ScopedTimerCancellation::fence(
            $run->fresh(),
            $task,
            $timer->id,
            $scope,
            $prepared['request_id'],
            '1.20'
        )['fenced']);
        $this->assertNotNull($marker);
        $this->expectExceptionMessage('cancellation_scope_delivery_history_invalid');
        CancellationScopeDelivery::recorded($run->fresh(), $scope);
    }

    #[DataProvider('receiptCorruption')]
    public function testColdReplayRejectsChangedCancellationReceipt(string $field): void
    {
        [$run, $task, $scope] = $this->tree();
        $this->timer($run, $task, $scope, 3);
        $prepared = $this->prepare($run, $task, $scope);
        $this->assertTrue($this->dispatch($task, $scope, $prepared['request_id'])['delivered']);
        $event = $run->historyEvents()
            ->where('event_type', HistoryEventType::TimerCancelled)->sole();
        $snapshot = $event->payload['cancellation_scope'];
        $snapshot[$field] = 'changed';
        $event->forceFill([
            'payload' => [
                ...$event->payload,
                'cancellation_scope' => $snapshot,
            ],
        ])->save();
        $this->expectExceptionMessage('cancellation_scope_delivery_history_invalid');
        CancellationScopeDelivery::recorded($run->fresh(), $scope);
    }

    public static function receiptCorruption(): iterable
    {
        foreach (['request_id', 'scope_id', 'workflow_run_id', 'request_history_event_id',
            'preparation_history_event_id', 'authority_deadline_at', 'schema'] as $field) {
            yield $field => [$field];
        }
    }

    public function testUnsupportedChildIsDiagnosedBeforeAnyTimerOrActivityEffect(): void
    {
        [$run, $task, $scope] = $this->tree();
        $timer = $this->timer($run, $task, $scope, 3);
        $reply = app(DefaultWorkflowTaskBridge::class)->checkpointCancellationScopePrefix(
            $task->id,
            $task->lease_owner,
            $task->attempt_count,
            'child-preflight',
            4,
            [[
                'type' => 'schedule_activity',
                'activity_type' => TestGreetingActivity::class,
                'arguments' => Serializer::serializeWithCodec('avro', []),
                'payload_codec' => 'avro',
                'cancellation_scope_id' => $scope,
            ], [
                'type' => 'start_child_workflow',
                'workflow_type' => 'child-scope-fixture',
                'arguments' => Serializer::serializeWithCodec('avro', []),
                'payload_codec' => 'avro',
                'cancellation_scope_id' => $scope,
            ]],
            '1.20'
        );
        $this->assertTrue($reply['checkpointed'], $reply['reason'] ?? '');
        $prepared = $this->prepare($run, $task, $scope);
        $before = $run->historyEvents()
            ->count();
        $result = $this->dispatch($task, $scope, $prepared['request_id']);
        $this->assertFalse($result['delivered']);
        $this->assertSame(['scoped_child_delivery'], $result['unavailable']);
        $this->assertSame($before, $run->historyEvents()->count());
        $this->assertSame(TimerStatus::Pending, $timer->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $run->activityExecutions()->sole()->status);
    }

    /**
     * @return array{WorkflowRun, WorkflowTask, string, string}
     */
    private function tree(): array
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class);
        $workflow->start('scoped-timer');
        $run = $workflow->run();
        $task = $run->tasks()
            ->where('task_type', TaskType::Workflow)->sole();
        $task->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'timer-scope-owner',
            'attempt_count' => 1,
            'lease_expires_at' => now()
                ->addMinutes(5),
        ])->save();
        $scope = CancellationScopeHistory::open($run, $task, 1, '1.20')->payload['scope_id'];
        $sibling = CancellationScopeHistory::open($run, $task, 2, '1.20')->payload['scope_id'];
        return [$run, $task->refresh(), $scope, $sibling];
    }

    private function timer(
        WorkflowRun $run,
        WorkflowTask $task,
        string $scope,
        int $sequence,
        int $delay = 60
    ): WorkflowTimer {
        $result = app(DefaultWorkflowTaskBridge::class)->checkpointCancellationScopePrefix(
            $task->id,
            $task->lease_owner,
            $task->attempt_count,
            'timer-' . $sequence,
            $sequence,
            [[
                'type' => 'start_timer',
                'delay_seconds' => $delay,
                'cancellation_scope_id' => $scope,
            ]],
            '1.20'
        );
        $this->assertTrue($result['checkpointed'], $result['reason'] ?? '');
        return $run->timers()
            ->where('sequence', $sequence)
            ->sole();
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
            3,
            'timer',
            protocolVersion: '1.20'
        );
        $this->assertTrue($result['prepared'], $result['reason'] ?? '');
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatch(WorkflowTask $task, string $scope, string $requestId): array
    {
        return app(DefaultWorkflowTaskBridge::class)->deliverCancellationScope(
            $task->id,
            $task->lease_owner,
            $task->attempt_count,
            $scope,
            $requestId,
            3,
            'timer',
            protocolVersion: '1.20'
        );
    }

    private function timerTask(WorkflowRun $run, WorkflowTimer $timer): WorkflowTask
    {
        return $run->tasks()
            ->where('task_type', TaskType::Timer)->where('payload->timer_id', $timer->id)->sole();
    }
}
