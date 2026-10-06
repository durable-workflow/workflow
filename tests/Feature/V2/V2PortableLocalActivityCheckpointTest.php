<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\MemoPayload;
use Workflow\V2\Support\PortableLocalActivityPreparation;
use Workflow\V2\Support\WorkflowStepHistory;
use Workflow\V2\WorkflowStub;

final class V2PortableLocalActivityCheckpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
    }

    public function testCommittedPrefixRetainsTheClaimAndAllowsTheNextPreparedLocalCall(): void
    {
        [$run, $task] = $this->newClaim();
        $before = $task->getAttributes();
        $reply = $this->checkpoint($task, $this->prefix());
        $this->assertTrue($reply['checkpointed']);
        $this->assertFalse($reply['duplicate']);
        $this->assertSame(4, $reply['next_sequence']);
        $this->assertSame(4, WorkflowStepHistory::nextDurableCommandSequence($run->fresh()));
        $memos = $run->fresh()
            ->typedMemos();
        $this->assertSame([
            'stage' => 'before-local',
        ], $memos);
        $this->assertSame(1, WorkflowLink::query()->where('parent_workflow_run_id', $run->id)->count());
        $this->assertCount(1, $reply['created_task_ids']);
        $after = $task->refresh()
            ->getAttributes();
        unset($before['payload'], $before['updated_at'], $after['payload'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertSame('keep-me', $task->payload['unrelated']);
        $this->assertSame(TaskStatus::Leased, $task->status);
        $this->assertSame($reply['lease_expires_at'], $task->lease_expires_at->toISOString());
        $this->assertTrue($this->prepare($task, 4)['prepared']);
        $this->assertSame(1, ActivityExecution::query()->count());
        $this->assertSame(0, $run->tasks()->where('task_type', TaskType::Activity)->count());
    }

    public function testLostCheckpointResponseReturnsTheSameReceiptWithoutDuplicatingPrefixWork(): void
    {
        [$run, $task] = $this->newClaim();
        $first = $this->checkpoint($task, $this->prefix());
        $this->assertTrue($first['checkpointed']);
        $count = WorkflowHistoryEvent::query()->count();
        $before = $task->refresh()
            ->getAttributes();
        Carbon::setTestNow(now()->addSecond());
        try {
            $second = $this->checkpoint($task, $this->prefix());
            $this->assertSameCheckpointReceipt($first, $second);
            $this->assertSame($count, WorkflowHistoryEvent::query()->count());
            $this->assertSame(1, WorkflowLink::query()->where('parent_workflow_run_id', $run->id)->count());
            $this->assertSame($before, $task->refresh()->getAttributes());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testAChangedCheckpointCannotRelabelACommittedPrefix(): void
    {
        [, $task] = $this->newClaim();
        $this->assertTrue($this->checkpoint($task, $this->prefix())['checkpointed']);
        $count = WorkflowHistoryEvent::query()->count();
        $changed = $this->prefix();
        $changed[0]['result'] = Serializer::serializeWithCodec('avro', 'different');
        $this->assertSame('local_activity_checkpoint_mismatch', $this->checkpoint($task, $changed)['reason']);
        $this->assertSame('local_activity_checkpoint_mismatch', $this->checkpoint($task, $this->prefix(), 2)['reason']);
        $this->assertSame($count, WorkflowHistoryEvent::query()->count());
    }

    public function testAnOldOwnerCannotCheckpointOrReadAnOriginalReceiptAfterClaimTakeover(): void
    {
        [, $task] = $this->newClaim();
        $this->assertTrue($this->checkpoint($task, $this->prefix())['checkpointed']);
        $task->forceFill([
            'lease_owner' => 'replacement',
            'attempt_count' => 2,
        ])->save();
        $before = $task->refresh()
            ->getAttributes();
        $this->assertSame('workflow_claim_mismatch', $this->checkpoint($task, $this->prefix())['reason']);
        $reply = app(DefaultWorkflowTaskBridge::class)->checkpointLocalActivityPrefix(
            $task->id,
            'replacement',
            2,
            'checkpoint-one',
            1,
            $this->prefix(),
            '1.20',
        );
        $this->assertSame('local_activity_checkpoint_mismatch', $reply['reason']);
        $this->assertSame($before, $task->refresh()->getAttributes());
    }

    public function testAnExpiredClaimCannotCheckpointOrRenewItself(): void
    {
        [, $task] = $this->newClaim();
        $task->forceFill([
            'lease_expires_at' => now()
                ->subSecond(),
        ])->save();
        $before = $task->refresh()
            ->getAttributes();
        $this->assertSame('workflow_claim_expired', $this->checkpoint($task, $this->prefix())['reason']);
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame(0, WorkflowHistoryEvent::query()->count());
    }

    public function testThePrefixMustStartAtTheCanonicalAuthoredCursor(): void
    {
        [, $task] = $this->newClaim();
        $this->assertSame(
            'local_activity_checkpoint_sequence_mismatch',
            $this->checkpoint($task, $this->prefix(), 2)['reason']
        );
        $this->assertSame(0, WorkflowHistoryEvent::query()->count());
        $this->assertArrayNotHasKey('portable_local_checkpoint', $task->refresh()->payload);
    }

    public function testCancellationStopsNewPrefixWorkButDoesNotEraseAnAlreadyCommittedReceipt(): void
    {
        [$run, $task] = $this->newClaim();
        $first = $this->checkpoint($task, $this->prefix());
        $this->assertTrue($first['checkpointed']);
        $this->assertTrue(
            WorkflowStub::load($run->workflow_instance_id)->requestCancellation('maintenance', 30)->accepted()
        );
        $deadline = $run->refresh()
            ->cancellation_deadline_at->toISOString();
        $count = WorkflowHistoryEvent::query()->count();
        $this->assertSameCheckpointReceipt($first, $this->checkpoint($task, $this->prefix()));
        $this->assertSame('cancellation_requested', $this->checkpoint($task, [], 4, 'new-checkpoint')['reason']);
        $this->assertSame('cancellation_requested', $this->prepare($task, 4)['reason']);
        $this->assertSame($count, WorkflowHistoryEvent::query()->count());
        $this->assertSame($deadline, $run->refresh()->cancellation_deadline_at->toISOString());
        $this->assertSame(0, ActivityExecution::query()->count());
    }

    public function testSequentialCheckpointsKeepOneBoundedReceiptAndFinalCompletionUsesFreshHistory(): void
    {
        [$run, $task] = $this->newClaim();
        $this->assertTrue($this->checkpoint($task, [$this->prefix()[0]])['checkpointed']);
        $second = $this->checkpoint($task, [$this->prefix()[2]], 2, 'checkpoint-two');
        $this->assertTrue($second['checkpointed']);
        $this->assertSame(3, $second['next_sequence']);
        $payload = $task->refresh()
->payload;
        $this->assertSame(['unrelated', 'portable_local_checkpoint'], array_keys($payload));
        $this->assertSame('checkpoint-two', $payload['portable_local_checkpoint']['checkpoint_id']);
        $this->assertSame(
            'local_activity_checkpoint_sequence_mismatch',
            $this->checkpoint($task, [$this->prefix()[0]])['reason']
        );
        $completed = app(DefaultWorkflowTaskBridge::class)->complete($task->id, [[
            'type' => 'complete_workflow',
            'result' => Serializer::serializeWithCodec('avro', 'done'),
            'payload_codec' => 'avro',
        ]]);
        $this->assertTrue($completed['completed']);
        $this->assertSame(RunStatus::Completed, $run->refresh()->status);
        $this->assertSame(1, $run->historyEvents()->where('event_type', HistoryEventType::SideEffectRecorded)->count());
        $this->assertSame(1, $run->historyEvents()->where('event_type', HistoryEventType::MemoUpserted)->count());
    }

    public function testAnApplicationFailureRollsBackTheWholePrefixAndItsReceipt(): void
    {
        [, $task] = $this->newClaim();
        $before = $task->getAttributes();
        $memos = [];
        for ($i = 0; $i <= WorkflowMemo::MAX_MEMOS_PER_RUN; ++$i) {
            $memos['memo-' . $i] = 'value';
        }
        try {
            $this->checkpoint($task, [
                $this->prefix()[0], [
                    'type' => 'upsert_memo',
                    'entries' => MemoPayload::envelope($memos),
                ]]);
            $this->fail('The excessive memo count must abort the transaction.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Memo count exceeds maximum', $exception->getMessage());
        }
        $this->assertSame(0, WorkflowHistoryEvent::query()->count());
        $this->assertSame(0, WorkflowMemo::query()->count());
        $this->assertSame($before, $task->refresh()->getAttributes());
    }

    #[DataProvider('invalidCommands')]
    public function testARejectedBatchCannotApplyEvenItsValidFirstCommand(array $invalid): void
    {
        [, $task] = $this->newClaim();
        $before = $task->getAttributes();
        $reply = $this->checkpoint($task, [$this->prefix()[0], $invalid]);
        $this->assertFalse($reply['checkpointed']);
        $this->assertSame('invalid_local_activity_checkpoint_commands', $reply['reason']);
        $this->assertSame(0, WorkflowHistoryEvent::query()->count());
        $this->assertSame($before, $task->refresh()->getAttributes());
    }

    public static function invalidCommands(): iterable
    {
        yield 'terminal' => [[
            'type' => 'complete_workflow',
        ]];
        yield 'unknown' => [[
            'type' => 'invented',
        ]];
        yield 'wait closes a turn' => [[
            'type' => 'open_signal_wait',
            'signal_name' => 'ready',
        ]];
        yield 'posthoc local execution' => [[
            'type' => 'record_local_activity',
        ]];
        yield 'invalid encoding' => [[
            'type' => 'record_version_marker',
            'change_id' => "bad-\xFF",
            'version' => 1,
        ]];
    }

    public function testProtocol119AndOversizedBatchesDoNotEnterThePreparedPath(): void
    {
        [, $task] = $this->newClaim();
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $this->assertSame('local_activity_checkpoint_requires_protocol_1_20', $bridge->checkpointLocalActivityPrefix(
            $task->id,
            'portable-worker',
            1,
            'checkpoint-one',
            1,
            $this->prefix(),
            '1.19',
        )['reason']);
        $this->assertSame(
            'invalid_local_activity_checkpoint',
            $this->checkpoint($task, array_fill(0, 101, $this->prefix()[0]))['reason']
        );
        $this->assertSame(0, WorkflowHistoryEvent::query()->count());
    }

    /** @param array<string, mixed> $first
     * @param array<string, mixed> $reply
     */
    private function assertSameCheckpointReceipt(array $first, array $reply): void
    {
        $expected = [
            ...$first,
            'duplicate' => true,
        ];
        // MySQL normalizes JSON object key order. Values and types must
        // remain identical, while object member order has no authority.
        ksort($expected);
        ksort($reply);
        $this->assertSame($expected, $reply);
    }

    /**
     * @return list<array{type: string, ...}>
     */
    private function prefix(): array
    {
        return [
            [
                'type' => 'record_side_effect',
                'result' => Serializer::serializeWithCodec('avro', 'recorded-once'),
            ],
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'python-child',
                'arguments' => Serializer::serializeWithCodec('avro', ['child']),
                'payload_codec' => 'avro',
            ],
            [
                'type' => 'upsert_memo',
                'entries' => MemoPayload::envelope([
                    'stage' => 'before-local',
                ]),
            ],
        ];
    }

    /** @param list<array{type: string, ...}> $commands
     * @return array<string, mixed>
     */
    private function checkpoint(
        WorkflowTask $task,
        array $commands,
        int $sequence = 1,
        string $id = 'checkpoint-one'
    ): array {
        return app(DefaultWorkflowTaskBridge::class)->checkpointLocalActivityPrefix(
            $task->id,
            'portable-worker',
            1,
            $id,
            $sequence,
            $commands,
            '1.20'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function prepare(WorkflowTask $task, int $sequence): array
    {
        return PortableLocalActivityPreparation::prepare(
            $task->id,
            'portable-worker',
            1,
            $sequence,
            'sdk-local-attempt',
            [
                'type' => 'record_local_activity',
                'activity_type' => 'php-local',
                'arguments' => Serializer::serializeWithCodec('avro', ['local']),
                'payload_codec' => 'avro',
            ],
            '1.20'
        );
    }

    /**
     * @return array{WorkflowRun, WorkflowTask}
     */
    private function newClaim(): array
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'portable-parent',
            'run_count' => 1,
            'started_at' => now()
                ->subMinute(),
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'portable-parent',
            'status' => RunStatus::Waiting,
            'arguments' => Serializer::serializeWithCodec('avro', ['Taylor']),
            'payload_codec' => 'avro',
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'started_at' => now()
                ->subMinute(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Leased,
            'attempt_count' => 1,
            'payload' => [
                'unrelated' => 'keep-me',
            ],
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'portable-worker',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);

        return [$run, $task->refresh()];
    }
}
