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
        $this->assertSame($surface, $event->payload['service_call']);
        $this->assertSame($surface, $event->payload['response_or_failure_surface']);
        $this->assertSame($this->run->id, $event->payload['caller_workflow_run_id']);
        $this->assertSame($payload, $event->payload['request_payload']);
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
        yield 'failed' => [$base + [
            'status' => 'failed',
            'failure_type' => 'billing_error',
            'failure_message' => 'Invoice declined',
        ], [], HistoryEventType::ServiceCallFailed, true, 'billing_error', 'Invoice declined'];
        yield 'cancelled' => [$base + [
            'status' => 'cancelled',
            'failure_message' => 'Customer cancelled',
        ], [], HistoryEventType::ServiceCallCancelled, true, 'service_call_cancelled', 'Customer cancelled'];
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
