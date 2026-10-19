<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\RunSignalView;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;
use function Workflow\V2\continueAsNew;

final class TerminalSignalBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('queue.default', 'database');
    }

    public static function terminalCommands(): iterable
    {
        foreach (['default', 'tenant-a'] as $namespace) {
            foreach (['complete_workflow', 'continue_as_new'] as $type) {
                yield $namespace . '/' . $type => [$namespace, $type];
            }
        }
    }

    #[DataProvider('terminalCommands')]
    public function testServiceTerminalTurnDeliversAcceptedSignalBeforeClosing(string $namespace, string $type): void
    {
        [$stub, $run, $task] = $this->externalRun($namespace);
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $this->assertTrue($bridge->claimStatus($task->id, 'original-worker')['claimed']);
        $accepted = $stub->signal('message');
        $this->assertTrue($accepted->accepted());
        $signal = WorkflowSignal::query()->where('workflow_run_id', $run->id)->sole();
        $deadline = $run->execution_deadline_at->format('Y-m-d H:i:s.u');
        $command = ['type' => $type];
        $completion = $bridge->complete($task->id, [$command]);
        $this->assertTrue($completion['completed']);
        $this->assertFalse($run->fresh()->status->isTerminal());
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        $this->assertCount(1, $completion['created_task_ids']);
        $resume = WorkflowTask::query()->findOrFail($completion['created_task_ids'][0]);
        $this->assertSame($run->id, $resume->workflow_run_id);
        $this->assertSame($namespace, $resume->namespace);
        $this->assertSame($signal->id, $resume->payload['workflow_signal_id']);
        $this->assertSame(SignalStatus::Received, $signal->fresh()->status);
        $this->assertSame(1, WorkflowRun::query()->where('workflow_instance_id', $run->workflow_instance_id)->count());
        $this->assertSame('task_not_leased', $bridge->complete($task->id, [$command])['reason']);

        $this->assertTrue($bridge->claimStatus($resume->id, 'cold-worker')['claimed']);
        $this->assertTrue($bridge->complete($resume->id, [$command])['completed']);
        $this->assertSame(SignalStatus::Applied, $signal->fresh()->status);
        $this->assertNotNull($signal->fresh()->closed_at);
        $this->assertSame($accepted->commandId(), $signal->workflow_command_id);
        $this->assertSame($deadline, $run->fresh()->execution_deadline_at->format('Y-m-d H:i:s.u'));
        $this->assertSame($type === 'continue_as_new' ? 'continued' : 'completed', $run->fresh()->closed_reason);
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SignalApplied->value)->count());
    }

    public static function embeddedModes(): iterable
    {
        yield 'complete' => ['complete'];
        yield 'continue' => ['continue'];
    }

    #[DataProvider('embeddedModes')]
    public function testEmbeddedUnusedSignalGetsInspectableTerminalDisposition(string $mode): void
    {
        $stub = WorkflowStub::make(TerminalBufferedSignalWorkflow::class);
        $stub->start($mode);
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $accepted = $stub->signal('message');
        $this->assertTrue($accepted->accepted());
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->sole();
        $this->assertTrue(app(DefaultWorkflowTaskBridge::class)->execute($task->id)['executed']);
        $this->assertTrue($run->fresh()->status->isTerminal());
        $signal = WorkflowSignal::query()->where('workflow_run_id', $run->id)->sole();
        $this->assertSame(SignalStatus::Rejected, $signal->status);
        $this->assertSame('run_closed_before_signal_applied', $signal->rejection_reason);
        $this->assertNotNull($signal->closed_at);
        $this->assertNull($signal->applied_at);
        $row = RunSignalView::forRun($run->fresh())[0];
        $this->assertSame('rejected', $row['status']);
        $this->assertSame($signal->rejection_reason, $row['rejection_reason']);
        $event = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', 'SignalRejected')->sole();
        $this->assertSame($signal->id, $event->payload['signal_id']);
        $this->assertSame($run->fresh()->closed_reason, $event->payload['terminal_reason']);
        $this->assertSame($accepted->commandId(), $event->workflow_command_id);
    }

    public function testServiceSignalDrainRollsBackWithTheTerminalTurn(): void
    {
        [$stub, $run, $task] = $this->externalRun('tenant-a');
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $this->assertTrue($bridge->claimStatus($task->id, 'original-worker')['claimed']);
        $this->assertTrue($stub->signal('message')->accepted());
        try {
            DB::transaction(function () use ($bridge, $task): void {
                $this->assertTrue($bridge->complete($task->id, [['type' => 'complete_workflow']])['completed']);
                throw new RuntimeException('rollback terminal turn');
            });
            $this->fail('Rollback was not raised.');
        } catch (RuntimeException $error) {
            $this->assertSame('rollback terminal turn', $error->getMessage());
        }
        $this->assertSame(TaskStatus::Leased, $task->fresh()->status);
        $this->assertFalse($run->fresh()->status->isTerminal());
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(SignalStatus::Received, WorkflowSignal::query()->where('workflow_run_id', $run->id)->sole()->status);
    }

    /** @return array{WorkflowStub, WorkflowRun, WorkflowTask} */
    private function externalRun(string $namespace): array
    {
        $class = 'Remote\\TerminalSignalWorkflow';
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => $class, 'workflow_type' => $class, 'namespace' => $namespace, 'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id, 'workflow_class' => $class, 'workflow_type' => $class,
            'namespace' => $namespace, 'run_number' => 1, 'status' => RunStatus::Running,
            'payload_codec' => 'avro', 'arguments' => Serializer::serializeWithCodec('avro', []),
            'connection' => 'database', 'queue' => 'signals', 'started_at' => now(),
            'execution_deadline_at' => now()->addMinutes(2), 'run_deadline_at' => now()->addMinute(),
            'run_timeout_seconds' => 60,
        ]);
        $instance->forceFill(['current_run_id' => $run->id])->save();
        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_class' => $class, 'workflow_type' => $class,
            'workflow_instance_id' => $instance->id, 'workflow_run_id' => $run->id,
            'declared_queries' => [], 'declared_query_contracts' => [],
            'declared_signals' => ['message'], 'declared_signal_contracts' => [['name' => 'message', 'parameters' => []]],
            'declared_updates' => [], 'declared_update_contracts' => [],
            'declared_entry_method' => 'handle', 'declared_entry_mode' => 'canonical',
            'declared_entry_declaring_class' => $class,
        ]);
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id, 'namespace' => $namespace, 'task_type' => 'workflow',
            'status' => TaskStatus::Ready, 'connection' => 'database', 'queue' => 'signals',
            'available_at' => now(), 'payload' => [],
        ]);
        return [WorkflowStub::load($instance->id), $run, $task];
    }
}

#[Signal('message')]
final class TerminalBufferedSignalWorkflow extends Workflow
{
    public function handle(string $mode): mixed
    {
        return $mode === 'continue' ? continueAsNew('complete') : 'done';
    }
}
