<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestUpdateTailCheckpointWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\CancellationScopeHistory;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Support\WorkflowStepHistory;

final class V2UpdateTailCheckpointTest extends TestCase
{
    public static function outcomes(): iterable
    {
        foreach (['scope', 'local'] as $kind) {
            foreach (['complete_update', 'fail_update'] as $outcome) {
                yield $kind . ' ' . $outcome => [$kind, $outcome];
            }
        }
    }

    public static function followups(): iterable
    {
        foreach (self::outcomes() as $label => [$kind, $outcome]) {
            foreach (['none', 'before', 'after'] as $marker) {
                foreach (['checkpoint', 'scope'] as $followup) {
                    yield $label . ' marker ' . $marker . ' then ' . $followup => [$kind, $outcome, $marker, $followup];
                }
            }
        }
    }

    #[DataProvider('followups')]
    public function testReturnedCursorAdmitsTheNextOperation(
        string $kind,
        string $outcome,
        string $marker,
        string $followup
    ): void {
        [$run, $task, $update, $commands] = $this->claim($outcome);
        if ($marker === 'before') {
            array_unshift($commands, self::marker('before'));
        } elseif ($marker === 'after') {
            $commands[] = self::marker('after');
        }
        $before = $task->getAttributes();
        $reply = $this->checkpoint($kind, $task, $commands);
        $this->assertTrue($reply['checkpointed'], json_encode($reply));
        $next = WorkflowStepHistory::nextDurableCommandSequence($run->fresh());
        $this->assertSame($next, $reply['next_sequence']);
        $this->assertSame($outcome === 'complete_update' ? 'completed' : 'failed', $update->fresh()->status->value);
        $after = $task->fresh()
            ->getAttributes();
        unset($before['payload'], $before['updated_at'], $after['payload'], $after['updated_at']);
        $this->assertSame($before, $after);

        $count = $run->historyEvents()
            ->count();
        $stored = $task->fresh()
            ->getAttributes();
        $duplicate = $this->checkpoint($kind, $task, $commands);
        $this->assertTrue($duplicate['duplicate']);
        unset($reply['duplicate'], $duplicate['duplicate']);
        $this->assertSame($reply, $duplicate);
        $this->assertSame($count, $run->historyEvents()->count());
        $this->assertSame($stored, $task->fresh()->getAttributes());

        if ($followup === 'scope') {
            $opened = CancellationScopeHistory::open($run->fresh(), $task->fresh(), $next, '1.20');
            $this->assertSame($next, $opened->payload['sequence']);
        } else {
            $second = $this->checkpoint($kind, $task, [self::marker('next')], $next, 'second');
            $this->assertTrue($second['checkpointed'], json_encode($second));
            $this->assertSame($next + 1, $second['next_sequence']);
        }
    }

    #[DataProvider('outcomes')]
    public function testOldReceiptCursorIsCorrectedWithoutReapplyingOrFollowingLaterHistory(
        string $kind,
        string $outcome
    ): void {
        [$run, $task, , $commands] = $this->claim($outcome);
        array_unshift($commands, self::marker('before'));
        $original = $this->checkpoint($kind, $task, $commands);
        $this->assertTrue($original['checkpointed']);
        $key = $kind === 'scope' ? 'portable_scope_checkpoint' : 'portable_local_checkpoint';
        $payload = $task->fresh()
->payload;
        $payload[$key]['next_sequence'] = 3;
        $task->forceFill([
            'payload' => $payload,
        ])->save();
        CancellationScopeHistory::open($run->fresh(), $task->fresh(), 2, '1.20');
        $this->assertSame(3, WorkflowStepHistory::nextDurableCommandSequence($run->fresh()));
        $before = $task->fresh()
            ->getAttributes();
        $count = $run->historyEvents()
            ->count();

        $again = $this->checkpoint($kind, $task, $commands);
        $this->assertTrue($again['checkpointed']);
        $this->assertTrue($again['duplicate']);
        $this->assertSame(2, $again['next_sequence']);
        foreach (['checkpoint_id', 'fingerprint', 'recorded_at', 'lease_expires_at', 'created_task_ids'] as $field) {
            $this->assertSame($original[$field], $again[$field]);
        }
        $this->assertSame($count, $run->historyEvents()->count());
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame($again, $this->checkpoint($kind, $task, $commands));
    }

