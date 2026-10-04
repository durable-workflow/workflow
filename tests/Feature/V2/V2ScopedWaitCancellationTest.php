<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\TestCase;
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
use Workflow\V2\Support\ConditionWaits;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\ScopedTimerCancellation;
use Workflow\V2\Support\ScopedWaitCancellation;
use Workflow\V2\Support\SignalWaits;
use Workflow\V2\WorkflowStub;

final class V2ScopedWaitCancellationTest extends TestCase
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

    #[DataProvider('waits')]
    public function testDispatchPreservesOriginalWaitIdentityAndClaim(string $kind, ?int $timeout): void
    {
        [$workflow, $run, $claim, $scope, $prepared] = $this->wait($kind, $timeout);
        $before = $claim->fresh()
            ->getAttributes();
        $result = $this->dispatch($claim, $scope, $prepared);
        $this->assertTrue($result['delivered'], $result['reason'] ?? '');
        $this->assertCount(1, $result['wait_cancellations']);
        $this->assertSame($timeout !== 0, $result['wait_cancellations'][0]['cancelled']);
        $this->assertSame($prepared['wait_members'], $result['wait_members']);
        $this->assertSame($before, $claim->fresh()->getAttributes());
        $this->assertFalse($run->fresh()->status->isTerminal());
        $this->assertSame($result, $this->dispatch($claim, $scope, $prepared));
        $this->assertNotNull(CancellationScopeDelivery::recorded($run->fresh(), $scope));
        $wait = ($kind === 'signal' ? SignalWaits::forRun($run->fresh()) : ConditionWaits::forRun($run->fresh()))[0];
        if ($timeout !== 0) {
            $this->assertSame('cancelled', $wait['status']);
            $this->assertSame('scope_cancelled', $wait['source_status']);
            $marker = $run->historyEvents()
                ->findOrFail($result['wait_cancellations'][0]['history_event_id']);
            $this->assertSame(
                $prepared['authority_deadline_at'],
                $marker->payload['cancellation_scope']['authority_deadline_at']
            );
            $this->assertSame(
                $prepared['preparation_history_event_id'],
                $marker->payload['cancellation_scope']['preparation_history_event_id']
            );
        } else {
            $this->assertSame(
                0,
                $run->historyEvents()
                    ->whereIn(
                        'event_type',
                        [HistoryEventType::SignalWaitCancelled, HistoryEventType::ConditionWaitCancelled]
                    )->count()
            );
        }
    }

    public static function waits(): iterable
    {
        foreach (['signal', 'condition'] as $kind) {
            foreach ([null, 0, 5] as $timeout) {
                yield $kind . ':' . ($timeout ?? 'untimed') => [$kind, $timeout];
            }
        }
    }

    #[DataProvider('kinds')]
    public function testLostAcknowledgementAndReplacementReuseCommittedWaitReceipt(string $kind): void
    {
        [$workflow, $run, $claim, $scope, $prepared] = $this->wait($kind, 5);
        $member = $prepared['wait_members'][0];
        $receipt = ScopedWaitCancellation::fence(
            $run->fresh(),
            $claim,
            $member['wait_id'],
            $scope,
            $prepared['request_id'],
            '1.20'
        );
        $before = $run->historyEvents()
            ->count();
        Carbon::setTestNow('2026-10-03T00:00:03Z');
        $claim->forceFill([
            'lease_owner' => 'replacement',
            'attempt_count' => 2,
        ])->save();
        $this->assertSame(
            $receipt,
            ScopedWaitCancellation::fence(
                $run->fresh(),
                $claim,
                $member['wait_id'],
                $scope,
                $prepared['request_id'],
                '1.20'
            )
        );
        $this->assertSame($before, $run->historyEvents()->count());
        $this->assertSame(
            $prepared['authority_deadline_at'],
            CancellationScopeDelivery::prepared($run->fresh(), $scope)->payload['authority_deadline_at']
        );
        $this->assertTrue($this->dispatch($claim, $scope, $prepared)['delivered']);
        Carbon::setTestNow('2026-10-03T00:00:31Z');
        $this->assertNotNull(CancellationScopeDelivery::recorded($run->fresh(), $scope));
    }

    #[DataProvider('kinds')]
    public function testWaitAndTimerFenceRollBackTogether(string $kind): void
    {
        [$workflow, $run, $claim, $scope, $prepared] = $this->wait($kind, 5);
        WorkflowHistoryEvent::creating(static function (WorkflowHistoryEvent $event): void {
            if ($event->event_type === HistoryEventType::TimerCancelled) {
                throw new RuntimeException('injected timer fence write failure');
            }
        });
        $before = $run->historyEvents()
            ->count();
        try {
            ScopedWaitCancellation::fence(
                $run->fresh(),
                $claim,
                $prepared['wait_members'][0]['wait_id'],
                $scope,
                $prepared['request_id'],
                '1.20'
            );
            $this->fail('Both records must roll back.');
        } catch (RuntimeException $error) {
            $this->assertSame('injected timer fence write failure', $error->getMessage());
        }
        $this->assertSame($before, $run->historyEvents()->count());
        $this->assertSame(TimerStatus::Pending, $run->timers()->sole()->status);
        $this->assertSame(TaskStatus::Ready, $run->tasks()->where('task_type', TaskType::Timer)->sole()->status);
    }

    #[DataProvider('kinds')]
    public function testDirectTimerEntryPointCancelsWaitAtomicallyAndLateJobCannotResume(string $kind): void
    {
        [$workflow, $run, $claim, $scope, $prepared] = $this->wait($kind, 5);
        $timer = $run->timers()
            ->sole();
        $task = $run->tasks()
            ->where('task_type', TaskType::Timer)->sole();
        $this->assertTrue(
            ScopedTimerCancellation::fence(
                $run->fresh(),
                $claim,
                $timer->id,
                $scope,
                $prepared['request_id'],
                '1.20'
            )['fenced']
        );
        $marker = $run->historyEvents()
            ->where(
                'event_type',
                $kind === 'signal' ? HistoryEventType::SignalWaitCancelled : HistoryEventType::ConditionWaitCancelled
            )->sole();
        $fence = $run->historyEvents()
            ->where('event_type', HistoryEventType::TimerCancelled)->sole();
        $this->assertLessThan($fence->sequence, $marker->sequence);
        $timer->forceFill([
            'status' => TimerStatus::Pending,
        ])->save();
        $task->forceFill([
            'status' => TaskStatus::Ready,
        ])->save();
        Carbon::setTestNow('2026-10-03T00:00:06Z');
        $before = $run->historyEvents()
            ->count();
        $this->app->call([new RunTimerTask($task->id), 'handle']);
        $this->assertSame($before, $run->historyEvents()->count());
        $this->assertSame(0, $run->historyEvents()->where('event_type', HistoryEventType::TimerFired)->count());
        $this->assertSame(2, $run->tasks()->where('task_type', TaskType::Workflow)->count());
        $this->assertTrue($this->dispatch($claim, $scope, $prepared)['delivered']);
    }

    #[DataProvider('kinds')]
    public function testNaturalTimeoutWinningFirstIsRetained(string $kind): void
    {
        [$workflow, $run, $claim, $scope, $prepared] = $this->wait($kind, 5);
        Carbon::setTestNow('2026-10-03T00:00:06Z');
        $this->app->call([new RunTimerTask($run->tasks()->where('task_type', TaskType::Timer)->sole()->id), 'handle']);
        $result = $this->dispatch($claim, $scope, $prepared);
        $this->assertTrue($result['delivered'], $result['reason'] ?? '');
        $this->assertFalse($result['wait_cancellations'][0]['cancelled']);
        $this->assertFalse($result['timer_cancellations'][0]['fenced']);
        $this->assertSame(1, $run->historyEvents()->where('event_type', HistoryEventType::TimerFired)->count());
        $this->assertSame(
            0,
            $run->historyEvents()
                ->whereIn(
                    'event_type',
                    [HistoryEventType::SignalWaitCancelled, HistoryEventType::ConditionWaitCancelled]
                )->count()
        );
    }

    public function testReceivedSignalRetainsBytesAndNaturalOutcome(): void
    {
        [$workflow, $run, $claim, $scope, $prepared] = $this->wait('signal', 5);
        $workflow->signal('name-provided', 'retained-payload');
        $before = $run->signals()
            ->sole()
            ->getAttributes();
        $result = $this->dispatch($claim, $scope, $prepared);
        $this->assertTrue($result['delivered'], $result['reason'] ?? '');
        $this->assertFalse($result['wait_cancellations'][0]['cancelled']);
        $this->assertSame($before, $run->signals()->sole()->getAttributes());
        $this->assertSame('received', SignalWaits::forRun($run->fresh())[0]['source_status']);
    }

    public function testSatisfiedConditionKeepsItsNaturalResultAndOriginalTimerCancellation(): void
    {
        [$workflow, $run, $claim, $scope, $prepared] = $this->wait('condition', 5);
        $workflow->signal('name-provided', 'condition-satisfied');
        $signal = $run->signals()
            ->sole();
        $claim->forceFill([
            'payload' => [
                'resume_source_kind' => 'workflow_signal',
                'workflow_signal_id' => $signal->id,
                'signal_name' => 'name-provided',
            ],
        ])->save();
        $reply = app(DefaultWorkflowTaskBridge::class)->complete($claim->id, []);
        $this->assertTrue($reply['completed'], $reply['reason'] ?? '');
        $satisfied = $run->historyEvents()
            ->where('event_type', HistoryEventType::ConditionWaitSatisfied)->sole();
        $timerCancellation = $run->historyEvents()
            ->where('event_type', HistoryEventType::TimerCancelled)->sole();
        $this->assertArrayNotHasKey('cancellation_scope', $timerCancellation->payload);
        $replacement = $claim->replicate(['id', 'created_at', 'updated_at'])->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'replacement-after-condition',
            'attempt_count' => 1,
            'payload' => null,
        ]);
        $replacement->save();
        $result = $this->dispatch($replacement, $scope, $prepared);
        $this->assertTrue($result['delivered'], $result['reason'] ?? '');
        $this->assertFalse($result['wait_cancellations'][0]['cancelled']);
        $this->assertSame($satisfied->id, $result['wait_cancellations'][0]['history_event_id']);
        $this->assertTrue($result['timer_cancellations'][0]['fenced']);
        $this->assertSame($timerCancellation->id, $result['timer_cancellations'][0]['history_event_id']);
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ConditionWaitCancelled)->count()
        );
        $this->assertNotNull(CancellationScopeDelivery::recorded($run->fresh(), $scope));
    }

    #[DataProvider('expiredLocks')]
    public function testAuthorityIsRecheckedAfterFinalTimerLock(string $kind, int $seconds, string $reason): void
    {
        [$workflow, $run, $claim, $scope, $prepared] = $this->wait($kind, 5);
        if ($seconds === 5) {
            $claim->forceFill([
                'lease_expires_at' => now()
                    ->addSeconds(5),
            ])->save();
        }
        $timerId = $run->timers()
            ->sole()
->id;
        WorkflowTimer::retrieved(static function (WorkflowTimer $timer) use ($timerId, $seconds): void {
            if ($timer->id === $timerId) {
                Carbon::setTestNow(Carbon::parse('2026-10-03T00:00:00Z')->addSeconds($seconds));
            }
        });
        $before = $run->historyEvents()
            ->count();
        try {
            ScopedWaitCancellation::fence(
                $run->fresh(),
                $claim,
                $prepared['wait_members'][0]['wait_id'],
                $scope,
                $prepared['request_id'],
                '1.20'
            );
            $this->fail('A waited lock cannot extend authority.');
        } catch (LogicException $error) {
            $this->assertSame($reason, $error->getMessage());
        }
        $this->assertSame($before, $run->historyEvents()->count());
    }

    #[DataProvider('kinds')]
    public function testLateSignalAndStaleResumeKeepMailboxBytesWithoutRevivingWait(string $kind): void
    {
        [$workflow, $run, $claim, $scope, $prepared] = $this->wait($kind, 5);
        $this->assertTrue($this->dispatch($claim, $scope, $prepared)['delivered']);
        $workflow->signal('name-provided', 'buffered-after-cancellation');
        $signal = $run->signals()
            ->sole();
        $before = $signal->getAttributes();
        $claim->forceFill([
            'payload' => [
                'resume_source_kind' => 'workflow_signal',
                'workflow_signal_id' => $signal->id,
                'signal_name' => 'name-provided',
                'signal_wait_id' => $kind === 'signal'
                                ? $prepared['wait_members'][0]['wait_id'] : $signal->signal_wait_id,
            ],
        ])->save();
        $reply = app(DefaultWorkflowTaskBridge::class)->complete($claim->id, []);
        $this->assertTrue($reply['completed'], $reply['reason'] ?? '');
        $this->assertSame($before, $run->signals()->sole()->getAttributes());
        $this->assertSame(
            0,
            $run->historyEvents()
                ->whereIn(
                    'event_type',
                    [HistoryEventType::SignalApplied, HistoryEventType::ConditionWaitSatisfied]
                )->count()
        );
        $waits = $kind === 'signal' ? SignalWaits::forRun($run->fresh()) : ConditionWaits::forRun($run->fresh());
        $this->assertSame('cancelled', $waits[0]['status']);
        $this->assertNotNull(CancellationScopeDelivery::recorded($run->fresh(), $scope));
    }

    public static function expiredLocks(): iterable
    {
        foreach (['signal', 'condition'] as $kind) {
            yield $kind . ':claim' => [$kind, 5, 'cancellation_scope_workflow_claim_mismatch'];
            yield $kind . ':deadline' => [$kind, 30, 'cancellation_scope_authority_expired'];
        }
    }

    #[DataProvider('receiptCorruption')]
    public function testColdReplayRejectsChangedReceipt(string $kind, string $field): void
    {
        [$workflow, $run, $claim, $scope, $prepared] = $this->wait($kind, 5);
        $result = $this->dispatch($claim, $scope, $prepared);
        $this->assertTrue($result['delivered'], $result['reason'] ?? '');
        $marker = $run->historyEvents()
            ->findOrFail($result['wait_cancellations'][0]['history_event_id']);
        $snapshot = $marker->payload['cancellation_scope'];
        $snapshot[$field] = 'changed';
        $marker->forceFill([
            'payload' => [
                ...$marker->payload,
                'cancellation_scope' => $snapshot,
            ],
        ])->save();
        $this->expectExceptionMessage('cancellation_scope_delivery_history_invalid');
        CancellationScopeDelivery::recorded($run->fresh(), $scope);
    }

    public static function receiptCorruption(): iterable
    {
        foreach (['signal', 'condition'] as $kind) {
            foreach ([
                'schema',
                'scope_id',
                'request_id',
                'request_history_event_id',
                'preparation_history_event_id',
                'authority_deadline_at',
            ] as $field) {
                yield $kind . ':' . $field => [$kind, $field];
            }
        }
    }

    #[DataProvider('scopeTrees')]
    public function testSiblingAndShieldedWaitsSurviveAndInheritedWaitsShareTheOriginalBoundary(
        string $kind,
        string $mode
    ): void {
        [$workflow, $run, $claim, $scope, $prepared] = $this->wait($kind, 5, $mode);
        $result = $this->dispatch($claim, $scope, $prepared);
        $waits = $kind === 'signal' ? SignalWaits::forRun($run->fresh()) : ConditionWaits::forRun($run->fresh());
        $this->assertTrue($result['delivered'], $result['reason'] ?? '');
        $this->assertSame('cancelled', $waits[0]['status']);
        $this->assertSame($mode === 'unshielded' ? 'cancelled' : 'open', $waits[1]['status']);
        $this->assertSame(
            $mode === 'unshielded' ? TimerStatus::Cancelled : TimerStatus::Pending,
            $run->timers()
                ->where('sequence', 4)
                ->sole()
->status
        );
        $this->assertSame(
            1,
            $run->historyEvents()->where('event_type', HistoryEventType::CancellationScopeDeliveryPrepared)->count()
        );
        $this->assertSame($result, $this->dispatch($claim, $scope, $prepared));
    }

    public static function scopeTrees(): iterable
    {
        foreach (['signal', 'condition'] as $kind) {
            foreach (['sibling', 'shielded', 'unshielded'] as $mode) {
                yield $kind . ':' . $mode => [$kind, $mode];
            }
        }
    }

    public static function kinds(): iterable
    {
        yield 'signal' => ['signal'];
        yield 'condition' => ['condition'];
    }

    /**
     * @return array{WorkflowStub, WorkflowRun, WorkflowTask, string, array<string, mixed>}
     */
    private function wait(string $kind, ?int $timeout, ?string $treeMode = null): array
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class);
        $workflow->start();
        $run = $workflow->run();
        $task = $run->tasks()
            ->where('task_type', TaskType::Workflow)->sole();
        $task->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'wait-owner',
            'attempt_count' => 1,
            'lease_expires_at' => now()
                ->addMinutes(5),
        ])->save();
        $scope = CancellationScopeHistory::open($run, $task, 1, '1.20')->payload['scope_id'];
        $sequence = 2;
        $otherScope = null;
        if ($treeMode !== null) {
            $otherScope = CancellationScopeHistory::open(
                $run,
                $task,
                2,
                '1.20',
                parentScopeId: $treeMode === 'sibling' ? 'root' : $scope,
                shieldParent: $treeMode === 'shielded'
            )->payload['scope_id'];
            $sequence = 3;
        }
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $command = array_filter(
            [
                'type' => 'open_' . $kind . '_wait',
                'cancellation_scope_id' => $scope,
                'timeout_seconds' => $timeout,
                ...($kind === 'signal' ? [
                    'signal_name' => 'name-provided',
                ] : [
                    'condition_key' => 'ready',
                ]),
            ],
            static fn (mixed $value): bool => $value !== null
        );
        $commands = [$command];
        if ($otherScope !== null) {
            $commands[] = [
                ...$command,
                'cancellation_scope_id' => $otherScope,
            ];
        }
        $this->assertTrue($bridge->complete($task->id, $commands)['completed']);
        $claim = $run->tasks()
            ->create([
                'namespace' => $run->namespace,
                'task_type' => TaskType::Workflow,
                'status' => TaskStatus::Leased,
                'lease_owner' => 'wait-hosting',
                'attempt_count' => 1,
                'lease_expires_at' => now()
                    ->addMinutes(5),
                'available_at' => now(),
                'connection' => $run->connection,
                'queue' => $run->queue,
                'compatibility' => $run->compatibility,
            ]);
        $callKind = $kind;
        if ($timeout === 0) {
            $sequence = 3;
            $callKind = 'timer';
            $reply = $bridge->checkpointCancellationScopePrefix(
                $claim->id,
                $claim->lease_owner,
                1,
                'after-immediate',
                3,
                [[
                    'type' => 'start_timer',
                    'delay_seconds' => 60,
                    'cancellation_scope_id' => $scope,
                ]],
                '1.20'
            );
            $this->assertTrue($reply['checkpointed'], $reply['reason'] ?? '');
        }
        $request = CancellationScopeRequests::request($run->fresh(), $scope, '1.20', 30);
        $prepared = $bridge->prepareCancellationScopeDelivery(
            $claim->id,
            $claim->lease_owner,
            1,
            $scope,
            $request->payload['request_id'],
            $sequence,
            $callKind,
            protocolVersion: '1.20'
        );
        $this->assertTrue($prepared['prepared'], $prepared['reason'] ?? '');
        $this->assertCount(1, $prepared['wait_members']);
        return [$workflow, $run, $claim, $scope, $prepared];
    }

    /** @param array<string, mixed> $prepared
     * @return array<string, mixed>
     */
    private function dispatch(WorkflowTask $claim, string $scope, array $prepared): array
    {
        return app(DefaultWorkflowTaskBridge::class)->deliverCancellationScope(
            $claim->id,
            $claim->lease_owner,
            $claim->attempt_count,
            $scope,
            $prepared['request_id'],
            $prepared['sequence'],
            $prepared['call_kind'],
            protocolVersion: '1.20'
        );
    }
}
