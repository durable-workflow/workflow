<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestInboxBranchWorkflow;
use Tests\Fixtures\V2\TestInboxKeptBranchWorkflow;
use Tests\Fixtures\V2\TestInboxKeptStreamWorkflow;
use Tests\Fixtures\V2\TestInboxTurnsWorkflow;
use Tests\Fixtures\V2\TestMessageStreamExactContentWorkflow;
use Tests\Fixtures\V2\TestMessageStreamKeptStreamWorkflow;
use Tests\Fixtures\V2\TestMessageStreamRedriveLaterReceiveWorkflow;
use Tests\Fixtures\V2\TestMessageStreamRedriveWorkflow;
use Tests\Fixtures\V2\TestMessageStreamTurnsWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\MessageChannel;
use Workflow\V2\Enums\MessageConsumeState;
use Workflow\V2\Enums\MessageDirection;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\MessageService;
use Workflow\V2\WorkflowStub;

/**
 * Message stream calls in workflow code run again on every replay: on each
 * later workflow task and on every query. They must act once (#1010).
 */
final class V2MessageStreamReplayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()
            ->set('workflows.v2.task_dispatch_mode', 'poll');
    }

    public function testAWorkflowCodeSendIsWrittenOncePerTurnAcrossReplaysAndQueries(): void
    {
        $workflow = WorkflowStub::make(TestMessageStreamTurnsWorkflow::class, 'message-stream-turns');
        $workflow->start(3);
        $this->drainReadyTasks();

        $counts = [];
        $workflow->signal('message', 'one');
        $this->drainReadyTasks();
        $counts['after turn 1'] = $this->replyRows('message-stream-turns');

        $workflow->signal('message', 'two');
        $this->drainReadyTasks();
        $counts['after turn 2'] = $this->replyRows('message-stream-turns');

        $before = $this->messageRows();
        $this->assertSame(2, $workflow->refresh()->query('status'));
        $counts['after a query'] = $this->replyRows('message-stream-turns');
        $this->assertSame(2, $workflow->refresh()->query('status'));
        $counts['after a second query'] = $this->replyRows('message-stream-turns');
        // A query writes nothing at all.
        $this->assertSame($before, $this->messageRows());

        $workflow->signal('message', 'three');
        $this->drainReadyTasks();
        $counts['after turn 3'] = $this->replyRows('message-stream-turns');

        $this->assertSame([
            'after turn 1' => [1, 1],
            'after turn 2' => [2, 2],
            'after a query' => [2, 2],
            'after a second query' => [2, 2],
            'after turn 3' => [3, 3],
        ], $counts);
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'turns' => 3,
            'sent' => ['ref:1', 'ref:2', 'ref:3'],
        ], $workflow->output());
        $this->assertSame(
            ['ref:1', 'ref:2', 'ref:3'],
            WorkflowMessage::query()
                ->where('workflow_instance_id', 'message-stream-turns')
                ->where('stream_key', 'conversation.replies')
                ->where('direction', MessageDirection::Outbound->value)
                ->orderBy('sequence')
                ->pluck('payload_reference')
                ->all(),
        );
    }

    public function testIdenticalSendsInOneTurnStaySeparateAcrossReplays(): void
    {
        $workflow = WorkflowStub::make(TestMessageStreamTurnsWorkflow::class, 'message-stream-copies');
        $workflow->start(2, 2);
        $this->drainReadyTasks();

        $workflow->signal('message', 'one');
        $this->drainReadyTasks();
        $this->assertSame([2, 2], $this->replyRows('message-stream-copies'));
        $this->assertSame(1, $workflow->refresh()->query('status'));
        $this->assertSame([2, 2], $this->replyRows('message-stream-copies'));

        $workflow->signal('message', 'two');
        $this->drainReadyTasks();

        $this->assertSame([4, 4], $this->replyRows('message-stream-copies'));
        $this->assertSame([
            'turns' => 2,
            'sent' => ['ref:1', 'ref:1', 'ref:2', 'ref:2'],
        ], $workflow->refresh()
            ->output());
    }

    /**
     * Releases through 2.20.0 wrote a duplicate on every replay. A run that
     * already has such rows matches its sends to them and writes only new
     * sends from now on; the old duplicates are left as they are.
     */
    public function testARunWithDuplicatesFromAnEarlierReleaseWritesOnlyNewSends(): void
    {
        $workflow = WorkflowStub::make(TestMessageStreamTurnsWorkflow::class, 'message-stream-legacy');
        $workflow->start(3);
        $this->drainReadyTasks();
        $workflow->signal('message', 'one');
        $this->drainReadyTasks();
        $run = WorkflowRun::query()->where('workflow_instance_id', 'message-stream-legacy')->sole();

        // The extra rows a replay wrote before this fix.
        foreach (range(1, 2) as $ignored) {
            (new MessageService())->sendMessage(
                $run,
                MessageChannel::WorkflowMessage,
                'message-stream-legacy',
                'ref:1',
                'conversation.replies',
                idempotencyKey: 'ref:1',
            );
        }
        $this->assertSame([3, 3], $this->replyRows('message-stream-legacy'));

        $workflow->signal('message', 'two');
        $this->drainReadyTasks();
        $this->assertSame([4, 4], $this->replyRows('message-stream-legacy'));
        $this->assertSame(2, $workflow->refresh()->query('status'));
        $this->assertSame([4, 4], $this->replyRows('message-stream-legacy'));

        $workflow->signal('message', 'three');
        $this->drainReadyTasks();
        $this->assertSame([5, 5], $this->replyRows('message-stream-legacy'));
        $this->assertSame(['ref:1', 'ref:2', 'ref:3'], $workflow->refresh()->output()['sent']);
    }

    /**
     * A stream kept in a property and first opened by an update handler that
     * replays with dispatch off still writes the workflow's later sends.
     */
    public function testAStreamFirstOpenedInAReplayedUpdateHandlerStillWritesLaterSends(): void
    {
        $workflow = WorkflowStub::make(TestMessageStreamKeptStreamWorkflow::class, 'message-stream-kept');
        $workflow->start();
        $this->drainReadyTasks();

        $this->assertSame('noted a', $workflow->attemptUpdate('note', 'a')->result());
        $this->drainReadyTasks();
        $workflow->signal('go');
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'sent' => 'ref:main',
        ], $workflow->output());
        $this->assertSame(
            ['note:a', 'ref:main'],
            WorkflowMessage::query()
                ->where('workflow_instance_id', 'message-stream-kept')
                ->where('stream_key', 'replies')
                ->where('direction', MessageDirection::Outbound->value)
                ->orderBy('sequence')
                ->pluck('payload_reference')
                ->all(),
        );
    }

    /**
     * A redrive successor runs the source's receive and send again over the
     * reused prefix. It returns what the source consumed and wrote.
     */
    public function testARedriveSuccessorReturnsTheSourcesReceiveAndSend(): void
    {
        config()
            ->set('workflows.v2.testing.redrive_failures_before_success', 1);
        Cache::forget('test:redrive:first-calls');
        Cache::forget('test:redrive:second-calls');
        $workflow = WorkflowStub::make(TestMessageStreamRedriveWorkflow::class, 'message-stream-redrive');
        $workflow->start();
        $this->drainReadyTasks();
        $source = WorkflowRun::query()->where('workflow_instance_id', 'message-stream-redrive')->sole();

        $workflow->signal('go');
        $this->sendToInbox($source, 'm1');
        $this->drainReadyTasks();
        $this->assertTrue($workflow->refresh()->failed());
        $this->sendToInbox($source->fresh(), 'm2');

        $result = app(WorkflowControlPlane::class)->redrive('message-stream-redrive', $source->id);
        $this->assertTrue($result['accepted'], (string) $result['reason']);
        $this->drainReadyTasks();

        $successor = WorkflowRun::query()->findOrFail($result['workflow_run_id']);
        $this->assertSame([
            'received' => 'm1',
            'sent' => 'ref:m1',
            'result' => 'Hello, m1! Redrive completed.',
        ], $successor->workflowOutput());
        $this->assertSame(['ref:m1'], WorkflowMessage::query()
            ->where('workflow_instance_id', 'message-stream-redrive')
            ->where('stream_key', 'replies')
            ->where('direction', MessageDirection::Outbound->value)
            ->pluck('payload_reference')
            ->all());
        // m1 is consumed once. m2, still pending when the source failed, moved
        // to the successor (#1013); this workflow doesn't receive again.
        $this->assertSame($successor->id, WorkflowMessage::query()
            ->where('workflow_instance_id', 'message-stream-redrive')
            ->where('payload_reference', 'm2')
            ->where('direction', MessageDirection::Inbound->value)
            ->sole()
            ->workflow_run_id);
        $this->assertSame(
            [
                'm1' => MessageConsumeState::Consumed->value,
                'm2' => MessageConsumeState::Pending->value,
            ],
            WorkflowMessage::query()
                ->where('workflow_instance_id', 'message-stream-redrive')
                ->where('stream_key', 'chat')
                ->where('direction', MessageDirection::Inbound->value)
                ->orderBy('sequence')
                ->get()
                ->mapWithKeys(static fn (WorkflowMessage $message): array => [
                    (string) $message->payload_reference => $message->consume_state->value,
                ])
                ->all(),
        );
    }

    /**
     * Sends that differ only by case, accent or trailing space are separate
     * sends, even where the column collation compares them as equal.
     */
    public function testSendsThatDifferOnlyByCaseAccentOrTrailingSpaceStaySeparate(): void
    {
        $workflow = WorkflowStub::make(TestMessageStreamExactContentWorkflow::class, 'message-stream-exact');
        $workflow->start();
        $this->drainReadyTasks();
        $workflow->signal('go');
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $rows = WorkflowMessage::query()
            ->where('workflow_instance_id', 'message-stream-exact')
            ->where('stream_key', 'docs')
            ->where('direction', MessageDirection::Outbound->value)
            ->orderBy('sequence')
            ->get();
        $this->assertSame(
            TestMessageStreamExactContentWorkflow::REFERENCES,
            $rows->map(static fn (WorkflowMessage $row): string => (string) $row->getRawOriginal('payload_reference'))
                ->all(),
        );
        $output = $workflow->output();
        $this->assertSame(TestMessageStreamExactContentWorkflow::REFERENCES, array_column($output, 'reference'));
        $this->assertSame(
            $rows->map(static fn (WorkflowMessage $row): int|string => $row->getKey())
                ->all(),
            array_column($output, 'id'),
        );
    }

    /**
     * A signal advances the run's message cursor past an older message that is
     * still pending on a stream. The message stays receivable (#1011).
     */
    public function testASignalDoesNotHideAnOlderPendingStreamMessage(): void
    {
        $workflow = WorkflowStub::make(TestInboxTurnsWorkflow::class, 'inbox-before-signal');
        $workflow->start(1);
        $this->drainReadyTasks();
        $run = WorkflowRun::query()->where('workflow_instance_id', 'inbox-before-signal')->sole();

        // The message arrives before the ping that wakes the receive.
        $this->sendToInbox($run, 'm1');
        $workflow->signal('ping');
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame(['m1'], $workflow->output());
    }

    /**
     * Consuming a newer message on one stream doesn't hide an older pending
     * message on another stream of the same run.
     */
    public function testAReceiveOnOneStreamDoesNotHideAnotherStreamsOlderMessage(): void
    {
        $workflow = WorkflowStub::make(TestInboxTurnsWorkflow::class, 'inbox-two-streams');
        $workflow->start(1);
        $this->drainReadyTasks();
        $run = WorkflowRun::query()->where('workflow_instance_id', 'inbox-two-streams')->sole();
        $service = new MessageService();
        $service->sendMessage($run, MessageChannel::WorkflowMessage, $run->workflow_instance_id, 'b1', 'b');
        $service->sendMessage($run, MessageChannel::WorkflowMessage, $run->workflow_instance_id, 'a1', 'a');

        $a1 = $service->peekMessages($run->fresh(), 'a')
            ->sole();
        $service->consumeMessage($run->fresh(), $a1, 1);
        $this->assertGreaterThan(
            (int) WorkflowMessage::query()->where('payload_reference', 'b1')->where(
                'direction',
                MessageDirection::Inbound->value
            )->sole()
->sequence,
            (int) $run->fresh()
->message_cursor_position,
        );

        $this->assertSame(['b1'], $service->peekMessages($run->fresh(), 'b')->pluck('payload_reference')->all());
        $this->assertSame(1, $service->getUnconsumedCount($run->fresh(), 'b'));
        $this->assertTrue($service->hasUnconsumedMessages($run->fresh(), 'b'));
        $this->assertSame([], $service->peekMessages($run->fresh(), 'a')->pluck('payload_reference')->all());
    }

    /**
     * A message that arrives while the run is failed belongs to that run. The
     * redrive moves it to the successor, which receives it at a later step.
     */
    public function testARedriveSuccessorReceivesAMessageThatArrivedWhileTheSourceWasFailed(): void
    {
        config()
            ->set('workflows.v2.testing.redrive_failures_before_success', 1);
        Cache::forget('test:redrive:first-calls');
        Cache::forget('test:redrive:second-calls');
        $workflow = WorkflowStub::make(
            TestMessageStreamRedriveLaterReceiveWorkflow::class,
            'message-stream-redrive-later'
        );
        $workflow->start();
        $this->drainReadyTasks();
        $source = WorkflowRun::query()->where('workflow_instance_id', 'message-stream-redrive-later')->sole();

        $workflow->signal('go');
        $this->sendToInbox($source, 'm1');
        $this->drainReadyTasks();
        $this->assertTrue($workflow->refresh()->failed());
        $this->sendToInbox($source->fresh(), 'm2');
        $this->assertSame($source->id, WorkflowMessage::query()
            ->where('payload_reference', 'm2')
            ->where('direction', MessageDirection::Inbound->value)
            ->sole()
            ->workflow_run_id);

        $result = app(WorkflowControlPlane::class)->redrive('message-stream-redrive-later', $source->id);
        $this->assertTrue($result['accepted'], (string) $result['reason']);
        $this->drainReadyTasks();

        $successor = WorkflowRun::query()->findOrFail($result['workflow_run_id']);
        $this->assertSame('completed', $successor->status->value);
        $this->assertSame([
            'first' => 'm1',
            'later' => 'm2',
            'result' => 'Hello, m1! Redrive completed.',
        ], $successor->workflowOutput());
        $m2 = WorkflowMessage::query()
            ->where('payload_reference', 'm2')
            ->where('direction', MessageDirection::Inbound->value)
            ->sole();
        $this->assertSame($successor->id, $m2->workflow_run_id);
        $this->assertSame(MessageConsumeState::Consumed, $m2->consume_state);
    }

    /**
     * A receive that found nothing replays as nothing, even when a message
     * has arrived since. The message waits for a receive the run hasn't made
     * yet (#1011 review): a history where an earlier release's cursor hid a
     * message must not consume it at the old step after upgrading.
     */
    public function testAReceiveThatFoundNothingReplaysAsNothingAndTheMessageWaitsForTheNextTurn(): void
    {
        $workflow = WorkflowStub::make(TestInboxTurnsWorkflow::class, 'inbox-empty-first');
        $workflow->start(2);
        $this->drainReadyTasks();
        $run = WorkflowRun::query()->where('workflow_instance_id', 'inbox-empty-first')->sole();

        // Turn 1 finds the inbox empty.
        $workflow->signal('ping');
        $this->drainReadyTasks();
        $this->sendToInbox($run, 'm1');
        $before = $this->messageRows();
        $this->assertSame([null], $workflow->refresh()->query('received'));
        $this->assertSame($before, $this->messageRows());

        $workflow->signal('ping');
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([null, 'm1'], $workflow->output());
    }

    /**
     * A stream kept from an earlier step still consumes at the step the code
     * is at now: the replay check reads the current step on every call.
     */
    public function testAKeptInboxStillReceivesAtLaterTurns(): void
    {
        $workflow = WorkflowStub::make(TestInboxKeptStreamWorkflow::class, 'inbox-kept');
        $workflow->start(2);
        $this->drainReadyTasks();
        $run = WorkflowRun::query()->where('workflow_instance_id', 'inbox-kept')->sole();

        $workflow->signal('ping');
        $this->sendToInbox($run, 'm1');
        $this->drainReadyTasks();
        $workflow->signal('ping');
        $this->sendToInbox($run, 'm2');
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame(['m1', 'm2'], $workflow->output());
    }

    /**
     * A kept inbox whose first turn found nothing records a later turn's
     * consumption at that later step, so the first turn still replays as
     * nothing.
     */
    public function testAKeptInboxWhoseFirstTurnWasEmptyReplaysThatTurnAsNothing(): void
    {
        $workflow = WorkflowStub::make(TestInboxKeptStreamWorkflow::class, 'inbox-kept-empty-first');
        $workflow->start(2);
        $this->drainReadyTasks();
        $run = WorkflowRun::query()->where('workflow_instance_id', 'inbox-kept-empty-first')->sole();

        $workflow->signal('ping');
        $this->drainReadyTasks();
        $this->sendToInbox($run, 'm1');
        $workflow->signal('ping');
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([null, 'm1'], $workflow->output());
    }

    /**
     * Rows an earlier release wrote for a kept stream sit at the step the
     * stream was opened at. A later turn finds its consumption there when
     * nothing is recorded at its own step.
     */
    public function testAKeptInboxStillReplaysConsumptionsRecordedAtItsOpenedStep(): void
    {
        $workflow = WorkflowStub::make(TestInboxKeptStreamWorkflow::class, 'inbox-kept-legacy');
        $workflow->start(3);
        $this->drainReadyTasks();
        $run = WorkflowRun::query()->where('workflow_instance_id', 'inbox-kept-legacy')->sole();

        $workflow->signal('ping');
        $this->sendToInbox($run, 'm1');
        $this->drainReadyTasks();
        $workflow->signal('ping');
        $this->sendToInbox($run, 'm2');
        $this->drainReadyTasks();
        $m1 = WorkflowMessage::query()->where('payload_reference', 'm1')->where(
            'direction',
            MessageDirection::Inbound->value
        )->sole();
        $m2 = WorkflowMessage::query()->where('payload_reference', 'm2')->where(
            'direction',
            MessageDirection::Inbound->value
        )->sole();
        $this->assertNotSame($m1->consumed_by_sequence, $m2->consumed_by_sequence);

        // As an earlier release recorded it: both at the step the stream opened.
        $m2->forceFill([
            'consumed_by_sequence' => $m1->consumed_by_sequence,
        ])->save();

        $this->assertSame(['m1', 'm2'], $workflow->refresh()->query('received'));
    }

    /**
     * The same with a branch and a third turn, whose task replays the first
     * two turns.
     */
    public function testAKeptInboxBranchReplaysAnEmptyFirstTurn(): void
    {
        $workflow = WorkflowStub::make(TestInboxKeptBranchWorkflow::class, 'inbox-kept-branch');
        $workflow->start(3);
        $this->drainReadyTasks();
        $run = WorkflowRun::query()->where('workflow_instance_id', 'inbox-kept-branch')->sole();

        $workflow->signal('ping');
        $this->drainReadyTasks();
        $this->sendToInbox($run, 'm1');
        $workflow->signal('ping');
        $this->drainReadyTasks();
        $workflow->signal('ping');
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed(), (string) $workflow->refresh()->status());
        $this->assertSame(['none:Hello, none!', 'got:m1', 'none:Hello, none!'], $workflow->output());
    }

    /**
     * The same history when workflow code branches on the receive: replay
     * takes the branch the original run took, so the run doesn't fail with a
     * history mismatch.
     */
    public function testABranchOnAnEmptyReceiveReplaysTheBranchTheRunTook(): void
    {
        $workflow = WorkflowStub::make(TestInboxBranchWorkflow::class, 'inbox-branch');
        $workflow->start(2);
        $this->drainReadyTasks();
        $run = WorkflowRun::query()->where('workflow_instance_id', 'inbox-branch')->sole();

        $workflow->signal('ping');
        $this->drainReadyTasks();
        $this->sendToInbox($run, 'm1');
        $workflow->signal('ping');
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed(), (string) $workflow->refresh()->status());
        $this->assertSame(['none:Hello, none!', 'got:m1'], $workflow->output());
        $this->assertSame(
            MessageConsumeState::Consumed,
            WorkflowMessage::query()->where('payload_reference', 'm1')->where(
                'direction',
                MessageDirection::Inbound->value
            )->sole()
->consume_state,
        );
    }

    public function testAWorkflowCodeReceiveReplaysTheMessageItConsumedAndQueriesConsumeNothing(): void
    {
        $workflow = WorkflowStub::make(TestInboxTurnsWorkflow::class, 'inbox-turns');
        $workflow->start(2);
        $this->drainReadyTasks();
        $run = WorkflowRun::query()->where('workflow_instance_id', 'inbox-turns')->sole();

        // Each turn's ping is sent before its messages: a signal advances the
        // run's shared message cursor past anything older.
        $workflow->signal('ping');
        $this->sendToInbox($run, 'm1');
        $this->drainReadyTasks();

        $before = $this->messageRows();
        $this->assertSame(['m1'], $workflow->refresh()->query('received'));
        // A query replays the message turn 1 consumed and consumes nothing.
        $this->assertSame($before, $this->messageRows());

        $workflow->signal('ping');
        // Two more messages arrive before the next turn runs.
        $this->sendToInbox($run, 'm2');
        $this->sendToInbox($run, 'm3');

        $before = $this->messageRows();
        $this->assertSame(['m1'], $workflow->refresh()->query('received'));
        $this->assertSame($before, $this->messageRows());

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        // Turn 1 replays the message it consumed; turn 2 takes the next one.
        $this->assertSame(['m1', 'm2'], $workflow->output());
        $this->assertSame(
            [
                'm1' => MessageConsumeState::Consumed->value,
                'm2' => MessageConsumeState::Consumed->value,
                'm3' => MessageConsumeState::Pending->value,
            ],
            WorkflowMessage::query()
                ->where('workflow_instance_id', 'inbox-turns')
                ->where('stream_key', 'chat')
                ->where('direction', MessageDirection::Inbound->value)
                ->orderBy('sequence')
                ->get()
                ->mapWithKeys(static fn (WorkflowMessage $message): array => [
                    (string) $message->payload_reference => $message->consume_state->value,
                ])
                ->all(),
        );
    }

    /**
     * @return array{int, int} Outbound and inbound reply rows.
     */
    private function replyRows(string $instanceId): array
    {
        $rows = WorkflowMessage::query()
            ->where('workflow_instance_id', $instanceId)
            ->where('stream_key', 'conversation.replies');

        return [
            (clone $rows)->where('direction', MessageDirection::Outbound->value)->count(),
            (clone $rows)->where('direction', MessageDirection::Inbound->value)->count(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messageRows(): array
    {
        return WorkflowMessage::query()
            ->orderBy('id')
            ->get()
            ->map(static fn (WorkflowMessage $message): array => $message->getRawOriginal())
            ->all();
    }

    private function sendToInbox(WorkflowRun $run, string $reference): void
    {
        (new MessageService())->sendMessage(
            $run,
            MessageChannel::WorkflowMessage,
            $run->workflow_instance_id,
            $reference,
            'chat',
        );
    }

    private function drainReadyTasks(): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->where('status', TaskStatus::Ready->value)
                ->orderBy('created_at')
                ->first();

            if ($task === null) {
                return;
            }

            if ($task->available_at !== null && $task->available_at->isFuture()) {
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
