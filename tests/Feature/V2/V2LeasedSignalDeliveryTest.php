<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestLeasedSignalDeliveryWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\SignalWaits;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\WorkflowStub;

final class V2LeasedSignalDeliveryTest extends TestCase
{
    public static function timings(): iterable
    {
        yield 'during lease, definition available' => [true, true];
        yield 'after completion, definition available' => [false, true];
        yield 'during lease, durable contract only' => [true, false];
        yield 'after completion, durable contract only' => [false, false];
    }

    #[DataProvider('timings')]
    public function testSignalReceivesADeliveryTurn(bool $duringLease, bool $definitionAvailable): void
    {
        Queue::fake();
        $workflow = WorkflowStub::make(TestLeasedSignalDeliveryWorkflow::class);
        $workflow->start();
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $initial = WorkflowTask::query()->where('workflow_run_id', $run->id)->sole();
        $this->assertNotNull($bridge->claim($initial->id, 'first-owner'));
        $firstHistory = $bridge->historyPayload($initial->id);
        $firstStep = WorkflowFiberRunner::forClass(
            TestLeasedSignalDeliveryWorkflow::class,
            $workflow->id(),
            $run->id,
            [],
            $firstHistory['payload_codec'],
            $firstHistory['history_events']
        )->step();
        $this->assertFalse($firstStep->completed);
        $this->assertCount(3, $firstStep->commands);
        $opened = $bridge->complete($initial->id, $firstStep->commands);
        $this->assertTrue($opened['completed'], json_encode($opened));
        $waitId = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SignalWaitOpened)->get()
            ->firstWhere('payload.signal_name', 'advance')
->payload['signal_wait_id'];
        $finish = $workflow->signal('finish');
        $this->assertTrue($finish->accepted());
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->sole();
        $this->assertNotNull($bridge->claim($task->id, 'second-owner'));
        // The worker's snapshot precedes the signal committed during its lease.
        $secondHistory = $bridge->historyPayload($task->id);
        $secondStep = WorkflowFiberRunner::forClass(
            TestLeasedSignalDeliveryWorkflow::class,
            $workflow->id(),
            $run->id,
            [],
            $secondHistory['payload_codec'],
            $secondHistory['history_events']
        )->step();
        $this->assertFalse($secondStep->completed);
        $this->assertSame([], $secondStep->commands);
        if (! $definitionAvailable) {
            $run->forceFill([
                'workflow_class' => 'UnavailableLeasedSignalDeliveryWorkflow',
            ])->save();
            $run->instance->forceFill([
                'workflow_class' => 'UnavailableLeasedSignalDeliveryWorkflow',
            ])->save();
        }
        $signal = $duringLease ? $workflow->signal('advance') : null;
        $completed = $bridge->complete($task->id, $secondStep->commands);
        $this->assertTrue($completed['completed'], json_encode($completed));
        $signal ??= $workflow->signal('advance');
        $this->assertTrue($signal->accepted());
        $wait = collect(SignalWaits::forRun($run->fresh()))->firstWhere('signal_wait_id', $waitId);
        $this->assertSame('received', $wait['source_status']);
        $ready = WorkflowTask::query()->where('workflow_run_id', $run->id)->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->get();
        $this->assertCount(1, $ready, json_encode([
            'completion' => $completed,
            'wait' => $wait,
        ]));
        $this->assertSame($signal->commandId(), $ready->sole()->payload['workflow_command_id']);

        $delivery = $ready->sole();
        $this->assertNotNull($bridge->claim($delivery->id, 'third-owner'));
        $history = $bridge->historyPayload($delivery->id);
        $replayed = WorkflowFiberRunner::forClass(
            TestLeasedSignalDeliveryWorkflow::class,
            $workflow->id(),
            $run->id,
            [],
            $history['payload_codec'],
            $history['history_events']
        )->step();
        $this->assertFalse($replayed->completed);
        $this->assertSame([], $replayed->commands);
        $applied = $bridge->complete($delivery->id, $replayed->commands);
        $this->assertTrue($applied['completed'], json_encode($applied));
        $this->assertSame([], $applied['created_task_ids']);
        $this->assertSame(2, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SignalApplied)->count());
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $run->id)->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->count());
    }
}
