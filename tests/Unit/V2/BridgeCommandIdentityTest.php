<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\CommandContext;
use Workflow\V2\Exceptions\BridgeCommandIdentityConflict;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;
use function Workflow\V2\signal;
use Workflow\V2\Support\BridgeCommandIdentity;
use Workflow\V2\Support\DefaultWorkflowControlPlane;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class BridgeCommandIdentityTest extends TestCase
{
    public function testSignalReusesItsLockedReceiptWithoutRecordingAnotherEffect(): void
    {
        $workflow = $this->waitingWorkflow();
        $identity = BridgeCommandIdentity::forCommand(
            'fixture',
            'signal_workflow',
            'signal-fixture',
            'signal_name',
            'advance'
        );
        $key = $identity->storageToken();
        $context = CommandContext::controlPlane()->with($identity->commandContext());
        $control = new DefaultWorkflowControlPlane();
        $first = $control->signal($workflow->id(), 'advance', [
            'command_context' => $context,
            'arguments' => ['first'],
        ]);
        $before = $this->rows();
        $second = $control->signal($workflow->id(), 'advance', [
            'command_context' => $context,
            'arguments' => ['ignored'],
        ]);

        $this->assertTrue($first['accepted'], json_encode($first, JSON_THROW_ON_ERROR));
        $this->assertSame($first['command_id'], $second['command_id']);
        $this->assertSame($before, $this->rows());
        $this->assertSame(1, WorkflowSignal::query()->count());
        $command = WorkflowCommand::query()->findOrFail($first['command_id']);
        $this->assertSame($key, $command->request_id);
        $this->assertSame($key, $command->context['request']['request_id']);
        $this->assertArrayNotHasKey('__bridge_command_identity', $command->context);
    }

    public function testUpdateReusesTheExactBoundedBridgeIdentity(): void
    {
        $workflow = $this->waitingWorkflow();
        $identity = BridgeCommandIdentity::forCommand(
            'fixture',
            'update_workflow',
            'update-fixture',
            'update_name',
            'change'
        );
        $key = $identity->storageToken();
        $context = CommandContext::controlPlane()->with($identity->commandContext());
        $options = [
            'command_context' => $context,
            'arguments' => ['first'],
            'wait_for' => 'accepted',
        ];
        $control = new DefaultWorkflowControlPlane();
        $first = $control->update($workflow->id(), 'change', $options);
        $before = $this->rows();
        $second = $control->update($workflow->id(), 'change', array_replace($options, [
            'arguments' => ['ignored'],
        ]));

        $this->assertTrue($first['accepted']);
        $this->assertSame($first['update_id'], $second['update_id']);
        $this->assertSame($before, $this->rows());
        $command = WorkflowCommand::query()->findOrFail($first['command_id']);
        $this->assertSame($key, $command->request_id);
        $this->assertArrayNotHasKey('__bridge_command_identity', $command->context);
    }

    public function testJsonAndOrdinaryRequestIdsCannotCreateABridgeIdentity(): void
    {
        $workflow = $this->waitingWorkflow();
        $key = 'dw-bridge:' . hash('sha256', 'forged-fixture');
        $context = CommandContext::controlPlane()->with([
            '__bridge_command_identity' => [
                'key' => $key,
            ],
            'request' => [
                'request_id' => $key,
            ],
        ]);
        $this->assertNull(BridgeCommandIdentity::fromContext($context->attributes()['context']));
        $control = new DefaultWorkflowControlPlane();
        $first = $control->signal($workflow->id(), 'finish', [
            'command_context' => $context,
        ]);
        $second = $control->signal($workflow->id(), 'finish', [
            'command_context' => $context,
        ]);
        $this->assertNotSame($first['command_id'], $second['command_id']);
        $this->assertSame(2, WorkflowSignal::query()->count());
        $this->assertNull(WorkflowCommand::query()->findOrFail($first['command_id'])->request_id);
    }

    public function testBridgeIdentityCannotOccupyThePrivilegedServiceCallNamespace(): void
    {
        $identity = BridgeCommandIdentity::forCommand(
            'fixture',
            'signal_workflow',
            'service-call-v1:' . hash('sha256', 'fixture'),
            'signal_name',
            'finish'
        );
        $this->assertMatchesRegularExpression('/^dw-bridge:v1:[a-f0-9]{64}$/D', $identity->storageToken());
    }

    public function testLegacyAcceptedReceiptIsReusedWithoutChangingItsIdentityOrEffects(): void
    {
        $workflow = $this->waitingWorkflow();
        $identity = BridgeCommandIdentity::forCommand(
            'fixture',
            'signal_workflow',
            'legacy-event',
            'signal_name',
            'advance'
        );
        $legacy = CommandContext::controlPlane()->with($identity->persistentContext($identity->commandContext()));
        $control = new DefaultWorkflowControlPlane();
        $first = $control->signal($workflow->id(), 'advance', [
            'command_context' => $legacy,
            'arguments' => ['first'],
        ]);
        $this->assertTrue($first['accepted']);
        $this->assertNull(WorkflowCommand::query()->findOrFail($first['command_id'])->request_id);
        $before = $this->rows();
        $second = $control->signal($workflow->id(), 'advance', [
            'command_context' => CommandContext::controlPlane()->with($identity->commandContext()),
            'arguments' => ['ignored'],
        ]);
        $this->assertSame($first['command_id'], $second['command_id']);
        $this->assertSame($before, $this->rows());
    }

    public function testAnOccupiedIdentityWithDifferentMetadataFailsClosed(): void
    {
        $workflow = $this->waitingWorkflow();
        $identity = BridgeCommandIdentity::forCommand(
            'fixture',
            'signal_workflow',
            'retained-event',
            'signal_name',
            'advance'
        );
        $options = [
            'command_context' => CommandContext::controlPlane()->with($identity->commandContext()),
            'arguments' => ['first'],
        ];
        $control = new DefaultWorkflowControlPlane();
        $first = $control->signal($workflow->id(), 'advance', $options);
        $command = WorkflowCommand::query()->findOrFail($first['command_id']);
        $context = $command->context;
        $context['server']['metadata']['idempotency_key'] = 'another-event';
        $command->forceFill([
            'context' => $context,
        ])->save();
        $before = $this->rows();
        try {
            $control->signal($workflow->id(), 'advance', $options);
            $this->fail('A conflicting retained tuple must be refused.');
        } catch (BridgeCommandIdentityConflict $exception) {
            $this->assertStringNotContainsString('retained-event', $exception->getMessage());
        }
        $this->assertSame($before, $this->rows());
    }

    #[DataProvider('invalidFields')]
    public function testRawFieldsAreBoundedBeforeHashing(
        string $adapter,
        string $action,
        string $key,
        string $field,
        string $name
    ): void {
        $this->expectException(InvalidArgumentException::class);
        BridgeCommandIdentity::forCommand($adapter, $action, $key, $field, $name);
    }

    public static function invalidFields(): iterable
    {
        yield [str_repeat('a', 65), 'signal_workflow', 'event', 'signal_name', 'finish'];
        yield ['fixture', 'signal_workflow', str_repeat('a', 1021), 'signal_name', 'finish'];
        yield ['fixture', 'signal_workflow', 'event', 'signal_name', str_repeat('a', 1021)];
        yield ['fixture', 'start_workflow', 'event', 'signal_name', 'finish'];
        yield ['fixture', 'signal_workflow', 'event', 'update_name', 'finish'];
    }

    public function testIdentityMatchesTheNativeUtf8ByteLengthVector(): void
    {
        $identity = BridgeCommandIdentity::forCommand('shopify', 'signal_workflow', ' 0é ', 'signal_name', 'advance');
        $this->assertSame(
            'dw-bridge:v1:f93240edbc062e56643b7a516064d14b72c3bc11a20a3a25d238bd538cbdc2a2',
            $identity->storageToken()
        );
    }

    private function waitingWorkflow(): WorkflowStub
    {
        config()->set('queue.default', 'redis');
        Queue::fake();
        $workflow = WorkflowStub::make(BridgeCommandIdentityWorkflow::class, 'bridge-command-identity');
        $workflow->start();
        $task = WorkflowTask::query()->where('workflow_run_id', $workflow->runId())->sole();
        $result = $this->app->make(DefaultWorkflowTaskBridge::class)->execute($task->id);
        $this->assertSame('waiting', $result['run_status']);

        return $workflow;
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

#[Signal('finish')]
#[Signal('advance', ['value'])]
final class BridgeCommandIdentityWorkflow extends Workflow
{
    public function handle(): void
    {
        signal('finish');
    }

    #[UpdateMethod]
    public function change(string $value): string
    {
        return $value;
    }
}
