<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\V2\TestConfiguredContinueSignalWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Attributes\Signal;
use function Workflow\V2\continueAsNew;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\RunCommandContract;
use Workflow\V2\Support\RunSignalView;
use Workflow\V2\Support\UnprojectedSignalDelivery;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class TerminalSignalBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()
            ->set('queue.default', 'database');
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
        $command = [
            'type' => $type,
        ];
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
        yield 'complete instance' => ['complete', false];
        yield 'continue run-targeted' => ['continue', true];
    }

    #[DataProvider('embeddedModes')]
    public function testEmbeddedUnusedSignalGetsInspectableTerminalDisposition(string $mode, bool $runTargeted): void
    {
        $stub = WorkflowStub::make(TerminalBufferedSignalWorkflow::class);
        $stub->start($mode);
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $accepted = ($runTargeted ? WorkflowStub::loadRun($run->id) : $stub)->signal('message');
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
            ->where('event_type', 'SignalRejected')
            ->sole();
        $this->assertSame($signal->id, $event->payload['signal_id']);
        $this->assertSame($run->fresh()->closed_reason, $event->payload['terminal_reason']);
        $this->assertSame($accepted->commandId(), $event->workflow_command_id);
        $command = WorkflowCommand::query()->findOrFail($accepted->commandId());
        $this->assertSame('accepted', $command->status->value);
        $this->assertSame('signal_received', $command->outcome->value);
        // The history fallback must retain the disposition if this derived row is lost.
        $signal->delete();
        $fallback = RunSignalView::forRun($run->fresh())[0];
        $this->assertSame('rejected', $fallback['status']);
        $this->assertSame('run_closed_before_signal_applied', $fallback['rejection_reason']);
        $this->assertNotNull($fallback['closed_at']);
    }

    public function testEmbeddedContinueCarriesTheOriginalInstanceSignalToColdSuccessor(): void
    {
        $stub = WorkflowStub::make(TestConfiguredContinueSignalWorkflow::class);
        $stub->start(0);
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $accepted = $stub->signal('name-provided', 'Taylor 雪');
        $signal = WorkflowSignal::query()->where('workflow_run_id', $run->id)->sole();
        $originalAttributes = $signal->getAttributes();
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->sole();
        $result = $bridge->execute($task->id);
        $this->assertTrue($result['executed']);
        $next = WorkflowTask::query()->findOrFail($result['next_task_id']);
        $this->assertNotSame($run->id, $next->workflow_run_id);
        $this->assertSame($next->workflow_run_id, $signal->fresh()->workflow_run_id);
        $this->assertSame($next->workflow_run_id, $signal->fresh()->resolved_workflow_run_id);
        $this->assertSame(SignalStatus::Received, $signal->fresh()->status);
        foreach ([
            'id',
            'workflow_command_id',
            'command_sequence',
            'arguments',
            'payload_codec',
            'received_at',
        ] as $field) {
            $this->assertSame($originalAttributes[$field], $signal->fresh()->getRawOriginal($field));
        }
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', 'SignalRejected')
            ->count());
        $this->assertTrue($bridge->execute($next->id)['executed']);
        $this->assertSame(SignalStatus::Applied, $signal->fresh()->status);
        $this->assertSame('Taylor 雪', $stub->refresh()->output()['name']);
        $this->assertSame($accepted->commandId(), $signal->workflow_command_id);
        $this->assertSame(2, WorkflowRun::query()->where('workflow_instance_id', $run->workflow_instance_id)->count());
    }

    public static function messageOrders(): iterable
    {
        yield 'signal then update' => [true];
        yield 'update then signal' => [false];
    }

    public static function legacyReceipts(): iterable
    {
        yield 'accepted command and event' => ['valid', true];
        yield 'missing event' => ['missing_event', false];
        yield 'rejected command' => ['rejected_command', false];
        yield 'explicit empty declaration' => ['empty_contract', false];
    }

    #[DataProvider('legacyReceipts')]
    public function testUnavailableDeclarationsRequireTheOriginalSignalReceipt(string $case, bool $deliverable): void
    {
        [$stub, $run, $task] = $this->externalRun('tenant-a');
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $this->assertTrue($bridge->claimStatus($task->id, 'first')['claimed']);
        $accepted = $stub->signal('message');
        $this->assertTrue($accepted->accepted());
        $signal = WorkflowSignal::query()->where('workflow_run_id', $run->id)->sole();
        $started = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', 'WorkflowStarted')
            ->sole();
        $payload = $started->payload;
        if ($case === 'empty_contract') {
            $payload['declared_signals'] = [];
            $payload['declared_signal_contracts'] = [];
        } else {
            foreach (array_keys($payload) as $field) {
                if (str_starts_with($field, 'declared_')) {
                    unset($payload[$field]);
                }
            }
        }
        $started->forceFill([
            'payload' => $payload,
        ])->save();
        if ($case === 'missing_event') {
            WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
                ->where('event_type', 'SignalReceived')
                ->delete();
        } elseif ($case === 'rejected_command') {
            WorkflowCommand::query()->findOrFail($accepted->commandId())->forceFill([
                'status' => 'rejected',
            ])->save();
        }
        $this->assertFalse(UnprojectedSignalDelivery::allows($run->fresh(), $signal));
        $this->assertSame(
            $deliverable,
            UnprojectedSignalDelivery::allows($run->fresh(), $signal, terminalSignalDelivery: true)
        );
        $this->assertSame(
            $case === 'empty_contract' ? 'durable_history' : 'unavailable',
            RunCommandContract::forRun($run->fresh())['source']
        );
        $completion = $bridge->complete($task->id, [[
            'type' => 'complete_workflow',
        ]]);
        $this->assertTrue($completion['completed']);
        $this->assertSame(! $deliverable, $run->fresh()->status->isTerminal());
        if ($deliverable) {
            $resume = WorkflowTask::query()->findOrFail($completion['created_task_ids'][0]);
            $this->assertTrue($bridge->claimStatus($resume->id, 'cold')['claimed']);
            $this->assertTrue($bridge->complete($resume->id, [[
                'type' => 'complete_workflow',
            ]])['completed']);
            $this->assertSame(SignalStatus::Applied, $signal->fresh()->status);
            $this->assertSame('unavailable', RunCommandContract::forRun($run->fresh())['source']);
        }
    }

    #[DataProvider('messageOrders')]
    public function testContinuePreservesSignalAndAcceptedUpdateOrder(bool $signalFirst): void
    {
        $stub = WorkflowStub::make(TestConfiguredContinueSignalWorkflow::class);
        $stub->start(0);
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        if ($signalFirst) {
            $signal = $stub->signal('name-provided', 'Taylor');
            $update = $stub->submitUpdate('mark-approved', true);
        } else {
            $update = $stub->submitUpdate('mark-approved', true);
            $signal = $stub->signal('name-provided', 'Taylor');
        }
        $this->assertTrue($signal->accepted());
        $this->assertTrue($update->accepted());
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->where('status', 'ready')->sole();
        $result = app(DefaultWorkflowTaskBridge::class)->execute($task->id);
        $this->assertTrue($result['executed']);
        $next = WorkflowTask::query()->findOrFail($result['next_task_id']);
        $admissions = WorkflowHistoryEvent::query()->where('workflow_run_id', $next->workflow_run_id)
            ->whereIn('event_type', ['SignalReceived', 'UpdateAccepted'])->orderBy('sequence')->get();
        $this->assertSame(
            $signalFirst ? [$signal->commandId(), $update->commandId()] : [$update->commandId(), $signal->commandId()],
            $admissions->pluck('workflow_command_id')
                ->all()
        );
        $this->assertSame(
            $next->workflow_run_id,
            WorkflowUpdate::query()->findOrFail($update->updateId())->workflow_run_id
        );
        $this->assertSame(
            $next->workflow_run_id,
            WorkflowSignal::query()->where('workflow_command_id', $signal->commandId())->sole()->workflow_run_id
        );
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->whereIn('event_type', ['SignalRejected', 'UpdateCompleted'])->count());
    }

    public function testSuccessDrainsSignalsInOriginalOrderAcrossColdClaims(): void
    {
        [$stub, $run, $task] = $this->externalRun('tenant-a');
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $this->assertTrue($bridge->claimStatus($task->id, 'first')['claimed']);
        $first = $stub->signal('message');
        $this->assertTrue($first->accepted());
        $completion = $bridge->complete($task->id, [[
            'type' => 'complete_workflow',
        ]]);
        $resume = WorkflowTask::query()->findOrFail($completion['created_task_ids'][0]);
        $this->assertTrue($bridge->claimStatus($resume->id, 'second')['claimed']);
        $second = $stub->signal('message');
        $this->assertTrue($second->accepted());
        $completion = $bridge->complete($resume->id, [[
            'type' => 'complete_workflow',
        ]]);
        $this->assertFalse($run->fresh()->status->isTerminal());
        $last = WorkflowTask::query()->findOrFail($completion['created_task_ids'][0]);
        $this->assertTrue($bridge->claimStatus($last->id, 'third')['claimed']);
        $this->assertTrue($bridge->complete($last->id, [[
            'type' => 'complete_workflow',
        ]])['completed']);
        $this->assertSame(RunStatus::Completed, $run->fresh()->status);
        $this->assertSame([$first->commandId(), $second->commandId()], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', 'SignalApplied')
            ->orderBy('sequence')
            ->pluck('workflow_command_id')
            ->all());
        $this->assertSame(0, WorkflowSignal::query()->where('workflow_run_id', $run->id)
            ->where('status', 'received')
            ->count());
    }

    public static function forcedClosures(): iterable
    {
        yield 'failure' => ['failure'];
        yield 'timeout' => ['timeout'];
        yield 'terminate' => ['terminate'];
        yield 'cancel' => ['cancel'];
    }

    #[DataProvider('forcedClosures')]
    public function testForcedClosureDisposesSignalsWithoutTouchingAnotherRun(string $mode): void
    {
        [$stub, $run, $task] = $this->externalRun('tenant-a');
        [$otherStub, $otherRun] = $this->externalRun('tenant-b');
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $this->assertTrue($bridge->claimStatus($task->id, 'worker')['claimed']);
        $this->assertTrue($stub->signal('message')->accepted());
        $this->assertTrue($otherStub->signal('message')->accepted());
        if ($mode === 'failure') {
            $this->assertTrue($bridge->complete($task->id, [[
                'type' => 'fail_workflow',
                'message' => 'failed',
            ]])['completed']);
        } elseif ($mode === 'timeout') {
            $run->forceFill([
                'execution_deadline_at' => now()
                    ->subSecond(),
            ])->save();
            $this->assertSame('run_timed_out', $bridge->complete($task->id, [[
                'type' => 'complete_workflow',
            ]])['reason']);
        } elseif ($mode === 'terminate') {
            $this->assertTrue($stub->terminate('stop')->accepted());
        } else {
            $this->assertTrue($stub->cancel('stop')->accepted());
        }
        $this->assertTrue($run->fresh()->status->isTerminal());
        $signal = WorkflowSignal::query()->where('workflow_run_id', $run->id)->sole();
        $this->assertSame(SignalStatus::Rejected, $signal->status);
        $this->assertNotNull($signal->closed_at);
        $this->assertSame(
            SignalStatus::Received,
            WorkflowSignal::query()->where('workflow_run_id', $otherRun->id)->sole()->status
        );
        $this->assertFalse($otherRun->fresh()->status->isTerminal());
    }

    public function testFailedSignalDispositionRollsBackTheTerminalEventAndRun(): void
    {
        [$stub, $run, $task] = $this->externalRun('tenant-a');
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $this->assertTrue($bridge->claimStatus($task->id, 'worker')['claimed']);
        $this->assertTrue($stub->signal('message')->accepted());
        WorkflowHistoryEvent::creating(static function (WorkflowHistoryEvent $event): void {
            if ($event->event_type->value === 'SignalRejected') {
                throw new RuntimeException('cannot publish signal disposition');
            }
        });
        try {
            $bridge->complete($task->id, [[
                'type' => 'fail_workflow',
                'message' => 'failed',
            ]]);
            $this->fail('The failed disposition must refuse the whole terminal transaction.');
        } catch (RuntimeException $error) {
            $this->assertSame('cannot publish signal disposition', $error->getMessage());
        }
        $this->assertSame(RunStatus::Running, $run->fresh()->status);
        $this->assertSame(TaskStatus::Leased, $task->fresh()->status);
        $this->assertSame(
            SignalStatus::Received,
            WorkflowSignal::query()->where('workflow_run_id', $run->id)->sole()->status
        );
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->whereIn('event_type', ['WorkflowFailed', 'SignalRejected'])->count());
    }

    public function testServiceSignalDrainRollsBackWithTheTerminalTurn(): void
    {
        [$stub, $run, $task] = $this->externalRun('tenant-a');
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $this->assertTrue($bridge->claimStatus($task->id, 'original-worker')['claimed']);
        $this->assertTrue($stub->signal('message')->accepted());
        try {
            DB::transaction(function () use ($bridge, $task): void {
                $this->assertTrue($bridge->complete($task->id, [[
                    'type' => 'complete_workflow',
                ]])['completed']);
                throw new RuntimeException('rollback terminal turn');
            });
            $this->fail('Rollback was not raised.');
        } catch (RuntimeException $error) {
            $this->assertSame('rollback terminal turn', $error->getMessage());
        }
        $this->assertSame(TaskStatus::Leased, $task->fresh()->status);
        $this->assertFalse($run->fresh()->status->isTerminal());
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(
            SignalStatus::Received,
            WorkflowSignal::query()->where('workflow_run_id', $run->id)->sole()->status
        );
    }

    /**
     * @return array{WorkflowStub, WorkflowRun, WorkflowTask}
     */
    private function externalRun(string $namespace): array
    {
        $class = 'Remote\\TerminalSignalWorkflow';
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => $class,
            'workflow_type' => $class,
            'namespace' => $namespace,
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'workflow_class' => $class,
            'workflow_type' => $class,
            'namespace' => $namespace,
            'run_number' => 1,
            'status' => RunStatus::Running,
            'payload_codec' => 'avro',
            'arguments' => Serializer::serializeWithCodec('avro', []),
            'connection' => 'database',
            'queue' => 'signals',
            'started_at' => now(),
            'execution_deadline_at' => now()
                ->addMinutes(2),
            'run_deadline_at' => now()
                ->addMinute(),
            'run_timeout_seconds' => 60,
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();
        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_class' => $class,
            'workflow_type' => $class,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'declared_queries' => [],
            'declared_query_contracts' => [],
            'declared_signals' => ['message'],
            'declared_signal_contracts' => [[
                'name' => 'message',
                'parameters' => [],
            ]],
            'declared_updates' => [],
            'declared_update_contracts' => [],
            'declared_entry_method' => 'handle',
            'declared_entry_mode' => 'canonical',
            'declared_entry_declaring_class' => $class,
        ]);
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $namespace,
            'task_type' => 'workflow',
            'status' => TaskStatus::Ready,
            'connection' => 'database',
            'queue' => 'signals',
            'available_at' => now(),
            'payload' => [],
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
