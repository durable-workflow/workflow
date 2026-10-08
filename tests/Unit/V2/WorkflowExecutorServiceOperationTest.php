<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Workflow\QueryMethod;
use Workflow\Serializers\Serializer;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Exceptions\RestoredWorkflowException;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\RunTimelineProjector;
use Workflow\V2\Support\ServiceOperationOptions;
use Workflow\V2\Support\ServiceOperationResult;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;
use Workflow\WorkflowOptions;

final class WorkflowExecutorServiceOperationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 17:10:00');
        Queue::fake();
        config()
            ->set([
                'queue.default' => 'redis',
                'workflows.v2.task_dispatch_mode' => 'queue',
                'workflows.v2.workflow_task_lease_seconds' => 300,
                'workflows.v2.compatibility.current' => 'service-build',
                'workflows.v2.compatibility.supported' => ['service-build'],
                'workflows.v2.compatibility.namespace' => 'service-caller',
            ]);
        WorkerCompatibilityFleet::clear();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        WorkerCompatibilityFleet::clear();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $surface
     * @param array<string, mixed> $options
     * @param array{type: string, message: string}|null $failure
     */
    #[DataProvider('outcomes')]
    public function testPublicExecutionRecordsTheBoundaryOutcomeAndReplaysWithoutCallingTheServiceAgain(
        array $surface,
        array $options,
        mixed $response,
        HistoryEventType $eventType,
        bool $waiting,
        ?array $failure,
    ): void {
        $surface = [
            'service_call_id' => 'service-call-one',
        ] + $surface;
        if ($eventType === HistoryEventType::ServiceCallCompleted) {
            $surface['response_payload'] = Serializer::serializeWithCodec('avro', $response);
        }
        $service = new EmbeddedServiceControlPlaneProbe($surface);
        $this->app->instance(ServiceControlPlane::class, $service);
        $connection = config('database.default');
        $this->assertIsString($connection);
        config()
            ->set('queue.connections.' . $connection, [
                'driver' => 'redis',
                'connection' => 'default',
                'queue' => 'service-boundary',
            ]);
        $workflow = WorkflowStub::make(ExecutorServiceOperationWorkflow::class, 'service-owner', 'service-caller');
        $this->assertTrue($workflow->attemptStart($options, new WorkflowOptions(
            connection: $connection,
            queue: 'service-boundary',
        ))->accepted());
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->sole();
        $bridge = $this->app->make(DefaultWorkflowTaskBridge::class);
        $execution = $bridge->execute($task->id);

        $this->assertTrue($execution['executed']);
        $this->assertSame($waiting ? 'waiting' : 'completed', $execution['run_status']);
        $this->assertNull($execution['next_task_id']);
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        $this->assertCount(1, $service->executions);
        $request = Serializer::serializeWithCodec('avro', ExecutorServiceOperationWorkflow::REQUEST);
        $expectedOptions = [
            'namespace' => 'service-caller',
            'arguments' => ExecutorServiceOperationWorkflow::REQUEST,
            'payload_blob' => $request,
            'payload_codec' => 'avro',
            'idempotency_key' => 'workflow-service-operation:' . $run->workflow_instance_id . ':' . $run->id . ':1',
            'caller_namespace' => 'service-caller',
            'caller_workflow_instance_id' => $run->workflow_instance_id,
            'caller_workflow_run_id' => $run->id,
            'metadata' => [
                'caller_sdk_language' => 'workflow-php',
                'caller_workflow_instance_id' => $run->workflow_instance_id,
                'caller_workflow_run_id' => $run->id,
                'workflow_sequence' => 1,
            ],
        ];
        if ($options !== []) {
            $expectedOptions += [
                'mode_override' => 'async',
                'wait_for' => 'accepted',
            ];
        }
        $this->assertSame(
            $this->ordered(['billing', 'invoices', 'create', $expectedOptions]),
            $this->ordered($service->executions[0])
        );
        $event = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->whereIn('event_type', [
            HistoryEventType::ServiceCallStarted->value,
            HistoryEventType::ServiceCallCompleted->value,
            HistoryEventType::ServiceCallFailed->value,
            HistoryEventType::ServiceCallCancelled->value,
        ])->sole();
        $this->assertSame($eventType, $event->event_type);
        $expectedPayload = [
            'sequence' => 1,
            'service_call_id' => 'service-call-one',
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'caller_workflow_instance_id' => $run->workflow_instance_id,
            'caller_workflow_run_id' => $run->id,
            'caller_sdk_language' => 'workflow-php',
            'endpoint_name' => 'billing',
            'service_name' => 'invoices',
            'operation_name' => 'create',
            'request_payload' => $request,
            'payload_codec' => 'avro',
            'outcome' => $surface['outcome'],
            'service_call' => $surface,
            'response_or_failure_surface' => $surface,
            'task' => [
                'id' => $task->id,
                'type' => 'workflow',
                'status' => 'leased',
                'available_at' => $task->available_at?->toJSON(),
                'leased_at' => '2026-10-08T17:10:00.000000Z',
                'lease_owner' => $task->id,
                'lease_expires_at' => '2026-10-08T17:15:00.000000Z',
                'attempt_count' => 1,
                'repair_count' => 0,
                'connection' => $connection,
                'queue' => $task->queue,
                'compatibility' => 'service-build',
                'last_dispatch_attempt_at' => $task->last_dispatch_attempt_at?->toJSON(),
                'last_dispatched_at' => $task->last_dispatched_at?->toJSON(),
            ],
        ];
        foreach (['status', 'operation_mode', 'wait_for', 'response_payload'] as $field) {
            if (array_key_exists($field, $surface)) {
                $expectedPayload[$field] = $surface[$field];
            }
        }
        if ($options !== []) {
            $expectedPayload += [
                'operation_mode' => 'async',
                'wait_for' => 'accepted',
            ];
        }
        if ($failure !== null) {
            $exception = [
                'class' => RuntimeException::class,
                'type' => $failure['type'],
                'message' => $failure['message'],
                'code' => 0,
            ];
            $expectedPayload += [
                'exception_type' => $failure['type'],
                'exception_class' => RuntimeException::class,
                'message' => $failure['message'],
                'code' => 0,
                'exception' => $exception,
            ];
        }
        $this->assertSame($this->ordered($expectedPayload), $this->ordered($event->payload));
        $expectedObserved = [
            'phase' => 'waiting',
        ];
        if ($failure !== null) {
            $expectedObserved = [
                'phase' => 'caught',
                'failure' => [
                    'class' => RuntimeException::class,
                    'message' => $failure['message'],
                    'code' => 0,
                ],
            ];
        } elseif (! $waiting) {
            $serviceCall = $surface + [
                'endpoint_name' => 'billing',
                'service_name' => 'invoices',
                'operation_name' => 'create',
            ];
            foreach (['operation_mode', 'wait_for'] as $field) {
                if (array_key_exists($field, $expectedPayload)) {
                    $serviceCall[$field] ??= $expectedPayload[$field];
                }
            }
            $expectedObserved = [
                'phase' => 'returned',
                'result' => [
                    'service_call_id' => 'service-call-one',
                    'status' => $surface['status'] ?? null,
                    'outcome' => $surface['outcome'],
                    'endpoint_name' => 'billing',
                    'service_name' => 'invoices',
                    'operation_name' => 'create',
                    'response_payload' => $response,
                    'service_call' => $serviceCall,
                ],
            ];
        }
        RunTimelineProjector::project($run->fresh());
        $before = $this->records();
        foreach ([1, 2] as $observation) {
            $observed = $workflow->query('observed');
            $this->assertIsArray($observed);
            $this->assertSame(
                $this->ordered($expectedObserved),
                $this->ordered($observed),
                'Observation ' . $observation
            );
        }
        $this->assertSame($before, $this->records());
        $this->assertFalse($bridge->execute($task->id)['executed']);
        $this->assertSame($before, $this->records());
        $this->assertCount(1, $service->executions);
        $this->assertSame($surface, $service->surface);
        if (! $waiting) {
            $output = $workflow->output();
            $this->assertIsArray($output);
            $this->assertSame($this->ordered($expectedObserved), $this->ordered($output));
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, mixed, HistoryEventType, bool, array{type: string, message: string}|null}>
     */
    public static function outcomes(): iterable
    {
        foreach ([
            'false' => false,
            'zero' => 0,
            'empty string' => '',
            'null' => null,
        ] as $name => $response) {
            yield 'completed ' . $name => [[
                'status' => 'completed',
                'outcome' => 'completed',
                'operation_mode' => 'sync',
                'wait_for' => 'completed',
            ], [], $response, HistoryEventType::ServiceCallCompleted, false, null];
        }
        yield 'surface async admission' => [[
            'status' => 'started',
            'outcome' => 'accepted',
            'operation_mode' => 'async',
            'wait_for' => 'accepted',
        ], [], null, HistoryEventType::ServiceCallStarted, false, null];
        yield 'explicit accepted admission' => [[
            'status' => 'accepted',
            'outcome' => 'accepted',
        ], [
            'modeOverride' => 'async',
            'waitFor' => 'accepted',
        ], null, HistoryEventType::ServiceCallStarted, false, null];
        yield 'sync waits for completion' => [[
            'status' => 'started',
            'outcome' => 'accepted',
            'operation_mode' => 'sync',
            'wait_for' => 'completed',
        ], [], null, HistoryEventType::ServiceCallStarted, true, null];
        yield 'typed handler failure' => [[
            'status' => 'failed',
            'outcome' => 'handler_failed',
            'outcome_metadata' => [
                'service_error_type' => 'remote_declined',
                'typed_error_message' => 'Payment refused.',
            ],
        ], [], null, HistoryEventType::ServiceCallFailed, false, [
            'type' => 'remote_declined',
            'message' => 'Payment refused.',
        ]];
        yield 'cancelled by customer' => [[
            'status' => 'cancelled',
            'outcome' => 'cancelled',
            'failure_message' => 'Customer cancelled.',
        ], [], null, HistoryEventType::ServiceCallCancelled, false, [
            'type' => 'service_call_cancelled',
            'message' => 'Customer cancelled.',
        ]];
        yield 'admission rejected' => [[
            'accepted' => false,
            'outcome' => 'rejected_forbidden',
            'reason' => 'access_denied',
            'message' => 'Access denied.',
        ], [], null, HistoryEventType::ServiceCallFailed, false, [
            'type' => 'access_denied',
            'message' => 'Access denied.',
        ]];
    }

    /**
     * Sort associative keys for whole-value wire comparisons, preserving all fields,
     * scalar types and list order.
     *
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function ordered(array $value): array
    {
        foreach ($value as &$entry) {
            if (is_array($entry)) {
                $entry = $this->ordered($entry);
            }
        }
        unset($entry);
        if (! array_is_list($value)) {
            ksort($value);
        }
        return $value;
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
            WorkflowFailure::class,
            WorkflowRunSummary::class,
        ] as $model) {
            $records[$model] = $model::query()->orderBy('id')->get()->map(
                static fn (Model $row): array => $row->getRawOriginal()
            )->all();
        }
        return $records;
    }
}

final class EmbeddedServiceControlPlaneProbe implements ServiceControlPlane
{
    /**
     * @var list<array{string, string, string, array<string, mixed>}>
     */
    public array $executions = [];

    /**
     * @param array<string, mixed> $surface
     */
    public function __construct(
        public readonly array $surface
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
        throw new LogicException('Unexpected service inspection during execution or replay.');
    }

    public function cancelCall(string $serviceCallId, array $options = []): array
    {
        throw new LogicException('Unexpected service cancellation during execution or replay.');
    }
}

