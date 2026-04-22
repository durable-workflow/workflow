<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;
use Workflow\V2\Enums\MessageChannel;
use Workflow\V2\Enums\MessageConsumeState;
use Workflow\V2\Enums\MessageDirection;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\MessageService;

/**
 * Phase 4 test coverage for workflow messages system.
 *
 * Validates:
 * - Message send/receive/consume lifecycle
 * - Stream key isolation and sequencing
 * - Consume state transitions
 * - Continue-as-new message transfer
 * - Cursor advancement integration
 * - Correlation and idempotency
 */
class MessageServiceTest extends TestCase
{
    use RefreshDatabase;

    private MessageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MessageService();
    }

    public function testItSendsMessageCreatingOutboundAndInboundRecords(): void
    {
        $sourceRun = $this->createRun();
        $targetInstance = $this->createInstance();

        $message = $this->service->sendMessage(
            $sourceRun,
            MessageChannel::WorkflowMessage,
            $targetInstance->id,
            'payload_ref_123',
            'test_stream',
            'corr_123',
        );

        // Check outbound message (sender's view)
        $this->assertNotNull($message);
        $this->assertEquals($sourceRun->workflow_instance_id, $message->workflow_instance_id);
        $this->assertEquals($sourceRun->id, $message->workflow_run_id);
        $this->assertEquals(MessageDirection::Outbound, $message->direction);
        $this->assertEquals('workflow_message', $message->channel);
        $this->assertEquals('test_stream', $message->stream_key);
        $this->assertEquals(1, $message->sequence); // First message
        $this->assertEquals($targetInstance->id, $message->target_workflow_instance_id);
        $this->assertEquals('payload_ref_123', $message->payload_reference);
        $this->assertEquals('corr_123', $message->correlation_id);
        $this->assertEquals(MessageConsumeState::Pending, $message->consume_state);

        // Check inbound message was also created (receiver's view)
        $inboundMessage = WorkflowMessage::where('workflow_instance_id', $targetInstance->id)
            ->where('direction', MessageDirection::Inbound)
            ->first();

        $this->assertNotNull($inboundMessage);
        $this->assertEquals($targetInstance->id, $inboundMessage->workflow_instance_id);
        $this->assertEquals(MessageDirection::Inbound, $inboundMessage->direction);
        $this->assertEquals('test_stream', $inboundMessage->stream_key);
        $this->assertEquals(1, $inboundMessage->sequence);
        $this->assertEquals($sourceRun->workflow_instance_id, $inboundMessage->source_workflow_instance_id);
        $this->assertEquals($sourceRun->id, $inboundMessage->source_workflow_run_id);
    }

    public function testItReservesSequentialMessageNumbersPerStream(): void
    {
        $sourceRun = $this->createRun();
        $targetInstance = $this->createInstance();

        // Send multiple messages to same stream
        $msg1 = $this->service->sendMessage($sourceRun, MessageChannel::Signal, $targetInstance->id, null, 'stream_a');
        $msg2 = $this->service->sendMessage($sourceRun, MessageChannel::Signal, $targetInstance->id, null, 'stream_a');
        $msg3 = $this->service->sendMessage($sourceRun, MessageChannel::Signal, $targetInstance->id, null, 'stream_a');

        // Sequences should increment
        $this->assertEquals(1, $msg1->sequence);
        $this->assertEquals(2, $msg2->sequence);
        $this->assertEquals(3, $msg3->sequence);

        // Check inbound messages are sequenced on the target instance stream.
        $inbound = WorkflowMessage::where('workflow_instance_id', $targetInstance->id)
            ->where('direction', MessageDirection::Inbound)
            ->orderBy('sequence')
            ->pluck('sequence')
            ->toArray();

        $this->assertEquals([1, 2, 3], $inbound);
    }

    public function testItReservesIndependentOutboundAndInboundSequencesForSelfTargetedStreams(): void
    {
        $run = $this->createRun();

        $message = $this->service->sendMessage(
            $run,
            MessageChannel::WorkflowMessage,
            $run->workflow_instance_id,
            'payload_ref_self',
            'self_stream',
        );

        $inboundMessage = WorkflowMessage::where('workflow_instance_id', $run->workflow_instance_id)
            ->where('direction', MessageDirection::Inbound)
            ->firstOrFail();

        $this->assertEquals(1, $message->sequence);
        $this->assertEquals(2, $inboundMessage->sequence);
        $this->assertEquals(2, $run->instance->refresh()->last_message_sequence);
    }

    public function testItReceivesUnconsumedInboundMessages(): void
    {
        $sourceRun = $this->createRun();
        $targetRun = $this->createRun();

        // Send messages to target
        $this->service->sendMessage($sourceRun, MessageChannel::Signal, $targetRun->workflow_instance_id);
        $this->service->sendMessage($sourceRun, MessageChannel::Signal, $targetRun->workflow_instance_id);
        $this->service->sendMessage($sourceRun, MessageChannel::Signal, $targetRun->workflow_instance_id);

        // Receive messages
        $messages = $this->service->receiveMessages($targetRun);

        $this->assertCount(3, $messages);
        $this->assertEquals(MessageDirection::Inbound, $messages[0]->direction);
        $this->assertEquals(MessageConsumeState::Pending, $messages[0]->consume_state);

        // Messages should be in sequence order
        $sequences = $messages->pluck('sequence')
            ->toArray();
        $this->assertEquals([1, 2, 3], $sequences);
    }

    public function testItConsumesMessageAndAdvancesCursor(): void
    {
        $sourceRun = $this->createRun();
        $targetRun = $this->createRun();

        // Send and receive message
        $this->service->sendMessage($sourceRun, MessageChannel::Signal, $targetRun->workflow_instance_id);
        $messages = $this->service->receiveMessages($targetRun);
        $message = $messages->first();

        $this->assertEquals(MessageConsumeState::Pending, $message->consume_state);

        // Consume message
        $this->service->consumeMessage($targetRun, $message, 10);

        // Check message is marked consumed
        $message->refresh();
        $this->assertEquals(MessageConsumeState::Consumed, $message->consume_state);
        $this->assertNotNull($message->consumed_at);
        $this->assertEquals(10, $message->consumed_by_sequence);

        // Check cursor was advanced
        $targetRun->refresh();
        $this->assertEquals(1, $targetRun->message_cursor_position);
    }

    public function testItConsumesMessageBatchAndAdvancesToHighestSequence(): void
    {
        $sourceRun = $this->createRun();
        $targetRun = $this->createRun();

        // Send multiple messages
        $this->service->sendMessage($sourceRun, MessageChannel::Signal, $targetRun->workflow_instance_id);
        $this->service->sendMessage($sourceRun, MessageChannel::Signal, $targetRun->workflow_instance_id);
        $this->service->sendMessage($sourceRun, MessageChannel::Signal, $targetRun->workflow_instance_id);

        $messages = $this->service->receiveMessages($targetRun);
        $this->assertCount(3, $messages);

        // Consume all in batch
        $this->service->consumeMessageBatch($targetRun, $messages->all(), 15);

        // All should be marked consumed
        foreach ($messages as $message) {
            $message->refresh();
            $this->assertEquals(MessageConsumeState::Consumed, $message->consume_state);
            $this->assertEquals(15, $message->consumed_by_sequence);
        }

        // Cursor should be at highest sequence
        $targetRun->refresh();
        $this->assertEquals(3, $targetRun->message_cursor_position);
    }

    public function testItOnlyReceivesMessagesAfterCursorPosition(): void
    {
        $sourceRun = $this->createRun();
        $targetRun = $this->createRun();

        // Send 5 messages
        for ($i = 0; $i < 5; $i++) {
            $this->service->sendMessage($sourceRun, MessageChannel::Signal, $targetRun->workflow_instance_id);
        }

        // Receive first 2
        $messages = $this->service->receiveMessages($targetRun, null, 2);
        $this->assertCount(2, $messages);

        // Consume them
        $this->service->consumeMessageBatch($targetRun, $messages->all(), 10);

        // Cursor now at 2
        $targetRun->refresh();
        $this->assertEquals(2, $targetRun->message_cursor_position);

        // Receive again - should only get messages 3, 4, 5
        $remainingMessages = $this->service->receiveMessages($targetRun);
        $this->assertCount(3, $remainingMessages);
        $sequences = $remainingMessages->pluck('sequence')
            ->toArray();
        $this->assertEquals([3, 4, 5], $sequences);
    }

    public function testItGetsUnconsumedCount(): void
    {
        $sourceRun = $this->createRun();
        $targetRun = $this->createRun();

        // Initially no messages
        $this->assertEquals(0, $this->service->getUnconsumedCount($targetRun));

        // Send 3 messages
        for ($i = 0; $i < 3; $i++) {
            $this->service->sendMessage($sourceRun, MessageChannel::Signal, $targetRun->workflow_instance_id);
        }

        $this->assertEquals(3, $this->service->getUnconsumedCount($targetRun));

        // Consume 1
        $messages = $this->service->receiveMessages($targetRun, null, 1);
        $this->service->consumeMessage($targetRun, $messages->first(), 10);

        // Now 2 unconsumed
        $this->assertEquals(2, $this->service->getUnconsumedCount($targetRun));
    }

    public function testItChecksForUnconsumedMessages(): void
    {
        $sourceRun = $this->createRun();
        $targetRun = $this->createRun();

        $this->assertFalse($this->service->hasUnconsumedMessages($targetRun));

        $this->service->sendMessage($sourceRun, MessageChannel::Signal, $targetRun->workflow_instance_id);

        $this->assertTrue($this->service->hasUnconsumedMessages($targetRun));
    }

    public function testItTransfersMessagesOnContinueAsNew(): void
    {
        $sourceRun = $this->createRun();
        $closingRun = $this->createRun();

        // Send 5 messages to closing run while it is the current run.
        for ($i = 0; $i < 5; $i++) {
            $this->service->sendMessage($sourceRun, MessageChannel::Signal, $closingRun->workflow_instance_id);
        }

        // Closing run consumes 2 messages
        $messages = $this->service->receiveMessages($closingRun, null, 2);
        $this->service->consumeMessageBatch($closingRun, $messages->all(), 10);

        // Cursor at 2, 3 messages still pending
        $closingRun->refresh();
        $this->assertEquals(2, $closingRun->message_cursor_position);
        $this->assertEquals(3, $this->service->getUnconsumedCount($closingRun));

        // Continue-as-new promotes a new run on the same instance.
        $continuedRun = $this->createRunForInstance($closingRun->instance);

        // Transfer to continued run
        $this->service->transferMessagesToContinuedRun($closingRun, $continuedRun);

        // Continued run should have cursor at 2
        $continuedRun->refresh();
        $this->assertEquals(2, $continuedRun->message_cursor_position);

        // Pending messages should now belong to continued run
        $pendingMessages = WorkflowMessage::where('workflow_instance_id', $closingRun->workflow_instance_id)
            ->where('direction', MessageDirection::Inbound)
            ->where('consume_state', MessageConsumeState::Pending)
            ->get();

        $this->assertCount(3, $pendingMessages);
        foreach ($pendingMessages as $msg) {
            $this->assertEquals($continuedRun->id, $msg->workflow_run_id);
        }

        // Continued run can receive remaining messages
        $remaining = $this->service->receiveMessages($continuedRun);
        $this->assertCount(3, $remaining);
        $sequences = $remaining->pluck('sequence')
            ->toArray();
        $this->assertEquals([3, 4, 5], $sequences);
    }

    public function testItIsolatesMessagesByStreamKey(): void
    {
        $sourceRun = $this->createRun();
        $targetRun = $this->createRun();

        // Send to different streams
        $this->service->sendMessage(
            $sourceRun,
            MessageChannel::Signal,
            $targetRun->workflow_instance_id,
            null,
            'stream_a'
        );
        $this->service->sendMessage(
            $sourceRun,
            MessageChannel::Signal,
            $targetRun->workflow_instance_id,
            null,
            'stream_a'
        );
        $this->service->sendMessage(
            $sourceRun,
            MessageChannel::Signal,
            $targetRun->workflow_instance_id,
            null,
            'stream_b'
        );
        $this->service->sendMessage(
            $sourceRun,
            MessageChannel::Signal,
            $targetRun->workflow_instance_id,
            null,
            'stream_b'
        );

        // Receive from stream_a
        $streamA = $this->service->receiveMessages($targetRun, 'stream_a');
        $this->assertCount(2, $streamA);
        $this->assertTrue($streamA->every(static fn ($msg) => $msg->stream_key === 'stream_a'));

        // Receive from stream_b
        $streamB = $this->service->receiveMessages($targetRun, 'stream_b');
        $this->assertCount(2, $streamB);
        $this->assertTrue($streamB->every(static fn ($msg) => $msg->stream_key === 'stream_b'));
    }

    public function testItPreventsConsumingNonConsumableMessage(): void
    {
        $sourceRun = $this->createRun();
        $targetRun = $this->createRun();

        $this->service->sendMessage($sourceRun, MessageChannel::Signal, $targetRun->workflow_instance_id);
        $messages = $this->service->receiveMessages($targetRun);
        $message = $messages->first();

        // Consume once
        $this->service->consumeMessage($targetRun, $message, 10);

        // Try to consume again
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not consumable');

        $this->service->consumeMessage($targetRun, $message, 11);
    }

    public function testItSupportsCorrelationIdTracking(): void
    {
        $sourceRun = $this->createRun();
        $targetInstance = $this->createInstance();

        // Send messages with same correlation ID
        $this->service->sendMessage(
            $sourceRun,
            MessageChannel::Signal,
            $targetInstance->id,
            null,
            null,
            'request_123',
        );

        $this->service->sendMessage(
            $sourceRun,
            MessageChannel::Signal,
            $targetInstance->id,
            null,
            null,
            'request_123',
        );

        // Query by correlation ID — two sendMessage calls produce outbound
        // and inbound records each, so all four rows share the correlation.
        $correlated = $this->service->getMessagesByCorrelationId('request_123');
        $this->assertCount(4, $correlated);
        $this->assertTrue($correlated->every(static fn ($msg) => $msg->correlation_id === 'request_123'));
    }

    public function testItSupportsIdempotencyKey(): void
    {
        $sourceRun = $this->createRun();
        $targetInstance = $this->createInstance();

        $msg1 = $this->service->sendMessage(
            $sourceRun,
            MessageChannel::Signal,
            $targetInstance->id,
            null,
            null,
            null,
            'idempotent_key_123',
        );

        $this->assertEquals('idempotent_key_123', $msg1->idempotency_key);

        // Caller is responsible for checking idempotency before sending
        // The system stores the key but doesn't enforce uniqueness
        // This allows flexible idempotency strategies
    }

    public function testItSupportsMessageExpiry(): void
    {
        $sourceRun = $this->createRun();
        $targetRun = $this->createRun();

        $expiresAt = now()
            ->addMinutes(5);

        $this->service->sendMessage(
            $sourceRun,
            MessageChannel::Signal,
            $targetRun->workflow_instance_id,
            null,
            null,
            null,
            null,
            [],
            $expiresAt,
        );

        $messages = $this->service->receiveMessages($targetRun);
        $message = $messages->first();

        $this->assertNotNull($message->expires_at);
        $this->assertTrue($message->isConsumable()); // Not expired yet

        // Artificially expire
        $message->expires_at = now()
            ->subMinute();
        $message->save();

        $this->assertFalse($message->isConsumable()); // Now expired
    }

    public function testItGetsMessagesForStream(): void
    {
        $sourceRun = $this->createRun();
        $targetInstance = $this->createInstance();

        $streamKey = 'test_stream_xyz';

        for ($i = 0; $i < 3; $i++) {
            $this->service->sendMessage($sourceRun, MessageChannel::Signal, $targetInstance->id, null, $streamKey);
        }

        $messages = $this->service->getMessagesForStream($targetInstance->id, $streamKey);

        $this->assertCount(3, $messages); // Gets inbound messages
        $this->assertTrue($messages->every(static fn ($msg) => $msg->stream_key === $streamKey));
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

    private function createRunForInstance(WorkflowInstance $instance): WorkflowRun
    {
        $run = WorkflowRun::create([
            'id' => 'run-' . uniqid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
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
