<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestAliasedUpdateWorkflow;
use Tests\Fixtures\V2\TestConfiguredGreetingWorkflow;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestQueryContinueAsNewWorkflow;
use Tests\Fixtures\V2\TestQueryWorkflow;
use Tests\Fixtures\V2\TestSignalThenUpdateWorkflow;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\Fixtures\V2\TestTimerWorkflow;
use Tests\Fixtures\V2\TestUpdateWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\WorkflowInstanceId;
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

        Queue::fake();

        Webhooks::routes([
            TestGreetingWorkflow::class,
            TestSignalWorkflow::class,
            'test-timer-workflow' => TestTimerWorkflow::class,
            TestQueryContinueAsNewWorkflow::class,
            TestQueryWorkflow::class,
            TestAliasedUpdateWorkflow::class,
            TestSignalThenUpdateWorkflow::class,
            TestUpdateWorkflow::class,
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
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('command_sequence', 1)
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('command_source', 'webhook')
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
            'source' => 'webhook',
            'status' => 'accepted',
            'outcome' => 'started_new',
            'workflow_type' => 'test-greeting-workflow',
        ]);
    }

    public function testActivityTaskClaimWebhookReturnsStructuredClaimPayload(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'activity-task-claim-webhook');
        $workflow->start('Taylor');

        $task = $this->stageFirstActivityTask($workflow);

        $response = $this->postJson("/webhooks/activity-tasks/{$task->id}/claim", [
            'lease_owner' => 'payments-worker-1',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('claimed', true)
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('workflow_instance_id', 'activity-task-claim-webhook')
            ->assertJsonPath('workflow_run_id', $workflow->runId())
            ->assertJsonPath('lease_owner', 'payments-worker-1')
            ->assertJsonPath('reason', null)
            ->assertJsonPath('retry_after_seconds', null)
            ->assertJsonPath('backend_error', null)
            ->assertJsonPath('compatibility_reason', null);

        $attemptId = $response->json('activity_attempt_id');

        $this->assertIsString($attemptId);
        $this->assertDatabaseHas('workflow_tasks', [
            'id' => $task->id,
            'status' => TaskStatus::Leased->value,
            'lease_owner' => 'payments-worker-1',
        ]);
        $this->assertDatabaseHas('activity_attempts', [
            'id' => $attemptId,
            'workflow_task_id' => $task->id,
            'attempt_number' => 1,
            'status' => 'running',
            'lease_owner' => 'payments-worker-1',
        ]);
        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => 'ActivityStarted',
        ]);
    }

    public function testActivityTaskClaimWebhookReturnsRetryAfterForFutureTask(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'activity-task-claim-future');
        $workflow->start('Taylor');

        $task = $this->stageFirstActivityTask($workflow);
        $task->forceFill([
            'available_at' => now()
                ->addSeconds(30),
        ])->save();

        $response = $this->postJson("/webhooks/activity-tasks/{$task->id}/claim");

        $response
            ->assertStatus(409)
            ->assertHeader('Retry-After')
            ->assertJsonPath('claimed', false)
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('reason', 'task_not_due');

        $retryAfter = $response->json('retry_after_seconds');

        $this->assertIsInt($retryAfter);
        $this->assertGreaterThanOrEqual(1, $retryAfter);
        $this->assertSame((string) $retryAfter, $response->headers->get('Retry-After'));
        $this->assertDatabaseHas('workflow_tasks', [
            'id' => $task->id,
            'status' => TaskStatus::Ready->value,
        ]);
    }

    public function testActivityTaskClaimWebhookReturnsBackendUnavailableReasonForUnsupportedConnection(): void
    {
        $this->configureUnsupportedSyncTaskConnection();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'activity-task-claim-backend');
        $workflow->start('Taylor');

        $task = $this->stageFirstActivityTask($workflow);
        $task->forceFill([
            'connection' => 'sync',
        ])->save();

        $response = $this->postJson("/webhooks/activity-tasks/{$task->id}/claim");

        $response
            ->assertStatus(409)
            ->assertJsonPath('claimed', false)
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('reason', 'backend_unavailable')
            ->assertJsonPath('reason_detail', null)
            ->assertJsonPath('compatibility_reason', null);

        $backendError = $response->json('backend_error');

        $this->assertIsString($backendError);
        $this->assertStringContainsString('queue_sync_unsupported', $backendError);
    }

    public function testActivityTaskClaimWebhookReturnsCompatibilityBlockedReasonForIncompatibleWorker(): void
    {
        $this->configureAsyncRedisTaskConnection();

        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'activity-task-claim-compatibility');
        $workflow->start('Taylor');

        $task = $this->stageFirstActivityTask($workflow);

        WorkflowRun::query()->findOrFail($workflow->runId())
            ->forceFill([
                'compatibility' => 'build-a',
            ])
            ->save();

        $task->forceFill([
            'compatibility' => 'build-a',
        ])->save();

        config()
            ->set('workflows.v2.compatibility.current', 'build-b');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-b']);

        $response = $this->postJson("/webhooks/activity-tasks/{$task->id}/claim");

        $response
            ->assertStatus(409)
            ->assertJsonPath('claimed', false)
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('reason', 'compatibility_blocked')
            ->assertJsonPath('reason_detail', null)
            ->assertJsonPath('backend_error', null)
            ->assertJsonPath(
                'compatibility_reason',
                'Requires compatibility [build-a]; this worker supports [build-b].',
            );
    }

    public function testActivityTaskClaimWebhookReturnsTaskNotClaimableReasonForDriftedTaskPayload(): void
    {
        $this->configureAsyncRedisTaskConnection();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'activity-task-claim-drifted');
        $workflow->start('Taylor');

        $task = $this->stageFirstActivityTask($workflow);
        $payload = $task->payload;

        unset($payload['activity_execution_id']);

        $task->forceFill([
            'payload' => $payload,
        ])->save();

        $response = $this->postJson("/webhooks/activity-tasks/{$task->id}/claim");

        $response
            ->assertStatus(409)
            ->assertJsonPath('claimed', false)
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('reason', 'task_not_claimable')
            ->assertJsonPath('reason_detail', 'activity_execution_missing')
            ->assertJsonPath('backend_error', null)
            ->assertJsonPath('compatibility_reason', null);
    }

    public function testActivityAttemptHeartbeatWebhookValidatesProgressPayload(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'activity-task-heartbeat-invalid');
        $workflow->start('Taylor');

        $task = $this->stageFirstActivityTask($workflow);
        $claim = $this->postJson("/webhooks/activity-tasks/{$task->id}/claim")
            ->assertOk();

        $attemptId = $claim->json('activity_attempt_id');

        $this->postJson("/webhooks/activity-attempts/{$attemptId}/heartbeat", [
            'progress' => [
                'unexpected' => 'value',
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['progress'])
            ->assertJsonPath(
                'errors.progress.0',
                'Heartbeat progress only supports [message, current, total, unit, details]; unknown keys: [unexpected].'
            );

        $this->assertDatabaseMissing('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => 'ActivityHeartbeatRecorded',
        ]);
    }

    public function testActivityAttemptHeartbeatAndCompleteWebhooksDriveTheBridgeContract(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'activity-task-complete-webhook');
        $workflow->start('Taylor');

        $task = $this->stageFirstActivityTask($workflow);
        $claim = $this->postJson("/webhooks/activity-tasks/{$task->id}/claim", [
            'lease_owner' => 'bridge-complete-worker',
        ])->assertOk();

        $attemptId = $claim->json('activity_attempt_id');

        $heartbeat = $this->postJson("/webhooks/activity-attempts/{$attemptId}/heartbeat", [
            'progress' => [
                'message' => 'Running remote work',
                'current' => 2,
                'total' => 4,
                'unit' => 'steps',
                'details' => [
                    'remote_state' => 'running',
                ],
            ],
        ]);

        $heartbeat
            ->assertOk()
            ->assertJsonPath('can_continue', true)
            ->assertJsonPath('cancel_requested', false)
            ->assertJsonPath('reason', null)
            ->assertJsonPath('heartbeat_recorded', true)
            ->assertJsonPath('workflow_task_id', $task->id)
            ->assertJsonPath('activity_attempt_id', $attemptId)
            ->assertJsonPath('lease_owner', 'bridge-complete-worker');

        /** @var WorkflowHistoryEvent $heartbeatEvent */
        $heartbeatEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', 'ActivityHeartbeatRecorded')
            ->latest('sequence')
            ->firstOrFail();

        $this->assertSame('Running remote work', $heartbeatEvent->payload['progress']['message'] ?? null);
        $this->assertSame('running', $heartbeatEvent->payload['progress']['details']['remote_state'] ?? null);

        $complete = $this->postJson("/webhooks/activity-attempts/{$attemptId}/complete", [
            'result' => 'Hello from webhook bridge!',
        ]);

        $complete
            ->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('reason', null);

        $nextTaskId = $complete->json('next_task_id');

        $this->assertIsString($nextTaskId);
        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $nextTaskId
        );

        $this->assertDatabaseHas('activity_attempts', [
            'id' => $attemptId,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('activity_executions', [
            'id' => $claim->json('activity_execution_id'),
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => 'ActivityCompleted',
        ]);

        $this->app->call([new RunWorkflowTask($nextTaskId), 'handle']);

        $workflow->refresh();

        $this->assertTrue($workflow->completed());
        $this->assertSame([
            'greeting' => 'Hello from webhook bridge!',
            'workflow_id' => 'activity-task-complete-webhook',
            'run_id' => $workflow->runId(),
        ], $workflow->output());
    }

    public function testActivityAttemptFailWebhookAcceptsStructuredFailurePayloads(): void
    {
        config()->set('workflows.v2.types.exceptions', [
            'payments.gateway-timeout' => \RuntimeException::class,
        ]);

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'activity-task-fail-webhook');
        $workflow->start('Taylor');

        $task = $this->stageFirstActivityTask($workflow);
        $claim = $this->postJson("/webhooks/activity-tasks/{$task->id}/claim", [
            'lease_owner' => 'bridge-fail-worker',
        ])->assertOk();

        $attemptId = $claim->json('activity_attempt_id');

        $failed = $this->postJson("/webhooks/activity-attempts/{$attemptId}/fail", [
            'failure' => [
                'type' => 'payments.gateway-timeout',
                'class' => \RuntimeException::class,
                'message' => 'Gateway timeout',
                'code' => 503,
            ],
        ]);

        $failed
            ->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('reason', null);

        $nextTaskId = $failed->json('next_task_id');

        $this->assertIsString($nextTaskId);
        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $nextTaskId
        );

        /** @var WorkflowHistoryEvent $failedEvent */
        $failedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', 'ActivityFailed')
            ->latest('sequence')
            ->firstOrFail();

        $this->assertSame('payments.gateway-timeout', $failedEvent->payload['exception_type'] ?? null);
        $this->assertSame('Gateway timeout', $failedEvent->payload['message'] ?? null);
        $this->assertDatabaseHas('activity_attempts', [
            'id' => $attemptId,
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('workflow_failures', [
            'workflow_run_id' => $workflow->runId(),
            'message' => 'Gateway timeout',
        ]);
    }

    public function testUpdateWebhookReturnsAcceptedLifecycleWhenCompletionWaitTimesOut(): void
    {
        config()->set('workflows.v2.update_wait.poll_interval_milliseconds', 10);

        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-webhook-timeout');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        WorkflowRun::query()->findOrFail($workflow->runId())->forceFill([
            'compatibility' => 'build-webhook-timeout',
        ])->save();

        $response = $this->postJson('/webhooks/instances/order-update-webhook-timeout/updates/approve', [
            'wait_timeout_seconds' => 1,
            'arguments' => [
                'approved' => true,
                'source' => 'webhook-timeout',
            ],
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('outcome', null)
            ->assertJsonPath('workflow_id', 'order-update-webhook-timeout')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('command_source', 'webhook')
            ->assertJsonPath('update_status', 'accepted')
            ->assertJsonPath('wait_for', 'completed')
            ->assertJsonPath('wait_timed_out', true)
            ->assertJsonPath('wait_timeout_seconds', 1)
            ->assertJsonPath('result', null);
    }

    public function testStartWebhookAcceptsVisibilityMetadata(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $response = $this->postJson('/webhooks/start/test-greeting-workflow', [
            'workflow_id' => 'order-visible',
            'name' => 'Taylor',
            'visibility' => [
                'business_key' => 'order-123',
                'labels' => [
                    'region' => 'us-east',
                    'tenant' => 'acme',
                ],
                'memo' => [
                    'customer' => [
                        'name' => 'Taylor',
                        'vip' => true,
                    ],
                ],
            ],
        ]);

        $response->assertStatus(202);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($response->json('run_id'));
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->findOrFail('order-visible');

        $this->assertSame('order-123', $instance->business_key);
        $this->assertSame([
            'region' => 'us-east',
            'tenant' => 'acme',
        ], $instance->visibility_labels);
        $this->assertSame('order-123', $run->business_key);
        $this->assertSame([
            'region' => 'us-east',
            'tenant' => 'acme',
        ], $run->visibility_labels);
        $this->assertSameJsonObject([
            'customer' => [
                'name' => 'Taylor',
                'vip' => true,
            ],
        ], $instance->memo);
        $this->assertSameJsonObject([
            'customer' => [
                'name' => 'Taylor',
                'vip' => true,
            ],
        ], $run->memo);
    }

    public function testWebhookCommandsPersistDurableIngressMetadata(): void
    {
        $response = $this->withHeaders([
            'X-Request-Id' => 'req-start-123',
            'X-Correlation-Id' => 'corr-start-456',
        ])->postJson('/webhooks/start/test-greeting-workflow', [
            'workflow_id' => 'order-context',
            'name' => 'Taylor',
        ]);

        /** @var WorkflowCommand $command */
        $command = WorkflowCommand::query()->findOrFail($response->json('command_id'));

        $this->assertSame('webhook', $command->source);
        $this->assertSame('Webhook', $command->callerLabel());
        $this->assertSame('not_configured', $command->authStatus());
        $this->assertSame('none', $command->authMethod());
        $this->assertSame('POST', $command->requestMethod());
        $this->assertSame('/webhooks/start/test-greeting-workflow', $command->requestPath());
        $this->assertSame('workflows.v2.start.test-greeting-workflow', $command->requestRouteName());
        $this->assertSame('req-start-123', $command->requestId());
        $this->assertSame('corr-start-456', $command->correlationId());
        $this->assertIsString($command->requestFingerprint());
        $this->assertStringStartsWith('sha256:', $command->requestFingerprint());
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
            ->assertJsonPath('command_sequence', 2)
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'instance_already_started')
            ->assertJsonPath('run_id', $first->json('run_id'))
            ->assertJsonPath('requested_run_id', null)
            ->assertJsonPath('resolved_run_id', $first->json('run_id'));

        $this->assertSame(1, WorkflowInstance::query()->count());
        $this->assertSame(2, WorkflowCommand::query()->count());
    }

    public function testStartWebhookCanReturnExistingActiveRunWhenRequested(): void
    {
        $first = $this->postJson('/webhooks/start/test-signal-workflow', [
            'workflow_id' => 'order-999',
        ]);

        $second = $this->postJson('/webhooks/start/test-signal-workflow', [
            'workflow_id' => 'order-999',
            'on_duplicate' => 'return_existing_active',
        ]);

        $second
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'returned_existing_active')
            ->assertJsonPath('workflow_id', 'order-999')
            ->assertJsonPath('workflow_type', 'test-signal-workflow')
            ->assertJsonPath('command_sequence', 2)
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('rejection_reason', null)
            ->assertJsonPath('run_id', $first->json('run_id'))
            ->assertJsonPath('requested_run_id', null)
            ->assertJsonPath('resolved_run_id', $first->json('run_id'));

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

    public function testStartWebhookRejectsBlankWorkflowIdWithoutCreatingAnInstance(): void
    {
        $response = $this->postJson('/webhooks/start/test-greeting-workflow', [
            'workflow_id' => '   ',
            'name' => 'Taylor',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['workflow_id'])
            ->assertJsonPath('errors.workflow_id.0', WorkflowInstanceId::validationMessage('workflow_id'));

        $this->assertSame(0, WorkflowInstance::query()->count());
        $this->assertSame(0, WorkflowCommand::query()->count());
    }

    public function testStartWebhookRejectsOverlongWorkflowIdWithoutCreatingAnInstance(): void
    {
        $response = $this->postJson('/webhooks/start/test-greeting-workflow', [
            'workflow_id' => str_repeat('a', WorkflowInstanceId::MAX_LENGTH + 1),
            'name' => 'Taylor',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['workflow_id'])
            ->assertJsonPath('errors.workflow_id.0', WorkflowInstanceId::validationMessage('workflow_id'));

        $this->assertSame(0, WorkflowInstance::query()->count());
        $this->assertSame(0, WorkflowCommand::query()->count());
    }

    public function testStartWebhookRejectsWorkflowIdWithUnsupportedCharacters(): void
    {
        $response = $this->postJson('/webhooks/start/test-greeting-workflow', [
            'workflow_id' => 'order/123',
            'name' => 'Taylor',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['workflow_id'])
            ->assertJsonPath('errors.workflow_id.0', WorkflowInstanceId::validationMessage('workflow_id'));

        $this->assertSame(0, WorkflowInstance::query()->count());
        $this->assertSame(0, WorkflowCommand::query()->count());
    }

    public function testWebhookRoutesAcceptLongRouteSafeWorkflowIds(): void
    {
        config()->set('queue.default', 'redis');

        $workflowId = 'tenant.alpha:' . str_repeat('x', WorkflowInstanceId::MAX_LENGTH - strlen('tenant.alpha:'));

        $start = $this->postJson('/webhooks/start/test-signal-workflow', [
            'workflow_id' => $workflowId,
        ]);

        $start
            ->assertStatus(202)
            ->assertJsonPath('workflow_id', $workflowId);

        $signal = $this->postJson('/webhooks/instances/' . $workflowId . '/signals/name-provided', [
            'arguments' => ['Taylor'],
        ]);

        $signal
            ->assertStatus(202)
            ->assertJsonPath('workflow_id', $workflowId)
            ->assertJsonPath('command_status', 'accepted');
    }

    public function testStartWebhookCanInferConfiguredDurableTypeWithoutTypeAttribute(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'configured-webhook-workflow' => TestConfiguredGreetingWorkflow::class,
        ]);

        Webhooks::routes([TestConfiguredGreetingWorkflow::class], 'configured-webhooks');

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

    public function testQueryWebhookReturnsSerializedResultForCurrentRun(): void
    {
        $workflow = WorkflowStub::make(TestQueryWorkflow::class, 'order-query-webhook');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson('/webhooks/instances/order-query-webhook/queries/countEventsByPrefix', [
            'arguments' => [
                'prefix' => 'start',
            ],
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('query_name', 'events-starting-with')
            ->assertJsonPath('workflow_id', 'order-query-webhook')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'instance');

        $this->assertSame(1, $response->json('result'));
    }

    public function testRunTargetedQueryWebhookReturnsSerializedResultForSelectedRun(): void
    {
        $workflow = WorkflowStub::make(TestQueryWorkflow::class, 'order-query-webhook-run');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson(
            '/webhooks/instances/order-query-webhook-run/runs/' . $workflow->runId() . '/queries/events-starting-with',
            [
                'arguments' => [
                    'prefix' => 'start',
                ],
            ]
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('query_name', 'events-starting-with')
            ->assertJsonPath('workflow_id', 'order-query-webhook-run')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'run');

        $this->assertSame(1, $response->json('result'));
    }

    public function testQueryWebhooksExposeContinueAsNewCurrentAndSelectedRunSemantics(): void
    {
        $workflow = WorkflowStub::make(TestQueryContinueAsNewWorkflow::class, 'order-query-webhook-continue');
        $started = $workflow->start(0, 2);
        $firstRunId = $started->runId();

        $this->assertNotNull($firstRunId);

        $this->drainReadyTasks();
        $this->assertTrue($workflow->refresh()->completed());

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'order-query-webhook-continue')
            ->orderBy('run_number')
            ->get();

        $this->assertCount(3, $runs);

        $current = $this->postJson('/webhooks/instances/order-query-webhook-continue/queries/currentCount');

        $current
            ->assertStatus(200)
            ->assertJsonPath('query_name', 'currentCount')
            ->assertJsonPath('workflow_id', 'order-query-webhook-continue')
            ->assertJsonPath('run_id', $runs->last()->id)
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('result', 2);

        $historical = $this->postJson(
            '/webhooks/instances/order-query-webhook-continue/runs/' . $firstRunId . '/queries/currentCount',
        );

        $historical
            ->assertStatus(200)
            ->assertJsonPath('query_name', 'currentCount')
            ->assertJsonPath('workflow_id', 'order-query-webhook-continue')
            ->assertJsonPath('run_id', $firstRunId)
            ->assertJsonPath('target_scope', 'run')
            ->assertJsonPath('result', 1);
    }

    public function testQueryWebhookReturnsValidationErrorsForInvalidArguments(): void
    {
        $workflow = WorkflowStub::make(TestQueryWorkflow::class, 'order-query-webhook-invalid');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson('/webhooks/instances/order-query-webhook-invalid/queries/events-starting-with', [
            'arguments' => [
                'extra' => 'start',
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('query_name', 'events-starting-with')
            ->assertJsonPath('workflow_id', 'order-query-webhook-invalid')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('validation_errors.prefix.0', 'The prefix argument is required.')
            ->assertJsonPath('validation_errors.extra.0', 'Unknown argument [extra].');
    }

    public function testQueryWebhookReturnsBlockedReasonWhenWorkflowDefinitionCannotBeResolved(): void
    {
        $workflow = WorkflowStub::make(TestQueryWorkflow::class, 'order-query-webhook-definition-unavailable');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        WorkflowRun::query()->whereKey($workflow->runId())->update([
            'workflow_class' => 'Missing\\Workflow\\TestQueryWorkflow',
            'workflow_type' => 'missing-query-workflow',
        ]);

        $response = $this->postJson(
            '/webhooks/instances/order-query-webhook-definition-unavailable/queries/events-starting-with',
            [
                'arguments' => [
                    'prefix' => 'start',
                ],
            ]
        );

        $response
            ->assertStatus(409)
            ->assertJsonPath('query_name', 'events-starting-with')
            ->assertJsonPath('workflow_id', 'order-query-webhook-definition-unavailable')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('blocked_reason', 'workflow_definition_unavailable')
            ->assertJsonPath(
                'message',
                sprintf(
                    'Workflow %s [%s] cannot execute query [%s] because the workflow definition is unavailable for durable type [%s].',
                    $workflow->runId(),
                    'order-query-webhook-definition-unavailable',
                    'events-starting-with',
                    'missing-query-workflow',
                ),
            );
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
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('workflow_type', 'test-signal-workflow')
            ->assertJsonPath('command_sequence', 2)
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('rejection_reason', null);

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $response->json('command_id'),
            'workflow_instance_id' => 'order-signal',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'signal',
            'target_scope' => 'instance',
            'status' => 'accepted',
            'outcome' => 'signal_received',
        ]);
    }

    public function testSignalWithStartWebhookReturnsTypedStartedNewResponse(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $response = $this->postJson('/webhooks/start/test-signal-workflow/signals/name-provided', [
            'workflow_id' => 'order-signal-with-start',
            'signal_arguments' => ['Taylor'],
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('outcome', 'signal_received')
            ->assertJsonPath('workflow_id', 'order-signal-with-start')
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('workflow_type', 'test-signal-workflow')
            ->assertJsonPath('start_outcome', 'started_new')
            ->assertJsonPath('start_command_sequence', 1)
            ->assertJsonPath('command_sequence', 2)
            ->assertJsonPath('start_command_status', 'accepted')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('rejection_reason', null);

        $runId = $response->json('run_id');
        $startCommandId = $response->json('start_command_id');
        $commandId = $response->json('command_id');
        $intakeGroupId = $response->json('intake_group_id');

        $this->assertIsString($runId);
        $this->assertIsString($startCommandId);
        $this->assertIsString($commandId);
        $this->assertIsString($intakeGroupId);
        $this->assertDatabaseHas('workflow_commands', [
            'id' => $startCommandId,
            'workflow_instance_id' => 'order-signal-with-start',
            'workflow_run_id' => $runId,
            'command_type' => 'start',
            'status' => 'accepted',
            'outcome' => 'started_new',
        ]);
        $this->assertDatabaseHas('workflow_commands', [
            'id' => $commandId,
            'workflow_instance_id' => 'order-signal-with-start',
            'workflow_run_id' => $runId,
            'command_type' => 'signal',
            'status' => 'accepted',
            'outcome' => 'signal_received',
        ]);
    }

    public function testSignalWithStartWebhookReturnsExistingActiveOutcomeAndSharedIngressMetadata(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'order-signal-with-start-existing');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->withHeaders([
            'X-Request-Id' => 'req-signal-with-start',
            'X-Correlation-Id' => 'corr-signal-with-start',
        ])->postJson('/webhooks/start/test-signal-workflow/signals/name-provided', [
            'workflow_id' => 'order-signal-with-start-existing',
            'signal_arguments' => ['Taylor'],
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('outcome', 'signal_received')
            ->assertJsonPath('workflow_id', 'order-signal-with-start-existing')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('start_outcome', 'returned_existing_active')
            ->assertJsonPath('start_command_sequence', 2)
            ->assertJsonPath('command_sequence', 3)
            ->assertJsonPath('start_command_status', 'accepted')
            ->assertJsonPath('command_status', 'accepted');

        /** @var WorkflowCommand $startCommand */
        $startCommand = WorkflowCommand::query()->findOrFail($response->json('start_command_id'));
        /** @var WorkflowCommand $signalCommand */
        $signalCommand = WorkflowCommand::query()->findOrFail($response->json('command_id'));

        $this->assertSame('corr-signal-with-start', $startCommand->correlationId());
        $this->assertSame('corr-signal-with-start', $signalCommand->correlationId());
        $this->assertSame($response->json('intake_group_id'), $startCommand->intakeGroupId());
        $this->assertSame($response->json('intake_group_id'), $signalCommand->intakeGroupId());
        $this->assertSame('/webhooks/start/test-signal-workflow/signals/name-provided', $startCommand->requestPath());
        $this->assertSame('/webhooks/start/test-signal-workflow/signals/name-provided', $signalCommand->requestPath());
        $this->assertSame('workflows.v2.start-signal.test-signal-workflow', $startCommand->requestRouteName());
        $this->assertSame('workflows.v2.start-signal.test-signal-workflow', $signalCommand->requestRouteName());
    }

    public function testSignalWithStartWebhookRejectsUnknownSignalWithoutStartingARun(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $response = $this->postJson('/webhooks/start/test-signal-workflow/signals/missing-signal', [
            'workflow_id' => 'order-signal-with-start-unknown',
            'signal_arguments' => ['Taylor'],
        ]);

        $response
            ->assertStatus(404)
            ->assertJsonPath('outcome', 'rejected_unknown_signal')
            ->assertJsonPath('workflow_id', 'order-signal-with-start-unknown')
            ->assertJsonPath('workflow_type', 'test-signal-workflow')
            ->assertJsonPath('run_id', null)
            ->assertJsonPath('start_command_id', null)
            ->assertJsonPath('start_outcome', null)
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'unknown_signal');

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $response->json('command_id'),
            'workflow_instance_id' => 'order-signal-with-start-unknown',
            'workflow_run_id' => null,
            'command_type' => 'signal',
            'status' => 'rejected',
            'outcome' => 'rejected_unknown_signal',
            'rejection_reason' => 'unknown_signal',
        ]);
        $this->assertDatabaseMissing('workflow_commands', [
            'workflow_instance_id' => 'order-signal-with-start-unknown',
            'command_type' => 'start',
        ]);
    }

    public function testRunTargetedSignalWebhookReturnsTypedAcceptedResponse(): void
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'order-signal-run');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson(
            '/webhooks/instances/order-signal-run/runs/' . $workflow->runId() . '/signals/name-provided',
            [
                'arguments' => ['Taylor'],
            ]
        );

        $response
            ->assertStatus(202)
            ->assertJsonPath('outcome', 'signal_received')
            ->assertJsonPath('workflow_id', 'order-signal-run')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'run')
            ->assertJsonPath('workflow_type', 'test-signal-workflow')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('rejection_reason', null);

        /** @var WorkflowCommand $command */
        $command = WorkflowCommand::query()->findOrFail($response->json('command_id'));

        $this->assertSame('run', $command->target_scope);
        $this->assertSame(
            '/webhooks/instances/order-signal-run/runs/' . $workflow->runId() . '/signals/name-provided',
            $command->requestPath(),
        );
        $this->assertSame('workflows.v2.runs.signal', $command->requestRouteName());
    }

    public function testSignalWebhookAcceptsNamedArgumentsWhenTheRunHasADurableSignalContract(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-signal-contract');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson('/webhooks/instances/order-signal-contract/signals/name-provided', [
            'arguments' => [
                'name' => 'Taylor',
            ],
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('outcome', 'signal_received')
            ->assertJsonPath('workflow_id', 'order-signal-contract')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('workflow_type', 'test-update-workflow')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('validation_errors', []);

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        $this->assertSame([
            'approved' => false,
            'events' => ['started', 'signal:Taylor'],
            'workflow_id' => 'order-signal-contract',
            'run_id' => $workflow->runId(),
        ], $workflow->output());
    }

    public function testSignalWebhookReturnsValidationErrorsForInvalidNamedArguments(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-signal-contract-invalid');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson('/webhooks/instances/order-signal-contract-invalid/signals/name-provided', [
            'arguments' => [
                'nickname' => 'Taylor',
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('outcome', 'rejected_invalid_arguments')
            ->assertJsonPath('workflow_id', 'order-signal-contract-invalid')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('workflow_type', 'test-update-workflow')
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'invalid_signal_arguments')
            ->assertJsonPath('validation_errors.name.0', 'The name argument is required.')
            ->assertJsonPath('validation_errors.nickname.0', 'Unknown argument [nickname].');
    }

    public function testSignalWebhookRejectsNamedArgumentsWhenLegacyContractNeedsBackfillAndDefinitionIsUnavailable(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-signal-contract-unavailable');
        $workflow->start();
        $this->runReadyWorkflowTask($workflow->runId());
        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'signal');

        /** @var WorkflowHistoryEvent $started */
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', 'WorkflowStarted')
            ->sole();

        $started->forceFill([
            'payload' => [
                'workflow_class' => TestUpdateWorkflow::class,
                'workflow_type' => 'test-update-workflow',
                'workflow_instance_id' => $workflow->id(),
                'workflow_run_id' => $workflow->runId(),
                'declared_signals' => ['name-provided'],
                'declared_updates' => ['approve', 'explode'],
            ],
        ])->save();

        WorkflowRun::query()->whereKey($workflow->runId())->update([
            'workflow_class' => 'Missing\\Workflow\\TestUpdateWorkflow',
            'workflow_type' => 'missing-update-workflow',
        ]);

        $response = $this->postJson('/webhooks/instances/order-signal-contract-unavailable/signals/name-provided', [
            'arguments' => [
                'name' => 'Taylor',
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('outcome', 'rejected_invalid_arguments')
            ->assertJsonPath('workflow_id', 'order-signal-contract-unavailable')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('workflow_type', 'missing-update-workflow')
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'invalid_signal_arguments')
            ->assertJsonPath(
                'validation_errors.arguments.0',
                'Named arguments require a durable or loadable workflow signal contract.'
            );
    }

    public function testSignalWebhookReturnsTypedUnknownSignalResponse(): void
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'order-signal-unknown');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson('/webhooks/instances/order-signal-unknown/signals/not-declared', [
            'arguments' => ['Taylor'],
        ]);

        $response
            ->assertStatus(404)
            ->assertJsonPath('outcome', 'rejected_unknown_signal')
            ->assertJsonPath('workflow_id', 'order-signal-unknown')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('workflow_type', 'test-signal-workflow')
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'unknown_signal');

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $response->json('command_id'),
            'workflow_instance_id' => 'order-signal-unknown',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'signal',
            'status' => 'rejected',
            'outcome' => 'rejected_unknown_signal',
            'rejection_reason' => 'unknown_signal',
        ]);
    }

    public function testUpdateWebhookReturnsTypedCompletedResponse(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-webhook');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson('/webhooks/instances/order-update-webhook/updates/approve', [
            'arguments' => [true, 'webhook'],
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'update_completed')
            ->assertJsonPath('workflow_id', 'order-update-webhook')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('workflow_type', 'test-update-workflow')
            ->assertJsonPath('command_sequence', 2)
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('update_id', $response->json('update_id'))
            ->assertJsonPath('rejection_reason', null)
            ->assertJsonPath('failure_id', null)
            ->assertJsonPath('failure_message', null)
            ->assertJson([
                'result' => [
                    'approved' => true,
                    'events' => ['started', 'approved:yes:webhook'],
                ],
            ]);

        $this->assertNotNull($response->json('update_id'));

        $this->assertDatabaseHas('workflow_updates', [
            'id' => $response->json('update_id'),
            'workflow_command_id' => $response->json('command_id'),
            'workflow_instance_id' => 'order-update-webhook',
            'workflow_run_id' => $workflow->runId(),
            'update_name' => 'approve',
            'status' => 'completed',
            'outcome' => 'update_completed',
        ]);

        $this->assertSame([
            'stage' => 'waiting-for-name',
            'approved' => true,
            'events' => ['started', 'approved:yes:webhook'],
        ], $workflow->currentState());
    }

    public function testUpdateWebhookCanReturnAfterAcceptanceAndLetWorkerApplyTheUpdate(): void
    {
        config()->set('queue.default', 'redis');

        Queue::fake();

        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-webhook-accepted');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $response = $this->postJson('/webhooks/instances/order-update-webhook-accepted/updates/approve', [
            'wait_for' => 'accepted',
            'arguments' => [true, 'webhook'],
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('outcome', null)
            ->assertJsonPath('workflow_id', 'order-update-webhook-accepted')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('workflow_type', 'test-update-workflow')
            ->assertJsonPath('command_sequence', 2)
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('update_status', 'accepted')
            ->assertJsonPath('result', null);

        $this->assertDatabaseHas('workflow_updates', [
            'id' => $response->json('update_id'),
            'workflow_command_id' => $response->json('command_id'),
            'workflow_instance_id' => 'order-update-webhook-accepted',
            'workflow_run_id' => $workflow->runId(),
            'update_name' => 'approve',
            'status' => 'accepted',
            'outcome' => null,
            'workflow_sequence' => null,
        ]);

        $this->assertSame([
            'stage' => 'waiting-for-name',
            'approved' => false,
            'events' => ['started'],
        ], $workflow->currentState());

        $this->runReadyWorkflowTask($workflow->runId());

        $this->assertSame([
            'stage' => 'waiting-for-name',
            'approved' => true,
            'events' => ['started', 'approved:yes:webhook'],
        ], $workflow->currentState());

        $this->assertDatabaseHas('workflow_updates', [
            'id' => $response->json('update_id'),
            'status' => 'completed',
            'outcome' => 'update_completed',
            'workflow_sequence' => 1,
        ]);
    }

    public function testUpdateWebhookCanInspectAcceptedLifecycleByUpdateId(): void
    {
        config()->set('queue.default', 'redis');

        Queue::fake();

        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-webhook-inspect');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $accepted = $this->postJson('/webhooks/instances/order-update-webhook-inspect/updates/approve', [
            'wait_for' => 'accepted',
            'arguments' => [true, 'webhook-inspect'],
        ]);

        $accepted
            ->assertStatus(202)
            ->assertJsonPath('update_status', 'accepted');

        $updateId = $accepted->json('update_id');

        $this->assertIsString($updateId);

        $this->getJson('/webhooks/instances/order-update-webhook-inspect/updates/' . $updateId)
            ->assertStatus(202)
            ->assertJsonPath('workflow_id', 'order-update-webhook-inspect')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('command_id', $accepted->json('command_id'))
            ->assertJsonPath('update_id', $updateId)
            ->assertJsonPath('update_name', 'approve')
            ->assertJsonPath('update_status', 'accepted')
            ->assertJsonPath('workflow_sequence', null)
            ->assertJsonPath('wait_for', 'status')
            ->assertJsonPath('wait_timed_out', false)
            ->assertJsonPath('wait_timeout_seconds', null)
            ->assertJsonPath('result', null);

        $this->runReadyWorkflowTask($workflow->runId());

        $this->getJson(
            '/webhooks/instances/order-update-webhook-inspect/runs/' . $workflow->runId() . '/updates/' . $updateId
        )
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'update_completed')
            ->assertJsonPath('update_id', $updateId)
            ->assertJsonPath('update_name', 'approve')
            ->assertJsonPath('update_status', 'completed')
            ->assertJsonPath('workflow_sequence', 1)
            ->assertJsonPath('wait_for', 'status')
            ->assertJsonPath('wait_timed_out', false)
            ->assertJsonPath('result.approved', true)
            ->assertJsonPath('result.events.0', 'started')
            ->assertJsonPath('result.events.1', 'approved:yes:webhook-inspect');
    }

    public function testUpdateWebhookRejectsLookupOnlyWaitForModeOnWriteRequests(): void
    {
        config()->set('queue.default', 'redis');

        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-webhook-invalid-wait-for');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $response = $this->postJson('/webhooks/instances/order-update-webhook-invalid-wait-for/updates/approve', [
            'wait_for' => 'status',
            'arguments' => [true, 'webhook-invalid'],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['wait_for'])
            ->assertJsonPath('errors.wait_for.0', 'The wait_for field must be one of: accepted, completed.');

        $this->assertSame(1, WorkflowCommand::query()
            ->where('workflow_instance_id', 'order-update-webhook-invalid-wait-for')
            ->count());
        $this->assertSame(0, WorkflowUpdate::query()
            ->where('workflow_instance_id', 'order-update-webhook-invalid-wait-for')
            ->count());
    }

    public function testUpdateWebhookRejectsLaterUpdateWhileAnEarlierSignalIsStillPending(): void
    {
        $workflow = WorkflowStub::make(TestSignalThenUpdateWorkflow::class, 'order-update-webhook-linearized');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        Queue::fake();

        $signal = $this->postJson('/webhooks/instances/order-update-webhook-linearized/signals/advance', [
            'arguments' => ['Taylor'],
        ]);

        $signal
            ->assertStatus(202)
            ->assertJsonPath('outcome', 'signal_received')
            ->assertJsonPath('command_sequence', 2);

        $response = $this->postJson('/webhooks/instances/order-update-webhook-linearized/updates/approve', [
            'arguments' => [true, 'webhook'],
        ]);

        $response
            ->assertStatus(409)
            ->assertJsonPath('outcome', 'rejected_pending_signal')
            ->assertJsonPath('workflow_id', 'order-update-webhook-linearized')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('workflow_type', 'test-signal-then-update-workflow')
            ->assertJsonPath('command_sequence', 3)
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('update_id', $response->json('update_id'))
            ->assertJsonPath('rejection_reason', 'earlier_signal_pending')
            ->assertJsonPath('result', null);

        $this->assertNotNull($response->json('update_id'));

        $this->assertDatabaseHas('workflow_updates', [
            'id' => $response->json('update_id'),
            'workflow_command_id' => $response->json('command_id'),
            'workflow_instance_id' => 'order-update-webhook-linearized',
            'workflow_run_id' => $workflow->runId(),
            'update_name' => 'approve',
            'status' => 'rejected',
            'outcome' => 'rejected_pending_signal',
            'rejection_reason' => 'earlier_signal_pending',
        ]);

        $this->assertSame([
            'stage' => 'waiting-for-advance',
            'name' => null,
            'approved' => false,
            'events' => ['started'],
        ], $workflow->currentState());
    }

    public function testUpdateWebhookUsesTheDeclaredAliasAsThePublicTarget(): void
    {
        $workflow = WorkflowStub::make(TestAliasedUpdateWorkflow::class, 'order-update-webhook-alias');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $accepted = $this->postJson('/webhooks/instances/order-update-webhook-alias/updates/mark-approved', [
            'arguments' => [true, 'webhook'],
        ]);

        $accepted
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'update_completed')
            ->assertJsonPath('workflow_id', 'order-update-webhook-alias')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('workflow_type', 'test-aliased-update-workflow')
            ->assertJson([
                'result' => [
                    'approved' => true,
                    'events' => ['started', 'approved:yes:webhook'],
                ],
            ]);

        $rejected = $this->postJson('/webhooks/instances/order-update-webhook-alias/updates/applyApproval', [
            'arguments' => [true, 'webhook'],
        ]);

        $rejected
            ->assertStatus(404)
            ->assertJsonPath('outcome', 'rejected_unknown_update')
            ->assertJsonPath('rejection_reason', 'unknown_update');
    }

    public function testUpdateWebhookAcceptsNamedArgumentsUsingTheDeclaredContract(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-webhook-named');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson('/webhooks/instances/order-update-webhook-named/updates/approve', [
            'arguments' => [
                'approved' => true,
            ],
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'update_completed')
            ->assertJsonPath('validation_errors', [])
            ->assertJson([
                'result' => [
                    'approved' => true,
                    'events' => ['started', 'approved:yes:manual'],
                ],
            ]);

        $accepted = WorkflowCommand::query()->findOrFail($response->json('command_id'));

        $this->assertSame([
            'name' => 'approve',
            'arguments' => [
                'approved' => true,
            ],
            'validation_errors' => [],
        ], Serializer::unserialize($accepted->payload));

        $acceptedEvent = \Workflow\V2\Models\WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', 'UpdateAccepted')
            ->sole();

        $this->assertSame(
            [true, 'manual'],
            Serializer::unserialize($acceptedEvent->payload['arguments'] ?? serialize([])),
        );
    }

    public function testRunTargetedUpdateWebhookRejectsHistoricalSelectedRun(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'order-update-webhook-history',
            'workflow_class' => TestUpdateWorkflow::class,
            'workflow_type' => 'test-update-workflow',
            'run_count' => 2,
            'started_at' => now()
                ->subMinutes(5),
        ]);

        /** @var WorkflowRun $historicalRun */
        $historicalRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestUpdateWorkflow::class,
            'workflow_type' => 'test-update-workflow',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(5),
            'closed_at' => now()
                ->subMinutes(4),
            'last_progress_at' => now()
                ->subMinutes(4),
        ]);

        /** @var WorkflowRun $currentRun */
        $currentRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'workflow_class' => TestUpdateWorkflow::class,
            'workflow_type' => 'test-update-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $currentRun->id,
        ])->save();

        $response = $this->postJson(
            '/webhooks/instances/order-update-webhook-history/runs/' . $historicalRun->id . '/updates/approve',
            [
                'arguments' => [true, 'webhook'],
            ]
        );

        $response
            ->assertStatus(409)
            ->assertJsonPath('outcome', 'rejected_not_current')
            ->assertJsonPath('workflow_id', 'order-update-webhook-history')
            ->assertJsonPath('run_id', $historicalRun->id)
            ->assertJsonPath('requested_run_id', $historicalRun->id)
            ->assertJsonPath('resolved_run_id', $currentRun->id)
            ->assertJsonPath('target_scope', 'run')
            ->assertJsonPath('workflow_type', 'test-update-workflow')
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'selected_run_not_current')
            ->assertJsonPath('result', null)
            ->assertJsonPath('failure_id', null)
            ->assertJsonPath('failure_message', null);

        /** @var WorkflowCommand $command */
        $command = WorkflowCommand::query()->findOrFail($response->json('command_id'));

        $this->assertSame('run', $command->target_scope);
        $this->assertSame($historicalRun->id, $command->requestedRunId());
        $this->assertSame($currentRun->id, $command->resolvedRunId());
        $this->assertSame(
            '/webhooks/instances/order-update-webhook-history/runs/' . $historicalRun->id . '/updates/approve',
            $command->requestPath(),
        );
        $this->assertSame('workflows.v2.runs.update', $command->requestRouteName());
    }

    public function testUpdateWebhookReturnsTypedUnknownUpdateResponse(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-web-unk');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson('/webhooks/instances/order-update-web-unk/updates/missing-update', [
            'arguments' => [true, 'webhook'],
        ]);

        $response
            ->assertStatus(404)
            ->assertJsonPath('outcome', 'rejected_unknown_update')
            ->assertJsonPath('workflow_id', 'order-update-web-unk')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('workflow_type', 'test-update-workflow')
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('update_id', $response->json('update_id'))
            ->assertJsonPath('rejection_reason', 'unknown_update')
            ->assertJsonPath('result', null)
            ->assertJsonPath('failure_id', null)
            ->assertJsonPath('failure_message', null);

        $this->assertNotNull($response->json('update_id'));

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $response->json('command_id'),
            'workflow_instance_id' => 'order-update-web-unk',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'update',
            'status' => 'rejected',
            'outcome' => 'rejected_unknown_update',
            'rejection_reason' => 'unknown_update',
        ]);
        $this->assertDatabaseHas('workflow_updates', [
            'id' => $response->json('update_id'),
            'workflow_command_id' => $response->json('command_id'),
            'workflow_instance_id' => 'order-update-web-unk',
            'workflow_run_id' => $workflow->runId(),
            'update_name' => 'missing-update',
            'status' => 'rejected',
            'outcome' => 'rejected_unknown_update',
            'rejection_reason' => 'unknown_update',
        ]);
    }

    public function testUpdateWebhookReturnsTypedInvalidArgumentResponse(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-web-invalid');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson('/webhooks/instances/order-update-web-invalid/updates/approve', [
            'arguments' => [
                'source' => 'webhook',
                'extra' => 'unexpected',
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('outcome', 'rejected_invalid_arguments')
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'invalid_update_arguments')
            ->assertJsonPath('validation_errors.approved.0', 'The approved argument is required.')
            ->assertJsonPath('validation_errors.extra.0', 'Unknown argument [extra].')
            ->assertJsonPath('result', null)
            ->assertJsonPath('failure_id', null)
            ->assertJsonPath('failure_message', null);

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $response->json('command_id'),
            'workflow_instance_id' => 'order-update-web-invalid',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'update',
            'status' => 'rejected',
            'outcome' => 'rejected_invalid_arguments',
            'rejection_reason' => 'invalid_update_arguments',
        ]);
    }

    public function testUpdateWebhookUsesDurableContractValidationWhenWorkflowDefinitionCannotBeResolved(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-web-invalid-history');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        WorkflowRun::query()->whereKey($workflow->runId())->update([
            'workflow_class' => 'Missing\\Workflow\\TestUpdateWorkflow',
            'workflow_type' => 'missing-update-workflow',
        ]);

        $response = $this->postJson('/webhooks/instances/order-update-web-invalid-history/updates/approve', [
            'arguments' => [
                'source' => 'webhook',
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('outcome', 'rejected_invalid_arguments')
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'invalid_update_arguments')
            ->assertJsonPath('validation_errors.approved.0', 'The approved argument is required.')
            ->assertJsonPath('workflow_id', 'order-update-web-invalid-history')
            ->assertJsonPath('result', null)
            ->assertJsonPath('failure_id', null)
            ->assertJsonPath('failure_message', null);

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $response->json('command_id'),
            'workflow_instance_id' => 'order-update-web-invalid-history',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'update',
            'status' => 'rejected',
            'outcome' => 'rejected_invalid_arguments',
            'rejection_reason' => 'invalid_update_arguments',
        ]);
    }

    public function testUpdateWebhookUsesDurableContractTypeValidationWhenWorkflowDefinitionCannotBeResolved(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-web-type-history');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        WorkflowRun::query()->whereKey($workflow->runId())->update([
            'workflow_class' => 'Missing\\Workflow\\TestUpdateWorkflow',
            'workflow_type' => 'missing-update-workflow',
        ]);

        $response = $this->postJson('/webhooks/instances/order-update-web-type-history/updates/approve', [
            'arguments' => [
                'approved' => 'yes',
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('outcome', 'rejected_invalid_arguments')
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'invalid_update_arguments')
            ->assertJsonPath('validation_errors.approved.0', 'The approved argument must be of type bool.')
            ->assertJsonPath('workflow_id', 'order-update-web-type-history')
            ->assertJsonPath('result', null)
            ->assertJsonPath('failure_id', null)
            ->assertJsonPath('failure_message', null);

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $response->json('command_id'),
            'workflow_instance_id' => 'order-update-web-type-history',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'update',
            'status' => 'rejected',
            'outcome' => 'rejected_invalid_arguments',
            'rejection_reason' => 'invalid_update_arguments',
        ]);
    }

    public function testUpdateWebhookRejectsNamedArgumentsWhenLegacyContractNeedsBackfillAndDefinitionIsUnavailable(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-web-contract-unavailable');
        $workflow->start();
        $this->runReadyWorkflowTask($workflow->runId());

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        /** @var WorkflowHistoryEvent $started */
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', 'WorkflowStarted')
            ->sole();

        $started->forceFill([
            'payload' => [
                'workflow_class' => TestUpdateWorkflow::class,
                'workflow_type' => 'test-update-workflow',
                'workflow_instance_id' => $workflow->id(),
                'workflow_run_id' => $workflow->runId(),
                'declared_queries' => ['currentState'],
                'declared_signals' => ['name-provided'],
                'declared_updates' => ['approve', 'explode'],
            ],
        ])->save();

        WorkflowRun::query()->whereKey($workflow->runId())->update([
            'workflow_class' => 'Missing\\Workflow\\TestUpdateWorkflow',
            'workflow_type' => 'missing-update-workflow',
        ]);

        $response = $this->postJson('/webhooks/instances/order-update-web-contract-unavailable/updates/approve', [
            'arguments' => [
                'source' => 'webhook',
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('outcome', 'rejected_invalid_arguments')
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'invalid_update_arguments')
            ->assertJsonPath(
                'validation_errors.arguments.0',
                'Named arguments require a durable or loadable workflow update contract.'
            )
            ->assertJsonPath('workflow_id', 'order-update-web-contract-unavailable')
            ->assertJsonPath('workflow_type', 'missing-update-workflow')
            ->assertJsonPath('result', null)
            ->assertJsonPath('failure_id', null)
            ->assertJsonPath('failure_message', null);

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $response->json('command_id'),
            'workflow_instance_id' => 'order-update-web-contract-unavailable',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'update',
            'status' => 'rejected',
            'outcome' => 'rejected_invalid_arguments',
            'rejection_reason' => 'invalid_update_arguments',
        ]);
    }

    public function testUpdateWebhookRejectsPendingSignalInsteadOfInlineClosingTheRun(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-webhook-b');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        Queue::fake();

        $signal = $this->postJson('/webhooks/instances/order-update-webhook-b/signals/name-provided', [
            'arguments' => ['Taylor'],
        ]);

        $signal
            ->assertStatus(202)
            ->assertJsonPath('outcome', 'signal_received')
            ->assertJsonPath('command_sequence', 2);

        $response = $this->postJson('/webhooks/instances/order-update-webhook-b/updates/approve', [
            'arguments' => [true, 'webhook'],
        ]);

        $response
            ->assertStatus(409)
            ->assertJsonPath('outcome', 'rejected_pending_signal')
            ->assertJsonPath('workflow_id', 'order-update-webhook-b')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('workflow_type', 'test-update-workflow')
            ->assertJsonPath('command_sequence', 3)
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'earlier_signal_pending')
            ->assertJsonPath('result', null)
            ->assertJsonPath('failure_id', null)
            ->assertJsonPath('failure_message', null);

        $this->assertSame('waiting', $workflow->refresh()->status());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $response->json('command_id'),
            'workflow_instance_id' => 'order-update-webhook-b',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'update',
            'status' => 'rejected',
            'outcome' => 'rejected_pending_signal',
            'rejection_reason' => 'earlier_signal_pending',
        ]);
    }

    public function testRepairWebhookReturnsTypedAcceptedResponseWhenTaskIsRecreated(): void
    {
        Queue::fake();

        WorkflowInstance::query()->create([
            'id' => 'order-repair',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
            'current_run_id' => '01JTESTFLOWRUNREPAIRWEB001',
        ]);

        WorkflowRun::query()->create([
            'id' => '01JTESTFLOWRUNREPAIRWEB001',
            'workflow_instance_id' => 'order-repair',
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(30),
        ]);

        $response = $this->postJson('/webhooks/instances/order-repair/repair');

        $response
            ->assertOk()
            ->assertJsonPath('outcome', 'repair_dispatched')
            ->assertJsonPath('workflow_id', 'order-repair')
            ->assertJsonPath('run_id', '01JTESTFLOWRUNREPAIRWEB001')
            ->assertJsonPath('command_sequence', 1)
            ->assertJsonPath('workflow_type', 'test-greeting-workflow')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('rejection_reason', null);

        Queue::assertPushed(RunWorkflowTask::class);

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $response->json('command_id'),
            'workflow_instance_id' => 'order-repair',
            'workflow_run_id' => '01JTESTFLOWRUNREPAIRWEB001',
            'command_type' => 'repair',
            'status' => 'accepted',
            'outcome' => 'repair_dispatched',
        ]);
    }

    public function testRepairWebhookReturnsNoOpOutcomeForHealthySignalWait(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'order-repair-signal');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'signal');

        $response = $this->postJson('/webhooks/instances/order-repair-signal/repair');

        $response
            ->assertOk()
            ->assertJsonPath('outcome', 'repair_not_needed')
            ->assertJsonPath('workflow_id', 'order-repair-signal')
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('workflow_type', 'test-signal-workflow')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('rejection_reason', null);
    }

    public function testRepairWebhookRecreatesMissingWorkflowTaskForAcceptedUpdateWebhook(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-repair-update-webhook');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $accepted = $this->postJson('/webhooks/instances/order-repair-update-webhook/updates/approve', [
            'wait_for' => 'accepted',
            'arguments' => [true, 'webhook-repair'],
        ]);

        $accepted
            ->assertStatus(202)
            ->assertJsonPath('update_status', 'accepted')
            ->assertJsonPath('command_source', 'webhook');

        $runId = $workflow->runId();
        $commandId = $accepted->json('command_id');
        $updateId = $accepted->json('update_id');

        $this->assertIsString($runId);
        $this->assertIsString($commandId);
        $this->assertIsString($updateId);

        WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->delete();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertSame('update', $summary->wait_kind);
        $this->assertSame('update:' . $updateId, $summary->open_wait_id);
        $this->assertSame('workflow_update', $summary->resume_source_kind);
        $this->assertSame($updateId, $summary->resume_source_id);
        $this->assertSame('repair_needed', $summary->liveness_state);
        $this->assertSame('Accepted update approve is open without an open workflow task.', $summary->liveness_reason);

        $repair = $this->postJson('/webhooks/instances/order-repair-update-webhook/repair');

        $repair
            ->assertOk()
            ->assertJsonPath('outcome', 'repair_dispatched')
            ->assertJsonPath('workflow_id', 'order-repair-update-webhook')
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('command_source', 'webhook')
            ->assertJsonPath('rejection_reason', null);

        /** @var WorkflowTask $repairedTask */
        $repairedTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();

        $this->assertSame(1, $repairedTask->repair_count);
        $this->assertSameJsonObject([
            'workflow_wait_kind' => 'update',
            'open_wait_id' => 'update:' . $updateId,
            'resume_source_kind' => 'workflow_update',
            'resume_source_id' => $updateId,
            'workflow_update_id' => $updateId,
            'workflow_command_id' => $commandId,
        ], $repairedTask->payload);

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $repairedTask->id
        );

        /** @var WorkflowRunSummary $updatedSummary */
        $updatedSummary = WorkflowRunSummary::query()->findOrFail($runId);

        $this->assertSame('update', $updatedSummary->wait_kind);
        $this->assertSame('workflow_task_ready', $updatedSummary->liveness_state);
        $this->assertSame($repairedTask->id, $updatedSummary->next_task_id);

        /** @var WorkflowRun $detailRun */
        $detailRun = WorkflowRun::query()->findOrFail($runId);
        $detail = RunDetailView::forRun($detailRun);
        $taskDetail = collect($detail['tasks'])->firstWhere('id', $repairedTask->id);

        $this->assertIsArray($taskDetail);
        $this->assertSame('Workflow task ready to apply accepted update.', $taskDetail['summary']);
        $this->assertSame('update', $taskDetail['workflow_wait_kind']);
        $this->assertSame($updateId, $taskDetail['workflow_update_id']);

        $this->runReadyWorkflowTask($runId);

        $this->assertDatabaseHas('workflow_updates', [
            'id' => $updateId,
            'status' => 'completed',
            'outcome' => 'update_completed',
            'workflow_sequence' => 1,
        ]);
    }

    public function testRepairWebhookRecreatesMissingWorkflowTaskForAcceptedSignalWebhook(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'order-repair-signal-webhook');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'signal');

        $accepted = $this->postJson('/webhooks/instances/order-repair-signal-webhook/signals/name-provided', [
            'arguments' => ['Taylor'],
        ]);

        $accepted
            ->assertStatus(202)
            ->assertJsonPath('outcome', 'signal_received')
            ->assertJsonPath('command_source', 'webhook');

        $runId = $workflow->runId();
        $commandId = $accepted->json('command_id');

        $this->assertIsString($runId);
        $this->assertIsString($commandId);

        /** @var WorkflowSignal $signal */
        $signal = WorkflowSignal::query()
            ->where('workflow_command_id', $commandId)
            ->sole();

        WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->delete();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertSame('signal', $summary->wait_kind);
        $this->assertSame('Waiting to apply signal name-provided', $summary->wait_reason);
        $this->assertSame('signal-application:' . $signal->id, $summary->open_wait_id);
        $this->assertSame('workflow_signal', $summary->resume_source_kind);
        $this->assertSame($signal->id, $summary->resume_source_id);
        $this->assertSame('repair_needed', $summary->liveness_state);

        $repair = $this->postJson('/webhooks/instances/order-repair-signal-webhook/repair');

        $repair
            ->assertOk()
            ->assertJsonPath('outcome', 'repair_dispatched')
            ->assertJsonPath('workflow_id', 'order-repair-signal-webhook')
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('command_source', 'webhook')
            ->assertJsonPath('rejection_reason', null);

        /** @var WorkflowTask $repairedTask */
        $repairedTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();

        $this->assertSame(1, $repairedTask->repair_count);
        $this->assertSameJsonObject([
            'workflow_wait_kind' => 'signal',
            'open_wait_id' => 'signal-application:' . $signal->id,
            'resume_source_kind' => 'workflow_signal',
            'resume_source_id' => $signal->id,
            'workflow_signal_id' => $signal->id,
            'signal_name' => $signal->signal_name,
            'signal_wait_id' => $signal->signal_wait_id,
            'workflow_command_id' => $commandId,
        ], $repairedTask->payload);

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $repairedTask->id
        );

        /** @var WorkflowRun $detailRun */
        $detailRun = WorkflowRun::query()->findOrFail($runId);
        $detail = RunDetailView::forRun($detailRun);
        $taskDetail = collect($detail['tasks'])->firstWhere('id', $repairedTask->id);

        $this->assertIsArray($taskDetail);
        $this->assertSame('Workflow task ready to apply accepted signal.', $taskDetail['summary']);
        $this->assertSame('signal', $taskDetail['workflow_wait_kind']);
        $this->assertSame($signal->id, $taskDetail['workflow_signal_id']);

        $this->runReadyWorkflowTask($runId);

        $this->assertDatabaseHas('workflow_signal_records', [
            'id' => $signal->id,
            'status' => 'applied',
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
            ->assertJsonPath('target_scope', 'instance')
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
            ->assertJsonPath('target_scope', 'instance')
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

    public function testRepairWebhookRejectsReservedInstanceThatHasNotStarted(): void
    {
        WorkflowStub::make(TestGreetingWorkflow::class, 'order-repair-reserved');

        $response = $this->postJson('/webhooks/instances/order-repair-reserved/repair');

        $response
            ->assertStatus(409)
            ->assertJsonPath('outcome', 'rejected_not_started')
            ->assertJsonPath('workflow_id', 'order-repair-reserved')
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

    public function testCancelWebhookAcceptsReasonInRequestBody(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'cancel-with-reason');
        $workflow->start(5);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson('/webhooks/instances/cancel-with-reason/cancel', [
            'reason' => 'Operator: duplicate order',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('outcome', 'cancelled')
            ->assertJsonPath('reason', 'Operator: duplicate order')
            ->assertJsonPath('workflow_id', 'cancel-with-reason')
            ->assertJsonPath('command_status', 'accepted');

        $command = WorkflowCommand::query()->findOrFail($response->json('command_id'));

        $this->assertSame('Operator: duplicate order', $command->commandReason());
    }

    public function testTerminateWebhookAcceptsReasonInRequestBody(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'terminate-with-reason');
        $workflow->start(5);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson('/webhooks/instances/terminate-with-reason/terminate', [
            'reason' => 'Emergency maintenance window',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('outcome', 'terminated')
            ->assertJsonPath('reason', 'Emergency maintenance window')
            ->assertJsonPath('workflow_id', 'terminate-with-reason')
            ->assertJsonPath('command_status', 'accepted');

        $command = WorkflowCommand::query()->findOrFail($response->json('command_id'));

        $this->assertSame('Emergency maintenance window', $command->commandReason());
    }

    // ── Describe webhooks ────────────────────────────────────────────

    public function testDescribeWebhookReturnsActiveWorkflowState(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'test-greeting-workflow' => TestGreetingWorkflow::class,
        ]);

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'describe-active');
        $workflow->start('Taylor');

        $response = $this->getJson('/webhooks/instances/describe-active/describe');

        $response
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('workflow_instance_id', 'describe-active')
            ->assertJsonPath('workflow_type', 'test-greeting-workflow')
            ->assertJsonPath('run.workflow_run_id', $workflow->runId())
            ->assertJsonPath('run.run_number', 1)
            ->assertJsonPath('run.is_current_run', true)
            ->assertJsonPath('run.status_bucket', 'running')
            ->assertJsonPath('run.closed_at', null)
            ->assertJsonPath('actions.can_signal', true)
            ->assertJsonPath('actions.can_query', true)
            ->assertJsonPath('actions.can_update', true)
            ->assertJsonPath('actions.can_cancel', true)
            ->assertJsonPath('actions.can_terminate', true)
            ->assertJsonPath('reason', null);
    }

    public function testDescribeWebhookReturnsTerminatedWorkflowState(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'describe-terminated');
        $workflow->start(5);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $workflow->terminate();

        $this->waitFor(static fn (): bool => $workflow->refresh()->terminated());

        $response = $this->getJson('/webhooks/instances/describe-terminated/describe');

        $response
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('workflow_instance_id', 'describe-terminated')
            ->assertJsonPath('run.status', 'terminated')
            ->assertJsonPath('run.status_bucket', 'failed')
            ->assertJsonPath('actions.can_signal', false)
            ->assertJsonPath('actions.can_cancel', false)
            ->assertJsonPath('actions.can_terminate', false);
    }

    public function testDescribeWebhookReturns404ForNonExistentInstance(): void
    {
        $response = $this->getJson('/webhooks/instances/nonexistent-describe/describe');

        $response
            ->assertStatus(404)
            ->assertJsonPath('found', false)
            ->assertJsonPath('workflow_instance_id', 'nonexistent-describe')
            ->assertJsonPath('run', null)
            ->assertJsonPath('reason', 'instance_not_found');
    }

    public function testDescribeWebhookWithSpecificRunId(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'describe-run-select');
        $workflow->start('Taylor');

        $runId = $workflow->runId();

        $response = $this->getJson("/webhooks/instances/describe-run-select/runs/{$runId}/describe");

        $response
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('workflow_instance_id', 'describe-run-select')
            ->assertJsonPath('run.workflow_run_id', $runId)
            ->assertJsonPath('run.is_current_run', true);
    }

    public function testDescribeWebhookRunTargetedReturns404ForNonExistentRun(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'describe-bad-run');
        $workflow->start('Taylor');

        $response = $this->getJson('/webhooks/instances/describe-bad-run/runs/nonexistent-run-id/describe');

        $response
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('workflow_instance_id', 'describe-bad-run')
            ->assertJsonPath('run', null)
            ->assertJsonPath('reason', 'run_not_found');
    }

    public function testWorkflowTaskClaimWebhookReturnsStructuredClaimPayload(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'wf-task-claim-webhook');
        $workflow->start('Taylor');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $response = $this->postJson("/webhooks/workflow-tasks/{$task->id}/claim", [
            'lease_owner' => 'server-worker-1',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('claimed', true)
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('workflow_instance_id', 'wf-task-claim-webhook')
            ->assertJsonPath('workflow_run_id', $workflow->runId())
            ->assertJsonPath('lease_owner', 'server-worker-1')
            ->assertJsonPath('reason', null);

        $this->assertDatabaseHas('workflow_tasks', [
            'id' => $task->id,
            'status' => TaskStatus::Leased->value,
            'lease_owner' => 'server-worker-1',
        ]);
    }

    public function testWorkflowTaskClaimWebhookReturns404ForMissingTask(): void
    {
        $response = $this->postJson('/webhooks/workflow-tasks/nonexistent-task-id/claim', [
            'lease_owner' => 'server-worker-1',
        ]);

        $response
            ->assertStatus(404)
            ->assertJsonPath('claimed', false)
            ->assertJsonPath('task_id', 'nonexistent-task-id')
            ->assertJsonPath('reason', 'task_not_found');
    }

    public function testWorkflowTaskHistoryWebhookReturnsFullHistoryPayload(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'wf-task-history-webhook');
        $workflow->start('Taylor');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $response = $this->getJson("/webhooks/workflow-tasks/{$task->id}/history");

        $response
            ->assertOk()
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('workflow_run_id', $workflow->runId())
            ->assertJsonPath('workflow_instance_id', 'wf-task-history-webhook')
            ->assertJsonPath('workflow_type', 'test-greeting-workflow')
            ->assertJsonPath('run_status', 'pending');

        $events = $response->json('history_events');

        $this->assertIsArray($events);
        $this->assertNotEmpty($events);
        $this->assertSame('StartAccepted', $events[0]['event_type']);
    }

    public function testWorkflowTaskHistoryWebhookReturns404ForMissingTask(): void
    {
        $response = $this->getJson('/webhooks/workflow-tasks/nonexistent-task-id/history');

        $response
            ->assertStatus(404)
            ->assertJsonPath('task_id', 'nonexistent-task-id')
            ->assertJsonPath('reason', 'task_not_found');
    }

    public function testWorkflowTaskExecuteWebhookClaimsAndExecutesTask(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'wf-task-execute-webhook');
        $workflow->start('Taylor');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $response = $this->postJson("/webhooks/workflow-tasks/{$task->id}/execute");

        $response
            ->assertOk()
            ->assertJsonPath('executed', true)
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('workflow_run_id', $workflow->runId())
            ->assertJsonPath('reason', null);

        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => 'ActivityScheduled',
        ]);
    }

    public function testWorkflowTaskExecuteWebhookProceedsForAlreadyLeasedTask(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'wf-task-execute-leased');
        $workflow->start('Taylor');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $task->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'other-worker',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ])->save();

        $response = $this->postJson("/webhooks/workflow-tasks/{$task->id}/execute");

        $response
            ->assertOk()
            ->assertJsonPath('executed', true)
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('reason', null);
    }

    public function testWorkflowTaskExecuteWebhookReturns409ForCompletedTask(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'wf-task-execute-completed');
        $workflow->start('Taylor');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $task->forceFill([
            'status' => TaskStatus::Completed,
        ])->save();

        $response = $this->postJson("/webhooks/workflow-tasks/{$task->id}/execute");

        $response
            ->assertStatus(409)
            ->assertJsonPath('executed', false)
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('reason', 'claim_failed');
    }

    public function testWorkflowTaskCompleteWebhookAppliesNonTerminalCommands(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'wf-task-complete-webhook');
        $workflow->start('Taylor');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $this->postJson("/webhooks/workflow-tasks/{$task->id}/claim", [
            'lease_owner' => 'server-complete-worker',
        ])->assertOk();

        $response = $this->postJson("/webhooks/workflow-tasks/{$task->id}/complete", [
            'commands' => [
                [
                    'type' => 'schedule_activity',
                    'activity_type' => 'test-greeting-activity',
                ],
                [
                    'type' => 'start_timer',
                    'delay_seconds' => 60,
                ],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('completed', true)
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('workflow_run_id', $workflow->runId())
            ->assertJsonPath('run_status', 'waiting')
            ->assertJsonPath('reason', null);

        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => 'ActivityScheduled',
        ]);
        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => 'TimerScheduled',
        ]);
    }

    public function testWorkflowTaskCompleteWebhookAppliesMetadataCommands(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'wf-task-complete-metadata');
        $workflow->start('Taylor');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $this->postJson("/webhooks/workflow-tasks/{$task->id}/claim", [
            'lease_owner' => 'server-metadata-worker',
        ])->assertOk();

        $response = $this->postJson("/webhooks/workflow-tasks/{$task->id}/complete", [
            'commands' => [
                [
                    'type' => 'record_side_effect',
                    'result' => Serializer::serialize([
                        'seed' => 123,
                    ]),
                ],
                [
                    'type' => 'upsert_search_attributes',
                    'attributes' => [
                        'env' => 'staging',
                        'tenant' => 'acme',
                    ],
                ],
                [
                    'type' => 'record_version_marker',
                    'change_id' => 'external-step',
                    'version' => 2,
                    'min_supported' => 1,
                    'max_supported' => 2,
                ],
                [
                    'type' => 'complete_workflow',
                    'result' => serialize('done'),
                ],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('completed', true)
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('workflow_run_id', $workflow->runId())
            ->assertJsonPath('run_status', 'completed')
            ->assertJsonPath('reason', null);

        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => 'SideEffectRecorded',
        ]);
        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => 'SearchAttributesUpserted',
        ]);
        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => 'VersionMarkerRecorded',
        ]);

        $summary = WorkflowRunSummary::query()
            ->whereKey($workflow->runId())
            ->first();

        $this->assertNotNull($summary);
        $this->assertSame([
            'env' => 'staging',
            'tenant' => 'acme',
        ], $summary->search_attributes);
    }

    public function testWorkflowTaskCompleteWebhookAppliesTerminalCommand(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'wf-task-complete-terminal');
        $workflow->start('Taylor');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $this->postJson("/webhooks/workflow-tasks/{$task->id}/claim", [
            'lease_owner' => 'server-terminal-worker',
        ])->assertOk();

        $response = $this->postJson("/webhooks/workflow-tasks/{$task->id}/complete", [
            'commands' => [
                [
                    'type' => 'complete_workflow',
                    'result' => serialize('Hello from external worker!'),
                ],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('completed', true)
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('workflow_run_id', $workflow->runId())
            ->assertJsonPath('run_status', 'completed')
            ->assertJsonPath('reason', null);

        $this->assertDatabaseHas('workflow_runs', [
            'id' => $workflow->runId(),
            'status' => RunStatus::Completed->value,
        ]);
        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => 'WorkflowCompleted',
        ]);
    }

    public function testWorkflowTaskCompleteWebhookReturns422ForEmptyCommands(): void
    {
        $response = $this->postJson('/webhooks/workflow-tasks/some-task/complete', [
            'commands' => [],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['commands']);
    }

    public function testWorkflowTaskCompleteWebhookReturns422ForMissingCommands(): void
    {
        $response = $this->postJson('/webhooks/workflow-tasks/some-task/complete', []);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['commands']);
    }

    public function testWorkflowTaskFailWebhookRecordsTaskFailure(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'wf-task-fail-webhook');
        $workflow->start('Taylor');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $response = $this->postJson("/webhooks/workflow-tasks/{$task->id}/fail", [
            'failure' => 'Worker crashed during replay',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('reason', null);

        $this->assertDatabaseHas('workflow_tasks', [
            'id' => $task->id,
            'status' => TaskStatus::Failed->value,
            'last_error' => 'Worker crashed during replay',
        ]);
    }

    public function testWorkflowTaskFailWebhookAcceptsStructuredFailurePayload(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'wf-task-fail-structured');
        $workflow->start('Taylor');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $response = $this->postJson("/webhooks/workflow-tasks/{$task->id}/fail", [
            'failure' => [
                'message' => 'History too large',
                'class' => 'RuntimeException',
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('reason', null);

        $this->assertDatabaseHas('workflow_tasks', [
            'id' => $task->id,
            'status' => TaskStatus::Failed->value,
        ]);
    }

    public function testWorkflowTaskFailWebhookReturns404ForMissingTask(): void
    {
        $response = $this->postJson('/webhooks/workflow-tasks/nonexistent-task-id/fail', [
            'failure' => 'Something broke',
        ]);

        $response
            ->assertStatus(404)
            ->assertJsonPath('recorded', false)
            ->assertJsonPath('task_id', 'nonexistent-task-id')
            ->assertJsonPath('reason', 'task_not_found');
    }

    public function testWorkflowTaskFailWebhookReturns422ForMissingFailureField(): void
    {
        $response = $this->postJson('/webhooks/workflow-tasks/some-task/fail', []);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['failure']);
    }

    public function testWorkflowTaskHeartbeatWebhookExtendsLease(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'wf-task-heartbeat-webhook');
        $workflow->start('Taylor');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $this->postJson("/webhooks/workflow-tasks/{$task->id}/claim", [
            'lease_owner' => 'server-heartbeat-worker',
        ])->assertOk();

        $response = $this->postJson("/webhooks/workflow-tasks/{$task->id}/heartbeat");

        $response
            ->assertOk()
            ->assertJsonPath('renewed', true)
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('task_status', TaskStatus::Leased->value)
            ->assertJsonPath('reason', null);

        $this->assertNotNull($response->json('lease_expires_at'));
    }

    public function testWorkflowTaskHeartbeatWebhookReturns404ForMissingTask(): void
    {
        $response = $this->postJson('/webhooks/workflow-tasks/nonexistent-task-id/heartbeat');

        $response
            ->assertStatus(404)
            ->assertJsonPath('renewed', false)
            ->assertJsonPath('task_id', 'nonexistent-task-id')
            ->assertJsonPath('reason', 'task_not_found');
    }

    public function testWorkflowTaskHeartbeatWebhookReturns409ForUnleasedTask(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'wf-task-heartbeat-unleased');
        $workflow->start('Taylor');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $response = $this->postJson("/webhooks/workflow-tasks/{$task->id}/heartbeat");

        $response
            ->assertStatus(409)
            ->assertJsonPath('renewed', false)
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('reason', 'task_not_leased');
    }

    private function runReadyWorkflowTask(string $runId): void
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->firstOrFail();

        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }

    private function waitFor(callable $condition): void
    {
        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }

            $this->drainReadyTasks();

            if ($condition()) {
                return;
            }

            usleep(100000);
        }

        $this->fail('Timed out waiting for workflow to settle.');
    }

    private function configureUnsupportedSyncTaskConnection(): void
    {
        $this->configureAsyncRedisTaskConnection();
        config()
            ->set('queue.connections.sync.driver', 'sync');
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
    }

    private function configureAsyncRedisTaskConnection(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
    }

    private function stageFirstActivityTask(WorkflowStub $workflow): WorkflowTask
    {
        /** @var WorkflowTask $workflowTask */
        $workflowTask = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->firstOrFail();

        $this->app->call([new RunWorkflowTask($workflowTask->id), 'handle']);

        /** @var WorkflowTask $activityTask */
        $activityTask = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Activity->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->firstOrFail();

        return $activityTask;
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

            if ($task->available_at !== null && $task->available_at->isFuture()) {
                return;
            }

            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new RunTimerTask($task->id),
            };

            $this->app->call([$job, 'handle']);
        }

        $this->fail('Timed out draining ready workflow tasks.');
    }
}