#[Type('executor-service-operation')]
final class ExecutorServiceOperationWorkflow extends Workflow
{
    public const REQUEST = [
        'invoice' => 42,
        'enabled' => false,
        'attempt' => 0,
        'note' => '',
    ];

    /**
     * @var array<string, mixed>
     */
    private array $state = [
        'phase' => 'waiting',
    ];

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function handle(array $options): array
    {
        try {
            $result = Workflow::serviceOperation(
                'billing',
                'invoices',
                'create',
                self::REQUEST,
                $options === [] ? null : new ServiceOperationOptions(...$options),
            );
            if (! $result instanceof ServiceOperationResult) {
                throw new LogicException('Service operations must return the public result surface.');
            }
            $this->state = [
                'phase' => 'returned',
                'result' => $result->toArray(),
            ];
        } catch (RuntimeException $exception) {
            $this->state = [
                'phase' => 'caught',
                'failure' => [
                    'class' => $exception instanceof RestoredWorkflowException
                        ? $exception->originalExceptionClass()
                        : $exception::class,
                    'message' => $exception->getMessage(),
                    'code' => $exception->getCode(),
                ],
            ];
        }
        return $this->state;
    }

    /**
     * @return array<string, mixed>
     */
    #[QueryMethod]
    public function observed(): array
    {
        return $this->state;
    }
}