    #[DataProvider('outcomes')]
    public function testColdReplayRetainsTheWorkflowStepIdentitiesAndUpdateOutcome(string $kind, string $outcome): void
    {
        [$run, $task, , $commands] = $this->claim($outcome);
        array_unshift($commands, self::marker('before'));
        $first = $this->checkpoint($kind, $task, $commands);
        $this->assertTrue($first['checkpointed']);
        $this->assertSame(2, $first['next_sequence']);
        $second = $this->checkpoint($kind, $task, [self::marker('after')], $first['next_sequence'], 'second');
        $this->assertTrue($second['checkpointed'], json_encode($second));
        $history = $run->historyEvents()
            ->orderBy('sequence')
            ->get()
            ->toArray();

        for ($copy = 0; $copy < 2; ++$copy) {
            $replayed = WorkflowFiberRunner::forClass(
                TestUpdateTailCheckpointWorkflow::class,
                $run->workflow_instance_id,
                $run->id,
                [],
                'avro',
                $history,
            )->step();
            $this->assertTrue($replayed->completed);
            $this->assertSame([
                'version' => 1,
                'updates' => $outcome === 'complete_update' ? 1 : 0,
            ], $replayed->result);
        }
    }

    private static function marker(string $id): array
    {
        return [
            'type' => 'record_version_marker',
            'change_id' => $id,
            'min_supported' => 1,
            'max_supported' => 1,
            'version' => 1,
        ];
    }

    private function checkpoint(
        string $kind,
        WorkflowTask $task,
        array $commands,
        int $sequence = 1,
        string $id = 'first'
    ): array {
        $bridge = app(DefaultWorkflowTaskBridge::class);
        return $kind === 'scope'
            ? $bridge->checkpointCancellationScopePrefix($task->id, 'owner', 1, $id, $sequence, $commands, '1.20')
            : $bridge->checkpointLocalActivityPrefix($task->id, 'owner', 1, $id, $sequence, $commands, '1.20');
    }

    private function claim(string $outcome): array
    {
        Queue::fake();
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestUpdateTailCheckpointWorkflow::class,
            'workflow_type' => 'update-checkpoint',
            'run_count' => 1,
            'started_at' => now(),
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestUpdateTailCheckpointWorkflow::class,
            'workflow_type' => 'update-checkpoint',
            'status' => 'waiting',
            'arguments' => Serializer::serializeWithCodec('avro', []),
            'payload_codec' => 'avro',
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'started_at' => now(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();
        $command = WorkflowCommand::query()->create([
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_type' => 'update',
            'status' => 'accepted',
            'command_sequence' => 1,
        ]);
        $update = WorkflowUpdate::query()->create([
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'update_name' => 'set',
            'status' => 'accepted',
            'command_sequence' => 1,
            'payload_codec' => 'avro',
            'arguments' => Serializer::serializeWithCodec('avro', []),
            'accepted_at' => now(),
        ]);
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => 'workflow',
            'status' => 'leased',
            'attempt_count' => 1,
            'payload' => [
                'workflow_update_id' => $update->id,
                'unrelated' => 'keep-me',
            ],
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'owner',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ])->refresh();
        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [], $task);
        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateAccepted, [
            'workflow_command_id' => $command->id,
            'update_id' => $update->id,
            'update_name' => 'set',
            'arguments' => $update->arguments,
        ], $task, $command);
        $completion = [
            'type' => $outcome,
            'update_id' => $update->id,
        ];
        if ($outcome === 'complete_update') {
            $completion += [
                'result' => Serializer::serializeWithCodec('avro', 1),
                'payload_codec' => 'avro',
            ];
        } else {
            $completion['message'] = 'expected update failure';
        }
        return [$run, $task, $update, [$completion]];
    }
}
