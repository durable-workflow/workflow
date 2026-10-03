<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\CancellationScopeHistory;
use Workflow\V2\Support\WorkflowStepHistory;
use Workflow\V2\WorkflowStub;

final class V2CancellationScopeHistoryTest extends TestCase
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

    public function testNestedScopeIdentityAndShieldModeSurviveResponseLossAndFreshRead(): void
    {
        [$workflow, $claim] = $this->workflowClaim('nested');
        $run = $workflow->run()
            ->fresh();
        $parent = CancellationScopeHistory::open($run, $claim, 1, '1.20');
        $child = CancellationScopeHistory::open($run, $claim, 2, '1.20', $parent->payload['scope_id'], true);
        $duplicate = CancellationScopeHistory::open($run, $claim, 2, '1.20', $parent->payload['scope_id'], true);

        $this->assertSame($child->id, $duplicate->id);
        $this->assertSame($child->fresh()->payload, $duplicate->payload);
        $this->assertNotSame($parent->payload['scope_id'], $child->payload['scope_id']);
        $scopes = CancellationScopeHistory::forRun($workflow->run()->fresh());
        $this->assertCount(2, $scopes);
        $this->assertSame(true, $scopes[$child->payload['scope_id']]['shield_parent']);
        $this->assertSame(false, $scopes[$parent->payload['scope_id']]['shield_parent']);
        $this->assertSame([
            CancellationScopeHistory::ROOT_SCOPE_ID,
            $parent->payload['scope_id'],
            $child->payload['scope_id'],
        ], CancellationScopeHistory::ancestry($workflow->run()->fresh(), $child->payload['scope_id']));
        $this->assertSame(3, WorkflowStepHistory::nextDurableCommandSequence($workflow->run()->fresh()));
        $this->assertNull($workflow->run()->fresh()->cancellation_request_command_id);
        $this->assertSame(TaskStatus::Leased, $claim->fresh()->status);
        $this->assertSame('scope-owner', $claim->fresh()->lease_owner);
    }

    #[DataProvider('changedDefinitionProvider')]
    public function testReplayCannotChangeRecordedParentOrShield(bool $changeParent): void
    {
        [$workflow, $claim] = $this->workflowClaim('changed');
        $run = $workflow->run()
            ->fresh();
        $parent = CancellationScopeHistory::open($run, $claim, 1, '1.20');
        $opened = CancellationScopeHistory::open($run, $claim, 2, '1.20', $parent->payload['scope_id']);
        $recordedPayload = $opened->fresh()
->payload;
        try {
            CancellationScopeHistory::open(
                $run,
                $claim,
                2,
                '1.20',
                $changeParent ? CancellationScopeHistory::ROOT_SCOPE_ID : $parent->payload['scope_id'],
                ! $changeParent,
            );
            $this->fail('Changed cancellation scope definition was accepted.');
        } catch (HistoryEventShapeMismatchException $error) {
            $this->assertSame(2, $error->workflowSequence);
        }
        $this->assertSame($recordedPayload, $opened->fresh()->payload);
        $this->assertCount(2, CancellationScopeHistory::forRun($run->fresh()));
    }

    public static function changedDefinitionProvider(): array
    {
        return [
            'parent' => [true],
            'shield' => [false],
        ];
    }

    public function testForeignScopeCannotBecomeParentInAnotherRun(): void
    {
        [$first, $firstClaim] = $this->workflowClaim('first');
        $foreign = CancellationScopeHistory::open($first->run()->fresh(), $firstClaim, 1, '1.20');
        [$second, $secondClaim] = $this->workflowClaim('second');
        try {
            CancellationScopeHistory::open(
                $second->run()
                    ->fresh(),
                $secondClaim,
                1,
                '1.20',
                $foreign->payload['scope_id']
            );
            $this->fail('A foreign scope was accepted as parent.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_parent_not_recorded', $error->getMessage());
        }
        $this->assertSame([], CancellationScopeHistory::forRun($second->run()->fresh()));
        $this->expectExceptionMessage('cancellation_scope_not_recorded');
        CancellationScopeHistory::ancestry($second->run()->fresh(), $foreign->payload['scope_id']);
    }

    public function testRecordedCreationCanReplayAfterRunRequestButNewScopeCannotOpen(): void
    {
        [$workflow, $claim] = $this->workflowClaim('requested');
        $run = $workflow->run()
            ->fresh();
        $original = CancellationScopeHistory::open($run, $claim, 1, '1.20');
        $request = $workflow->requestCancellation('maintenance', 30);
        $replayed = CancellationScopeHistory::open($run, $claim, 1, '1.20');
        $this->assertSame($original->id, $replayed->id);
        $this->assertSame($request->commandId(), $run->fresh()->cancellation_request_command_id);
        $this->expectExceptionMessage('cancellation_scope_run_not_active');
        CancellationScopeHistory::open($run, $claim, 2, '1.20');
    }

    #[DataProvider('unsupportedProtocolProvider')]
    public function testUnsupportedProtocolCannotCreateHistory(string $protocol): void
    {
        [$workflow, $claim] = $this->workflowClaim('unsupported');
        try {
            CancellationScopeHistory::open($workflow->run()->fresh(), $claim, 1, $protocol);
            $this->fail('Unsupported protocol opened a scope.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_requires_protocol_1_20', $error->getMessage());
        }
        $this->assertSame([], CancellationScopeHistory::forRun($workflow->run()->fresh()));
    }

    public static function unsupportedProtocolProvider(): array
    {
        return [
            'published default' => ['1.19'],
            'malformed' => ['1.20.extra'],
        ];
    }

    public function testExpiredAndReplacedClaimsCannotCreateScope(): void
    {
        [$workflow, $claim] = $this->workflowClaim('expired');
        $original = clone $claim;
        $claim->forceFill([
            'lease_owner' => 'replacement',
            'attempt_count' => 2,
        ])->save();
        foreach ([$original, $claim->fresh()] as $index => $candidate) {
            if ($index === 1) {
                Carbon::setTestNow(now()->addMinutes(6));
            }
            try {
                CancellationScopeHistory::open($workflow->run()->fresh(), $candidate, 1, '1.20');
                $this->fail('An invalid workflow claim opened a scope.');
            } catch (LogicException $error) {
                $this->assertSame('cancellation_scope_workflow_claim_mismatch', $error->getMessage());
            }
        }
        $this->assertSame([], CancellationScopeHistory::forRun($workflow->run()->fresh()));
    }

    public function testScopeCreationCannotReplaceAnExistingDurableOperation(): void
    {
        [$workflow, $claim] = $this->workflowClaim('occupied');
        $run = $workflow->run()
            ->fresh();
        WorkflowHistoryEvent::record($run, HistoryEventType::TimerScheduled, [
            'sequence' => 1,
        ], $claim);
        $this->expectException(HistoryEventShapeMismatchException::class);
        CancellationScopeHistory::open($run, $claim, 1, '1.20');
    }

    public function testMalformedRecordedAncestryIsNotSilentlyIgnored(): void
    {
        [$workflow, $claim] = $this->workflowClaim('malformed');
        $event = CancellationScopeHistory::open($workflow->run()->fresh(), $claim, 1, '1.20');
        $payload = $event->payload;
        $payload['parent_scope_id'] = $payload['scope_id'];
        $event->forceFill([
            'payload' => $payload,
        ])->save();
        $this->expectExceptionMessage('cancellation_scope_history_invalid');
        CancellationScopeHistory::forRun($workflow->run()->fresh());
    }

    public function testScopeTransactionUsesConfiguredStorageConnection(): void
    {
        [$workflow, $claim] = $this->workflowClaim('storage');
        $run = $workflow->run()
            ->fresh();
        $originalDefault = config('database.default');
        $originalStorage = config('workflows.storage.connection');
        config([
            'workflows.storage.connection' => $run->getConnection()
                ->getName(),
            'database.default' => 'scope-unconfigured-default',
        ]);
        try {
            $scope = CancellationScopeHistory::open($run, $claim, 1, '1.20');
            $this->assertSame($run->id, $scope->workflow_run_id);
            $this->assertCount(1, CancellationScopeHistory::forRun($run));
        } finally {
            config([
                'database.default' => $originalDefault,
                'workflows.storage.connection' => $originalStorage,
            ]);
        }
    }

    public function testReplacementClaimReplaysTheOriginalScopeAndTaskSnapshot(): void
    {
        [$workflow, $claim] = $this->workflowClaim('cold-replay');
        $original = CancellationScopeHistory::open($workflow->run()->fresh(), $claim, 1, '1.20');
        $recorded = $original->fresh()
->payload;
        $claim->forceFill([
            'lease_owner' => 'replacement-owner',
            'attempt_count' => 2,
        ])->save();
        $replayed = CancellationScopeHistory::open($workflow->run()->fresh(), $claim->fresh(), 1, '1.20');
        $this->assertSame($original->id, $replayed->id);
        $this->assertSame($recorded, $replayed->payload);
        $this->assertSame('scope-owner', $replayed->payload['task']['lease_owner']);
        $this->assertSame(1, $replayed->payload['task']['attempt_count']);
        $this->assertCount(1, CancellationScopeHistory::forRun($workflow->run()->fresh()));
    }

    public function testForeignHostingClaimCannotMutateAnotherRun(): void
    {
        [$first, $claim] = $this->workflowClaim('claim-owner');
        [$second] = $this->workflowClaim('foreign-claim');
        try {
            CancellationScopeHistory::open($second->run()->fresh(), $claim, 1, '1.20');
            $this->fail('A foreign hosting claim opened a scope.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_workflow_claim_mismatch', $error->getMessage());
        }
        $this->assertSame([], CancellationScopeHistory::forRun($first->run()->fresh()));
        $this->assertSame([], CancellationScopeHistory::forRun($second->run()->fresh()));
    }

    public function testExpiredRunCanReplayRecordedScopeButCannotAdmitNewScope(): void
    {
        [$workflow, $claim] = $this->workflowClaim('run-deadline');
        $run = $workflow->run()
            ->fresh();
        $original = CancellationScopeHistory::open($run, $claim, 1, '1.20');
        $run->forceFill([
            'run_deadline_at' => now(),
        ])->save();
        $this->assertSame($original->id, CancellationScopeHistory::open($run, $claim, 1, '1.20')->id);
        $this->expectExceptionMessage('cancellation_scope_run_not_active');
        CancellationScopeHistory::open($run, $claim, 2, '1.20');
    }

    /**
     * @return array{WorkflowStub, WorkflowTask}
     */
    private function workflowClaim(string $name): array
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'scope-' . $name);
        $workflow->start();
        $task = WorkflowTask::query()->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)->sole();
        $task->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'scope-owner',
            'attempt_count' => 1,
            'lease_expires_at' => now()
                ->addMinutes(5),
        ])->save();
        return [$workflow, $task];
    }
}
