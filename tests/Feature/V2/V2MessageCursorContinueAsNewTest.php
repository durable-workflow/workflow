<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestConfiguredContinueSignalWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\MessageStreamCursor;
use Workflow\V2\WorkflowStub;

final class V2MessageCursorContinueAsNewTest extends TestCase
{
    public function testSignalCursorTransfersThroughContinueAsNew(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestConfiguredContinueSignalWorkflow::class, 'cursor-continue-1');
        $workflow->start(0);

        $this->drainReadyTasks();

        // Run 1 (count=0) does continue-as-new immediately.
        // Run 2 (count=1) opens a signal wait.
        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'signal');

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'cursor-continue-1')
            ->orderBy('run_number')
            ->get();

        $this->assertCount(2, $runs);

        $firstRun = $runs[0];
        $secondRun = $runs[1];

        // Cursor transfer: run 1 consumed no messages, so both start at 0.
        // After transfer, run 2 inherits run 1's position (0).
        $this->assertSame(0, (int) $firstRun->message_cursor_position);
        $this->assertSame(0, (int) $secondRun->fresh()->message_cursor_position);

        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->findOrFail('cursor-continue-1');

        // No signal sent yet — instance sequence is 0.
        $this->assertSame(0, (int) $instance->last_message_sequence);
        $this->assertFalse(MessageStreamCursor::hasUnconsumedMessages($secondRun, $instance));

        // Send signal — creates a command with message_sequence.
        $result = $workflow->signal('name-provided', 'Taylor');

        $this->assertTrue($result->accepted());
        $this->assertSame('signal_received', $result->outcome());

        // Instance sequence should have advanced.
        $instance->refresh();
        $this->assertGreaterThanOrEqual(1, (int) $instance->last_message_sequence);

        // The signal command should carry a message_sequence.
        $command = WorkflowCommand::query()->findOrFail($result->commandId());
        $this->assertNotNull($command->message_sequence);
        $this->assertSame((int) $instance->last_message_sequence, (int) $command->message_sequence);

        // Before draining: run 2 has unconsumed messages.
        $this->assertTrue(MessageStreamCursor::hasUnconsumedMessages($secondRun->fresh(), $instance));

        // Drain tasks so the signal is applied on run 2.
        $this->drainReadyTasks();

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        // After completion: run 2's cursor should have advanced past the signal.
        $secondRun->refresh();
        $this->assertGreaterThanOrEqual(
            (int) $command->message_sequence,
            (int) $secondRun->message_cursor_position,
        );
        $this->assertFalse(MessageStreamCursor::hasUnconsumedMessages($secondRun, $instance->fresh()));

        // A MessageCursorAdvanced event should exist on run 2.
        $cursorEvents = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $secondRun->id)
            ->where('event_type', HistoryEventType::MessageCursorAdvanced)
            ->get();

        $this->assertCount(1, $cursorEvents);
        $this->assertSame(0, $cursorEvents[0]->payload['previous_position']);
        $this->assertSame((int) $command->message_sequence, $cursorEvents[0]->payload['new_position']);
        $this->assertSame(
            sprintf('instance:%s', $instance->id),
            $cursorEvents[0]->payload['stream_key'],
        );
    }

    public function testMultipleSignalsBeforeContinueAsNewTransferCursorCorrectly(): void
    {
        Queue::fake();

        // Use the signal-with-start pattern: send signal at start, then continue-as-new
        // discards the signal (since run 1 doesn't await it), and run 2 picks up a fresh signal.
        $workflow = WorkflowStub::make(TestConfiguredContinueSignalWorkflow::class, 'cursor-continue-2');
        $workflow->start(0);

        $this->drainReadyTasks();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'signal');

        // Send two signals — only the first should be consumed by the signal wait.
        $first = $workflow->signal('name-provided', 'Alice');
        $second = $workflow->signal('name-provided', 'Bob');

        $this->assertTrue($first->accepted());
        $this->assertTrue($second->accepted());

        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->findOrFail('cursor-continue-2');
        $this->assertSame(2, (int) $instance->last_message_sequence);

        $firstCommand = WorkflowCommand::query()->findOrFail($first->commandId());
        $secondCommand = WorkflowCommand::query()->findOrFail($second->commandId());

        $this->assertSame(1, (int) $firstCommand->message_sequence);
        $this->assertSame(2, (int) $secondCommand->message_sequence);

        $this->drainReadyTasks();

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'cursor-continue-2')
            ->orderBy('run_number')
            ->get();

        $continuedRun = $runs->last();

        // The cursor should have advanced to at least the first signal's sequence.
        $this->assertGreaterThanOrEqual(1, (int) $continuedRun->message_cursor_position);

        // Verify the output used the first signal's value.
        $this->assertSame('Alice', $workflow->output()['name']);
    }

    private function waitFor(callable $condition): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }

            usleep(100000);
        }

        $this->fail('Timed out waiting for workflow to settle.');
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

            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                default => throw new \RuntimeException("Unexpected task type: {$task->task_type->value}"),
            };

            $this->app->call([$job, 'handle']);
        }

        $this->fail('Timed out draining ready workflow tasks.');
    }
}
