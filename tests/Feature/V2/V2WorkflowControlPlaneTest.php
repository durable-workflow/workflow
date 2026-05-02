<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestQueryContinueAsNewWorkflow;
use Tests\Fixtures\V2\TestQueryWorkflow;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\Fixtures\V2\TestUpdateWorkflow;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\CommandContext;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\HistoryEventType;
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
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultHistoryProjectionRole;
use Workflow\V2\Support\DefaultWorkflowControlPlane;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\WorkflowStub;

final class V2WorkflowControlPlaneTest extends TestCase
{
    private WorkflowControlPlane $controlPlane;

    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        $this->controlPlane = $this->app->make(WorkflowControlPlane::class);
    }

    public function testControlPlaneIsResolvableFromContainer(): void
    {
        $controlPlane = $this->app->make(WorkflowControlPlane::class);

        $this->assertInstanceOf(DefaultWorkflowControlPlane::class, $controlPlane);
    }

    public function testStartWithLocallyResolvableClass(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'test-greeting-workflow' => TestGreetingWorkflow::class,
        ]);

        $result = $this->controlPlane->start('test-greeting-workflow', 'ctrl-plane-test-1', [
            'arguments' => Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), ['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $this->assertTrue($result['started']);
        $this->assertSame('ctrl-plane-test-1', $result['workflow_instance_id']);
        $this->assertNotNull($result['workflow_run_id']);
        $this->assertSame('test-greeting-workflow', $result['workflow_type']);
        $this->assertSame('started_new', $result['outcome']);
        $this->assertNotNull($result['task_id']);
        $this->assertNull($result['reason']);

        $instance = WorkflowInstance::query()->find('ctrl-plane-test-1');
        $this->assertNotNull($instance);
        $this->assertSame('test-greeting-workflow', $instance->workflow_type);

        $run = WorkflowRun::query()->find($result['workflow_run_id']);
        $this->assertNotNull($run);
        $this->assertSame(RunStatus::Pending, $run->status);

        $task = WorkflowTask::query()->find($result['task_id']);
        $this->assertNotNull($task);
        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(TaskType::Workflow, $task->task_type);

        $startedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->first();
        $this->assertNotNull($startedEvent);
        $this->assertArrayHasKey('declared_signals', $startedEvent->payload);
    }

    public function testStartWithTypeKeyOnly(): void
    {
        $result = $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-test-2', [
            'arguments' => Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), ['arg1']),
            'connection' => 'redis',
            'queue' => 'remote-workers',
        ]);

        $this->assertTrue($result['started']);
        $this->assertSame('ctrl-plane-test-2', $result['workflow_instance_id']);
        $this->assertNotNull($result['workflow_run_id']);
        $this->assertSame('remote-workflow-type', $result['workflow_type']);
        $this->assertSame('started_new', $result['outcome']);
        $this->assertNotNull($result['task_id']);

        $instance = WorkflowInstance::query()->find('ctrl-plane-test-2');
        $this->assertNotNull($instance);
        $this->assertSame('remote-workflow-type', $instance->workflow_type);

        $run = WorkflowRun::query()->find($result['workflow_run_id']);
        $this->assertNotNull($run);
        $this->assertSame('redis', $run->connection);
        $this->assertSame('remote-workers', $run->queue);
        $this->assertSame('remote-workflow-type', $run->workflow_type);
        $this->assertSame('remote-workflow-type', $run->workflow_class);

        $startedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->first();
        $this->assertNotNull($startedEvent);
        $this->assertArrayNotHasKey('declared_signals', $startedEvent->payload);
    }

    public function testStartUsesHistoryProjectionRoleBindingForNewRunProjection(): void
    {
        $customRole = new class(new DefaultHistoryProjectionRole()) implements HistoryProjectionRole {
            public array $calls = [];

            public function __construct(
                private readonly DefaultHistoryProjectionRole $delegate,
            ) {
            }

            public function projectRun(WorkflowRun $run): WorkflowRunSummary
            {
                $this->calls[] = ['projectRun', $run->id];

                return $this->delegate->projectRun($run);
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                \Workflow\V2\Models\ActivityExecution $execution,
                \Workflow\V2\Models\ActivityAttempt $attempt,
                WorkflowTask $task,
            ): WorkflowRunSummary {
                return $this->delegate->recordActivityStarted($run, $execution, $attempt, $task);
            }
        };

        $this->app->instance(HistoryProjectionRole::class, $customRole);

        $result = $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-history-role-start-1', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $this->assertTrue($result['started']);
        $this->assertSame([['projectRun', $result['workflow_run_id']]], $customRole->calls);
    }

    public function testStartWithAutoGeneratedInstanceId(): void
    {
        $result = $this->controlPlane->start('remote-workflow-type', null, [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $this->assertTrue($result['started']);
        $this->assertNotEmpty($result['workflow_instance_id']);
        $this->assertNotNull($result['workflow_run_id']);
    }

    public function testStartSupportsCommandContext(): void
    {
        $result = $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-start-context-1', [
            'connection' => 'redis',
            'queue' => 'default',
            'command_context' => CommandContext::controlPlane()->with([
                'caller' => [
                    'type' => 'server',
                    'label' => 'Standalone Server',
                ],
                'server' => [
                    'namespace' => 'default',
                    'command' => 'start',
                ],
            ]),
        ]);

        $this->assertTrue($result['started']);

        $command = WorkflowCommand::query()
            ->where('workflow_instance_id', 'ctrl-plane-start-context-1')
            ->where('outcome', 'started_new')
            ->firstOrFail();

        $this->assertSame('control_plane', $command->source);
        $this->assertSame('server', $command->commandContext()['caller']['type'] ?? null);
        $this->assertSame('Standalone Server', $command->commandContext()['caller']['label'] ?? null);
        $this->assertSame('default', $command->commandContext()['server']['namespace'] ?? null);
        $this->assertSame('start', $command->commandContext()['server']['command'] ?? null);
    }

    public function testStartFailsClosedWhenOnlyIncompatibleLiveWorkersExist(): void
    {
        config()->set('workflows.v2.fleet.validation_mode', 'fail');
        WorkerCompatibilityFleet::clear();
        WorkerCompatibilityFleet::record(['build-b'], 'redis', 'default', 'worker-build-b');

        $result = $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-compat-blocked', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $this->assertFalse($result['started']);
        $this->assertSame('ctrl-plane-compat-blocked', $result['workflow_instance_id']);
        $this->assertNull($result['workflow_run_id']);
        $this->assertSame('rejected_compatibility_blocked', $result['outcome']);
        $this->assertSame('compatibility_blocked', $result['reason']);
        $this->assertSame(
            'Workflow instance [ctrl-plane-compat-blocked] cannot start. Start blocked under fail validation mode. '
            . 'No active worker heartbeat for connection [redis] queue [default] advertises compatibility [build-a]. '
            . 'Active workers there advertise [build-b].',
            $result['message'],
        );
        $this->assertNull($result['task_id']);
        $this->assertSame(0, WorkflowRun::query()->count());
    }

    public function testStartRejectsDuplicate(): void
    {
        $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-dup-1', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $result = $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-dup-1', [
            'command_context' => CommandContext::controlPlane()->with([
                'caller' => [
                    'type' => 'server',
                    'label' => 'Standalone Server',
                ],
                'server' => [
                    'namespace' => 'default',
                    'command' => 'start',
                ],
            ]),
        ]);

        $this->assertFalse($result['started']);
        $this->assertSame('ctrl-plane-dup-1', $result['workflow_instance_id']);
        $this->assertSame('rejected_duplicate', $result['outcome']);
        $this->assertSame('instance_already_started', $result['reason']);

        $command = WorkflowCommand::query()
            ->where('workflow_instance_id', 'ctrl-plane-dup-1')
            ->where('outcome', 'rejected_duplicate')
            ->firstOrFail();

        $this->assertSame('server', $command->commandContext()['caller']['type'] ?? null);
        $this->assertSame('default', $command->commandContext()['server']['namespace'] ?? null);
    }

    public function testStartReturnExistingActive(): void
    {
        $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-existing-1', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $result = $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-existing-1', [
            'duplicate_start_policy' => 'return_existing_active',
        ]);

        $this->assertTrue($result['started']);
        $this->assertSame('ctrl-plane-existing-1', $result['workflow_instance_id']);
        $this->assertSame('returned_existing_active', $result['outcome']);
    }

    public function testStartUsesHistoryProjectionRoleBindingForExistingRunProjection(): void
    {
        $first = $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-existing-history-role-1', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $customRole = new class(new DefaultHistoryProjectionRole()) implements HistoryProjectionRole {
            public array $calls = [];

            public function __construct(
                private readonly DefaultHistoryProjectionRole $delegate,
            ) {
            }

            public function projectRun(WorkflowRun $run): WorkflowRunSummary
            {
                $this->calls[] = ['projectRun', $run->id];

                return $this->delegate->projectRun($run);
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                \Workflow\V2\Models\ActivityExecution $execution,
                \Workflow\V2\Models\ActivityAttempt $attempt,
                WorkflowTask $task,
            ): WorkflowRunSummary {
                return $this->delegate->recordActivityStarted($run, $execution, $attempt, $task);
            }
        };

        $this->app->instance(HistoryProjectionRole::class, $customRole);

        $result = $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-existing-history-role-1', [
            'duplicate_start_policy' => 'return_existing_active',
        ]);

        $this->assertTrue($result['started']);
        $this->assertSame('returned_existing_active', $result['outcome']);
        $this->assertSame([['projectRun', $first['workflow_run_id']]], $customRole->calls);
    }

    public function testRunWorkflowTaskUsesHistoryProjectionRoleBindingDuringExecution(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'test-greeting-workflow' => TestGreetingWorkflow::class,
        ]);

        $start = $this->controlPlane->start('test-greeting-workflow', 'ctrl-plane-history-role-execution-1', [
            'arguments' => Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), ['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $customRole = new class(new DefaultHistoryProjectionRole()) implements HistoryProjectionRole {
            public array $calls = [];

            public function __construct(
                private readonly DefaultHistoryProjectionRole $delegate,
            ) {
            }

            public function projectRun(WorkflowRun $run): WorkflowRunSummary
            {
                $this->calls[] = ['projectRun', $run->id];

                return $this->delegate->projectRun($run);
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                \Workflow\V2\Models\ActivityExecution $execution,
                \Workflow\V2\Models\ActivityAttempt $attempt,
                WorkflowTask $task,
            ): WorkflowRunSummary {
                return $this->delegate->recordActivityStarted($run, $execution, $attempt, $task);
            }
        };

        $this->app->instance(HistoryProjectionRole::class, $customRole);
        $this->app->call([new RunWorkflowTask((string) $start['task_id']), 'handle']);

        $this->assertGreaterThanOrEqual(2, count($customRole->calls));
        $this->assertContains(['projectRun', $start['workflow_run_id']], $customRole->calls);
    }

    public function testCancelActiveWorkflow(): void
    {
        $start = $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-cancel-1', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $result = $this->controlPlane->cancel('ctrl-plane-cancel-1', [
            'reason' => 'Testing cancellation',
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame('ctrl-plane-cancel-1', $result['workflow_instance_id']);
        $this->assertNotNull($result['workflow_command_id']);
        $this->assertNull($result['reason']);
    }

    public function testTerminateActiveWorkflow(): void
    {
        $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-term-1', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $result = $this->controlPlane->terminate('ctrl-plane-term-1', [
            'reason' => 'Testing termination',
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame('ctrl-plane-term-1', $result['workflow_instance_id']);
        $this->assertNotNull($result['workflow_command_id']);
        $this->assertNull($result['reason']);
    }

    public function testCancelNonExistentInstance(): void
    {
        $result = $this->controlPlane->cancel('nonexistent-instance');

        $this->assertFalse($result['accepted']);
        $this->assertNotNull($result['reason']);
    }

    public function testTerminateAlreadyClosedWorkflow(): void
    {
        $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-closed-1', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $this->controlPlane->terminate('ctrl-plane-closed-1');

        $result = $this->controlPlane->terminate('ctrl-plane-closed-1');

        $this->assertFalse($result['accepted']);
        $this->assertNotNull($result['reason']);
    }

    public function testSignalWithDurableContract(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'test-greeting-workflow' => TestGreetingWorkflow::class,
        ]);

        $this->controlPlane->start('test-greeting-workflow', 'ctrl-plane-signal-1', [
            'arguments' => Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), ['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $result = $this->controlPlane->signal('ctrl-plane-signal-1', 'nonexistent_signal');

        $this->assertFalse($result['accepted']);
        $this->assertNotNull($result['reason']);
    }

    public function testQueryNonExistentInstance(): void
    {
        $result = $this->controlPlane->query('nonexistent-instance', 'getStatus');

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['reason']);
    }

    public function testUpdateNonExistentInstance(): void
    {
        $result = $this->controlPlane->update('nonexistent-instance', 'setName');

        $this->assertFalse($result['accepted']);
        $this->assertNotNull($result['reason']);
    }

    public function testStartWithExplicitRoutingOverridesClassDefaults(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'test-greeting-workflow' => TestGreetingWorkflow::class,
        ]);

        $result = $this->controlPlane->start('test-greeting-workflow', 'ctrl-plane-routing-1', [
            'arguments' => Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), ['Taylor']),
            'connection' => 'redis',
            'queue' => 'custom-queue',
        ]);

        $this->assertTrue($result['started']);

        $run = WorkflowRun::query()->find($result['workflow_run_id']);
        $this->assertSame('redis', $run->connection);
        $this->assertSame('custom-queue', $run->queue);
    }

    public function testStartRecordsBusinessKeyAndLabels(): void
    {
        $result = $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-meta-1', [
            'connection' => 'redis',
            'queue' => 'default',
            'business_key' => 'order-12345',
            'labels' => [
                'team' => 'payments',
                'env' => 'staging',
            ],
            'memo' => [
                'description' => 'Test workflow',
            ],
        ]);

        $this->assertTrue($result['started']);

        $run = WorkflowRun::query()->find($result['workflow_run_id']);
        $this->assertSame('order-12345', $run->business_key);
        $this->assertSameJsonObject([
            'team' => 'payments',
            'env' => 'staging',
        ], $run->visibility_labels);
        $this->assertSameJsonObject([
            'description' => 'Test workflow',
        ], $run->typedMemos());
    }

    public function testStartResolvesDottedDurableTypeKey(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'tests.external-greeting-workflow' => TestGreetingWorkflow::class,
        ]);

        $result = $this->controlPlane->start('tests.external-greeting-workflow', 'ctrl-plane-dotted-1', [
            'arguments' => Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), ['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $this->assertTrue($result['started']);
        $this->assertSame('ctrl-plane-dotted-1', $result['workflow_instance_id']);
        $this->assertSame('tests.external-greeting-workflow', $result['workflow_type']);
        $this->assertNotNull($result['task_id']);

        $run = WorkflowRun::query()->find($result['workflow_run_id']);
        $this->assertNotNull($run);
        $this->assertSame(TestGreetingWorkflow::class, $run->workflow_class);
        $this->assertSame('tests.external-greeting-workflow', $run->workflow_type);

        // Dotted type key resolves to the real class, so command contract is captured.
        $startedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->first();
        $this->assertNotNull($startedEvent);
        $this->assertArrayHasKey('declared_signals', $startedEvent->payload);
    }

    public function testStartThenPollReturnsSameTickTask(): void
    {
        $startResult = $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-poll-1', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $this->assertTrue($startResult['started']);
        $this->assertNotNull($startResult['task_id']);

        // Poll immediately in the same request tick — task must be visible.
        $bridge = $this->app->make(\Workflow\V2\Contracts\WorkflowTaskBridge::class);
        $polled = $bridge->poll('redis', 'default');

        $this->assertNotEmpty($polled, 'Same-tick workflow task must be visible to poll().');

        $polledTaskIds = array_column($polled, 'task_id');
        $this->assertContains($startResult['task_id'], $polledTaskIds);
    }

    // ── Describe ────────────────────────────────────────────────────

    public function testDescribeActiveWorkflow(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'test-greeting-workflow' => TestGreetingWorkflow::class,
        ]);

        $start = $this->controlPlane->start('test-greeting-workflow', 'ctrl-plane-desc-1', [
            'arguments' => Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), ['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'business_key' => 'order-100',
        ]);

        $result = $this->controlPlane->describe('ctrl-plane-desc-1');

        $this->assertTrue($result['found']);
        $this->assertSame('ctrl-plane-desc-1', $result['workflow_instance_id']);
        $this->assertSame('test-greeting-workflow', $result['workflow_type']);
        $this->assertSame('order-100', $result['business_key']);
        $this->assertSame(1, $result['run_count']);
        $this->assertNull($result['reason']);

        $this->assertNotNull($result['run']);
        $this->assertSame($start['workflow_run_id'], $result['run']['workflow_run_id']);
        $this->assertSame(1, $result['run']['run_number']);
        $this->assertTrue($result['run']['is_current_run']);
        $this->assertSame('running', $result['run']['status_bucket']);
        $this->assertNull($result['run']['closed_at']);

        $this->assertTrue($result['actions']['can_signal']);
        $this->assertTrue($result['actions']['can_query']);
        $this->assertTrue($result['actions']['can_update']);
        $this->assertTrue($result['actions']['can_cancel']);
        $this->assertTrue($result['actions']['can_terminate']);
    }

    public function testDescribeTerminatedWorkflow(): void
    {
        $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-desc-term-1', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $this->controlPlane->terminate('ctrl-plane-desc-term-1', [
            'reason' => 'testing',
        ]);

        $result = $this->controlPlane->describe('ctrl-plane-desc-term-1');

        $this->assertTrue($result['found']);
        $this->assertNotNull($result['run']);
        $this->assertSame('terminated', $result['run']['status']);
        $this->assertSame('failed', $result['run']['status_bucket']);

        $this->assertFalse($result['actions']['can_signal']);
        $this->assertFalse($result['actions']['can_query']);
        $this->assertFalse($result['actions']['can_update']);
        $this->assertFalse($result['actions']['can_cancel']);
        $this->assertFalse($result['actions']['can_terminate']);
    }

    public function testDescribeNonExistentInstance(): void
    {
        $result = $this->controlPlane->describe('nonexistent-desc-1');

        $this->assertFalse($result['found']);
        $this->assertSame('nonexistent-desc-1', $result['workflow_instance_id']);
        $this->assertNull($result['run']);
        $this->assertSame('instance_not_found', $result['reason']);
    }

    public function testDescribeRemoteOnlyWorkflow(): void
    {
        $start = $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-desc-remote-1', [
            'connection' => 'redis',
            'queue' => 'remote-workers',
        ]);

        $result = $this->controlPlane->describe('ctrl-plane-desc-remote-1');

        $this->assertTrue($result['found']);
        $this->assertSame('remote-workflow-type', $result['workflow_type']);
        $this->assertNotNull($result['run']);
        $this->assertTrue($result['run']['is_current_run']);

        // Remote-only workflows cannot serve queries or updates locally.
        $this->assertTrue($result['actions']['can_signal']);
        $this->assertFalse($result['actions']['can_query']);
        $this->assertFalse($result['actions']['can_update']);
        $this->assertTrue($result['actions']['can_cancel']);
        $this->assertTrue($result['actions']['can_terminate']);
    }

    // ── Signal happy path ───────────────────────────────────────────

    public function testSignalAcceptedForDeclaredSignal(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'test-signal-workflow' => TestSignalWorkflow::class,
        ]);

        $this->controlPlane->start('test-signal-workflow', 'ctrl-plane-sig-ok-1', [
            'queue' => 'default',
        ]);

        // Execute the initial workflow task so the run enters Waiting state.
        $bridge = $this->app->make(\Workflow\V2\Contracts\WorkflowTaskBridge::class);
        $polled = $bridge->poll(null, 'default');
        $this->assertNotEmpty($polled);
        $bridge->execute($polled[0]['task_id']);

        $result = $this->controlPlane->signal('ctrl-plane-sig-ok-1', 'name-provided', [
            'arguments' => ['Taylor'],
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame('ctrl-plane-sig-ok-1', $result['workflow_instance_id']);
        $this->assertNotNull($result['workflow_command_id']);
        $this->assertNull($result['reason']);
    }

    public function testSignalSupportsCommandContextAndDetailedResponsePayload(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'test-signal-workflow' => TestSignalWorkflow::class,
        ]);

        $this->controlPlane->start('test-signal-workflow', 'ctrl-plane-sig-detailed-1', [
            'queue' => 'default',
        ]);

        $bridge = $this->app->make(\Workflow\V2\Contracts\WorkflowTaskBridge::class);
        $polled = $bridge->poll(null, 'default');
        $this->assertNotEmpty($polled);
        $bridge->execute($polled[0]['task_id']);

        $result = $this->controlPlane->signal('ctrl-plane-sig-detailed-1', 'name-provided', [
            'arguments' => ['Taylor'],
            'command_context' => CommandContext::controlPlane()->with([
                'caller' => [
                    'type' => 'server',
                    'label' => 'Standalone Server',
                ],
                'server' => [
                    'namespace' => 'default',
                    'command' => 'signal',
                ],
            ]),
            'strict_configured_type_validation' => true,
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame(202, $result['status']);
        $this->assertSame('name-provided', $result['signal_name']);
        $this->assertSame('accepted', $result['command_status']);
        $this->assertSame('control_plane', $result['command_source']);
        $this->assertIsString($result['command_id']);

        $command = WorkflowCommand::query()->findOrFail($result['command_id']);

        $this->assertSame('server', $command->commandContext()['caller']['type'] ?? null);
        $this->assertSame('default', $command->commandContext()['server']['namespace'] ?? null);
    }

    // ── Query happy path ────────────────────────────────────────────

    public function testQueryReturnsResult(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'test-query-workflow' => TestQueryWorkflow::class,
        ]);

        $this->controlPlane->start('test-query-workflow', 'ctrl-plane-query-ok-1', [
            'queue' => 'default',
        ]);

        // Execute the initial workflow task so the run advances and has state.
        $bridge = $this->app->make(\Workflow\V2\Contracts\WorkflowTaskBridge::class);
        $polled = $bridge->poll(null, 'default');
        $this->assertNotEmpty($polled);
        $bridge->execute($polled[0]['task_id']);

        $result = $this->controlPlane->query('ctrl-plane-query-ok-1', 'currentStage');

        $this->assertTrue($result['success']);
        $this->assertSame('ctrl-plane-query-ok-1', $result['workflow_instance_id']);
        $this->assertSame('waiting-for-name', $result['result']);
        $this->assertNull($result['reason']);
    }

    public function testQueryReturnsDetailedPayloadAndValidationErrors(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'test-query-workflow' => TestQueryWorkflow::class,
        ]);

        $this->controlPlane->start('test-query-workflow', 'ctrl-plane-query-detail-1', [
            'queue' => 'default',
        ]);

        $bridge = $this->app->make(\Workflow\V2\Contracts\WorkflowTaskBridge::class);
        $polled = $bridge->poll(null, 'default');
        $this->assertNotEmpty($polled);
        $bridge->execute($polled[0]['task_id']);

        $result = $this->controlPlane->query('ctrl-plane-query-detail-1', 'currentStage', [
            'strict_configured_type_validation' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('ctrl-plane-query-detail-1', $result['workflow_id']);
        $this->assertSame('currentStage', $result['query_name']);
        $this->assertSame('instance', $result['target_scope']);
        $this->assertSame('waiting-for-name', $result['result']);

        $invalid = $this->controlPlane->query('ctrl-plane-query-detail-1', 'events-starting-with', [
            'arguments' => [
                'extra' => 'start',
            ],
            'strict_configured_type_validation' => true,
        ]);

        $this->assertFalse($invalid['success']);
        $this->assertSame(422, $invalid['status']);
        $this->assertSame('events-starting-with', $invalid['query_name']);
        $this->assertSame('invalid_query_arguments', $invalid['reason']);
        $this->assertArrayHasKey('prefix', $invalid['validation_errors']);
        $this->assertArrayHasKey('extra', $invalid['validation_errors']);
    }

    public function testInstanceQueryReadsContinuedCurrentRunAfterContinueAsNew(): void
    {
        $workflow = WorkflowStub::make(TestQueryContinueAsNewWorkflow::class, 'ctrl-plane-query-continue');
        $started = $workflow->start(0, 2);
        $firstRunId = $started->runId();

        $this->assertNotNull($firstRunId);

        $this->drainReadyTasks();
        $this->assertTrue($workflow->refresh()->completed());

        $result = $this->controlPlane->query('ctrl-plane-query-continue', 'currentCount');

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('ctrl-plane-query-continue', $result['workflow_id']);
        $this->assertSame('currentCount', $result['query_name']);
        $this->assertSame('instance', $result['target_scope']);
        $this->assertSame(2, $result['result']);
        $this->assertNotSame($firstRunId, $result['run_id']);
    }

    // ── Update happy path ───────────────────────────────────────────

    public function testUpdateAccepted(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'test-update-workflow' => TestUpdateWorkflow::class,
        ]);

        $this->ensureJobsTable();

        $this->controlPlane->start('test-update-workflow', 'ctrl-plane-update-ok-1', [
            'connection' => 'database',
            'queue' => 'default',
        ]);

        // Execute the initial workflow task so the run advances.
        $bridge = $this->app->make(\Workflow\V2\Contracts\WorkflowTaskBridge::class);
        $polled = $bridge->poll('database', 'default');
        $this->assertNotEmpty($polled);
        $bridge->execute($polled[0]['task_id']);

        $result = $this->controlPlane->update('ctrl-plane-update-ok-1', 'approve', [
            'arguments' => [true, 'control-plane'],
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame('ctrl-plane-update-ok-1', $result['workflow_instance_id']);
        $this->assertNotNull($result['update_id']);
        $this->assertNull($result['reason']);
    }

    public function testUpdateCanWaitForCompletionAndReturnATimeoutPayloadWithoutAWorker(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'test-update-workflow' => TestUpdateWorkflow::class,
        ]);

        $this->ensureJobsTable();

        $this->controlPlane->start('test-update-workflow', 'ctrl-plane-update-detail-1', [
            'connection' => 'database',
            'queue' => 'default',
        ]);

        $bridge = $this->app->make(\Workflow\V2\Contracts\WorkflowTaskBridge::class);
        $polled = $bridge->poll('database', 'default');
        $this->assertNotEmpty($polled);
        $bridge->execute($polled[0]['task_id']);

        $result = $this->controlPlane->update('ctrl-plane-update-detail-1', 'approve', [
            'arguments' => [true, 'control-plane'],
            'wait_for' => 'completed',
            'wait_timeout_seconds' => 1,
            'strict_configured_type_validation' => true,
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame(202, $result['status']);
        $this->assertSame('accepted', $result['command_status']);
        $this->assertSame('accepted', $result['update_status']);
        $this->assertSame('completed', $result['wait_for']);
        $this->assertTrue($result['wait_timed_out']);
        $this->assertSame(1, $result['wait_timeout_seconds']);
        $this->assertSame('approve', $result['update_name']);
        $this->assertNull($result['result']);
    }

    // ── Repair ──────────────────────────────────────────────────────

    public function testRepairActiveWorkflow(): void
    {
        $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-repair-1', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $result = $this->controlPlane->repair('ctrl-plane-repair-1');

        $this->assertTrue($result['accepted']);
        $this->assertSame('ctrl-plane-repair-1', $result['workflow_instance_id']);
        $this->assertNotNull($result['workflow_command_id']);
        $this->assertNull($result['reason']);
    }

    public function testRepairNonExistentInstance(): void
    {
        $result = $this->controlPlane->repair('nonexistent-repair-1');

        $this->assertFalse($result['accepted']);
        $this->assertNotNull($result['reason']);
    }

    public function testRepairTerminatedWorkflowIsRejected(): void
    {
        $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-repair-closed-1', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $this->controlPlane->terminate('ctrl-plane-repair-closed-1');

        $result = $this->controlPlane->repair('ctrl-plane-repair-closed-1');

        $this->assertFalse($result['accepted']);
        $this->assertNotNull($result['reason']);
    }

    // ── Archive ────────────────────────────────────────────────────

    public function testArchiveTerminatedWorkflow(): void
    {
        $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-archive-1', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $this->controlPlane->terminate('ctrl-plane-archive-1');

        $result = $this->controlPlane->archive('ctrl-plane-archive-1', [
            'reason' => 'Testing archival',
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame('ctrl-plane-archive-1', $result['workflow_instance_id']);
        $this->assertNotNull($result['workflow_command_id']);
        $this->assertNull($result['reason']);
    }

    public function testArchiveNonExistentInstance(): void
    {
        $result = $this->controlPlane->archive('nonexistent-archive-1');

        $this->assertFalse($result['accepted']);
        $this->assertNotNull($result['reason']);
    }

    public function testArchiveActiveWorkflowIsRejected(): void
    {
        $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-archive-active-1', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $result = $this->controlPlane->archive('ctrl-plane-archive-active-1');

        $this->assertFalse($result['accepted']);
        $this->assertNotNull($result['reason']);
    }

    // ── Describe with repair/archive actions ───────────────────────

    public function testDescribeActiveWorkflowIncludesRepairAndArchiveActions(): void
    {
        $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-desc-actions-1', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $result = $this->controlPlane->describe('ctrl-plane-desc-actions-1');

        $this->assertTrue($result['found']);
        $this->assertTrue($result['actions']['can_repair']);
        $this->assertFalse($result['actions']['can_archive']);
    }

    public function testDescribeTerminatedWorkflowCanArchive(): void
    {
        $this->controlPlane->start('remote-workflow-type', 'ctrl-plane-desc-archive-1', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $this->controlPlane->terminate('ctrl-plane-desc-archive-1');

        $result = $this->controlPlane->describe('ctrl-plane-desc-archive-1');

        $this->assertTrue($result['found']);
        $this->assertFalse($result['actions']['can_repair']);
        $this->assertTrue($result['actions']['can_archive']);
    }

    private function ensureJobsTable(): void
    {
        if (Schema::hasTable('jobs')) {
            return;
        }

        Schema::create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue')
                ->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')
                ->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
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
