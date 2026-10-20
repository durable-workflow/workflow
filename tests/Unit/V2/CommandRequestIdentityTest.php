<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestConfiguredContinueSignalWorkflow;
use Tests\TestCase;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\CommandContext;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;
use function Workflow\V2\signal;
use Workflow\V2\Support\CommandIntent;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class CommandRequestIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()
            ->set('queue.default', 'redis');
        Queue::fake();
    }

    public function testKeyedSignalRetryReturnsTheOriginalAdmission(): void
    {
        $workflow = $this->waitingWorkflow();
        $first = $workflow->withRequestId('caller-key')
            ->signal('changed', 1);
        $before = $this->rows();

        $this->assertTrue($first->accepted());
        $this->assertFalse($first->retained());
        $this->assertTrue($first->intentVerified());
        $this->assertSame('caller-key', $first->requestId());

        $retry = $workflow->withRequestId('caller-key')
            ->signal('changed', 1);

        $this->assertTrue($retry->accepted());
        $this->assertTrue($retry->retained());
        $this->assertTrue($retry->intentVerified());
        $this->assertSame($first->commandId(), $retry->commandId());
        $this->assertSame($first->commandSequence(), $retry->commandSequence());
        $this->assertSame('caller-key', $retry->requestId());
        $this->assertSame($before, $this->rows());
        $this->assertSame(1, $this->signalReceivedCount($workflow));

        $command = WorkflowCommand::query()->findOrFail($first->commandId());
        $this->assertStringStartsWith('signal-v1:', (string) $command->request_id);
        $this->assertLessThanOrEqual(191, strlen((string) $command->request_id));
        $this->assertTrue(CommandIntent::isDigest($command->intentDigest()));
        $this->assertSame('caller-key', $command->context['request']['request_id']);
    }

    public function testUnkeyedSignalsStayIndependent(): void
    {
        $workflow = $this->waitingWorkflow();
        $first = $workflow->signal('changed', 1);
        $second = $workflow->signal('changed', 1);

        $this->assertNull($first->requestId());
        $this->assertFalse($second->retained());
        $this->assertNotSame($first->commandId(), $second->commandId());
        $this->assertSame(2, $this->signalReceivedCount($workflow));
    }

    public function testKeyReuseWithDifferentIntentIsRefusedWithoutConsumingTheKey(): void
    {
        $workflow = $this->waitingWorkflow();
        $first = $workflow->withRequestId('caller-key')
            ->signal('changed', 1);

        $conflict = $workflow->withRequestId('caller-key')
            ->attemptSignal('changed', 2);

        $this->assertTrue($conflict->rejected());
        $this->assertTrue($conflict->requestIdConflict());
        $this->assertSame('request_id_conflict', $conflict->rejectionReason());
        $this->assertSame(CommandOutcome::RejectedRequestIdConflict->value, $conflict->outcome());
        $this->assertSame('caller-key', $conflict->requestId());
        $this->assertSame([
            'retained_command_id' => $first->commandId(),
            'retained_command_type' => 'signal',
            'retained_outcome' => 'signal_received',
            'conflict' => ['arguments'],
        ], $conflict->conflict());
        $this->assertSame(1, $this->signalReceivedCount($workflow));
        $rejected = WorkflowSignal::query()->where('workflow_command_id', $conflict->commandId())->sole();
        $this->assertSame(SignalStatus::Rejected, $rejected->status);
        $this->assertSame(2, WorkflowCommand::query()->where('command_type', 'signal')->count());

        $renamed = $workflow->withRequestId('caller-key')
            ->attemptSignal('other', 1);
        $this->assertTrue($renamed->requestIdConflict());
        $this->assertSame(['name'], $renamed->conflict()['conflict']);

        $retry = $workflow->withRequestId('caller-key')
            ->signal('changed', 1);
        $this->assertTrue($retry->retained());
        $this->assertSame($first->commandId(), $retry->commandId());
        $this->assertSame(1, $this->signalReceivedCount($workflow));
    }

    public function testSignalAndUpdateKeysAreIndependentScopes(): void
    {
        $workflow = $this->waitingWorkflow();
        $signal = $workflow->withRequestId('shared-key')
            ->signal('changed', 1);
        $update = $workflow->withRequestId('shared-key')
            ->submitUpdate('rename', 'x');

        $this->assertTrue($signal->accepted());
        $this->assertTrue($update->accepted());
        $this->assertFalse($update->retained());
        $this->assertNotSame($signal->commandId(), $update->commandId());
    }

    public function testRetryAfterCompletionReturnsTheRetainedReceipt(): void
    {
        $workflow = $this->waitingWorkflow();
        $first = $workflow->withRequestId('caller-key')
            ->signal('changed', 1);
        $workflow->signal('changed', 2);
        $this->runTask($workflow);
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $this->assertSame('completed', $run->status->value);
        $before = $this->rows();

        $retry = $workflow->withRequestId('caller-key')
            ->signal('changed', 1);
        $this->assertTrue($retry->accepted());
        $this->assertTrue($retry->retained());
        $this->assertSame($first->commandId(), $retry->commandId());
        $this->assertSame($before, $this->rows());

        $late = $workflow->withRequestId('late-key')
            ->attemptSignal('changed', 3);
        $this->assertTrue($late->rejected());
        $this->assertSame('run_not_active', $late->rejectionReason());
        $lateRows = $this->rows();

        $lateRetry = $workflow->withRequestId('late-key')
            ->attemptSignal('changed', 3);
        $this->assertTrue($lateRetry->retained());
        $this->assertTrue($lateRetry->rejected());
        $this->assertSame($late->commandId(), $lateRetry->commandId());
        $this->assertSame($lateRows, $this->rows());
    }

    public function testRetryAfterContinueAsNewReturnsTheOriginalReceipt(): void
    {
        $stub = WorkflowStub::make(TestConfiguredContinueSignalWorkflow::class);
        $stub->start(0);
        $first = $stub->withRequestId('caller-key')
            ->signal('name-provided', 'Taylor 雪');
        $this->assertTrue($first->accepted());
        $predecessor = $stub->runId();
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $task = WorkflowTask::query()->where('workflow_run_id', $predecessor)->sole();
        $result = $bridge->execute($task->id);
        $this->assertTrue($result['executed']);
        $successor = WorkflowTask::query()->findOrFail($result['next_task_id'])->workflow_run_id;
        $this->assertNotSame($predecessor, $successor);
        $before = $this->rows();

        $retry = $stub->withRequestId('caller-key')
            ->signal('name-provided', 'Taylor 雪');
        $this->assertTrue($retry->retained());
        $this->assertTrue($retry->intentVerified());
        $this->assertSame($first->commandId(), $retry->commandId());
        $this->assertSame($successor, $retry->runId());
        $this->assertSame($before, $this->rows());

        $conflict = $stub->withRequestId('caller-key')
            ->attemptSignal('name-provided', 'Someone else');
        $this->assertTrue($conflict->requestIdConflict());
        $this->assertSame($first->commandId(), $conflict->conflict()['retained_command_id']);

        $this->assertTrue($bridge->execute($result['next_task_id'])['executed']);
        $this->assertSame('Taylor 雪', $stub->refresh()->output()['name']);
    }

    public function testLegacyAcceptedReceiptWithoutDigestIsComparedFromItsArguments(): void
    {
        $workflow = $this->waitingWorkflow();
        $first = $workflow->withRequestId('legacy')
            ->signal('changed', 7);
        WorkflowCommand::query()->whereKey($first->commandId())->update([
            'intent_digest' => null,
        ]);
        $before = $this->rows();

        $same = $workflow->withRequestId('legacy')
            ->signal('changed', 7);
        $this->assertTrue($same->retained());
        $this->assertTrue($same->intentVerified());
        $this->assertSame($first->commandId(), $same->commandId());
        $this->assertSame($before, $this->rows());

        $different = $workflow->withRequestId('legacy')
            ->attemptSignal('changed', 8);
        $this->assertTrue($different->requestIdConflict());
    }

    public function testLegacyRejectedReceiptWithoutDigestIsReturnedUnverified(): void
    {
        $workflow = $this->waitingWorkflow();
        $rejection = $workflow->withRequestId('legacy')
            ->attemptSignal('undeclared', 1);
        $this->assertSame('unknown_signal', $rejection->rejectionReason());
        WorkflowCommand::query()->whereKey($rejection->commandId())->update([
            'intent_digest' => null,
        ]);
        $before = $this->rows();

        $retry = $workflow->withRequestId('legacy')
            ->attemptSignal('changed', 1);
        $this->assertTrue($retry->retained());
        $this->assertFalse($retry->intentVerified());
        $this->assertTrue($retry->rejected());
        $this->assertSame($rejection->commandId(), $retry->commandId());
        $this->assertSame($before, $this->rows());
    }

    public function testSignalWithStartRetryReturnsTheOriginalIntake(): void
    {
        $stub = WorkflowStub::make(CommandRequestIdentityWorkflow::class, 'signal-with-start-key');
        $first = $stub->withRequestId('intake-key')
            ->signalWithStart('changed', [1]);
        $this->assertTrue($first->accepted());
        $this->assertTrue($first->startedNew());
        $before = $this->rows();

        $retry = $stub->withRequestId('intake-key')
            ->signalWithStart('changed', [1]);
        $this->assertTrue($retry->accepted());
        $this->assertTrue($retry->retained());
        $this->assertSame($first->commandId(), $retry->commandId());
        $this->assertSame($first->startCommandId(), $retry->startCommandId());
        $this->assertSame($first->intakeGroupId(), $retry->intakeGroupId());
        $this->assertTrue($retry->startedNew());
        $this->assertSame($before, $this->rows());
        $this->assertSame(1, WorkflowRun::query()->where('workflow_instance_id', $stub->workflowId())->count());

        $conflict = $stub->withRequestId('intake-key')
            ->attemptSignalWithStart('changed', [2]);
        $this->assertTrue($conflict->requestIdConflict());
        $this->assertNull($conflict->startCommandId());
        $this->assertSame(1, $this->signalReceivedCount($stub));
    }

    public function testControlPlaneRequestIdOptionKeysSignalsAndUpdates(): void
    {
        $workflow = $this->waitingWorkflow();
        $controlPlane = app(WorkflowControlPlane::class);

        $first = $controlPlane->signal($workflow->workflowId(), 'changed', [
            'arguments' => [1],
            'request_id' => 'plane-key',
        ]);
        $retry = $controlPlane->signal($workflow->workflowId(), 'changed', [
            'arguments' => [1],
            'request_id' => 'plane-key',
        ]);
        $conflict = $controlPlane->signal($workflow->workflowId(), 'changed', [
            'arguments' => [2],
            'request_id' => 'plane-key',
        ]);

        $this->assertSame(202, $first['status']);
        $this->assertSame('plane-key', $first['request_id']);
        $this->assertFalse($first['retained']);
        $this->assertTrue($first['intent_verified']);
        $this->assertSame(202, $retry['status']);
        $this->assertTrue($retry['retained']);
        $this->assertSame($first['command_id'], $retry['command_id']);
        $this->assertSame(409, $conflict['status']);
        $this->assertSame('request_id_conflict', $conflict['reason']);
        $this->assertSame($first['command_id'], $conflict['retained_command_id']);
        $this->assertSame(['arguments'], $conflict['conflict']);

        $update = $controlPlane->update($workflow->workflowId(), 'rename', [
            'arguments' => ['x'],
            'request_id' => 'plane-update',
        ]);
        $updateRetry = $controlPlane->update($workflow->workflowId(), 'rename', [
            'arguments' => ['x'],
            'request_id' => 'plane-update',
        ]);
        $updateConflict = $controlPlane->update($workflow->workflowId(), 'rename', [
            'arguments' => ['y'],
            'request_id' => 'plane-update',
        ]);

        $this->assertSame(202, $update['status']);
        $this->assertSame('plane-update', $update['request_id']);
        $this->assertTrue($updateRetry['retained']);
        $this->assertSame($update['update_id'], $updateRetry['update_id']);
        $this->assertSame(409, $updateConflict['status']);
        $this->assertSame('request_id_conflict', $updateConflict['reason']);
        $this->assertSame($update['command_id'], $updateConflict['retained_command_id']);
        $this->assertSame(1, WorkflowUpdate::query()->where('status', 'accepted')->count());
    }

    public function testServerContextsUseTheBodyKeyAndIgnoreTheCorrelationHeader(): void
    {
        $workflow = $this->waitingWorkflow();
        $first = $workflow->withCommandContext($this->serverContext('body-key', 'proxy-a'))
            ->signal('changed', 1);
        $retry = $workflow->withCommandContext($this->serverContext('body-key', 'proxy-b'))
            ->signal('changed', 1);
        $headerOnly = $workflow->withCommandContext($this->serverContext(null, 'proxy-a'))
            ->signal('changed', 1);

        $this->assertTrue($retry->retained());
        $this->assertSame($first->commandId(), $retry->commandId());
        $this->assertSame('body-key', $retry->requestId());
        $this->assertFalse($headerOnly->retained());
        $this->assertNull($headerOnly->requestId());
        $this->assertSame(2, $this->signalReceivedCount($workflow));
    }

    #[DataProvider('runTargetCommands')]
    public function testControlPlaneRefusesASelectedPredecessorInsteadOfDeliveringToItsSuccessor(
        string $operation,
        string $name,
        array $arguments,
    ): void {
        $stub = WorkflowStub::make(TestConfiguredContinueSignalWorkflow::class);
        $stub->start(0);
        $predecessor = $stub->runId();
        $this->runTask($stub);
        $stub->refresh();
        $successor = $stub->runId();
        $this->assertNotSame($predecessor, $successor);
        $before = WorkflowHistoryEvent::query()->orderBy('id')->get()->toArray();

        $result = app(WorkflowControlPlane::class)->{$operation}($stub->id(), $name, [
            'run_id' => $predecessor,
            'request_id' => 'selected-original',
            'arguments' => $arguments,
        ]);

        $this->assertSame(409, $result['status']);
        $this->assertSame('selected_run_not_current', $result['reason']);
        $this->assertSame('run', $result['target_scope']);
        $this->assertSame($predecessor, $result['run_id']);
        $this->assertSame($before, WorkflowHistoryEvent::query()->orderBy('id')->get()->toArray());
        $rows = $this->rows();
        $retry = app(WorkflowControlPlane::class)->{$operation}($stub->id(), $name, [
            'run_id' => $predecessor,
            'request_id' => 'selected-original',
            'arguments' => $arguments,
        ]);
        $this->assertTrue($retry['retained']);
        $this->assertSame($result['command_id'], $retry['command_id']);
        $this->assertSame($rows, $this->rows());
    }

    #[DataProvider('runTargetCommands')]
    public function testControlPlaneIncludesTheSelectedRunInIntent(
        string $operation,
        string $name,
        array $arguments,
    ): void {
        $stub = WorkflowStub::make(TestConfiguredContinueSignalWorkflow::class);
        $stub->start(0);
        $predecessor = $stub->runId();
        $this->runTask($stub);
        $stub->refresh();
        $controlPlane = app(WorkflowControlPlane::class);
        $options = [
            'run_id' => $stub->runId(),
            'request_id' => 'selected-current',
            'arguments' => $arguments,
        ];
        $first = $controlPlane->{$operation}($stub->id(), $name, $options);
        $this->assertSame(202, $first['status']);
        $this->assertSame('run', $first['target_scope']);
        $this->assertSame($stub->runId(), $first['run_id']);
        $rows = $this->rows();
        $retry = $controlPlane->{$operation}($stub->id(), $name, $options);
        $this->assertTrue($retry['retained']);
        $this->assertSame($first['command_id'], $retry['command_id']);
        $this->assertSame($rows, $this->rows());

        $options['run_id'] = $predecessor;
        $conflict = $controlPlane->{$operation}($stub->id(), $name, $options);
        $this->assertSame(409, $conflict['status']);
        $this->assertSame('request_id_conflict', $conflict['reason']);
        $this->assertSame(['target'], $conflict['conflict']);
        $this->assertSame($first['command_id'], $conflict['retained_command_id']);
    }

    #[DataProvider('runTargetCommands')]
    public function testControlPlaneMissingAndForeignRunTargetsDoNotMutateEitherWorkflow(
        string $operation,
        string $name,
        array $arguments,
    ): void {
        $stub = WorkflowStub::make(TestConfiguredContinueSignalWorkflow::class, null, 'tenant-a');
        $stub->start(1);
        $foreign = WorkflowStub::make(TestConfiguredContinueSignalWorkflow::class, null, 'tenant-b');
        $foreign->start(1);
        $rows = $this->rows();

        foreach (['missing-run', $foreign->runId()] as $runId) {
            $result = app(WorkflowControlPlane::class)->{$operation}($stub->id(), $name, [
                'namespace' => 'tenant-a',
                'run_id' => $runId,
                'request_id' => 'foreign-key',
                'arguments' => $arguments,
            ]);
            $this->assertSame(404, $result['status']);
            $this->assertSame('instance_not_found', $result['reason']);
            $this->assertSame($rows, $this->rows());
        }
    }

    /** @return iterable<string, array{string, string, array}> */
    public static function runTargetCommands(): iterable
    {
        yield 'signal' => ['signal', 'name-provided', ['Taylor 雪']];
        yield 'update' => ['update', 'mark-approved', [true]];
    }

    public function testWebhookContextsKeepTheRequestHeaderAsTheEmbeddedKey(): void
    {
        $workflow = $this->waitingWorkflow();
        $request = Request::create('/hooks/changed', 'POST', [
            'revision' => 1,
        ], [], [], [
            'HTTP_X_REQUEST_ID' => 'delivery-1',
        ]);
        $context = CommandContext::webhook($request, 'none');

        $first = $workflow->withCommandContext($context)
            ->signal('changed', 1);
        $retry = $workflow->withCommandContext($context)
            ->signal('changed', 1);

        $this->assertSame('delivery-1', $first->requestId());
        $this->assertTrue($retry->retained());
        $this->assertSame($first->commandId(), $retry->commandId());
        $this->assertSame(1, $this->signalReceivedCount($workflow));
    }

    private function waitingWorkflow(): WorkflowStub
    {
        $workflow = WorkflowStub::make(CommandRequestIdentityWorkflow::class);
        $workflow->start();
        $this->runTask($workflow);

        return $workflow;
    }

    private function runTask(WorkflowStub $workflow): void
    {
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('status', 'ready')
            ->orderBy('available_at')
            ->first();
        $this->assertInstanceOf(WorkflowTask::class, $task);
        $this->assertTrue(app(DefaultWorkflowTaskBridge::class)->execute($task->id)['executed']);
    }

    private function signalReceivedCount(WorkflowStub $workflow): int
    {
        $runIds = WorkflowRun::query()->where('workflow_instance_id', $workflow->workflowId())->pluck('id');

        return WorkflowHistoryEvent::query()
            ->whereIn('workflow_run_id', $runIds)
            ->where('event_type', HistoryEventType::SignalReceived->value)
            ->count();
    }

    private function serverContext(?string $key, string $header): CommandContext
    {
        return CommandContext::controlPlane()->with([
            'request' => [
                'request_id' => $header,
                'headers' => [
                    'x_request_id' => $header,
                ],
            ],
            'server' => [
                'command' => 'signal',
                'metadata' => array_filter([
                    'request_id' => $key,
                ], static fn (mixed $value): bool => $value !== null),
            ],
        ]);
    }

    private function rows(): array
    {
        $rows = [];
        foreach ([
            WorkflowCommand::class,
            WorkflowSignal::class,
            WorkflowUpdate::class,
            WorkflowTask::class,
            WorkflowHistoryEvent::class,
        ] as $model) {
            $rows[$model] = $model::query()->orderBy('id')->get()->map(
                static fn ($row): array => $row->getRawOriginal()
            )->all();
        }

        return $rows;
    }
}

#[Signal('changed', [
    [
        'name' => 'revision',
        'type' => 'int',
    ],
])]
#[Signal('other', [
    [
        'name' => 'revision',
        'type' => 'int',
    ],
])]
final class CommandRequestIdentityWorkflow extends Workflow
{
    private string $name = '';

    public function handle(): array
    {
        $first = signal('changed');
        $second = signal('changed');

        return [
            'revisions' => [$first, $second],
            'name' => $this->name,
        ];
    }

    #[\Workflow\UpdateMethod]
    public function rename(string $value): string
    {
        $this->name = $value;

        return $value;
    }
}
