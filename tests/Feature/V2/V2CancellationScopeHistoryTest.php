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
use Workflow\V2\Contracts\CancellationScopeAdmission;
use Workflow\V2\Contracts\CancellationScopeTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\CancellationScopeHistory;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\ParallelChildGroup;
use Workflow\V2\Support\WorkflowCommandNormalizer;
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

    public function testOptionalScopeOpeningPersistsBeforeTheBodyAndRetainsTheClaim(): void
    {
        [$workflow, $claim] = $this->workflowClaim('portable-open');
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $this->assertInstanceOf(CancellationScopeTaskBridge::class, $bridge);
        $before = $claim->fresh()
            ->getAttributes();
        $first = $bridge->openCancellationScope($claim->id, 'scope-owner', 1, 1, 'root', false, '1.20');
        $this->assertTrue($first['opened'], $first['reason'] ?? '');
        $this->assertFalse($first['duplicate']);
        $this->assertFalse($first['claim_released']);
        $this->assertSame([], $first['created_task_ids']);
        $this->assertSame($workflow->runId(), $first['workflow_run_id']);
        $event = $workflow->run()
            ->historyEvents()
            ->where('event_type', HistoryEventType::CancellationScopeOpened)->sole();
        $this->assertSame($event->id, $first['history_event_id']);
        $this->assertSame($event->payload['scope_id'], $first['scope_id']);
        $this->assertSame('root', $first['parent_scope_id']);
        $this->assertFalse($first['shield_parent']);
        $this->assertSame(1, $first['sequence']);
        $this->assertSame($before, $claim->fresh()->getAttributes());
        $this->assertSame(0, ActivityExecution::query()->count());
        $this->assertSame(0, WorkflowTimer::query()->count());
        $this->assertSame(0, WorkflowLink::query()->count());
        $duplicate = $bridge->openCancellationScope($claim->id, 'scope-owner', 1, 1, 'root', false, '1.20');
        $this->assertTrue($duplicate['opened']);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame($first['scope_id'], $duplicate['scope_id']);
        $this->assertSame($first['history_event_id'], $duplicate['history_event_id']);
        $this->assertSame(
            1,
            $workflow->run()
                ->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeOpened)->count()
        );
    }

    public function testScopePrefixCommitsBeforeOpeningWithoutLocalActivityAdmission(): void
    {
        [$workflow, $claim] = $this->workflowClaim('scope-prefix');
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $originalLease = $claim->lease_expires_at->toISOString();
        $commands = [[
            'type' => 'record_side_effect',
            'result' => Serializer::serializeWithCodec('avro', 'before-scope'),
        ]];
        $first = $bridge->checkpointCancellationScopePrefix(
            $claim->id,
            'scope-owner',
            1,
            'scope-prefix',
            1,
            $commands,
            '1.20'
        );
        $this->assertTrue($first['checkpointed'], $first['reason'] ?? '');
        $this->assertFalse($first['duplicate']);
        $this->assertSame(2, $first['next_sequence']);
        $this->assertSame($originalLease, $claim->fresh()->lease_expires_at->toISOString());
        $this->assertArrayHasKey('portable_scope_checkpoint', $claim->fresh()->payload);
        $this->assertArrayNotHasKey('portable_local_checkpoint', $claim->fresh()->payload);
        $before = $workflow->run()
            ->historyEvents()
            ->count();
        $retry = $bridge->checkpointCancellationScopePrefix(
            $claim->id,
            'scope-owner',
            1,
            'scope-prefix',
            1,
            $commands,
            '1.20'
        );
        $this->assertTrue($retry['checkpointed']);
        $this->assertTrue($retry['duplicate']);
        $this->assertSame($first['fingerprint'], $retry['fingerprint']);
        $this->assertSame($before, $workflow->run()->historyEvents()->count());
        $scope = $bridge->openCancellationScope($claim->id, 'scope-owner', 1, 2, 'root', false, '1.20');
        $this->assertTrue($scope['opened'], $scope['reason'] ?? '');
        $this->assertSame(3, WorkflowStepHistory::nextDurableCommandSequence($workflow->run()->fresh()));
        $this->assertSame(TaskStatus::Leased, $claim->fresh()->status);
        $this->assertSame(0, ActivityExecution::query()->count());
    }

    public static function refusedScopePrefixes(): iterable
    {
        yield 'legacy protocol' => [
            '1.19',
            1,
            'scope-owner',
            1,
            [],
            'cancellation_scope_checkpoint_requires_protocol_1_20',
        ];
        yield 'unknown protocol major' => [
            '2.0',
            1,
            'scope-owner',
            1,
            [],
            'cancellation_scope_checkpoint_requires_protocol_1_20',
        ];
        yield 'old attempt' => ['1.20', 2, 'scope-owner', 1, [], 'workflow_claim_mismatch'];
        yield 'wrong owner' => ['1.20', 1, 'wrong-owner', 1, [], 'workflow_claim_mismatch'];
        yield 'future sequence' => ['1.20', 1, 'scope-owner', 2, [], 'cancellation_scope_checkpoint_sequence_mismatch'];
        yield 'terminal command' => ['1.20', 1, 'scope-owner', 1, [[
            'type' => 'complete_workflow',
            'result' => 'ignored',
        ]], 'invalid_cancellation_scope_checkpoint_commands'];
        yield 'local callback report' => ['1.20', 1, 'scope-owner', 1, [[
            'type' => 'record_local_activity',
        ]], 'invalid_cancellation_scope_checkpoint_commands'];
        yield 'local preparation' => ['1.20', 1, 'scope-owner', 1, [[
            'type' => 'prepare_local_activity',
        ]], 'invalid_cancellation_scope_checkpoint_commands'];
    }

    #[DataProvider('refusedScopePrefixes')]
    public function testScopePrefixRefusalLeavesHistoryAndClaimUnchanged(
        string $protocol,
        int $attempt,
        string $owner,
        int $sequence,
        array $commands,
        string $reason
    ): void {
        [$workflow, $claim] = $this->workflowClaim('refused-scope-prefix');
        $before = $claim->getAttributes();
        $history = $workflow->run()
            ->historyEvents()
            ->count();
        $reply = app(DefaultWorkflowTaskBridge::class)->checkpointCancellationScopePrefix(
            $claim->id,
            $owner,
            $attempt,
            'scope-prefix',
            $sequence,
            $commands,
            $protocol
        );
        $this->assertFalse($reply['checkpointed']);
        $this->assertSame($reason, $reply['reason']);
        $this->assertSame($before, $claim->fresh()->getAttributes());
        $this->assertSame($history, $workflow->run()->historyEvents()->count());
    }

    public function testScopePrefixCannotReplayChangedCommandsOrPublishFromAReplacedClaim(): void
    {
        [$workflow, $claim] = $this->workflowClaim('changed-scope-prefix');
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $commands = [[
            'type' => 'record_side_effect',
            'result' => Serializer::serializeWithCodec('avro', 'first'),
        ]];
        $this->assertTrue($bridge->checkpointCancellationScopePrefix(
            $claim->id,
            'scope-owner',
            1,
            'scope-prefix',
            1,
            $commands,
            '1.20'
        )['checkpointed']);
        $history = $workflow->run()
            ->historyEvents()
            ->count();
        $commands[0]['result'] = Serializer::serializeWithCodec('avro', 'changed');
        $this->assertSame('cancellation_scope_checkpoint_mismatch', $bridge->checkpointCancellationScopePrefix(
            $claim->id,
            'scope-owner',
            1,
            'scope-prefix',
            1,
            $commands,
            '1.20'
        )['reason']);
        $claim->forceFill([
            'lease_owner' => 'replacement',
            'attempt_count' => 2,
        ])->save();
        $this->assertSame('workflow_claim_mismatch', $bridge->checkpointCancellationScopePrefix(
            $claim->id,
            'scope-owner',
            1,
            'scope-prefix',
            2,
            $commands,
            '1.20'
        )['reason']);
        $scope = $bridge->openCancellationScope($claim->id, 'replacement', 2, 2, 'root', false, '1.20');
        $this->assertTrue($scope['opened'], $scope['reason'] ?? '');
        $this->assertSame($history + 1, $workflow->run()->historyEvents()->count());
    }

    public function testOptionalScopeOpeningReplaysTheSameNestedShieldAfterClaimTakeover(): void
    {
        [$workflow, $claim] = $this->workflowClaim('portable-shield');
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $parent = $bridge->openCancellationScope($claim->id, 'scope-owner', 1, 1, 'root', false, '1.20');
        $child = $bridge->openCancellationScope($claim->id, 'scope-owner', 1, 2, $parent['scope_id'], true, '1.20');
        $this->assertTrue($child['opened']);
        $this->assertTrue($child['shield_parent']);
        $claim->forceFill([
            'lease_owner' => 'replacement',
            'attempt_count' => 2,
        ])->save();
        $before = $workflow->run()
            ->historyEvents()
            ->count();
        $stale = $bridge->openCancellationScope($claim->id, 'scope-owner', 1, 2, $parent['scope_id'], true, '1.20');
        $this->assertFalse($stale['opened']);
        $this->assertSame('cancellation_scope_workflow_claim_mismatch', $stale['reason']);
        $replay = $bridge->openCancellationScope($claim->id, 'replacement', 2, 2, $parent['scope_id'], true, '1.20');
        $this->assertTrue($replay['opened']);
        $this->assertTrue($replay['duplicate']);
        $this->assertSame($child['scope_id'], $replay['scope_id']);
        $this->assertSame($child['history_event_id'], $replay['history_event_id']);
        $this->assertSame($before, $workflow->run()->historyEvents()->count());
    }

    public function testOptionalScopeOpeningRefusesChangedReplayWithoutWritingHistory(): void
    {
        [$workflow, $claim] = $this->workflowClaim('portable-mismatch');
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $first = $bridge->openCancellationScope($claim->id, 'scope-owner', 1, 1, 'root', false, '1.20');
        $before = $workflow->run()
            ->historyEvents()
            ->count();
        foreach ([['root', true], [$first['scope_id'], false]] as [$parent, $shield]) {
            $changed = $bridge->openCancellationScope($claim->id, 'scope-owner', 1, 1, $parent, $shield, '1.20');
            $this->assertFalse($changed['opened']);
            $this->assertSame('cancellation_scope_replay_mismatch', $changed['reason']);
        }
        $this->assertSame($before, $workflow->run()->historyEvents()->count());
    }

    public static function invalidScopeOpenings(): array
    {
        return [
            ['1.19', 'scope-owner', 1, 1, 'root', 'cancellation_scope_requires_protocol_1_20'],
            ['2.20', 'scope-owner', 1, 1, 'root', 'cancellation_scope_requires_protocol_1_20'],
            ['invalid', 'scope-owner', 1, 1, 'root', 'cancellation_scope_requires_protocol_1_20'],
            ['1.20', '', 1, 1, 'root', 'invalid_cancellation_scope_open'],
            ['1.20', 'scope-owner', 0, 1, 'root', 'invalid_cancellation_scope_open'],
            ['1.20', 'other-owner', 1, 1, 'root', 'cancellation_scope_workflow_claim_mismatch'],
            ['1.20', 'scope-owner', 2, 1, 'root', 'cancellation_scope_workflow_claim_mismatch'],
            ['1.20', 'scope-owner', 1, 0, 'root', 'invalid_cancellation_scope_open'],
            ['1.20', 'scope-owner', 1, 1, '', 'invalid_cancellation_scope_open'],
            ['1.20', 'scope-owner', 1, 1, '   ', 'invalid_cancellation_scope_open'],
            ['1.20', 'scope-owner', 1, 1, "\xff", 'invalid_cancellation_scope_open'],
            ['1.20', 'scope-owner', 1, 1, str_repeat('x', 256), 'invalid_cancellation_scope_open'],
            ['1.20', 'scope-owner', 1, 1, 'unknown-parent', 'cancellation_scope_parent_not_recorded'],
            ['1.20', 'scope-owner', 1, 2, 'root', 'cancellation_scope_sequence_mismatch'],
        ];
    }

    #[DataProvider('invalidScopeOpenings')]
    public function testOptionalScopeOpeningRefusesInvalidAuthorityBeforeAnyHistory(
        string $version,
        string $owner,
        int $attempt,
        int $sequence,
        string $parent,
        string $reason,
    ): void {
        [$workflow, $claim] = $this->workflowClaim('portable-refused');
        $before = $workflow->run()
            ->historyEvents()
            ->count();
        $attributes = $claim->fresh()
            ->getAttributes();
        $reply = app(DefaultWorkflowTaskBridge::class)->openCancellationScope(
            $claim->id,
            $owner,
            $attempt,
            $sequence,
            $parent,
            false,
            $version
        );
        $this->assertFalse($reply['opened']);
        $this->assertFalse($reply['claim_released']);
        $this->assertSame($reason, $reply['reason']);
        $this->assertSame($before, $workflow->run()->historyEvents()->count());
        $this->assertSame($attributes, $claim->fresh()->getAttributes());
    }

    public function testOptionalScopeOpeningRefusesExpiredClaimAndForeignParent(): void
    {
        [$other, $otherClaim] = $this->workflowClaim('portable-foreign');
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $foreign = $bridge->openCancellationScope($otherClaim->id, 'scope-owner', 1, 1, 'root', false, '1.20');
        [$workflow, $claim] = $this->workflowClaim('portable-current');
        $before = $workflow->run()
            ->historyEvents()
            ->count();
        $reply = $bridge->openCancellationScope($claim->id, 'scope-owner', 1, 1, $foreign['scope_id'], false, '1.20');
        $this->assertFalse($reply['opened']);
        $this->assertSame('cancellation_scope_parent_not_recorded', $reply['reason']);
        $claim->forceFill([
            'lease_expires_at' => now(),
        ])->save();
        $reply = $bridge->openCancellationScope($claim->id, 'scope-owner', 1, 1, 'root', false, '1.20');
        $this->assertFalse($reply['opened']);
        $this->assertSame('cancellation_scope_workflow_claim_mismatch', $reply['reason']);
        $this->assertSame($before, $workflow->run()->historyEvents()->count());
    }

    public function testOptionalScopeOpeningRefusesMissingTaskAndWrongNamespaceWithoutMutation(): void
    {
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $missing = $bridge->openCancellationScope('missing-task', 'scope-owner', 1, 1, 'root', false, '1.20');
        $this->assertFalse($missing['opened']);
        $this->assertSame('task_not_found', $missing['reason']);
        [$workflow, $claim] = $this->workflowClaim('portable-namespace');
        $before = $workflow->run()
            ->historyEvents()
            ->count();
        $claim->forceFill([
            'namespace' => 'another-namespace',
        ])->save();
        $reply = $bridge->openCancellationScope($claim->id, 'scope-owner', 1, 1, 'root', false, '1.20');
        $this->assertFalse($reply['opened']);
        $this->assertSame('cancellation_scope_workflow_claim_mismatch', $reply['reason']);
        $this->assertSame($before, $workflow->run()->historyEvents()->count());
    }

    public function testOptionalScopeOpeningRequiresAnActiveRunAndAnUnchangedCanonicalHistory(): void
    {
        [$workflow, $claim] = $this->workflowClaim('portable-history');
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $first = $bridge->openCancellationScope($claim->id, 'scope-owner', 1, 1, 'root', false, '1.20');
        $event = $workflow->run()
            ->historyEvents()
            ->findOrFail($first['history_event_id']);
        $event->forceFill([
            'payload' => [
                ...$event->payload,
                'workflow_run_id' => 'another-run',
            ],
        ])->save();
        $before = $workflow->run()
            ->historyEvents()
            ->count();
        $reply = $bridge->openCancellationScope($claim->id, 'scope-owner', 1, 1, 'root', false, '1.20');
        $this->assertFalse($reply['opened']);
        $this->assertSame('cancellation_scope_history_invalid', $reply['reason']);
        $this->assertSame($before, $workflow->run()->historyEvents()->count());
        $workflow->run()
            ->forceFill([
                'status' => \Workflow\V2\Enums\RunStatus::Cancelled,
            ])->save();
        $reply = $bridge->openCancellationScope($claim->id, 'scope-owner', 1, 2, 'root', false, '1.20');
        $this->assertFalse($reply['opened']);
        $this->assertSame('cancellation_scope_run_not_active', $reply['reason']);
        $this->assertSame($before, $workflow->run()->historyEvents()->count());
    }

    public function testOptionalScopeAdmissionRoleValidatesBeforeEffectsWithoutMutatingTheClaim(): void
    {
        [$workflow, $claim] = $this->workflowClaim('admission-role');
        $run = $workflow->run()
            ->fresh();
        $scope = CancellationScopeHistory::open($run, $claim, 1, '1.20')->payload['scope_id'];
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $this->assertInstanceOf(CancellationScopeAdmission::class, $bridge);
        $commands = $this->operationCommands();
        foreach ($commands as &$command) {
            $command['cancellation_scope_id'] = $scope;
        }
        unset($command);
        $history = $run->historyEvents()
            ->count();
        $before = $claim->fresh()
            ->getAttributes();
        $this->assertNull($bridge->validateCancellationScopeMembership($run, $commands, 2));
        foreach (array_keys($commands) as $index) {
            $changed = $commands;
            $changed[$index]['cancellation_scope_id'] = 'unknown-scope';
            $this->assertSame(
                'operation_scope_not_recorded',
                $bridge->validateCancellationScopeMembership($run, $changed, 2)
            );
        }
        $this->assertSame(
            'operation_scope_not_recorded',
            $bridge->validateCancellationScopeMembership($run, $commands, 1)
        );
        $local = [
            'type' => 'prepare_local_activity',
            'cancellation_scope_id' => $scope,
        ];
        $this->assertNull($bridge->validateCancellationScopeMembership($run, [$local], 2));
        $local['cancellation_scope_id'] = 'unknown-scope';
        $this->assertSame(
            'local_activity_scope_not_recorded',
            $bridge->validateCancellationScopeMembership($run, [$local], 2)
        );
        $this->assertNull($bridge->validateCancellationScopeMembership($run, [[
            'type' => 'prepare_local_activity',
        ]], 2));
        $this->assertSame($history, $run->historyEvents()->count());
        $this->assertSame($before, $claim->fresh()->getAttributes());
        $this->assertSame(0, ActivityExecution::query()->count());
        $this->assertSame(0, WorkflowTimer::query()->count());
        $this->assertSame(0, WorkflowLink::query()->count());
    }

    public function testRemoteActivityTimerAndChildKeepCanonicalMembershipBeforeAdmission(): void
    {
        [$workflow, $claim] = $this->workflowClaim('operations');
        $run = $workflow->run()
            ->fresh();
        $parent = CancellationScopeHistory::open($run, $claim, 1, '1.20')->payload['scope_id'];
        $shield = CancellationScopeHistory::open($run, $claim, 2, '1.20', $parent, true)->payload['scope_id'];
        $commands = $this->operationCommands();
        foreach ($commands as $index => &$command) {
            $command['cancellation_scope_id'] = $index === 1 ? $shield : $parent;
        }
        unset($command);
        $commands = WorkflowCommandNormalizer::normalize($commands, '1.20');
        $reply = app(DefaultWorkflowTaskBridge::class)->complete($claim->id, $commands);
        $this->assertTrue($reply['completed'], $reply['reason'] ?? '');
        $activity = $run->activityExecutions()
            ->sole();
        $this->assertSame($parent, $activity->activity_options['cancellation_scope_id']);
        $scheduled = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityScheduled)->sole();
        $this->assertSame($parent, $scheduled->payload['activity']['cancellation_scope_id']);
        $timer = $run->historyEvents()
            ->where('event_type', HistoryEventType::TimerScheduled)->sole();
        $this->assertSame($shield, $timer->payload['cancellation_scope_id']);
        $timerTask = $run->tasks()
            ->where('task_type', TaskType::Timer)->sole();
        $this->assertSame($shield, $timerTask->payload['cancellation_scope_id']);
        foreach ($run->historyEvents()->whereIn('event_type', [HistoryEventType::ChildWorkflowScheduled,
            HistoryEventType::ChildRunStarted])->get() as $event) {
            $this->assertSame($parent, $event->payload['cancellation_scope_id']);
        }
        $projection = WorkflowChildCall::query()->where('parent_workflow_run_id', $run->id)->sole();
        $this->assertSame($parent, $projection->metadata['cancellation_scope_id']);
        $projection->forceFill([
            'metadata' => [
                'cancellation_scope_id' => 'projection-only',
            ],
        ])->save();
        $this->assertSame($parent, $run->historyEvents()->where('event_type', HistoryEventType::ChildWorkflowScheduled)
            ->sole()
->payload['cancellation_scope_id']);
        $this->assertNull($run->fresh()->cancellation_request_command_id);
    }

    #[DataProvider('foreignOperationScopes')]
    public function testUnrecordedOperationScopeRefusesTheWholeBatchBeforeAnySiblingIsCreated(
        int $operation,
        bool $foreign,
    ): void {
        [$workflow, $claim] = $this->workflowClaim('refused');
        $run = $workflow->run()
            ->fresh();
        CancellationScopeHistory::open($run, $claim, 1, '1.20');
        $scope = 'unrecorded-scope';
        if ($foreign) {
            [$other, $otherClaim] = $this->workflowClaim('foreign-operation');
            $scope = CancellationScopeHistory::open(
                $other->run()
                    ->fresh(),
                $otherClaim,
                1,
                '1.20'
            )->payload['scope_id'];
        }
        $commands = $this->operationCommands();
        $invalid = [
            ...$commands[$operation],
            'cancellation_scope_id' => $scope,
        ];
        $before = $claim->fresh()
            ->getAttributes();
        $historyBefore = $run->historyEvents()
            ->count();
        $reply = app(DefaultWorkflowTaskBridge::class)->complete($claim->id, [$commands[0], $invalid]);
        $this->assertFalse($reply['completed']);
        $this->assertSame('operation_scope_not_recorded', $reply['reason']);
        $this->assertSame([], $reply['created_task_ids']);
        $this->assertSame(0, ActivityExecution::query()->count());
        $this->assertSame(0, WorkflowTimer::query()->count());
        $this->assertSame(0, WorkflowLink::query()->count());
        $this->assertSame($historyBefore, $run->historyEvents()->count());
        $this->assertSame($before, $claim->fresh()->getAttributes());
        $prefix = app(DefaultWorkflowTaskBridge::class)->checkpointLocalActivityPrefix(
            $claim->id,
            'scope-owner',
            1,
            'refused-prefix',
            2,
            [$commands[0], $invalid],
            '1.20',
        );
        $this->assertFalse($prefix['checkpointed']);
        $this->assertSame('operation_scope_not_recorded', $prefix['reason']);
        $this->assertSame(0, ActivityExecution::query()->count());
        $this->assertSame($historyBefore, $run->historyEvents()->count());
        $this->assertSame($before, $claim->fresh()->getAttributes());
    }

    public static function foreignOperationScopes(): iterable
    {
        foreach (['remote activity', 'timer', 'child'] as $index => $kind) {
            yield $kind . ' unknown scope' => [$index, false];
            yield $kind . ' foreign run scope' => [$index, true];
        }
    }

    #[DataProvider('operationKinds')]
    public function testLostCheckpointResponseCannotReparentAnAdmittedOperation(int $operation): void
    {
        [$workflow, $claim] = $this->workflowClaim('lost-response');
        $run = $workflow->run()
            ->fresh();
        $scope = CancellationScopeHistory::open($run, $claim, 1, '1.20')->payload['scope_id'];
        $otherScope = CancellationScopeHistory::open($run, $claim, 2, '1.20')->payload['scope_id'];
        $command = [
            ...$this->operationCommands()[$operation],
            'cancellation_scope_id' => $scope,
        ];
        $checkpoint = static fn (array $commands): array => app(DefaultWorkflowTaskBridge::class)
            ->checkpointLocalActivityPrefix($claim->id, 'scope-owner', 1, 'lost-response', 3, $commands, '1.20');
        $first = $checkpoint([$command]);
        $this->assertTrue($first['checkpointed'], $first['reason'] ?? '');
        $historyBefore = $run->historyEvents()
            ->orderBy('id')
            ->get()
            ->toArray();
        $claimBefore = $claim->fresh()
            ->getAttributes();
        $duplicate = $checkpoint([$command]);
        $this->assertTrue($duplicate['checkpointed']);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame($first['created_task_ids'], $duplicate['created_task_ids']);
        foreach ([$otherScope, CancellationScopeHistory::ROOT_SCOPE_ID, null] as $changed) {
            $reparented = $command;
            if ($changed === null) {
                unset($reparented['cancellation_scope_id']);
            } else {
                $reparented['cancellation_scope_id'] = $changed;
            }
            $reply = $checkpoint([$reparented]);
            $this->assertFalse($reply['checkpointed']);
            $this->assertSame('local_activity_checkpoint_mismatch', $reply['reason']);
        }
        $this->assertSame($historyBefore, $run->historyEvents()->orderBy('id')->get()->toArray());
        $this->assertSame($claimBefore, $claim->fresh()->getAttributes());
    }

    public static function operationKinds(): iterable
    {
        foreach (['remote activity', 'timer', 'child'] as $index => $kind) {
            yield $kind => [$index];
        }
    }

    public function testRetainedOperationScopesRejectAnUnqualifiedMajorProtocolBeforeAdmission(): void
    {
        [$workflow, $claim] = $this->workflowClaim('unqualified-major');
        $run = $workflow->run()
            ->fresh();
        $scope = CancellationScopeHistory::open($run, $claim, 1, '1.20')->payload['scope_id'];
        $historyBefore = $run->historyEvents()
            ->count();
        $claimBefore = $claim->fresh()
            ->getAttributes();
        foreach ($this->operationCommands() as $command) {
            $reply = app(DefaultWorkflowTaskBridge::class)->checkpointLocalActivityPrefix(
                $claim->id,
                'scope-owner',
                1,
                'unqualified-major',
                2,
                [[
                    ...$command,
                    'cancellation_scope_id' => $scope,
                ]],
                '2.0',
            );
            $this->assertFalse($reply['checkpointed']);
            $this->assertSame('operation_scope_requires_protocol_1_20', $reply['reason']);
            $this->assertSame($historyBefore, $run->historyEvents()->count());
            $this->assertSame($claimBefore, $claim->fresh()->getAttributes());
        }
        $this->assertSame(0, ActivityExecution::query()->count());
        $this->assertSame(0, WorkflowTimer::query()->count());
        $this->assertSame(0, WorkflowLink::query()->count());
    }

    public function testUnscopedActivityTimerAndChildKeepHistoricalSchedulingShapes(): void
    {
        [$workflow, $claim] = $this->workflowClaim('unscoped');
        $run = $workflow->run()
            ->fresh();
        $reply = app(DefaultWorkflowTaskBridge::class)->complete(
            $claim->id,
            WorkflowCommandNormalizer::normalize($this->operationCommands(), '1.19'),
        );
        $this->assertTrue($reply['completed'], $reply['reason'] ?? '');
        foreach ($run->historyEvents()->whereIn('event_type', [HistoryEventType::ActivityScheduled,
            HistoryEventType::TimerScheduled, HistoryEventType::ChildWorkflowScheduled,
            HistoryEventType::ChildRunStarted])->get() as $event) {
            $this->assertArrayNotHasKey('cancellation_scope_id', $event->payload);
            if ($event->event_type === HistoryEventType::ActivityScheduled) {
                $this->assertArrayNotHasKey('cancellation_scope_id', $event->payload['activity']);
            }
        }
        $this->assertNull($run->activityExecutions()->sole()->activity_options);
        $this->assertArrayNotHasKey(
            'cancellation_scope_id',
            $run->tasks()
                ->where('task_type', TaskType::Timer)->sole()->payload
        );
        $this->assertSame([], CancellationScopeHistory::forRun($run->fresh()));
    }

    public function testRetainedClaimMixedGroupPreservesEveryLocalAndRemoteLeafScope(): void
    {
        [$workflow, $claim] = $this->workflowClaim('mixed-scoped');
        $run = $workflow->run()
            ->fresh();
        $first = CancellationScopeHistory::open($run, $claim, 1, '1.20')->payload['scope_id'];
        $second = CancellationScopeHistory::open($run, $claim, 2, '1.20')->payload['scope_id'];
        $commands = $this->operationCommands();
        array_unshift($commands, [
            ...$commands[0],
            'type' => 'prepare_local_activity',
        ]);
        foreach ($commands as $index => &$command) {
            $command = [
                ...$command,
                'cancellation_scope_id' => $index === 0 ? $first : $second,
                ...ParallelChildGroup::itemMetadata(3, 4, $index, 'mixed'),
            ];
        }
        unset($command);
        $before = $claim->fresh()
            ->getAttributes();
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $reply = $bridge->checkpointLocalActivityGroup(
            $claim->id,
            'scope-owner',
            1,
            'mixed-scoped',
            3,
            $commands,
            '1.20'
        );
        $this->assertTrue($reply['checkpointed'], $reply['reason'] ?? '');
        $local = [
            ...$commands[0],
            'type' => 'record_local_activity',
        ];
        $prepared = $bridge->prepareLocalActivity(
            $claim->id,
            'scope-owner',
            1,
            3,
            'scoped-worker-attempt',
            $local,
            '1.20'
        );
        $this->assertTrue($prepared['prepared'], $prepared['reason'] ?? '');
        $this->assertSame($first, $prepared['cancellation_scope_id']);
        $this->assertSame($claim->id, $prepared['workflow_task_id']);
        $remote = $run->activityExecutions()
            ->where('sequence', 4)
            ->sole();
        $this->assertSame($second, $remote->activity_options['cancellation_scope_id']);
        $this->assertSame($second, $run->historyEvents()->where('event_type', HistoryEventType::TimerScheduled)
            ->sole()
->payload['cancellation_scope_id']);
        $this->assertSame($second, $run->historyEvents()->where('event_type', HistoryEventType::ChildWorkflowScheduled)
            ->sole()
->payload['cancellation_scope_id']);
        $this->assertSame(TaskStatus::Leased, $claim->fresh()->status);
        $this->assertSame($before['lease_owner'], $claim->fresh()->lease_owner);
        $this->assertSame($before['attempt_count'], $claim->fresh()->attempt_count);
        $this->assertSame(1, $run->tasks()->where('task_type', TaskType::Activity)->count());
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
            'unknown protocol major' => ['2.0'],
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
     * @return list<array{type: string, ...}>
     */
    private function operationCommands(): array
    {
        return [
            [
                'type' => 'schedule_activity',
                'activity_type' => 'remote-scope-fixture',
                'arguments' => Serializer::serializeWithCodec('avro', ['value']),
                'payload_codec' => 'avro',
            ],
            [
                'type' => 'start_timer',
                'delay_seconds' => 5,
            ],
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'child-scope-fixture',
                'arguments' => Serializer::serializeWithCodec('avro', ['child']),
                'payload_codec' => 'avro',
            ],
        ];
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
