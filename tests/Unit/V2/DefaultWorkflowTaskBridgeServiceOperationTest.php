<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class DefaultWorkflowTaskBridgeServiceOperationTest extends TestCase
{
    private WorkflowTaskBridge $bridge;

    private WorkflowRun $run;

    private WorkflowTask $task;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08T02:00:00Z');
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
            'workflows.v2.connection' => 'redis',
            'workflows.v2.queue' => 'service-caller',
            'workflows.v2.compatibility.current' => 'service-build',
            'workflows.v2.compatibility.supported' => ['service-build'],
        ]);
        WorkerCompatibilityFleet::clear();
        Queue::fake();
        BridgeServiceOperationProbeWorkflow::$calls = 0;
        $workflow = WorkflowStub::make(BridgeServiceOperationProbeWorkflow::class, 'service-caller-owner', 'caller');
        $this->assertTrue($workflow->attemptStart()->accepted());
        $this->run = WorkflowRun::query()->findOrFail($workflow->runId());
        $this->task = WorkflowTask::query()->where('workflow_run_id', $this->run->id)->sole();
        $this->bridge = $this->app->make(WorkflowTaskBridge::class);
        $claim = $this->bridge->claimStatus($this->task->id, 'service-command-worker');
        $this->assertTrue($claim['claimed'], json_encode($claim, JSON_THROW_ON_ERROR));
        $this->assertSame(TaskStatus::Leased, $this->task->fresh()->status);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        WorkerCompatibilityFleet::clear();
        BridgeServiceOperationProbeWorkflow::$calls = 0;
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $surface
     * @param array<string, mixed> $options
     */
    #[DataProvider('serviceOutcomes')]
    public function testServiceOutcomeRecordsDurableEvidenceAndOnlyResumesVisibleResults(
        array $surface,
        array $options,
        HistoryEventType $eventType,
        bool $resume,
        ?string $errorType,
        ?string $errorMessage,
    ): void {
        $service = new BridgeServiceControlPlaneProbe($surface);
        $this->app->instance(ServiceControlPlane::class, $service);
        $request = [
            'invoice_id' => 42,
            'customer' => 'Taylor',
        ];
        $payload = Serializer::serialize($request);
        $command = [
            'type' => 'start_service_operation',
            'endpoint_name' => 'billing',
            'service_name' => 'invoices',
            'operation_name' => 'create',
            'request_payload' => $payload,
            'payload_codec' => 'avro',
        ] + $options;
        $beforeCount = WorkflowHistoryEvent::query()->count();
        $result = $this->bridge->complete($this->task->id, [$command]);

        $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertNull($result['reason']);
        $this->assertSame(RunStatus::Waiting, $this->run->fresh()->status);
        $this->assertSame(TaskStatus::Completed, $this->task->fresh()->status);
        $this->assertNull($this->task->fresh()->lease_expires_at);
        $this->assertCount(1, $service->executions);
        $call = $service->executions[0];
        $this->assertSame(['billing', 'invoices', 'create'], array_slice($call, 0, 3));
        $this->assertSame($request, $call[3]['arguments']);
        $this->assertSame($payload, $call[3]['payload_blob']);
        $this->assertSame('avro', $call[3]['payload_codec']);
        $this->assertSame('caller', $call[3]['namespace']);
        $this->assertSame('caller', $call[3]['caller_namespace']);
        $this->assertSame($this->run->workflow_instance_id, $call[3]['caller_workflow_instance_id']);
        $this->assertSame($this->run->id, $call[3]['caller_workflow_run_id']);
        $this->assertSame(
            'workflow-service-operation:' . $this->run->workflow_instance_id . ':' . $this->run->id . ':1',
            $call[3]['idempotency_key']
        );

        $event = WorkflowHistoryEvent::query()->where('workflow_run_id', $this->run->id)
            ->where('event_type', $eventType->value)
            ->sole();
        $this->assertSame($beforeCount + 1, WorkflowHistoryEvent::query()->count());
        $this->assertSame($this->task->id, $event->workflow_task_id);
        $this->assertSame('caller', $event->run->namespace);
        $this->assertSame(1, $event->payload['sequence']);
        $this->assertSameJsonObject($surface, $event->payload['service_call']);
        $this->assertSameJsonObject($surface, $event->payload['response_or_failure_surface']);
        $this->assertSame($this->run->id, $event->payload['caller_workflow_run_id']);
        $this->assertSame($payload, $event->payload['request_payload']);
        $response = $surface['response_payload'] ?? $surface['result'] ?? null;
        if ($response === null) {
            $this->assertArrayNotHasKey('response_payload', $event->payload);
        } elseif (is_array($response)) {
            $this->assertSameJsonObject($response, $event->payload['response_payload']);
        } else {
            $this->assertSame($response, $event->payload['response_payload']);
        }
        if ($errorType !== null) {
            $this->assertSame($errorType, $event->payload['exception_type']);
            $this->assertSame($errorMessage, $event->payload['message']);
            $this->assertSame($errorMessage, $event->payload['exception']['message']);
        } else {
            $this->assertArrayNotHasKey('exception', $event->payload);
        }

        $resumeTasks = WorkflowTask::query()->where('workflow_run_id', $this->run->id)
            ->where('status', TaskStatus::Ready->value)->get();
        $this->assertSame($resume ? 1 : 0, $resumeTasks->count());
        $this->assertSame($resumeTasks->pluck('id')->all(), $result['created_task_ids']);
        foreach ($resumeTasks as $task) {
            $this->assertSame('caller', $task->namespace);
            $this->assertSame($this->run->connection, $task->connection);
            $this->assertSame($this->run->queue, $task->queue);
            $this->assertSame($this->run->compatibility, $task->compatibility);
            $this->assertSame('service_call', $task->payload['resume_source_kind']);
            $this->assertSame($surface['service_call_id'] ?? null, $task->payload['service_call_id']);
            $this->assertSame($event->id, $task->payload['workflow_history_event_id']);
            $this->assertSame($eventType->value, $task->payload['workflow_event_type']);
            $this->assertSame(1, $task->payload['workflow_sequence']);
        }

        $before = $this->records();
        foreach ([1, 2] as $_) {
            $redelivery = $this->bridge->complete($this->task->id, [$command]);
            $this->assertFalse($redelivery['completed']);
            $this->assertSame('task_not_leased', $redelivery['reason']);
            $this->assertSame([], $redelivery['created_task_ids']);
            $this->assertSame($before, $this->records());
            $this->assertCount(1, $service->executions);
        }
        $this->assertSame(0, BridgeServiceOperationProbeWorkflow::$calls);
        Queue::assertNothingPushed();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, HistoryEventType, bool, ?string, ?string}>
     */
    public static function serviceOutcomes(): iterable
    {
        $base = [
            'service_call_id' => 'service-call-42',
            'accepted' => true,
        ];
        yield 'completed' => [$base + [
            'status' => 'completed',
            'result' => [
                'invoice_id' => 42,
            ],
        ], [], HistoryEventType::ServiceCallCompleted, true, null, null];
        foreach ([
            'false' => false,
            'zero' => 0,
            'empty string' => '',
            'empty array' => [],
        ] as $name => $value) {
            yield 'completed ' . $name => [$base + [
                'status' => 'completed',
                'result' => $value,
            ], [], HistoryEventType::ServiceCallCompleted, true, null, null];
        }
        yield 'failed' => [$base + [
            'status' => 'failed',
            'failure_type' => 'billing_error',
            'failure_message' => 'Invoice declined',
        ], [], HistoryEventType::ServiceCallFailed, true, 'billing_error', 'Invoice declined'];
        yield 'failed with default details' => [$base + [
            'status' => 'failed',
        ], [], HistoryEventType::ServiceCallFailed, true, 'service_operation_failed', 'Service operation failed.'];
        yield 'caller error takes precedence' => [$base + [
            'status' => 'failed',
            'caller_observed_error_type' => 'caller_error',
            'service_error_type' => 'service_error',
            'typed_error_message' => 'Caller observed a failure',
            'failure_type' => 'fallback_error',
            'failure_message' => 'Fallback message',
            'outcome_metadata' => [
                'caller_observed_error_type' => 'nested_error',
                'typed_error_message' => 'Nested message',
            ],
        ], [], HistoryEventType::ServiceCallFailed, true, 'caller_error', 'Caller observed a failure'];
        yield 'service error takes precedence over nested caller error' => [$base + [
            'status' => 'failed',
            'service_error_type' => 'service_error',
            'outcome_metadata' => [
                'caller_observed_error_type' => 'nested_error',
                'typed_error_message' => 'Service supplied a typed message',
            ],
        ], [], HistoryEventType::ServiceCallFailed, true, 'service_error', 'Service supplied a typed message'];
        yield 'nested caller error takes precedence over nested service error' => [$base + [
            'status' => 'failed',
            'outcome_metadata' => [
                'caller_observed_error_type' => 'nested_caller_error',
                'service_error_type' => 'nested_service_error',
                'typed_error_message' => 'Nested caller message',
            ],
        ], [], HistoryEventType::ServiceCallFailed, true, 'nested_caller_error', 'Nested caller message'];
        yield 'nested service error' => [$base + [
            'status' => 'failed',
            'outcome_metadata' => [
                'service_error_type' => 'nested_service_error',
                'typed_error_message' => 'Nested service message',
            ],
        ], [], HistoryEventType::ServiceCallFailed, true, 'nested_service_error', 'Nested service message'];
        yield 'cancelled' => [$base + [
            'status' => 'cancelled',
            'failure_message' => 'Customer cancelled',
        ], [], HistoryEventType::ServiceCallCancelled, true, 'service_call_cancelled', 'Customer cancelled'];
        yield 'cancelled with default details' => [$base + [
            'status' => 'cancelled',
        ], [], HistoryEventType::ServiceCallCancelled, true, 'service_call_cancelled', 'Service operation cancelled.'];
        yield 'rejected without status' => [[
            'service_call_id' => 'rejected-call',
            'accepted' => false,
            'reason' => 'permission_denied',
        ], [], HistoryEventType::ServiceCallFailed, true, 'permission_denied', 'permission_denied'];
        yield 'accepted without status' => [$base, [], HistoryEventType::ServiceCallStarted, false, null, null];
        $started = $base + [
            'status' => 'started',
        ];
        yield 'blocking started call' => [$started, [], HistoryEventType::ServiceCallStarted, false, null, null];
        yield 'command accepted wait' => [$started, [
            'wait_for' => 'accepted',
        ], HistoryEventType::ServiceCallStarted, true, null, null];
        yield 'command async mode' => [$started, [
            'mode_override' => 'async',
        ], HistoryEventType::ServiceCallStarted, true, null, null];
        yield 'surface accepted wait' => [$started + [
            'wait_for' => 'accepted',
        ], [], HistoryEventType::ServiceCallStarted, true, null, null];
        yield 'surface async mode' => [$started + [
            'operation_mode' => 'async',
        ], [], HistoryEventType::ServiceCallStarted, true, null, null];
    }

    public function testServiceOptionsPreserveTypedValuesAndAuthoritativeCallerMetadata(): void
    {
        $surface = [
            'service_call_id' => 'explicit-service-call',
            'accepted' => true,
            'status' => 'completed',
            'response_payload' => [
                'approved' => false,
                'amount' => 0,
                'label' => '',
            ],
            'service_sdk_language' => 'python',
            'operation_mode' => 'sync',
            'wait_for' => 'completed',
            'resolved_binding_kind' => 'workflow_start',
            'resolved_target_reference' => 'invoice-workflow',
            'linked_workflow_instance_id' => 'invoice-owner',
            'linked_workflow_run_id' => 'invoice-run',
            'linked_workflow_update_id' => 'invoice-update',
        ];
        $service = new BridgeServiceControlPlaneProbe($surface);
        $this->app->instance(ServiceControlPlane::class, $service);
        $arguments = [
            'invoice_id' => 42,
            'approved' => false,
            'amount' => 0,
        ];
        $payload = Serializer::serialize($arguments);
        $options = [
            'namespace' => 'billing',
            'caller_namespace' => 'finance',
            'service_call_id' => 'requested-service-call',
            'idempotency_key' => 'invoice-request-42',
            'mode_override' => ' SYNC ',
            'wait_for' => ' COMPLETED ',
            'wait_timeout_seconds' => 0,
            'target_workflow_instance_id' => 'invoice-owner',
            'target_workflow_run_id' => 'invoice-run',
            'connection' => 'redis',
            'queue' => 'billing-worker',
            'business_key' => 'invoice-42',
            'labels' => [
                'department' => 'finance',
            ],
            'memo' => [
                'approved' => false,
            ],
            'search_attributes' => [
                'amount' => 0,
            ],
            'duplicate_start_policy' => 'return_existing_active',
            'request_payload_reference' => 'invoice-request-reference',
            'principal_subject' => 'user:billing-clerk',
            'principal_method' => 'token',
            'principal_roles' => ['invoice:create', 'invoice:read'],
            'principal_tenant' => 'customer-a',
            'principal_claims' => [
                'approved' => false,
            ],
            'metadata' => [
                'caller_sdk_language' => 'forged-sdk',
                'caller_workflow_instance_id' => 'forged-owner',
                'caller_workflow_run_id' => 'forged-run',
                'workflow_sequence' => 99,
                'service_sdk_language' => 'rust',
                'trace_id' => 'trace-42',
            ],
        ];
        $command = [
            'type' => 'start_service_operation',
            'endpoint_name' => 'billing',
            'service_name' => 'invoices',
            'operation_name' => 'create',
            'request_payload' => [
                'codec' => 'avro',
                'blob' => $payload,
            ],
        ] + $options;
        $result = $this->bridge->complete($this->task->id, [$command]);
        $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertCount(1, $service->executions);
        $forwarded = $service->executions[0][3];
        $expected = $options;
        $expected['mode_override'] = 'sync';
        $expected['wait_for'] = 'completed';
        $expected['metadata'] = [
            'caller_sdk_language' => 'workflow-php',
            'caller_workflow_instance_id' => $this->run->workflow_instance_id,
            'caller_workflow_run_id' => $this->run->id,
            'workflow_sequence' => 1,
            'service_sdk_language' => 'rust',
            'trace_id' => 'trace-42',
        ];
        $expected += [
            'arguments' => $arguments,
            'payload_blob' => $payload,
            'payload_codec' => 'avro',
            'caller_workflow_instance_id' => $this->run->workflow_instance_id,
            'caller_workflow_run_id' => $this->run->id,
        ];
        $this->assertSameJsonObject($expected, $forwarded);
        $event = WorkflowHistoryEvent::query()->where(
            'event_type',
            HistoryEventType::ServiceCallCompleted->value
        )->sole();
        $this->assertSame('explicit-service-call', $event->payload['service_call_id']);
        $this->assertSameJsonObject($surface['response_payload'], $event->payload['response_payload']);
        $this->assertSame('python', $event->payload['service_sdk_language']);
        $this->assertSame('workflow-php', $event->payload['caller_sdk_language']);
        foreach ([
            'operation_mode',
            'wait_for',
            'resolved_binding_kind',
            'resolved_target_reference',
            'linked_workflow_instance_id',
            'linked_workflow_run_id',
            'linked_workflow_update_id',
        ] as $field) {
            $this->assertSame($surface[$field], $event->payload[$field]);
        }
        $this->assertSame(0, BridgeServiceOperationProbeWorkflow::$calls);
        Queue::assertNothingPushed();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidServiceCommands')]
    public function testMalformedServiceCommandRejectsTheWholeBatchWithoutCallingTheService(array $overrides): void
    {
        $service = new BridgeServiceControlPlaneProbe([
            'accepted' => true,
            'status' => 'completed',
        ]);
        $this->app->instance(ServiceControlPlane::class, $service);
        $valid = [
            'type' => 'start_service_operation',
            'endpoint_name' => 'billing',
            'service_name' => 'invoices',
            'operation_name' => 'create',
            'request_payload' => Serializer::serialize([
                'invoice_id' => 42,
            ]),
            'payload_codec' => 'avro',
        ];
        $invalid = array_replace($valid, $overrides);
        $before = $this->records();
        foreach ([[$invalid], [$valid, $invalid], [$invalid, $valid]] as $batch) {
            $response = $this->bridge->complete($this->task->id, $batch);
            $this->assertFalse($response['completed']);
            $this->assertSame('invalid_commands', $response['reason']);
            $this->assertSame([], $response['created_task_ids']);
            $this->assertSame($before, $this->records());
            $this->assertSame([], $service->executions);
        }
        $this->assertSame(TaskStatus::Leased, $this->task->fresh()->status);
        $this->assertSame(0, BridgeServiceOperationProbeWorkflow::$calls);
        Queue::assertNothingPushed();
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidServiceCommands(): iterable
    {
        foreach (['endpoint_name', 'service_name', 'operation_name'] as $field) {
            foreach ([null, '', 42, []] as $value) {
                yield $field . ' ' . json_encode($value, JSON_THROW_ON_ERROR) => [[
                    $field => $value,
                ]];
            }
        }
        yield 'invalid payload scalar' => [[
            'request_payload' => 42,
        ]];
        yield 'invalid envelope blob' => [[
            'request_payload' => [
                'codec' => 'avro',
                'blob' => 42,
            ],
        ]];
        yield 'unsupported codec' => [[
            'payload_codec' => 'json',
        ]];
        foreach ([
            'mode_override' => ['queued', 42],
            'wait_for' => ['applied', []],
            'wait_timeout_seconds' => [-1, 1.5, '0'],
        ] as $field => $values) {
            foreach ($values as $value) {
                yield $field . ' ' . json_encode($value, JSON_THROW_ON_ERROR) => [[
                    $field => $value,
                ]];
            }
        }
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function records(): array
    {
        $records = [];
        foreach ([
            WorkflowInstance::class,
            WorkflowRun::class,
            WorkflowTask::class,
            WorkflowCommand::class,
            WorkflowHistoryEvent::class,
            WorkflowRunSummary::class,
        ] as $model) {
            $records[$model] = $model::query()->orderBy('id')->get()->map(
                static fn (Model $row): array => $row->getRawOriginal()
            )->all();
        }

        return $records;
    }
}

final class BridgeServiceControlPlaneProbe implements ServiceControlPlane
{
    /**
     * @var list<array{string, string, string, array<string, mixed>}>
     */
    public array $executions = [];

    /**
     * @param array<string, mixed> $surface
     */
    public function __construct(
        private readonly array $surface
    ) {
    }

    public function execute(
        string $endpointName,
        string $serviceName,
        string $operationName,
        array $options = []
    ): array {
        $this->executions[] = [$endpointName, $serviceName, $operationName, $options];

        return $this->surface;
    }

    public function describeCall(string $serviceCallId, array $options = []): array
    {
        throw new LogicException('Unexpected service-call inspection.');
    }

    public function cancelCall(string $serviceCallId, array $options = []): array
    {
        throw new LogicException('Unexpected service-call cancellation.');
    }
}

#[Type('bridge-service-operation-probe')]
final class BridgeServiceOperationProbeWorkflow extends Workflow
{
    public static int $calls = 0;

    public function handle(): string
    {
        ++self::$calls;

        return 'workflow body must remain unexecuted';
    }
}
