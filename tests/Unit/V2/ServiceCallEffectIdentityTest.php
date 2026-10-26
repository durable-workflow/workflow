<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\CommandContext;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallOperationMode;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;
use function Workflow\V2\signal;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\DefaultWorkflowControlPlane;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\ServiceCallEffectIdentity;
use Workflow\V2\Support\ServiceCallPrincipal;
use Workflow\V2\Support\ServiceCallScheduler;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class ServiceCallEffectIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()
            ->set('queue.default', 'redis');
        Queue::fake();
    }

    public function testWorkflowStartConsumesTheStableServiceCallIdentity(): void
    {
        $caller = $this->waitingWorkflow('service-effect-start-caller');
        $identity = $this->currentClaimIdentity($caller);
        $control = new DefaultWorkflowControlPlane();
        $options = [
            ServiceCallEffectIdentity::OPTION => $identity,
        ];

        $first = $control->start(ServiceCallEffectWorkflow::class, 'service-effect-start', $options);
        $second = $control->start(ServiceCallEffectWorkflow::class, 'service-effect-start', $options);

        $this->assertTrue($first['started']);
        $this->assertTrue($second['started']);
        $this->assertSame($first['workflow_run_id'], $second['workflow_run_id']);
        $this->assertSame(1, WorkflowRun::query() ->where('workflow_instance_id', 'service-effect-start') ->count());
        $command = WorkflowCommand::query()
            ->where('workflow_instance_id', 'service-effect-start')
            ->where('command_type', CommandType::Start->value)
            ->sole();
        $this->assertSame($identity->storageToken(), $command->request_id);
    }

    public function testWorkflowUpdateConsumesTheStableServiceCallIdentity(): void
    {
        $workflow = $this->waitingWorkflow('service-effect-update');
        $identity = $this->currentClaimIdentity($workflow);
        $control = new DefaultWorkflowControlPlane();
        $options = [
            'arguments' => ['first'],
            'wait_for' => 'accepted',
            ServiceCallEffectIdentity::OPTION => $identity,
        ];

        $first = $control->update($workflow->id(), 'change', $options);
        $second = $control->update($workflow->id(), 'change', array_replace($options, [
            'arguments' => ['ignored'],
        ]));

        $this->assertTrue($first['accepted']);
        $this->assertTrue($second['accepted']);
        $this->assertSame($first['update_id'], $second['update_id']);
        $this->assertSame(1, WorkflowUpdate::query()->count());
        $command = WorkflowCommand::query()
            ->where('workflow_instance_id', $workflow->id())
            ->where('command_type', CommandType::Update->value)
            ->sole();
        $this->assertSame($identity->storageToken(), $command->requestId());
        $auditIdentity = $identity->commandContext()['idempotency_key'];
        $this->assertSame($auditIdentity, $command->context['service_call']['idempotency_key']);
        $this->assertCount(1, $command->context['service_call']);
    }

    public function testWorkflowSignalConsumesTheStableServiceCallIdentity(): void
    {
        $workflow = $this->waitingWorkflow('service-effect-signal');
        $identity = $this->currentClaimIdentity($workflow);
        $control = new DefaultWorkflowControlPlane();
        $options = [
            ServiceCallEffectIdentity::OPTION => $identity,
        ];

        $first = $control->signal($workflow->id(), 'finish', $options);
        $second = $control->signal($workflow->id(), 'finish', $options);

        $this->assertTrue($first['accepted']);
        $this->assertTrue($second['accepted']);
        $this->assertSame($first['workflow_command_id'], $second['workflow_command_id']);
        $this->assertSame(1, WorkflowSignal::query()->count());
        $command = WorkflowCommand::query()
            ->where('workflow_instance_id', $workflow->id())
            ->where('command_type', CommandType::Signal->value)
            ->sole();
        $this->assertSame($identity->storageToken(), $command->request_id);
    }

    public function testRawDirectOptionsCannotClaimReservedStartUpdateOrSignalIdentity(): void
    {
        $rawIdentity = 'workflow-service-call:predictable:attempt:1';
        $rawOptions = [
            'service_call_idempotency_key' => $rawIdentity,
            ServiceCallEffectIdentity::OPTION => [
                'idempotency_key' => $rawIdentity,
            ],
        ];
        $control = new DefaultWorkflowControlPlane();

        $start = $control->start(ServiceCallEffectWorkflow::class, 'raw-effect-start', $rawOptions);
        $duplicateStart = $control->start(ServiceCallEffectWorkflow::class, 'raw-effect-start', $rawOptions);
        $this->assertTrue($start['started']);
        $this->assertFalse($duplicateStart['started']);
        $startCommands = WorkflowCommand::query()
            ->where('workflow_instance_id', 'raw-effect-start')
            ->where('command_type', CommandType::Start->value)
            ->get();
        $this->assertCount(2, $startCommands);
        $this->assertTrue($startCommands->every(static fn (WorkflowCommand $command): bool =>
            $command->request_id === null));

        $updateWorkflow = $this->waitingWorkflow('raw-effect-update');
        $updateOptions = $rawOptions + [
            'arguments' => ['first'],
            'wait_for' => 'accepted',
        ];
        $firstUpdate = $control->update($updateWorkflow->id(), 'change', $updateOptions);
        $secondUpdate = $control->update($updateWorkflow->id(), 'change', array_replace($updateOptions, [
            'arguments' => ['second'],
        ]));
        $this->assertNotSame($firstUpdate['update_id'], $secondUpdate['update_id']);
        $this->assertSame(2, WorkflowUpdate::query()->count());

        $signalWorkflow = $this->waitingWorkflow('raw-effect-signal');
        $firstSignal = $control->signal($signalWorkflow->id(), 'finish', $rawOptions);
        $secondSignal = $control->signal($signalWorkflow->id(), 'finish', $rawOptions);
        $this->assertNotSame($firstSignal['workflow_command_id'], $secondSignal['workflow_command_id']);
        $this->assertSame(2, WorkflowSignal::query()->count());
    }

    public function testCraftedCommandContextCannotClaimReservedSignalIdentity(): void
    {
        $workflow = $this->waitingWorkflow('context-effect-signal');
        $context = CommandContext::controlPlane()
            ->with([
                'service_call' => [
                    'idempotency_key' => 'workflow-service-call:predictable:attempt:1',
                ],
            ]);
        $control = new DefaultWorkflowControlPlane();

        $first = $control->signal($workflow->id(), 'finish', [
            'command_context' => $context,
        ]);
        $second = $control->signal($workflow->id(), 'finish', [
            'command_context' => $context,
        ]);

        $this->assertNotSame($first['workflow_command_id'], $second['workflow_command_id']);
        $commands = WorkflowCommand::query()
            ->where('workflow_instance_id', $workflow->id())
            ->where('command_type', CommandType::Signal->value)
            ->get();
        $this->assertCount(2, $commands);
        $this->assertTrue($commands->every(static fn (WorkflowCommand $command): bool =>
            $command->request_id === null));
    }

    public function testPredictableRawIdentityCannotPreseedTheScheduledUpdateReceipt(): void
    {
        $workflow = $this->waitingWorkflow('preseed-effect-update');
        $identity = $this->currentClaimIdentity($workflow);
        $rawIdentity = $identity->commandContext()['idempotency_key'];
        $attackerContext = CommandContext::controlPlane()
            ->with([
                'request' => [
                    'request_id' => $rawIdentity,
                ],
                'service_call' => [
                    'idempotency_key' => $rawIdentity,
                ],
            ]);
        $control = new DefaultWorkflowControlPlane();
        $attacker = $control->update($workflow->id(), 'change', [
            'arguments' => ['attacker'],
            'wait_for' => 'accepted',
            'command_context' => $attackerContext,
        ]);
        $scheduledOptions = [
            'arguments' => ['legitimate'],
            'wait_for' => 'accepted',
            ServiceCallEffectIdentity::OPTION => $identity,
        ];

        $legitimate = $control->update($workflow->id(), 'change', $scheduledOptions);
        $retry = $control->update($workflow->id(), 'change', array_replace($scheduledOptions, [
            'arguments' => ['ignored retry'],
        ]));

        $this->assertNotSame($attacker['update_id'], $legitimate['update_id']);
        $this->assertSame($legitimate['update_id'], $retry['update_id']);
        $this->assertSame(2, WorkflowUpdate::query()->count());
        $tokens = WorkflowCommand::query()
            ->where('workflow_instance_id', $workflow->id())
            ->where('command_type', CommandType::Update->value)
            ->orderBy('created_at')
            ->pluck('request_id')
            ->all();
        $this->assertCount(2, $tokens);
        $this->assertStringStartsWith('update-v1:', $tokens[0]);
        $this->assertSame($identity->storageToken(), $tokens[1]);
    }

    private function currentClaimIdentity(WorkflowStub $caller): ServiceCallEffectIdentity
    {
        $run = WorkflowRun::query()->findOrFail($caller->runId());
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'billing',
            'endpoint_name' => 'billing',
        ]);
        $service = WorkflowService::query()->create([
            'namespace' => 'billing',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'effects',
        ]);
        WorkflowServiceOperation::query()->create([
            'namespace' => 'billing',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'operation_name' => 'apply',
            'operation_mode' => ServiceCallOperationMode::Sync->value,
            'handler_binding_kind' => ServiceCallBindingKind::WorkflowUpdate->value,
            'handler_binding' => [
                'workflow_instance_id' => $caller->id(),
                'update_name' => 'change',
            ],
            'handler_target_reference' => 'change',
        ]);
        $control = new DefaultServiceControlPlane(new DefaultWorkflowControlPlane());
        DB::transaction(static fn (): array => $control->execute('billing', 'effects', 'apply', [
            'namespace' => 'billing',
            'caller_namespace' => $run->namespace,
            'caller_workflow_instance_id' => $caller->id(),
            'caller_workflow_run_id' => $run->id,
            'principal' => new ServiceCallPrincipal(subject: 'workflow:' . $run->id, method: 'workflow'),
            'idempotency_key' => 'effect-identity-' . $caller->id(),
            'wait_for' => 'completed',
            'wait_timeout_seconds' => 60,
        ]));
        $claim = ServiceCallScheduler::claim(WorkflowServiceCall::query()->sole()->id);
        $this->assertNotNull($claim);
        $identity = ServiceCallEffectIdentity::forCurrentClaim($claim);
        $this->assertInstanceOf(ServiceCallEffectIdentity::class, $identity);

        return $identity;
    }

    private function waitingWorkflow(string $instanceId): WorkflowStub
    {
        $workflow = WorkflowStub::make(ServiceCallEffectWorkflow::class, $instanceId);
        $workflow->start();
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->sole();
        $result = $this->app->make(DefaultWorkflowTaskBridge::class)->execute($task->id);
        $this->assertSame('waiting', $result['run_status']);
        Queue::fake();

        return $workflow;
    }
}

#[Type('service-call-effect')]
#[Signal('finish')]
final class ServiceCallEffectWorkflow extends Workflow
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
