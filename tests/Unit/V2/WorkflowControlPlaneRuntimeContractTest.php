<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Http\Request;
use LogicException;
use Tests\Fixtures\V2\TestQueryWorkflow;
use Tests\Fixtures\V2\TestUpdateWorkflow;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\CommandContext;
use Workflow\V2\CommandResult;
use Workflow\V2\Contracts\RuntimeSignalControlPlane;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\WorkflowStub;

final class WorkflowControlPlaneRuntimeContractTest extends TestCase
{
    private WorkflowControlPlane $controlPlane;

    private RuntimeSignalControlPlane $runtimeControlPlane;

    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
        config()
            ->set('workflows.v2.task_dispatch_mode', 'poll');

        $this->controlPlane = $this->app->make(WorkflowControlPlane::class);
        $this->runtimeControlPlane = $this->app->make(RuntimeSignalControlPlane::class);
    }

    public function testStartPersistsRoutingMetadataDeadlinesAndCallerContext(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'contract-query-workflow' => TestQueryWorkflow::class,
        ]);

        $context = CommandContext::controlPlane()
            ->withIntake('single', 'request-group-1')
            ->withPrincipal('service', 'server-1', 'Standalone Server');
        $arguments = Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), []);

        $result = $this->controlPlane->start('contract-query-workflow', 'unit-control-start', [
            'arguments' => $arguments,
            'payload_codec' => CodecRegistry::defaultCodec(),
            'connection' => 'redis',
            'queue' => 'priority',
            'namespace' => 'tenant-a',
            'business_key' => 'order-42',
            'labels' => [
                'team' => 'payments',
            ],
            'memo' => [
                'description' => 'Priority order',
            ],
            'search_attributes' => [
                'customer' => 'acme',
                'attempt' => 3,
            ],
            'search_attribute_types' => [
                'customer' => 'keyword',
                'attempt' => 'int',
                'ignored' => 'unsupported',
                1 => 'keyword',
            ],
            'execution_timeout_seconds' => 120,
            'run_timeout_seconds' => 60,
            'build_id' => 'build-pinned',
            'priority' => 2,
            'fairness_key' => ' Tenant.Blue ',
            'fairness_weight' => 7,
            'command_context' => $context,
        ]);

        $this->assertTrue($result['started']);
        $this->assertSame('started_new', $result['outcome']);

        $run = WorkflowRun::query()->findOrFail($result['workflow_run_id']);
        $this->assertSame(TestQueryWorkflow::class, $run->workflow_class);
        $this->assertSame('tenant-a', $run->namespace);
        $this->assertSame('order-42', $run->business_key);
        $this->assertSame([
            'team' => 'payments',
        ], $run->visibility_labels);
        $this->assertSame([
            'description' => 'Priority order',
        ], $run->typedMemos());
        $this->assertSame([
            'attempt' => 3,
            'customer' => 'acme',
        ], $run->typedSearchAttributes());
        $this->assertSame('redis', $run->connection);
        $this->assertSame('priority', $run->queue);
        $this->assertSame('build-pinned', $run->compatibility);
        $this->assertSame(2, $run->priority);
        $this->assertSame('tenant.blue', $run->fairness_key);
        $this->assertSame(7, $run->fairness_weight);
        $this->assertNotNull($run->execution_deadline_at);
        $this->assertNotNull($run->run_deadline_at);

        $command = WorkflowCommand::query()
            ->where('workflow_run_id', $run->id)
            ->where('outcome', 'started_new')
            ->firstOrFail();
        $this->assertSame('control_plane', $command->source);
        $this->assertSame('request-group-1', $command->commandContext()['intake']['group_id']);
        $this->assertSame('server-1', $command->commandContext()['principal']['id']);

        $commandResult = new CommandResult($command);
        $this->assertSame($run->id, $commandResult->runId());
        $this->assertSame('unit-control-start', $commandResult->workflowId());
        $this->assertSame(TestQueryWorkflow::class, $commandResult->workflowClass());
        $this->assertSame('start', $commandResult->type());
        $this->assertTrue($commandResult->accepted());
        $this->assertFalse($commandResult->rejected());
        $this->assertFalse($commandResult->rejectedNotCurrent());
        $this->assertFalse($commandResult->rejectedInvalidArguments());
        $this->assertNull($commandResult->message());

        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->firstOrFail();
        $this->assertSame(
            ['countEventsMatching', 'currentStage', 'events-starting-with'],
            $started->payload['declared_queries']
        );
        $this->assertSame([
            'team' => 'payments',
        ], $started->payload['visibility_labels']);
    }

    public function testRemoteStartsEnforceInstanceIdentityAndDuplicatePolicy(): void
    {
        $started = $this->controlPlane->start('remote.orders', 'unit-control-duplicate', [
            'connection' => 'redis',
            'queue' => 'remote-workers',
            'compatibility' => 'remote-build',
        ]);
        $rejected = $this->controlPlane->start('remote.orders', 'unit-control-duplicate');
        $existing = $this->controlPlane->start('remote.orders', 'unit-control-duplicate', [
            'duplicate_start_policy' => 'return_existing_active',
        ]);
        $generated = $this->controlPlane->start('remote.generated', null, [
            'connection' => 'redis',
            'queue' => 'remote-workers',
        ]);

        $this->assertTrue($started['started']);
        $this->assertFalse($rejected['started']);
        $this->assertSame('rejected_duplicate', $rejected['outcome']);
        $this->assertSame('instance_already_started', $rejected['reason']);
        $this->assertTrue($existing['started']);
        $this->assertSame('returned_existing_active', $existing['outcome']);
        $this->assertSame($started['workflow_run_id'], $existing['workflow_run_id']);
        $this->assertTrue($generated['started']);
        $this->assertNotSame('', $generated['workflow_instance_id']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot be reused');
        $this->controlPlane->start('remote.invoices', 'unit-control-duplicate');
    }

    public function testStartFailsClosedWhenTheTargetQueueHasNoCompatibleWorker(): void
    {
        config()->set('workflows.v2.fleet.validation_mode', 'fail');
        WorkerCompatibilityFleet::clear();
        WorkerCompatibilityFleet::record(['build-b'], 'redis', 'blocked', 'worker-build-b');

        $result = $this->controlPlane->start('remote.blocked', 'unit-control-blocked', [
            'arguments' => Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), ['payload']),
            'connection' => 'redis',
            'queue' => 'blocked',
        ]);

        $this->assertFalse($result['started']);
        $this->assertNull($result['workflow_run_id']);
        $this->assertSame('rejected_compatibility_blocked', $result['outcome']);
        $this->assertSame('compatibility_blocked', $result['reason']);
        $this->assertStringContainsString('build-b', $result['message']);
    }

    public function testSignalsEnforceDeclaredAndRuntimeOwnedContracts(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'contract-signal-workflow' => TestUpdateWorkflow::class,
        ]);

        $this->controlPlane->start('contract-signal-workflow', 'unit-control-signal', [
            'queue' => 'default',
        ]);
        $this->executeInitialTask(null, 'default');

        $unknown = $this->controlPlane->signal('unit-control-signal', 'missing-signal');
        $invalid = $this->controlPlane->signal('unit-control-signal', 'name-provided');
        $forged = $this->controlPlane->signal(
            'unit-control-signal',
            WorkflowStub::MESSAGE_STREAM_RUNTIME_SIGNAL,
            [
                'arguments' => [[
                    'stream' => 'orders',
                ]],
            ],
        );
        $runtime = $this->runtimeControlPlane->runtimeSignal(
            'unit-control-signal',
            WorkflowStub::MESSAGE_STREAM_RUNTIME_SIGNAL,
            [
                'arguments' => [[
                    'stream' => 'orders',
                ]],
            ],
        );
        $accepted = $this->controlPlane->signal('unit-control-signal', 'name-provided', [
            'arguments' => ['Taylor'],
            'command_context' => CommandContext::controlPlane()->with([
                'caller' => [
                    'type' => 'server',
                    'label' => 'Standalone Server',
                ],
            ]),
        ]);

        $this->assertFalse($unknown['accepted']);
        $this->assertSame(404, $unknown['status']);
        $this->assertFalse($invalid['accepted']);
        $this->assertSame(422, $invalid['status']);
        $this->assertFalse($forged['accepted']);
        $this->assertTrue($runtime['accepted']);
        $this->assertSame(202, $runtime['status']);
        $this->assertTrue($accepted['accepted']);
        $command = WorkflowCommand::query()->findOrFail($accepted['command_id']);
        $this->assertSame('server', $command->commandContext()['caller']['type']);
    }

    public function testQueriesReturnResultsValidationFailuresAndConfiguredTypeErrors(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'contract-query-workflow' => TestQueryWorkflow::class,
        ]);

        $this->controlPlane->start('contract-query-workflow', 'unit-control-query', [
            'queue' => 'default',
        ]);
        $this->executeInitialTask(null, 'default');

        $success = $this->controlPlane->query('unit-control-query', 'currentStage');
        $missing = $this->controlPlane->query('unit-control-query', 'missing-query');
        $invalid = $this->controlPlane->query('unit-control-query', 'events-starting-with', [
            'arguments' => [
                'extra' => 'value',
            ],
        ]);

        $this->assertTrue($success['success']);
        $this->assertSame('waiting-for-name', $success['result']);
        $this->assertIsArray($success['result_envelope']);
        $this->assertFalse($missing['success']);
        $this->assertSame(404, $missing['status']);
        $this->assertSame('query_not_found', $missing['reason']);
        $this->assertFalse($invalid['success']);
        $this->assertSame(422, $invalid['status']);
        $this->assertArrayHasKey('prefix', $invalid['validation_errors']);
        $this->assertArrayHasKey('extra', $invalid['validation_errors']);

        config()
            ->set('workflows.v2.types.workflows', [
                'contract-query-workflow' => \stdClass::class,
            ]);
        $blocked = $this->controlPlane->query('unit-control-query', 'currentStage', [
            'strict_configured_type_validation' => true,
        ]);

        $this->assertSame(409, $blocked['status']);
        $this->assertSame('configured_workflow_type_invalid', $blocked['reason']);
    }

    public function testUpdatesExposeAdmissionAndArgumentValidation(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'contract-update-workflow' => TestUpdateWorkflow::class,
        ]);

        $this->controlPlane->start('contract-update-workflow', 'unit-control-update', [
            'queue' => 'default',
        ]);
        $this->executeInitialTask(null, 'default');

        $accepted = $this->controlPlane->update('unit-control-update', 'approve', [
            'arguments' => [true, 'unit-contract'],
        ]);
        $unknown = $this->controlPlane->update('unit-control-update', 'missing-update');
        $invalid = $this->controlPlane->update('unit-control-update', 'approve', [
            'arguments' => ['not-a-boolean', 'unit-contract'],
        ]);

        $this->assertTrue($accepted['accepted']);
        $this->assertSame(202, $accepted['status']);
        $this->assertSame('approve', $accepted['update_name']);
        $this->assertFalse($unknown['accepted']);
        $this->assertSame(404, $unknown['status']);
        $this->assertFalse($invalid['accepted']);
        $this->assertSame(422, $invalid['status']);
        $this->assertSame('invalid_update_arguments', $invalid['reason']);

        $invalidResult = new CommandResult(WorkflowCommand::query()->findOrFail($invalid['command_id']));
        $this->assertTrue($invalidResult->rejectedInvalidArguments());
        $this->assertNull($invalidResult->message());
    }

    public function testLifecycleCommandsAndDescribeReflectRunState(): void
    {
        $this->controlPlane->start('remote.lifecycle', 'unit-control-repair', [
            'connection' => 'redis',
            'queue' => 'remote-workers',
        ]);
        $repair = $this->controlPlane->repair('unit-control-repair');
        $active = $this->controlPlane->describe('unit-control-repair');

        $this->controlPlane->start('remote.lifecycle', 'unit-control-cancel', [
            'connection' => 'redis',
            'queue' => 'remote-workers',
        ]);
        $cancel = $this->controlPlane->cancel('unit-control-cancel', [
            'reason' => 'No longer needed',
        ]);

        $this->controlPlane->start('remote.lifecycle', 'unit-control-archive', [
            'connection' => 'redis',
            'queue' => 'remote-workers',
            'namespace' => 'tenant-a',
        ]);
        $terminate = $this->controlPlane->terminate('unit-control-archive', [
            'reason' => 'Superseded',
        ]);
        $closed = $this->controlPlane->describe('unit-control-archive', [
            'namespace' => 'tenant-a',
        ]);
        $hidden = $this->controlPlane->describe('unit-control-archive', [
            'namespace' => 'tenant-b',
        ]);
        $archive = $this->controlPlane->archive('unit-control-archive', [
            'reason' => 'Retention elapsed',
        ]);

        $this->assertTrue($repair['accepted']);
        $this->assertTrue($active['found']);
        $this->assertTrue($active['actions']['can_repair']);
        $this->assertFalse($active['actions']['can_archive']);
        $this->assertTrue($cancel['accepted']);
        $this->assertTrue($terminate['accepted']);
        $this->assertSame('terminated', $closed['run']['status']);
        $this->assertFalse($closed['actions']['can_repair']);
        $this->assertTrue($closed['actions']['can_archive']);
        $this->assertFalse($hidden['found']);
        $this->assertTrue($archive['accepted']);

        foreach (['cancel', 'terminate', 'repair', 'archive'] as $operation) {
            $notFound = $this->controlPlane->{$operation}('unit-control-missing');
            $this->assertFalse($notFound['accepted']);
            $this->assertSame(404, $notFound['status']);
        }

        $reserved = WorkflowInstance::query()->create([
            'id' => 'unit-control-reserved',
            'workflow_class' => 'remote.reserved',
            'workflow_type' => 'remote.reserved',
            'run_count' => 0,
        ]);
        $this->assertNotNull($reserved);

        $missingRun = $this->controlPlane->describe('unit-control-reserved');
        $missingInstance = $this->controlPlane->describe('unit-control-missing');
        $this->assertTrue($missingRun['found']);
        $this->assertSame('run_not_found', $missingRun['reason']);
        $this->assertFalse($missingInstance['found']);
        $this->assertSame('instance_not_found', $missingInstance['reason']);
    }

    public function testCommandContextsDescribeHttpAndWorkflowCallersDeterministically(): void
    {
        $first = Request::create('/hooks/orders', 'POST', [
            'z' => [
                'b' => 2,
                'a' => 1,
            ],
            'a' => 'first',
        ], server: [
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_USER_AGENT' => 'contract-client',
            'HTTP_X_REQUEST_ID' => 'request-42',
            'HTTP_X_CORRELATION_ID' => 'correlation-42',
        ]);
        $second = Request::create('/hooks/orders', 'POST', [
            'a' => 'first',
            'z' => [
                'a' => 1,
                'b' => 2,
            ],
        ], server: [
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_USER_AGENT' => 'contract-client',
            'HTTP_X_REQUEST_ID' => 'request-42',
            'HTTP_X_CORRELATION_ID' => 'correlation-42',
        ]);

        $webhook = CommandContext::webhook($first, 'hmac-sha256')->attributes();
        $sameWebhook = CommandContext::webhook($second, 'hmac-sha256')->attributes();
        $unsecuredWebhook = CommandContext::webhook(Request::create('/hooks/open', 'POST'), 'none')->attributes();
        $waterline = CommandContext::waterline($first)->attributes();
        $workflow = CommandContext::workflow('parent-instance', 'parent-run', 7, 'child-call')->attributes();
        $php = CommandContext::phpApi()
            ->withPrincipal('user', 'user-42')
            ->attributes();

        $this->assertSame('webhook', $webhook['source']);
        $this->assertSame('authorized', $webhook['context']['auth']['status']);
        $this->assertSame('/hooks/orders', $webhook['context']['request']['path']);
        $this->assertSame('request-42', $webhook['context']['request']['request_id']);
        $this->assertSame(
            $webhook['context']['request']['fingerprint'],
            $sameWebhook['context']['request']['fingerprint'],
        );
        $this->assertSame('not_configured', $unsecuredWebhook['context']['auth']['status']);
        $this->assertSame('waterline', $waterline['source']);
        $this->assertSame('workflow', $workflow['source']);
        $this->assertSame(7, $workflow['context']['workflow']['sequence']);
        $this->assertSame('child-call', $workflow['context']['workflow']['child_call_id']);
        $this->assertSame('php', $php['source']);
        $this->assertSame('user-42', $php['context']['principal']['id']);
    }

    private function executeInitialTask(?string $connection, string $queue): void
    {
        $bridge = $this->app->make(\Workflow\V2\Contracts\WorkflowTaskBridge::class);
        $tasks = $bridge->poll($connection, $queue);

        $this->assertNotEmpty($tasks);
        $bridge->execute($tasks[0]['task_id']);
    }
}
