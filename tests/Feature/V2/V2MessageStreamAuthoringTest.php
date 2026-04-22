<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestMessageStreamAuthoringWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\MessageChannel;
use Workflow\V2\Enums\MessageConsumeState;
use Workflow\V2\Enums\MessageDirection;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\MessageService;
use Workflow\V2\WorkflowStub;

final class V2MessageStreamAuthoringTest extends TestCase
{
    public function testWorkflowCanPeekReceiveAndReplyThroughFirstClassMessageStreams(): void
    {
        Queue::fake();
        config()->set('workflows.v2.task_dispatch_mode', 'poll');

        $workflow = WorkflowStub::make(TestMessageStreamAuthoringWorkflow::class, 'message-stream-authoring');
        $workflow->start();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()
            ->where('workflow_instance_id', 'message-stream-authoring')
            ->sole();

        $service = new MessageService();
        $service->sendMessage(
            $run,
            MessageChannel::WorkflowMessage,
            $run->workflow_instance_id,
            'payload:hello',
            'chat',
        );

        /** @var WorkflowMessage $inbound */
        $inbound = WorkflowMessage::query()
            ->where('workflow_instance_id', $run->workflow_instance_id)
            ->where('workflow_run_id', $run->id)
            ->where('direction', MessageDirection::Inbound)
            ->where('stream_key', 'chat')
            ->sole();

        $this->assertSame(MessageConsumeState::Pending, $inbound->consume_state);
        $this->assertNull($inbound->consumed_by_sequence);

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'workflow_id' => 'message-stream-authoring',
            'run_id' => $run->id,
            'peeked' => ['payload:hello'],
            'received' => 'payload:hello',
            'pending_after_receive' => 0,
            'reply_payload_reference' => 'reply:payload:hello',
            'reply_stream_key' => 'chat.replies',
        ], $workflow->output());

        $inbound->refresh();
        $this->assertSame(MessageConsumeState::Consumed, $inbound->consume_state);
        $this->assertGreaterThanOrEqual(1, (int) $inbound->consumed_by_sequence);

        $run->refresh();
        $this->assertSame((int) $inbound->sequence, (int) $run->message_cursor_position);

        $replyMessages = WorkflowMessage::query()
            ->where('workflow_instance_id', $run->workflow_instance_id)
            ->where('stream_key', 'chat.replies')
            ->orderBy('direction')
            ->get();

        $this->assertCount(2, $replyMessages);
        $this->assertSame(
            [MessageDirection::Inbound->value, MessageDirection::Outbound->value],
            $replyMessages->pluck('direction')
                ->map(static fn (MessageDirection $direction): string => $direction->value)
                ->all(),
        );
        $this->assertSame(['reply:payload:hello', 'reply:payload:hello'], $replyMessages->pluck('payload_reference')->all());

        /** @var WorkflowHistoryEvent $cursorAdvanced */
        $cursorAdvanced = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::MessageCursorAdvanced)
            ->sole();

        $this->assertSame([
            'stream_key' => 'chat',
            'previous_position' => 0,
            'new_position' => (int) $inbound->sequence,
        ], $cursorAdvanced->payload);
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
