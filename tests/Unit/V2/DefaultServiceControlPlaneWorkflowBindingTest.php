<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ServiceBoundaryPolicy;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallOperationMode;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\DefaultServiceBoundaryPolicy;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\ServiceBoundaryDecision;
use Workflow\V2\Support\ServiceBoundaryRequest;

final class DefaultServiceControlPlaneWorkflowBindingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08T03:00:00Z');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('updateOutcomes')]
    public function testUpdateBindingPreservesOptionsAndDurableOutcomeWithoutRedispatch(
        ServiceCallOperationMode $mode,
        bool $completed,
        bool $returnedIdentity,
    ): void {
        $operation = $this->catalog(ServiceCallBindingKind::WorkflowUpdate, $mode, [
            'workflow_instance_id' => 'bound-invoice-owner',
            'update_name' => 'approve',
        ]);
        $policy = new WorkflowBindingBoundaryObserver();
        /** @var WorkflowControlPlane&MockInterface $workflows */
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $arguments = [
            'amount' => 0,
            'approved' => false,
            'labels' => ['', 'invoice'],
        ];
        $payload = Serializer::serialize($arguments);
        $surface = [
            'accepted' => true,
            'update_status' => $completed ? 'completed' : 'accepted',
            'workflow_instance_id' => $returnedIdentity ? 'returned-owner' : null,
            'run_id' => $returnedIdentity ? 'returned-run' : null,
            'update_id' => $returnedIdentity ? 'returned-update' : null,
            'result' => [
                'private' => 'result material',
            ],
            'result_envelope' => [
                'codec' => 'avro',
                'blob' => $payload,
            ],
            'payload' => [
                'private' => 'payload material',
            ],
            'payload_blob' => $payload,
            'arguments' => $arguments,
        ];
        $forwarded = [];
        $statusAtDispatch = null;
        $workflows->shouldReceive('update')
            ->once()
            ->andReturnUsing(
                static function (string $instanceId, string $name, array $options) use (
                    &$forwarded,
                    &$statusAtDispatch,
                    $surface,
                ): array {
                    $forwarded = [$instanceId, $name, $options];
                    $statusAtDispatch = WorkflowServiceCall::query()->sole()->status;

                    return $surface;
                }
            );
        $control = new DefaultServiceControlPlane($workflows, $policy);
        $options = [
            'namespace' => 'billing',
            'caller_namespace' => 'finance',
            'idempotency_key' => 'invoice-approval-42',
            'target_workflow_instance_id' => 'caller-supplied-owner',
            'arguments' => $arguments,
            'payload_codec' => 'avro',
            'payload_blob' => $payload,
            'wait_timeout_seconds' => 0,
        ];
        $catalogBefore = $operation->fresh()
            ->getRawOriginal();
        $result = $control->execute('Billing', 'Invoices', 'Approve', $options);

        $this->assertTrue($result['accepted']);
        $this->assertFalse($result['idempotent_replay']);
        $this->assertNull($result['reason']);
        $this->assertSame(ServiceCallStatus::Accepted->value, $statusAtDispatch);
        $this->assertSame(['bound-invoice-owner', 'approve'], array_slice($forwarded, 0, 2));
        $this->assertSameJsonObject([
            'namespace' => 'billing',
            'arguments' => $arguments,
            'payload_codec' => 'avro',
            'payload_blob' => $payload,
            'wait_for' => $mode === ServiceCallOperationMode::Sync ? 'completed' : 'accepted',
            'wait_timeout_seconds' => 0,
        ], $forwarded[2]);

        $call = WorkflowServiceCall::query()->sole();
        $status = $completed ? ServiceCallStatus::Completed : ServiceCallStatus::Started;
        $outcome = $completed ? ServiceCallOutcome::Completed : ServiceCallOutcome::Accepted;
        $this->assertSame($call->id, $result['service_call_id']);
        $this->assertSame('billing', $call->namespace);
        $this->assertSame('billing', $call->target_namespace);
        $this->assertSame('finance', $call->caller_namespace);
        $this->assertSame($mode->value, $call->operation_mode);
        $this->assertSame($status->value, $call->status);
        $this->assertSame($status->value, $result['status']);
        $this->assertSame($outcome, $call->outcome);
        $this->assertSame($outcome->value, $result['outcome']);
        $this->assertSame(ServiceCallBindingKind::WorkflowUpdate->value, $call->resolved_binding_kind);
        $this->assertSame($returnedIdentity ? 'returned-update' : 'approve', $call->resolved_target_reference);
        $this->assertSame(
            $returnedIdentity ? 'returned-owner' : 'bound-invoice-owner',
            $call->linked_workflow_instance_id
        );
        $this->assertSame($returnedIdentity ? 'returned-run' : null, $call->linked_workflow_run_id);
        $this->assertSame($returnedIdentity ? 'returned-update' : null, $call->linked_workflow_update_id);
        $this->assertSame('approve', $call->metadata['update_name']);
        $this->assertNotNull($call->accepted_at);
        $this->assertNotNull($call->started_at);
        $this->assertSame($completed, $call->completed_at !== null);
        $this->assertNull($call->failed_at);
        $this->assertNull($call->failure_message);
        $this->assertSame($completed ? [$status->value] : [], $policy->releasedStatuses);
        $this->assertSame(1, $policy->evaluations);
        $this->assertSame(1, $result['retry_attempt_count']);
        $this->assertCount(1, $result['service_call_attempts']);
        $this->assertSame($status->value, $result['service_call_attempts'][0]['status']);
        $this->assertFalse($result['service_call_attempts'][0]['retry_scheduled']);

        $publicSurface = array_diff_key($surface, array_flip([
            'result', 'result_envelope', 'payload', 'payload_blob', 'arguments',
        ]));
        $this->assertSameJsonObject($publicSurface, $call->metadata['control_plane']);
        $this->assertSameJsonObject($publicSurface + [
            'kind' => ServiceCallBindingKind::WorkflowUpdate->value,
        ], $result['handler']);
        $before = $call->getRawOriginal();
        foreach ([
            [$options, true],
            [$options + [
                'service_call_id' => $call->id,
            ], false],
        ] as [$replayOptions, $idempotent]) {
            $replay = $control->execute('billing', 'invoices', 'approve', $replayOptions);
            $this->assertTrue($replay['accepted']);
            $this->assertSame($idempotent, $replay['idempotent_replay']);
            $this->assertSame($call->id, $replay['service_call_id']);
            $this->assertSame($status->value, $replay['status']);
            $this->assertSame($before, $call->fresh()->getRawOriginal());
            $this->assertSame(1, WorkflowServiceCall::query()->count());
            $this->assertSame(1, $policy->evaluations);
            $this->assertSame($completed ? [$status->value] : [], $policy->releasedStatuses);
        }
        $this->assertSame($catalogBefore, $operation->fresh()->getRawOriginal());
        Queue::assertNothingPushed();
    }

    /**
     * @return iterable<string, array{ServiceCallOperationMode, bool, bool}>
     */
    public static function updateOutcomes(): iterable
    {
        foreach ([ServiceCallOperationMode::Sync, ServiceCallOperationMode::Async] as $mode) {
            foreach ([true, false] as $completed) {
                foreach ([true, false] as $identity) {
                    yield $mode->value . ' ' . ($completed ? 'completed' : 'pending')
                        . ' ' . ($identity ? 'returned identity' : 'binding identity') => [
                            $mode,
                            $completed,
                            $identity,
                        ];
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $binding
     */
    private function catalog(
        ServiceCallBindingKind $kind,
        ServiceCallOperationMode $mode,
        array $binding,
    ): WorkflowServiceOperation {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'billing',
            'endpoint_name' => 'billing',
        ]);
        $service = WorkflowService::query()->create([
            'namespace' => 'billing',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'invoices',
        ]);

        return WorkflowServiceOperation::query()->create([
            'namespace' => 'billing',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'operation_name' => 'approve',
            'operation_mode' => $mode->value,
            'handler_binding_kind' => $kind->value,
            'handler_binding' => $binding,
        ]);
    }
}

final class WorkflowBindingBoundaryObserver implements ServiceBoundaryPolicy
{
    public int $evaluations = 0;

    /**
     * @var list<string>
     */
    public array $releasedStatuses = [];

    private readonly DefaultServiceBoundaryPolicy $policy;

    public function __construct()
    {
        $this->policy = new DefaultServiceBoundaryPolicy();
    }

    public function evaluate(ServiceBoundaryRequest $request): ServiceBoundaryDecision
    {
        ++$this->evaluations;

        return $this->policy->evaluate($request);
    }

    public function release(ServiceBoundaryRequest $request): void
    {
        $this->releasedStatuses[] = WorkflowServiceCall::query()->sole()->status;
        $this->policy->release($request);
    }
}
