<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;
use function Workflow\V2\signal;
use Workflow\V2\Support\DefaultWorkflowControlPlane;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\ServiceCallEffectIdentity;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class ServiceCallEffectIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function testWorkflowStartConsumesTheStableServiceCallIdentity(): void
    {
        $control = new DefaultWorkflowControlPlane();
        $options = [
            'service_call_idempotency_key' => 'workflow-service-call:call:attempt:1',
        ];

        $first = $control->start(ServiceCallEffectWorkflow::class, 'service-effect-start', $options);
        $second = $control->start(ServiceCallEffectWorkflow::class, 'service-effect-start', $options);

        $this->assertTrue($first['started']);
        $this->assertTrue($second['started']);
        $this->assertSame($first['workflow_run_id'], $second['workflow_run_id']);
        $this->assertSame(1, WorkflowRun::query()->count());
        $command = WorkflowCommand::query()
            ->where('command_type', CommandType::Start->value)
            ->sole();
        $this->assertSame(
            ServiceCallEffectIdentity::storageToken($options['service_call_idempotency_key']),
            $command->request_id,
        );
    }

    public function testWorkflowUpdateConsumesTheStableServiceCallIdentity(): void
    {
        $workflow = $this->waitingWorkflow('service-effect-update');
        $control = new DefaultWorkflowControlPlane();
        $options = [
            'arguments' => ['first'],
            'wait_for' => 'accepted',
            'service_call_idempotency_key' => 'workflow-service-call:update:attempt:1',
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
            ->where('command_type', CommandType::Update->value)
            ->sole();
        $this->assertSame($options['service_call_idempotency_key'], $command->requestId());
        $this->assertSame(
            $options['service_call_idempotency_key'],
            $command->context['service_call']['idempotency_key'],
        );
    }

    public function testWorkflowSignalConsumesTheStableServiceCallIdentity(): void
    {
        $workflow = $this->waitingWorkflow('service-effect-signal');
        $control = new DefaultWorkflowControlPlane();
        $options = [
            'service_call_idempotency_key' => 'workflow-service-call:signal:attempt:1',
        ];

        $first = $control->signal($workflow->id(), 'finish', $options);
        $second = $control->signal($workflow->id(), 'finish', $options);

        $this->assertTrue($first['accepted']);
        $this->assertTrue($second['accepted']);
        $this->assertSame($first['workflow_command_id'], $second['workflow_command_id']);
        $this->assertSame(1, WorkflowSignal::query()->count());
        $command = WorkflowCommand::query()
            ->where('command_type', CommandType::Signal->value)
            ->sole();
        $this->assertSame(
            ServiceCallEffectIdentity::storageToken($options['service_call_idempotency_key']),
            $command->request_id,
        );
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
