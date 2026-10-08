<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ServiceBoundaryPolicy;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallFailureReason;
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

    #[DataProvider('bindingFailures')]
    public function testWorkflowBindingFailuresAreDurableAndReplayedWithoutAnotherDispatch(
        ServiceCallBindingKind $kind,
        string $method,
        string $failure,
        string $reason,
        string $message,
    ): void {
        $binding = $failure === 'missing target' ? [] : [
            'workflow_type' => 'invoice-workflow',
            'workflow_instance_id' => 'invoice-owner',
            'update_name' => 'approve',
            'signal_name' => 'approve',
            'query_name' => 'approval',
        ];
        $operation = $this->catalog($kind, ServiceCallOperationMode::Async, $binding);
        $policy = new WorkflowBindingBoundaryObserver();
        /** @var WorkflowControlPlane&MockInterface $workflows */
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $surface = [
            'started' => false,
            'accepted' => false,
            'success' => false,
            'result' => [
                'private' => 'result material',
            ],
            'payload_blob' => 'private payload material',
        ];
        if ($failure === 'typed rejection') {
            $surface += [
                'reason' => 'approval_denied',
                'message' => 'The invoice approval was denied.',
                'service_error_type' => 'invoice_declined',
            ];
        }
        $statusAtDispatch = null;
        if ($failure !== 'missing target') {
            $workflows->shouldReceive($method)
                ->once()
                ->andReturnUsing(
                    static function () use (&$statusAtDispatch, $failure, $surface): array {
                        $statusAtDispatch = WorkflowServiceCall::query()->sole()->status;
                        if ($failure === 'exception') {
                            throw new RuntimeException('The workflow adapter failed.');
                        }

                        return $surface;
                    }
                );
        }
        $control = new DefaultServiceControlPlane($workflows, $policy);
        $options = [
            'namespace' => 'billing',
            'caller_namespace' => 'finance',
            'idempotency_key' => 'invoice-binding-failure-42',
        ];
        $catalogBefore = $operation->fresh()
            ->getRawOriginal();
        $result = $control->execute('billing', 'invoices', 'approve', $options);
        $call = WorkflowServiceCall::query()->sole();
        $errorType = match ($failure) {
            'exception' => RuntimeException::class,
            'typed rejection' => 'invoice_declined',
            default => $reason,
        };

        $this->assertFalse($result['accepted']);
        $this->assertFalse($result['idempotent_replay']);
        $this->assertSame($reason, $result['reason']);
        $this->assertSame($message, $result['message']);
        $this->assertSame($errorType, $result['error_type']);
        $this->assertSame($errorType, $result['service_error_type']);
        $this->assertSame($call->id, $result['service_call_id']);
        $this->assertSame(ServiceCallStatus::Failed->value, $call->status);
        $this->assertSame(ServiceCallOutcome::HandlerFailed, $call->outcome);
        $this->assertSame($kind->value, $call->resolved_binding_kind);
        $this->assertSame('billing', $call->target_namespace);
        $this->assertSame('finance', $call->caller_namespace);
        $this->assertSame($reason, $call->outcome_reason);
        $this->assertSame($message, $call->failure_message);
        $this->assertSame(ServiceCallFailureReason::HandlerFailure->value, $call->outcome_metadata['failure_reason']);
        $this->assertSame($errorType, $call->outcome_metadata['failure_type']);
        $this->assertSame($message, $call->outcome_metadata['typed_error_message']);
        $this->assertNotNull($call->accepted_at);
        $this->assertNotNull($call->failed_at);
        $this->assertNull($call->completed_at);
        $this->assertSame([ServiceCallStatus::Failed->value], $policy->releasedStatuses);
        $this->assertSame(1, $policy->evaluations);
        $this->assertSame(1, $result['retry_attempt_count']);
        $this->assertCount(1, $result['service_call_attempts']);
        $this->assertFalse($result['service_call_attempts'][0]['retry_scheduled']);
        $this->assertSame($errorType, $result['service_call_attempts'][0]['failure_type']);
        $this->assertSame($failure === 'missing target' ? null : ServiceCallStatus::Accepted->value, $statusAtDispatch);
        if ($failure === 'exception') {
            $this->assertSame(RuntimeException::class, $call->outcome_metadata['exception_type']);
        } elseif ($failure === 'missing target') {
            $this->assertArrayNotHasKey('control_plane', $call->outcome_metadata);
        } else {
            $this->assertSameJsonObject(array_diff_key($surface, array_flip([
                'result', 'payload_blob',
            ])), $call->outcome_metadata['control_plane']);
        }

        $before = $call->getRawOriginal();
        foreach ([
            $options, $options + [
                'service_call_id' => $call->id,
            ]] as $replayOptions) {
            $replay = $control->execute('billing', 'invoices', 'approve', $replayOptions);
            $this->assertFalse($replay['accepted']);
            $this->assertSame($call->id, $replay['service_call_id']);
            $this->assertSame($reason, $replay['outcome_reason']);
            $this->assertSame($errorType, $replay['error_type']);
            $this->assertSame($message, $replay['message']);
            $this->assertSame($before, $call->fresh()->getRawOriginal());
            $this->assertSame(1, WorkflowServiceCall::query()->count());
            $this->assertSame(1, $policy->evaluations);
            $this->assertSame([ServiceCallStatus::Failed->value], $policy->releasedStatuses);
        }
        $this->assertSame($catalogBefore, $operation->fresh()->getRawOriginal());
        Queue::assertNothingPushed();
    }

    /**
     * @return iterable<string, array{ServiceCallBindingKind, string, string, string, string}>
     */
    public static function bindingFailures(): iterable
    {
        foreach ([
            [
                ServiceCallBindingKind::WorkflowRun,
                'start',
                'workflow_start',
                'Workflow start binding has no workflow type.',
            ],
            [
                ServiceCallBindingKind::WorkflowUpdate,
                'update',
                'workflow_update',
                'Workflow update binding has no workflow instance id.',
            ],
            [
                ServiceCallBindingKind::WorkflowSignal,
                'signal',
                'workflow_signal',
                'Workflow signal binding has no workflow instance id.',
            ],
            [
                ServiceCallBindingKind::WorkflowQuery,
                'query',
                'workflow_query',
                'Workflow query binding has no workflow instance id.',
            ],
        ] as [$kind, $method, $prefix, $missingMessage]) {
            yield $method . ' missing target' => [
                $kind,
                $method,
                'missing target',
                'handler_target_missing',
                $missingMessage,
            ];
            yield $method . ' exception' => [
                $kind,
                $method,
                'exception',
                $prefix . '_exception',
                'The workflow adapter failed.',
            ];
            yield $method . ' rejected' => [$kind, $method, 'rejected', $prefix . '_rejected', $prefix . '_rejected'];
            yield $method . ' typed rejection' => [
                $kind,
                $method,
                'typed rejection',
                'approval_denied',
                'The invoice approval was denied.',
            ];
        }
    }

    /**
     * @param array<string, mixed> $binding
     * @param array<string, mixed> $options
     */
    #[DataProvider('updateRouting')]
    public function testUpdateBindingUsesCatalogAndCallerRoutingInTheDeclaredOrder(
        array $binding,
        array $options,
        ?string $reference,
        string $expectedInstance,
        string $expectedName,
        string $expectedWait,
    ): void {
        $arguments = [42, false, ''];
        $payload = Serializer::serialize($arguments);
        $this->catalog(ServiceCallBindingKind::WorkflowUpdate, ServiceCallOperationMode::Async, $binding + [
            'arguments' => [99, true],
            'payload_codec' => 'avro',
            'payload_blob' => $payload,
            'wait_for' => 'accepted',
            'wait_timeout_seconds' => 60,
        ], $reference);
        $policy = new WorkflowBindingBoundaryObserver();
        /** @var WorkflowControlPlane&MockInterface $workflows */
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $forwarded = [];
        $workflows->shouldReceive('update')
            ->once()
            ->andReturnUsing(
                static function (string $instanceId, string $name, array $commandOptions) use (&$forwarded): array {
                    $forwarded = [$instanceId, $name, $commandOptions];

                    return [
                        'accepted' => true,
                        'update_status' => 'accepted',
                        'workflow_instance_id' => $instanceId,
                        'update_id' => 'route-update',
                    ];
                }
            );
        $result = (new DefaultServiceControlPlane($workflows, $policy))->execute(
            'billing',
            'invoices',
            'approve',
            $options + [
                'namespace' => 'billing',
                'arguments' => $arguments,
                'wait_timeout_seconds' => 0,
            ]
        );
        $this->assertTrue($result['accepted']);
        $this->assertSame([$expectedInstance, $expectedName], array_slice($forwarded, 0, 2));
        $this->assertSameJsonObject([
            'namespace' => 'billing',
            'arguments' => $arguments,
            'payload_codec' => 'avro',
            'payload_blob' => $payload,
            'wait_for' => $expectedWait,
            'wait_timeout_seconds' => 0,
        ], $forwarded[2]);
        $call = WorkflowServiceCall::query()->sole();
        $this->assertSame(ServiceCallStatus::Started->value, $call->status);
        $this->assertSame('route-update', $call->resolved_target_reference);
        $this->assertSame($expectedInstance, $call->linked_workflow_instance_id);
        $this->assertSame($expectedName, $call->metadata['update_name']);
        $this->assertSame([], $policy->releasedStatuses);
        $this->assertSame(1, $policy->evaluations);
        Queue::assertNothingPushed();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, ?string, string, string, string}>
     */
    public static function updateRouting(): iterable
    {
        yield 'catalog primary names precede aliases and caller target' => [[
            'workflow_instance_id' => 'catalog-owner',
            'instance_id' => 'alias-owner',
            'update_name' => 'catalog-update',
            'name' => 'alias-update',
        ], [
            'target_workflow_instance_id' => 'caller-owner',
        ], 'reference-update', 'catalog-owner', 'catalog-update', 'accepted'];
        yield 'catalog instance and name aliases' => [[
            'instance_id' => 'alias-owner',
            'name' => 'alias-update',
        ], [
            'workflow_instance_id' => 'caller-owner',
        ], 'reference-update', 'alias-owner', 'alias-update', 'accepted'];
        yield 'catalog target alias' => [[
            'target_instance_id' => 'alias-owner',
        ], [], 'reference-update', 'alias-owner', 'reference-update', 'accepted'];
        yield 'caller target precedes caller instance alias' => [[], [
            'target_workflow_instance_id' => 'target-owner',
            'workflow_instance_id' => 'alias-owner',
        ], 'reference-update', 'target-owner', 'reference-update', 'accepted'];
        yield 'caller instance and operation name fallbacks' => [[], [
            'workflow_instance_id' => 'alias-owner',
        ], null, 'alias-owner', 'approve', 'accepted'];
        yield 'caller wait overrides catalog wait' => [[
            'instance_id' => 'catalog-owner',
        ], [
            'wait_for' => 'completed',
        ], null, 'catalog-owner', 'approve', 'completed'];
    }

    public function testInvalidHostQueryResultProducesADurableFailureWithoutWorkflowFallback(): void
    {
        $this->catalog(ServiceCallBindingKind::WorkflowQuery, ServiceCallOperationMode::Sync, [
            'workflow_instance_id' => 'invoice-owner',
            'query_name' => 'approval',
        ]);
        $calls = [];
        $this->app->instance(
            'workflow.v2.service_control_plane.workflow_query_handler',
            static function (string $instanceId, string $queryName, array $options) use (&$calls): int {
                $calls[] = [$instanceId, $queryName, $options];

                return 42;
            }
        );
        $policy = new WorkflowBindingBoundaryObserver();
        /** @var WorkflowControlPlane&MockInterface $workflows */
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $control = new DefaultServiceControlPlane($workflows, $policy);
        $options = [
            'namespace' => 'billing',
            'caller_namespace' => 'finance',
            'idempotency_key' => 'invalid-host-query-42',
        ];
        $result = $control->execute('billing', 'invoices', 'approve', $options);
        $call = WorkflowServiceCall::query()->sole();
        $this->assertFalse($result['accepted']);
        $this->assertSame('workflow_query_handler_invalid_result', $result['reason']);
        $this->assertSame(
            'Configured workflow query service handler did not return an array result.',
            $result['message']
        );
        $this->assertSame(ServiceCallStatus::Failed->value, $call->status);
        $this->assertSame(ServiceCallOutcome::HandlerFailed, $call->outcome);
        $this->assertSame([ServiceCallStatus::Failed->value], $policy->releasedStatuses);
        $this->assertCount(1, $calls);
        $this->assertSame(['invoice-owner', 'approval'], array_slice($calls[0], 0, 2));
        $this->assertSame($call->id, $calls[0][2]['service_call_id']);
        $this->assertSame(1, $calls[0][2]['service_call_attempt']);
        $this->assertSame(1, $calls[0][2]['service_call_max_attempts']);
        $this->assertSame(500, $call->outcome_metadata['control_plane']['status']);
        $this->assertArrayNotHasKey('result', $call->outcome_metadata['control_plane']);
        $before = $call->getRawOriginal();
        $replay = $control->execute('billing', 'invoices', 'approve', $options);
        $this->assertFalse($replay['accepted']);
        $this->assertTrue($replay['idempotent_replay']);
        $this->assertSame($before, $call->fresh()->getRawOriginal());
        $this->assertCount(1, $calls);
        $this->assertSame(1, $policy->evaluations);
        $this->assertSame([ServiceCallStatus::Failed->value], $policy->releasedStatuses);
        Queue::assertNothingPushed();
    }

    /**
     * @param array<string, mixed> $binding
     */
    private function catalog(
        ServiceCallBindingKind $kind,
        ServiceCallOperationMode $mode,
        array $binding,
        ?string $reference = null,
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
            'handler_target_reference' => $reference,
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
