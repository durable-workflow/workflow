<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;
use Workflow\V2\Enums\MessageChannel;
use Workflow\V2\Enums\MessageConsumeState;
use Workflow\V2\MessageStream;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\MessageService;

final class MessageStreamTest extends TestCase
{
    use RefreshDatabase;

    public function testItPeeksWithoutConsumingMessages(): void
    {
        $sourceRun = $this->createRun();
        $targetRun = $this->createRun();
        $stream = MessageStream::forRun($targetRun, 'chat');

        $stream->sendReference($targetRun->workflow_instance_id, 'payload:one');
        (new MessageService())->sendMessage(
            $sourceRun,
            MessageChannel::WorkflowMessage,
            $targetRun->workflow_instance_id,
            'payload:two',
            'chat',
        );

        $messages = $stream->peek(2);

        $this->assertCount(2, $messages);
        $this->assertSame(['payload:one', 'payload:two'], $messages->pluck('payload_reference')->all());
        $this->assertSame(0, $stream->cursor());
        $this->assertSame(2, $stream->pendingCount());
        $this->assertTrue($messages->every(
            static fn (WorkflowMessage $message): bool => $message->consume_state === MessageConsumeState::Pending,
        ));
    }

    public function testItReceivesMessagesAndAdvancesCursorWithExplicitSequence(): void
    {
        $sourceRun = $this->createRun();
        $targetRun = $this->createRun();
        $service = new MessageService();

        $service->sendMessage(
            $sourceRun,
            MessageChannel::WorkflowMessage,
            $targetRun->workflow_instance_id,
            'one',
            'chat'
        );
        $service->sendMessage(
            $sourceRun,
            MessageChannel::WorkflowMessage,
            $targetRun->workflow_instance_id,
            'two',
            'chat'
        );
        $service->sendMessage(
            $sourceRun,
            MessageChannel::WorkflowMessage,
            $targetRun->workflow_instance_id,
            'three',
            'chat'
        );

        $stream = $service->stream($targetRun, 'chat');
        $received = $stream->receive(2, 7);

        $this->assertCount(2, $received);
        $this->assertSame(['one', 'two'], $received->pluck('payload_reference')->all());
        $this->assertSame(2, $targetRun->refresh()->message_cursor_position);
        $this->assertSame(1, $stream->pendingCount());

        foreach ($received as $message) {
            $this->assertSame(MessageConsumeState::Consumed, $message->refresh()->consume_state);
            $this->assertSame(7, $message->consumed_by_sequence);
        }

        $this->assertSame('three', $stream->receiveOne(8)?->payload_reference);
        $this->assertSame(3, $targetRun->refresh()->message_cursor_position);
    }

    public function testItUsesWorkflowDefaultSequenceWhenProvided(): void
    {
        $sourceRun = $this->createRun();
        $targetRun = $this->createRun();
        $service = new MessageService();

        $service->sendMessage($sourceRun, MessageChannel::WorkflowMessage, $targetRun->workflow_instance_id, 'one');

        $message = MessageStream::forRun($targetRun, defaultConsumedBySequence: 11)->receiveOne();

        $this->assertSame('one', $message?->payload_reference);
        $this->assertSame(11, $message?->refresh()->consumed_by_sequence);
    }

    public function testItRequiresASequenceBeforeConsumingMessages(): void
    {
        $sourceRun = $this->createRun();
        $targetRun = $this->createRun();
        $service = new MessageService();

        $service->sendMessage($sourceRun, MessageChannel::WorkflowMessage, $targetRun->workflow_instance_id, 'one');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive workflow history sequence');

        MessageStream::forRun($targetRun)->receiveOne();
    }

    public function testItRejectsInvalidStreamKeysAndLimits(): void
    {
        $run = $this->createRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');
        MessageStream::forRun($run, '');
    }

    public function testItRejectsNonPositiveReceiveLimits(): void
    {
        $run = $this->createRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limit must be at least 1');

        MessageStream::forRun($run)->peek(0);
    }

    private function createInstance(): WorkflowInstance
    {
        return WorkflowInstance::create([
            'id' => 'inst-' . uniqid(),
            'workflow_type' => 'TestWorkflow',
            'workflow_class' => 'Tests\\TestWorkflow',
        ]);
    }

    private function createRun(?WorkflowInstance $instance = null): WorkflowRun
    {
        $instance = $instance ?? $this->createInstance();

        $run = WorkflowRun::create([
            'id' => 'run-' . uniqid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'Tests\\TestWorkflow',
            'workflow_type' => 'TestWorkflow',
            'status' => 'running',
            'message_cursor_position' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }
}
