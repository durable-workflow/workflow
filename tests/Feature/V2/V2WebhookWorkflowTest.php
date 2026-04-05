<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Tests\Fixtures\V2\TestConfiguredGreetingWorkflow;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\Fixtures\V2\TestTimerWorkflow;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Webhooks;
use Workflow\V2\WorkflowStub;

final class V2WebhookWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'workflows.webhook_auth.method' => 'none',
        ]);

        Webhooks::routes([
            TestGreetingWorkflow::class,
            TestSignalWorkflow::class,
            'test-timer-workflow' => TestTimerWorkflow::class,
        ]);
    }

    public function testMakeReusesCallerSuppliedInstanceIdAcrossRequests(): void
    {
        $first = WorkflowStub::make(TestGreetingWorkflow::class, 'order-123');
        $accepted = $first->attemptStart('Taylor');

        $this->assertTrue($accepted->accepted());

        $second = WorkflowStub::make(TestGreetingWorkflow::class, 'order-123');
        $rejected = $second->attemptStart('Jordan');

        $this->assertSame('order-123', $second->id());
        $this->assertSame($first->runId(), $second->runId());
        $this->assertTrue($rejected->rejected());
        $this->assertSame('instance_already_started', $rejected->rejectionReason());
        $this->assertSame(1, WorkflowInstance::query()->count());
        $this->assertSame(2, WorkflowCommand::query()->count());
    }

    public function testStartWebhookReturnsTypedAcceptedResponse(): void
    {
        $response = $this->postJson('/webhooks/start/test-greeting-workflow', [
            'workflow_id' => 'order-456',
            'name' => 'Taylor',
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('outcome', 'started_new')
            ->assertJsonPath('workflow_id', 'order-456')
            ->assertJsonPath('workflow_type', 'test-greeting-workflow')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('rejection_reason', null);

        $runId = $response->json('run_id');
        $commandId = $response->json('command_id');

        $this->assertIsString($runId);
        $this->assertIsString($commandId);
        $this->assertNotSame('order-456', $runId);

        $this->assertDatabaseHas('workflow_instances', [
            'id' => 'order-456',
            'workflow_type' => 'test-greeting-workflow',
        ]);

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $commandId,
            'workflow_instance_id' => 'order-456',
            'workflow_run_id' => $runId,
            'command_type' => 'start',
            'status' => 'accepted',
            'outcome' => 'started_new',
            'workflow_type' => 'test-greeting-workflow',
        ]);
    }

    public function testStartWebhookReturnsRejectedDuplicateOutcomeForExistingInstance(): void
    {
        $first = $this->postJson('/webhooks/start/test-greeting-workflow', [
            'workflow_id' => 'order-789',
            'name' => 'Taylor',
        ]);

        $second = $this->postJson('/webhooks/start/test-greeting-workflow', [
            'workflow_id' => 'order-789',
            'name' => 'Jordan',
        ]);

        $second
            ->assertStatus(409)
            ->assertJsonPath('outcome', 'rejected_duplicate')
            ->assertJsonPath('workflow_id', 'order-789')
            ->assertJsonPath('workflow_type', 'test-greeting-workflow')
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'instance_already_started')
            ->assertJsonPath('run_id', $first->json('run_id'));

        $this->assertSame(1, WorkflowInstance::query()->count());
        $this->assertSame(2, WorkflowCommand::query()->count());
    }

    public function testStartWebhookCanReturnExistingActiveRunWhenRequested(): void
    {
        $first = $this->postJson('/webhooks/start/test-greeting-workflow', [
            'workflow_id' => 'order-999',
            'name' => 'Taylor',
        ]);

        $second = $this->postJson('/webhooks/start/test-greeting-workflow', [
            'workflow_id' => 'order-999',
            'name' => 'Jordan',
            'on_duplicate' => 'return_existing_active',
        ]);

        $second
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'returned_existing_active')
            ->assertJsonPath('workflow_id', 'order-999')
            ->assertJsonPath('workflow_type', 'test-greeting-workflow')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('rejection_reason', null)
            ->assertJsonPath('run_id', $first->json('run_id'));

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $second->json('command_id'),
            'workflow_instance_id' => 'order-999',
            'workflow_run_id' => $first->json('run_id'),
            'command_type' => 'start',
            'status' => 'accepted',
            'outcome' => 'returned_existing_active',
        ]);
    }

    public function testStartWebhookRejectsMissingRequiredArgumentsWithoutCreatingAnInstance(): void
    {
        $response = $this->postJson('/webhooks/start/test-greeting-workflow', [
            'workflow_id' => 'order-invalid',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->assertSame(0, WorkflowInstance::query()->count());
        $this->assertSame(0, WorkflowCommand::query()->count());
    }

    public function testStartWebhookCanInferConfiguredDurableTypeWithoutTypeAttribute(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'configured-webhook-workflow' => TestConfiguredGreetingWorkflow::class,
        ]);

        Webhooks::routes([
            TestConfiguredGreetingWorkflow::class,
        ], 'configured-webhooks');

        $response = $this->postJson('/configured-webhooks/start/configured-webhook-workflow', [
            'workflow_id' => 'order-configured-webhook',
            'name' => 'Taylor',
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('workflow_id', 'order-configured-webhook')
            ->assertJsonPath('workflow_type', 'configured-webhook-workflow')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('outcome', 'started_new');

        $this->assertDatabaseHas('workflow_instances', [
            'id' => 'order-configured-webhook',
            'workflow_type' => 'configured-webhook-workflow',
        ]);
    }

    public function testSignalWebhookReturnsTypedAcceptedResponse(): void
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'order-signal');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson('/webhooks/instances/order-signal/signals/name-provided', [
            'arguments' => ['Taylor'],
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('outcome', 'signal_received')
            ->assertJsonPath('workflow_id', 'order-signal')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('workflow_type', 'test-signal-workflow')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('rejection_reason', null);

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $response->json('command_id'),
            'workflow_instance_id' => 'order-signal',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'signal',
            'status' => 'accepted',
            'outcome' => 'signal_received',
        ]);
    }

    public function testCancelWebhookReturnsTypedAcceptedResponse(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'order-cancel');
        $workflow->start(5);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson('/webhooks/instances/order-cancel/cancel');

        $response
            ->assertOk()
            ->assertJsonPath('outcome', 'cancelled')
            ->assertJsonPath('workflow_id', 'order-cancel')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('workflow_type', TestTimerWorkflow::class)
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('rejection_reason', null);

        $this->waitFor(static fn (): bool => $workflow->refresh()->cancelled());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $response->json('command_id'),
            'workflow_instance_id' => 'order-cancel',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'cancel',
            'status' => 'accepted',
            'outcome' => 'cancelled',
        ]);
    }

    public function testTerminateWebhookReturnsTypedAcceptedResponse(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'order-terminate');
        $workflow->start(5);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson('/webhooks/instances/order-terminate/terminate');

        $response
            ->assertOk()
            ->assertJsonPath('outcome', 'terminated')
            ->assertJsonPath('workflow_id', 'order-terminate')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('workflow_type', TestTimerWorkflow::class)
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('rejection_reason', null);

        $this->waitFor(static fn (): bool => $workflow->refresh()->terminated());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $response->json('command_id'),
            'workflow_instance_id' => 'order-terminate',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'terminate',
            'status' => 'accepted',
            'outcome' => 'terminated',
        ]);
    }

    public function testCancelWebhookRejectsReservedInstanceThatHasNotStarted(): void
    {
        WorkflowStub::make(TestGreetingWorkflow::class, 'order-reserved');

        $response = $this->postJson('/webhooks/instances/order-reserved/cancel');

        $response
            ->assertStatus(409)
            ->assertJsonPath('outcome', 'rejected_not_started')
            ->assertJsonPath('workflow_id', 'order-reserved')
            ->assertJsonPath('run_id', null)
            ->assertJsonPath('workflow_type', 'test-greeting-workflow')
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'instance_not_started');
    }

    public function testTerminateWebhookRejectsClosedRun(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'order-closed');
        $workflow->start('Taylor');

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        $response = $this->postJson('/webhooks/instances/order-closed/terminate');

        $response
            ->assertStatus(409)
            ->assertJsonPath('outcome', 'rejected_not_active')
            ->assertJsonPath('workflow_id', 'order-closed')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('workflow_type', 'test-greeting-workflow')
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'run_not_active');
    }

    public function testSignalWebhookRejectsClosedRun(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'order-signal-closed');
        $workflow->start('Taylor');

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        $response = $this->postJson('/webhooks/instances/order-signal-closed/signals/name-provided', [
            'arguments' => ['Jordan'],
        ]);

        $response
            ->assertStatus(409)
            ->assertJsonPath('outcome', 'rejected_not_active')
            ->assertJsonPath('workflow_id', 'order-signal-closed')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('workflow_type', 'test-greeting-workflow')
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'run_not_active');
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
}
