<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestSignalExternalChildHandleWorkflow;
use Tests\Fixtures\V2\TestSignalExternalCleanupWorkflow;
use Tests\Fixtures\V2\TestSignalExternalInvalidNameWorkflow;
use Tests\Fixtures\V2\TestSignalExternalSenderWorkflow;
use Tests\Fixtures\V2\TestSignalExternalTargetWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\InvalidCommandRequestIdException;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\Webhooks;
use Workflow\V2\WorkflowStub;

/**
 * Durable signal-external from embedded workflow code (.github#223).
 */
final class V2SignalExternalWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function testDeliversOnceAndRecordsTheOutcomeInTheSendersHistory(): void
    {
        $target = $this->startTarget('sx-delivered-target');
        $sender = WorkflowStub::make(TestSignalExternalSenderWorkflow::class, 'sx-delivered-sender');
        $sender->start([[
            'workflow_id' => 'sx-delivered-target',
            'signal' => 'approve',
            'arguments' => ['Ada'],
        ]]);
        $this->drainReadyTasks();

        $senderRun = WorkflowRun::query()->findOrFail($sender->runId());
        $this->assertSame(RunStatus::Completed, $senderRun->status);
        $output = $sender->refresh()
            ->output();
        $requestId = 'workflow-signal:' . $senderRun->id . ':1';
        $delivery = $output['results'][0];
        $this->assertSame('delivered', $delivery['outcome']);
        $this->assertNull($delivery['reason']);
        $this->assertSame($target->runId(), $delivery['target_run_id']);
        $this->assertSame($requestId, $delivery['request_id']);
        $this->assertFalse($delivery['retained']);
        $this->assertSame('after', $output['after']);

        // The target received an ordinary signal attributed to the sender.
        $this->assertSame([
            'approved' => 'Ada',
            'workflow_id' => 'sx-delivered-target',
        ], $target->refresh()
            ->output());
        $command = WorkflowCommand::query()->findOrFail($delivery['target_command_id']);
        $this->assertSame(CommandType::Signal, $command->command_type);
        $this->assertSame(CommandStatus::Accepted, $command->status);
        $this->assertSame('workflow', $command->source);
        $this->assertSame($requestId, $command->requestId());
        // JSON column key order is driver-specific.
        $this->assertEquals([
            'parent_instance_id' => 'sx-delivered-sender',
            'parent_run_id' => $senderRun->id,
            'sequence' => 1,
        ], $command->context['workflow']);
        $this->assertSame(
            $delivery['target_signal_id'],
            WorkflowSignal::query()->where('workflow_command_id', $command->id)->sole()->id,
        );
        $this->assertSame(1, $this->eventCount($target->runId(), HistoryEventType::SignalReceived));

        // One request and one outcome at the command's sequence; the next
        // durable step takes the following sequence.
        $events = $this->stepEvents($senderRun->id);
        $this->assertSame([
            ['ExternalSignalRequested', 1],
            ['ExternalSignalDelivered', 1],
            ['SideEffectRecorded', 2],
        ], $events);
        $requested = $this->event($senderRun->id, HistoryEventType::ExternalSignalRequested);
        $this->assertSame('sx-delivered-target', $requested->payload['target_workflow_instance_id']);
        $this->assertNull($requested->payload['target_workflow_run_id']);
        $this->assertSame('approve', $requested->payload['signal_name']);
        $this->assertSame($requestId, $requested->payload['request_id']);
        $this->assertSame('avro', $requested->payload['payload_codec']);
        $this->assertSame('avro', $requested->payload['arguments']['codec']);

        // Cold replay and query replay read the recorded outcome and send nothing.
        $replayed = (new WorkflowReplayer())->replay(WorkflowRun::query()->findOrFail($senderRun->id));
        $this->assertNull($replayed->current);
        $this->assertSame(3, $replayed->sequence);
        $queried = (new QueryStateReplayer())->replayState(
            WorkflowRun::query()->with('historyEvents')->findOrFail($senderRun->id)
        );
        $this->assertNull($queried->current);
        $this->assertSame(1, WorkflowSignal::query()->where('workflow_run_id', $target->runId())->count());
        $this->assertSame(1, WorkflowCommand::query()
            ->where('workflow_instance_id', 'sx-delivered-target')
            ->where('command_type', CommandType::Signal->value)
            ->count());
    }

    public function testRolledBackSenderTaskSendsNothingAndItsRetryDeliversOnce(): void
    {
        $target = $this->startTarget('sx-rollback-target');
        $sender = WorkflowStub::make(TestSignalExternalSenderWorkflow::class, 'sx-rollback-sender');
        $sender->start([[
            'workflow_id' => 'sx-rollback-target',
            'signal' => 'approve',
            'arguments' => ['retry'],
        ]]);
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $sender->runId())
            ->where('status', TaskStatus::Ready->value)
            ->sole();

        // The sender's task runs completely, then its transaction is lost.
        // Delivery waits for the sender's commit, so nothing reached the target.
        DB::beginTransaction();
        try {
            $this->app->call([new RunWorkflowTask($task->id), 'handle']);
            $this->assertSame(1, $this->eventCount($sender->runId(), HistoryEventType::ExternalSignalRequested));
            $this->assertSame(0, WorkflowSignal::query()->where('workflow_run_id', $target->runId())->count());
        } finally {
            DB::rollBack();
        }

        $this->assertSame(0, WorkflowSignal::query()->where('workflow_run_id', $target->runId())->count());
        $this->assertSame(0, $this->eventCount($sender->runId(), HistoryEventType::ExternalSignalRequested));

        $this->drainReadyTasks();

        $this->assertSame('delivered', $sender->refresh()->output()['results'][0]['outcome']);
        $this->assertSame(1, WorkflowSignal::query()->where('workflow_run_id', $target->runId())->count());
        $this->assertSame(1, $this->eventCount($sender->runId(), HistoryEventType::ExternalSignalRequested));
        $this->assertSame('retry', $target->refresh()->output()['approved']);
    }

    public function testFailedOutcomesAreRecordedValuesNotTaskFailures(): void
    {
        $closed = $this->startTarget('sx-closed-target');
        WorkflowStub::load('sx-closed-target')->signal('approve', 'first');
        $this->drainReadyTasks();
        $this->assertTrue($closed->refresh()->completed());

        $open = $this->startTarget('sx-open-target');
        $foreign = $this->startTarget('sx-foreign-target');
        WorkflowInstance::query()->whereKey('sx-foreign-target')->update([
            'namespace' => 'another-namespace',
        ]);

        $sender = WorkflowStub::make(TestSignalExternalSenderWorkflow::class, 'sx-failure-sender');
        $sender->start([
            [
                'workflow_id' => 'sx-missing-target',
                'signal' => 'approve',
            ],
            [
                'workflow_id' => 'sx-closed-target',
                'signal' => 'approve',
                'arguments' => ['late'],
            ],
            [
                'workflow_id' => 'sx-open-target',
                'signal' => 'undeclared',
            ],
            [
                'workflow_id' => 'sx-failure-sender',
                'signal' => 'approve',
            ],
            [
                'workflow_id' => 'sx-open-target',
                'signal' => 'approve',
                'run_id' => $closed->runId(),
            ],
            [
                'workflow_id' => 'sx-foreign-target',
                'signal' => 'approve',
            ],
            [
                'workflow_id' => 'sx-open-target',
                'signal' => WorkflowStub::MESSAGE_STREAM_RUNTIME_SIGNAL,
            ],
        ]);
        $this->drainReadyTasks();

        $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($sender->runId())->status);
        $results = $sender->refresh()
            ->output()['results'];
        $this->assertSame([
            ['target_not_found', 'instance_not_found'],
            ['target_closed', 'run_not_active'],
            ['rejected', 'unknown_signal'],
            ['rejected', 'self_target'],
            ['target_not_found', 'run_not_found'],
            ['target_not_found', 'instance_not_found'],
            ['rejected', 'runtime_reserved_signal'],
        ], array_map(static fn (array $result): array => [$result['outcome'], $result['reason']], $results));

        // A closed target records the rejected command, as for any signal.
        $this->assertSame($closed->runId(), $results[1]['target_run_id']);
        $rejected = WorkflowCommand::query()->findOrFail($results[1]['target_command_id']);
        $this->assertSame(CommandStatus::Rejected, $rejected->status);
        $this->assertSame('run_not_active', $rejected->rejection_reason);
        $this->assertSame(0, WorkflowCommand::query()
            ->where('workflow_instance_id', 'sx-foreign-target')
            ->where('command_type', CommandType::Signal->value)
            ->count());
        $this->assertFalse($open->refresh()->completed());
        $this->assertFalse($foreign->refresh()->completed());
        $this->assertSame(7, $this->eventCount($sender->runId(), HistoryEventType::ExternalSignalFailed));
        $this->assertSame(0, $this->eventCount($sender->runId(), HistoryEventType::ExternalSignalDelivered));
        $this->assertSame(
            [['SideEffectRecorded', 8]],
            array_values(array_filter(
                $this->stepEvents($sender->runId()),
                static fn (array $event): bool => $event[0] === 'SideEffectRecorded',
            )),
        );
    }

    public function testRunTargetedSignalReachesOnlyThatRun(): void
    {
        $target = $this->startTarget('sx-run-target');
        $sender = WorkflowStub::make(TestSignalExternalSenderWorkflow::class, 'sx-run-sender');
        $sender->start([[
            'workflow_id' => 'sx-run-target',
            'signal' => 'approve',
            'arguments' => ['run'],
            'run_id' => $target->runId(),
        ]]);
        $this->drainReadyTasks();

        $result = $sender->refresh()
            ->output()['results'][0];
        $this->assertSame('delivered', $result['outcome']);
        $this->assertSame($target->runId(), $result['target_run_id']);
        $this->assertSame('run', $target->refresh()->output()['approved']);
    }

    public function testChildHandleSignalsItsChildDurably(): void
    {
        // Native child admission receipts name their namespace.
        config([
            'workflows.v2.namespace' => 'sx-child-handle',
        ]);
        $parent = WorkflowStub::make(TestSignalExternalChildHandleWorkflow::class, 'sx-child-handle-parent');
        $parent->start('from-parent');
        $this->drainReadyTasks();

        $output = $parent->refresh()
            ->output();
        $failure = \Workflow\V2\Models\WorkflowFailure::query()->where('workflow_run_id', $parent->runId())->first();
        $this->assertNull($failure?->message, (string) $failure?->message);
        $this->assertSame('delivered', $output['signal']['outcome']);
        $this->assertSame('from-parent', $output['child']['approved']);
        $this->assertSame(1, $this->eventCount($parent->runId(), HistoryEventType::ExternalSignalDelivered));
    }

    public function testInvalidSignalNameIsRefusedInWorkflowCodeBeforeAnythingIsRecorded(): void
    {
        $this->startTarget('sx-invalid-name-target');
        $sender = WorkflowStub::make(TestSignalExternalInvalidNameWorkflow::class, 'sx-invalid-name-sender');
        $sender->start('sx-invalid-name-target', "bad\nname");
        $this->drainReadyTasks();

        $this->assertSame([
            'reason' => 'invalid_signal_name',
            'field' => 'signal_name',
        ], $sender->refresh()
            ->output());
        $this->assertSame(0, $this->eventCount($sender->runId(), HistoryEventType::ExternalSignalRequested));
        $this->assertSame(0, WorkflowCommand::query()
            ->where('workflow_instance_id', 'sx-invalid-name-target')
            ->where('command_type', CommandType::Signal->value)
            ->count());
    }

    public function testCallersCannotUseTheReservedRequestIdPrefix(): void
    {
        $this->startTarget('sx-reserved-target');

        try {
            WorkflowStub::load('sx-reserved-target')
                ->withRequestId('workflow-signal:some-run:1')
                ->attemptSignal('approve', 'forged');
            $this->fail('A caller must not use a reserved workflow-signal key.');
        } catch (InvalidCommandRequestIdException $exception) {
            $this->assertSame('request_id_reserved', $exception->reason());
        }

        try {
            WorkflowStub::make(TestSignalExternalTargetWorkflow::class, 'sx-reserved-signal-with-start')
                ->withRequestId('workflow-signal:some-run:2')
                ->attemptSignalWithStart('approve', ['forged'], StartOptions::returnExistingActive());
            $this->fail('Signal-with-start must not use a reserved workflow-signal key.');
        } catch (InvalidCommandRequestIdException $exception) {
            $this->assertSame('request_id_reserved', $exception->reason());
        }

        $response = $this->app->make(WorkflowControlPlane::class)->signal('sx-reserved-target', 'approve', [
            'request_id' => 'workflow-signal:some-run:3',
            'arguments' => ['forged'],
        ]);
        $this->assertSame(422, $response['status']);
        $this->assertSame('request_id_reserved', $response['reason']);
        $this->assertFalse($response['recorded']);

        $this->assertSame(0, WorkflowCommand::query()
            ->whereIn('workflow_instance_id', ['sx-reserved-target', 'sx-reserved-signal-with-start'])
            ->where('command_type', CommandType::Signal->value)
            ->count());
        $this->assertNull(WorkflowStub::load('sx-reserved-signal-with-start')->runId());

        // An ordinary key is unaffected.
        $this->assertTrue(WorkflowStub::load('sx-reserved-target')
            ->withRequestId('workflow-signalling-is-fine')
            ->attemptSignal('approve', 'ok')
            ->accepted());
    }

    public function testCleanupAfterACancellationRequestCannotSignalAnotherWorkflow(): void
    {
        $target = $this->startTarget('sx-cleanup-target');
        $sender = WorkflowStub::make(TestSignalExternalCleanupWorkflow::class, 'sx-cleanup-sender');
        $sender->start('sx-cleanup-target');
        $this->drainReadyTasks();

        $this->assertTrue($sender->requestCancellation('stop', 30)->accepted());
        $this->drainReadyTasks();

        $this->assertTrue($sender->refresh()->cancelled());
        $this->assertSame(0, $this->eventCount($sender->runId(), HistoryEventType::ExternalSignalRequested));
        $this->assertSame(
            'cancellation_cleanup_command_not_available',
            Serializer::unserializeWithCodec(
                'avro',
                $this->event($sender->runId(), HistoryEventType::SideEffectRecorded)->payload['result'],
            ),
        );
        $this->assertSame(0, WorkflowSignal::query()->where('workflow_run_id', $target->runId())->count());
    }

    public function testReservedPrefixRefusalsCarryTheServerBody(): void
    {
        $this->startTarget('sx-body-target');
        $message = 'The request_id prefix [workflow-signal:] is reserved for signals sent by workflow code.';

        $controlPlane = $this->app->make(WorkflowControlPlane::class)->signal('sx-body-target', 'approve', [
            'request_id' => 'workflow-signal:run:9',
            'arguments' => ['forged'],
        ]);
        $this->assertSame('request_id_reserved', $controlPlane['reason']);
        $this->assertSame('rejected_request_id_reserved', $controlPlane['outcome']);
        $this->assertFalse($controlPlane['accepted']);
        $this->assertSame($message, $controlPlane['message']);

        config([
            'workflows.webhook_auth.method' => 'none',
        ]);
        Webhooks::routes([TestSignalExternalTargetWorkflow::class]);

        $this->withHeader('X-Request-Id', 'workflow-signal:run:10')
            ->postJson('/webhooks/instances/sx-body-target/signals/approve', [
                'arguments' => ['forged'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'request_id_reserved')
            ->assertJsonPath('outcome', 'rejected_request_id_reserved')
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('message', $message);

        $this->assertSame(0, WorkflowCommand::query()
            ->where('workflow_instance_id', 'sx-body-target')
            ->where('command_type', CommandType::Signal->value)
            ->count());
    }

    private function startTarget(string $id): WorkflowStub
    {
        $target = WorkflowStub::make(TestSignalExternalTargetWorkflow::class, $id);
        $target->start();
        $this->drainReadyTasks();

        return $target;
    }

    /**
     * @return list<array{0: string, 1: int|null}>
     */
    private function stepEvents(string $runId): array
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->whereIn('event_type', [
                HistoryEventType::ExternalSignalRequested->value,
                HistoryEventType::ExternalSignalDelivered->value,
                HistoryEventType::ExternalSignalFailed->value,
                HistoryEventType::SideEffectRecorded->value,
            ])
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): array => [
                $event->event_type->value,
                $event->payload['sequence'] ?? null,
            ])
            ->values()
            ->all();
    }

    private function event(string $runId, HistoryEventType $type): WorkflowHistoryEvent
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', $type->value)
            ->sole();
    }

    private function eventCount(string $runId, HistoryEventType $type): int
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', $type->value)
            ->count();
    }

    private function drainReadyTasks(): void
    {
        $deadline = microtime(true) + 20;

        while (microtime(true) < $deadline) {
            /** @var WorkflowTask|null $task */
            // Due tasks only: a delayed delivery retry waits for its time.
            $task = WorkflowTask::query()
                ->where('status', TaskStatus::Ready->value)
                ->orderBy('available_at')
                ->orderBy('created_at')
                ->get()
                ->first(static fn (WorkflowTask $ready): bool => $ready->available_at === null
                    || ! $ready->available_at->isFuture());

            if ($task === null) {
                return;
            }

            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new RunTimerTask($task->id),
            };

            $this->app->call([$job, 'handle']);
        }

        $this->fail('Timed out draining ready workflow tasks.');
    }
}
