<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\MessageStreamCursor;

final class MessageStreamCursorTest extends TestCase
{
    public function testReserveNextSequenceIncrementsInstanceCounter(): void
    {
        $instance = $this->createInstance();

        $this->assertSame(0, (int) $instance->last_message_sequence);

        $first = MessageStreamCursor::reserveNextSequence($instance);
        $this->assertSame(1, $first);
        $this->assertSame(1, (int) $instance->last_message_sequence);

        $second = MessageStreamCursor::reserveNextSequence($instance);
        $this->assertSame(2, $second);
        $this->assertSame(2, (int) $instance->last_message_sequence);
    }

    public function testReserveNextSequenceIsDurableThroughRefresh(): void
    {
        $instance = $this->createInstance();

        MessageStreamCursor::reserveNextSequence($instance);
        MessageStreamCursor::reserveNextSequence($instance);
        MessageStreamCursor::reserveNextSequence($instance);

        $reloaded = WorkflowInstance::query()->findOrFail($instance->id);
        $this->assertSame(3, (int) $reloaded->last_message_sequence);
    }

    public function testAdvanceCursorUpdatesCursorAndRecordsHistoryEvent(): void
    {
        $instance = $this->createInstance();
        $run = $this->createRun($instance);

        $this->assertSame(0, MessageStreamCursor::positionForRun($run));

        $event = MessageStreamCursor::advanceCursor($run, 3);

        $this->assertInstanceOf(WorkflowHistoryEvent::class, $event);
        $this->assertSame(HistoryEventType::MessageCursorAdvanced, $event->event_type);
        $this->assertSame(0, $event->payload['previous_position']);
        $this->assertSame(3, $event->payload['new_position']);
        $this->assertSame(3, MessageStreamCursor::positionForRun($run));
    }

    public function testAdvanceCursorIsIdempotentForSamePosition(): void
    {
        $instance = $this->createInstance();
        $run = $this->createRun($instance);

        $first = MessageStreamCursor::advanceCursor($run, 5);
        $this->assertInstanceOf(WorkflowHistoryEvent::class, $first);

        $second = MessageStreamCursor::advanceCursor($run, 5);
        $this->assertNull($second);

        $this->assertSame(5, MessageStreamCursor::positionForRun($run));
    }

    public function testAdvanceCursorIgnoresBackwardMovement(): void
    {
        $instance = $this->createInstance();
        $run = $this->createRun($instance);

        MessageStreamCursor::advanceCursor($run, 10);

        $result = MessageStreamCursor::advanceCursor($run, 5);
        $this->assertNull($result);

        $this->assertSame(10, MessageStreamCursor::positionForRun($run));
    }

    public function testAdvanceCursorIncludesDefaultStreamKey(): void
    {
        $instance = $this->createInstance();
        $run = $this->createRun($instance);

        $event = MessageStreamCursor::advanceCursor($run, 1);

        $expectedKey = sprintf('instance:%s', $instance->id);
        $this->assertSame($expectedKey, $event->payload['stream_key']);
    }

    public function testAdvanceCursorAcceptsCustomStreamKey(): void
    {
        $instance = $this->createInstance();
        $run = $this->createRun($instance);

        $event = MessageStreamCursor::advanceCursor($run, 1, null, 'custom:signals');

        $this->assertSame('custom:signals', $event->payload['stream_key']);
    }

    public function testTransferCursorCopiesPositionToNewRun(): void
    {
        $instance = $this->createInstance();
        $oldRun = $this->createRun($instance, 1);
        $newRun = $this->createRun($instance, 2);

        MessageStreamCursor::advanceCursor($oldRun, 7);
        $this->assertSame(0, MessageStreamCursor::positionForRun($newRun));

        MessageStreamCursor::transferCursor($oldRun, $newRun);

        $this->assertSame(7, MessageStreamCursor::positionForRun($newRun));
    }

    public function testTransferCursorIsDurableThroughRefresh(): void
    {
        $instance = $this->createInstance();
        $oldRun = $this->createRun($instance, 1);
        $newRun = $this->createRun($instance, 2);

        MessageStreamCursor::advanceCursor($oldRun, 12);
        MessageStreamCursor::transferCursor($oldRun, $newRun);

        $reloaded = WorkflowRun::query()->findOrFail($newRun->id);
        $this->assertSame(12, (int) $reloaded->message_cursor_position);
    }

    public function testHasUnconsumedMessagesDetectsGap(): void
    {
        $instance = $this->createInstance();
        $run = $this->createRun($instance);

        $this->assertFalse(MessageStreamCursor::hasUnconsumedMessages($run, $instance));

        MessageStreamCursor::reserveNextSequence($instance);
        MessageStreamCursor::reserveNextSequence($instance);

        $this->assertTrue(MessageStreamCursor::hasUnconsumedMessages($run, $instance));

        MessageStreamCursor::advanceCursor($run, 2);

        $this->assertFalse(MessageStreamCursor::hasUnconsumedMessages($run, $instance));
    }

    public function testDefaultStreamKeyFormat(): void
    {
        $instance = $this->createInstance();
        $run = $this->createRun($instance);

        $key = MessageStreamCursor::defaultStreamKey($run);

        $this->assertSame(sprintf('instance:%s', $instance->id), $key);
    }

    public function testAdvanceCursorIsDurable(): void
    {
        $instance = $this->createInstance();
        $run = $this->createRun($instance);

        MessageStreamCursor::advanceCursor($run, 5);

        $reloaded = WorkflowRun::query()->findOrFail($run->id);
        $this->assertSame(5, (int) $reloaded->message_cursor_position);
    }

    public function testContinueAsNewPreservesUnconsumedMessageDetection(): void
    {
        $instance = $this->createInstance();
        $oldRun = $this->createRun($instance, 1);

        MessageStreamCursor::reserveNextSequence($instance);
        MessageStreamCursor::reserveNextSequence($instance);
        MessageStreamCursor::reserveNextSequence($instance);

        MessageStreamCursor::advanceCursor($oldRun, 2);

        $newRun = $this->createRun($instance, 2);
        MessageStreamCursor::transferCursor($oldRun, $newRun);

        $this->assertTrue(MessageStreamCursor::hasUnconsumedMessages($newRun, $instance));

        MessageStreamCursor::advanceCursor($newRun, 3);

        $this->assertFalse(MessageStreamCursor::hasUnconsumedMessages($newRun, $instance));
    }

    private function createInstance(): WorkflowInstance
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => 'App\\Workflows\\TestWorkflow',
            'workflow_type' => 'test_workflow',
            'last_message_sequence' => 0,
        ]);

        return $instance;
    }

    private function createRun(WorkflowInstance $instance, int $runNumber = 1): WorkflowRun
    {
        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => $runNumber,
            'workflow_class' => $instance->workflow_class,
            'workflow_type' => $instance->workflow_type,
            'status' => RunStatus::Running->value,
            'last_history_sequence' => 0,
            'last_command_sequence' => 0,
            'message_cursor_position' => 0,
        ]);

        return $run;
    }
}
