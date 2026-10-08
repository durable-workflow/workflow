<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestBufferedSignalHistoryWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\WorkflowTaskPayload;
use Workflow\V2\WorkflowStub;

final class BufferedSignalWaitIdentityTest extends TestCase
{
    public static function waits(): array
    {
        return [
            'active matching' => ['append', false, true],
            'cancelled matching' => ['append', true, false],
            'unrelated name' => ['another', false, false],
        ];
    }

    #[DataProvider('waits')]
    public function testBufferedDeliveryOnlyResolvesAnActiveMatchingAuthoredWait(
        string $name,
        bool $cancelled,
        bool $applies
    ): void {
        config()->set('queue.default', 'database');
        Queue::fake();
        $stub = WorkflowStub::make(TestBufferedSignalHistoryWorkflow::class, 'buffered-identity');
        $stub->start(1);
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $bridge = $this->app->make(DefaultWorkflowTaskBridge::class);
        $initial = WorkflowTask::query()->where('workflow_run_id', $run->id)->sole();
        $this->assertTrue($bridge->claimStatus($initial->id, 'identity-worker')['claimed']);
        $this->assertTrue($bridge->complete($initial->id, [[
            'type' => 'open_signal_wait',
            'signal_name' => $name,
        ]])['completed']);
        $opened = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SignalWaitOpened->value)->sole();
        $waitId = $opened->payload['signal_wait_id'];
        $this->assertTrue($stub->signal('append', 'first')->accepted());
        $signal = WorkflowSignal::query()->where('workflow_run_id', $run->id)->sole();
        $bufferedId = 'signal-command:' . $signal->workflow_command_id;
        $signal->forceFill([
            'signal_wait_id' => $bufferedId,
        ])->save();
        $received = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SignalReceived->value)->sole();
        $received->forceFill([
            'payload' => [
                ...$received->payload,
                'signal_wait_id' => $bufferedId,
            ],
        ])->save();
        if ($cancelled) {
            WorkflowHistoryEvent::record($run, HistoryEventType::SignalWaitCancelled, [
                'signal_wait_id' => $waitId,
            ]);
        }
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->where('status', 'ready')->first();
        if (! $task instanceof WorkflowTask) {
            $task = WorkflowTask::query()->create([
                'workflow_run_id' => $run->id,
                'task_type' => 'workflow',
                'status' => 'ready',
                'available_at' => now(),
                'connection' => $run->connection,
                'queue' => $run->queue,
                'compatibility' => $run->compatibility,
            ]);
        }
        $task->forceFill([
            'payload' => WorkflowTaskPayload::forSignal($signal),
        ])->save();
        $this->assertTrue($bridge->claimStatus($task->id, 'identity-worker')['claimed']);
        $this->assertTrue($bridge->complete($task->id, [])['completed']);
        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SignalApplied->value)->get();
        $this->assertCount($applies ? 1 : 0, $events);
        $this->assertSame($applies ? SignalStatus::Applied : SignalStatus::Received, $signal->fresh()->status);
        if ($applies) {
            $this->assertSame($waitId, $events->sole()->payload['signal_wait_id']);
            $this->assertSame(1, $events->sole()->payload['sequence']);
            $this->assertSame($signal->id, $events->sole()->payload['signal_id']);
        } else {
            $this->assertSame($bufferedId, $signal->fresh()->signal_wait_id);
        }
    }
}
