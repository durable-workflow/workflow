<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Contracts\WorkflowServiceOperationAuthorizer;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\DenyWorkflowServiceOperationAuthorizer;
use Workflow\V2\Support\RunServiceOperationAuthorizer;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class WorkflowServiceOperationAuthorityTest extends TestCase
{
    private WorkflowRun $run;

    private WorkflowTask $task;

    private WorkflowTaskBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(WorkflowServiceOperationAuthorizer::class, new RunServiceOperationAuthorizer());
        Carbon::setTestNow('2026-10-25T21:00:00Z');
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
            'workflows.v2.compatibility.current' => 'authority-test',
            'workflows.v2.compatibility.supported' => ['authority-test'],
            'workflows.v2.namespace' => null,
        ]);
        WorkerCompatibilityFleet::clear();
        Queue::fake();
        $workflow = WorkflowStub::make(ServiceAuthorityWorkflow::class, 'authority-owner', 'caller');
        $this->assertTrue($workflow->attemptStart()->accepted());
        $this->run = WorkflowRun::query()->findOrFail($workflow->runId());
        $this->task = WorkflowTask::query()->where('workflow_run_id', $this->run->id)->sole();
        $this->bridge = $this->app->make(WorkflowTaskBridge::class);
        $this->assertTrue($this->bridge->claimStatus($this->task->id, 'authority-worker')['claimed']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        WorkerCompatibilityFleet::clear();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('forgedAuthority')]
    public function testWorkerAuthorityOverridesRefuseTheWholeCompletion(array $options): void
    {
        $before = $this->records();
        $result = $this->bridge->complete($this->task->id, [
            [
                'type' => 'start_timer',
                'delay_seconds' => 60,
            ],
            $this->command() + $options,
        ]);

        $this->assertFalse($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame('service_operation_authority_invalid', $result['reason']);
        $this->assertSame($before, $this->records());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function forgedAuthority(): iterable
    {
        yield 'foreign namespace' => [[
            'namespace' => 'foreign',
        ]];
        yield 'foreign caller' => [[
            'caller_namespace' => 'foreign',
        ]];
        yield 'principal subject' => [[
            'principal_subject' => 'administrator',
        ]];
        yield 'principal method' => [[
            'principal_method' => 'system',
        ]];
        yield 'principal roles' => [[
            'principal_roles' => ['admin'],
        ]];
        yield 'principal tenant' => [[
            'principal_tenant' => 'foreign',
        ]];
        yield 'principal claims' => [[
            'principal_claims' => [
                'administrator' => true,
            ],
        ]];
    }

    #[DataProvider('foreignCalls')]
    public function testForeignCallReferencesRefuseBeforeAnyCommandWrites(
        string $status,
        string $mismatch,
        bool $idempotency,
    ): void {
        $call = $this->serviceCall($status);
        $call->forceFill(match ($mismatch) {
            'run' => [
                'caller_workflow_run_id' => 'another-run',
            ],
            'instance' => [
                'caller_workflow_instance_id' => 'another-owner',
            ],
            'caller' => [
                'caller_namespace' => 'another-caller',
            ],
            'catalog' => [
                'operation_name' => 'another-operation',
            ],
            'namespace' => [
                'namespace' => 'foreign',
                'target_namespace' => 'foreign',
            ],
            'endpoint id' => [
                'workflow_service_endpoint_id' => 'foreign-endpoint',
            ],
            'service id' => [
                'workflow_service_id' => 'foreign-service',
            ],
            'operation id' => [
                'workflow_service_operation_id' => 'foreign-operation',
            ],
        })->save();
        $options = $idempotency ? [
            'idempotency_key' => $call->idempotency_key,
        ]
            : [
                'service_call_id' => $call->id,
            ];
        $before = $this->records();
        $result = $this->bridge->complete($this->task->id, [
            [
                'type' => 'start_timer',
                'delay_seconds' => 60,
            ],
            $this->command() + $options,
        ]);

        $this->assertFalse($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame('service_call_not_owned', $result['reason']);
        $this->assertSame($before, $this->records());
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function foreignCalls(): iterable
    {
        foreach (['pending', 'started', 'completed'] as $status) {
            foreach ([
                'run',
                'instance',
                'caller',
                'catalog',
                'namespace',
                'endpoint id',
                'service id',
                'operation id',
            ] as $mismatch) {
                yield $status . ' ' . $mismatch => [$status, $mismatch, false];
            }
            foreach (['run', 'instance', 'caller'] as $mismatch) {
                yield $status . ' idempotency ' . $mismatch => [$status, $mismatch, true];
            }
        }
    }

    #[DataProvider('ownedCalls')]
    public function testOwnedCallReplayRemainsUsable(string $status, bool $idempotency): void
    {
        $call = $this->serviceCall($status);
        $result = $this->bridge->complete($this->task->id, [
            $this->command() + ($idempotency
                        ? [
                            'idempotency_key' => $call->idempotency_key,
                        ] : [
                            'service_call_id' => $call->id,
                        ])]);
        $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame(1, WorkflowServiceCall::query()->count());
        $this->assertSame($this->run->id, $call->fresh()->caller_workflow_run_id);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function ownedCalls(): iterable
    {
        foreach (['started', 'completed'] as $status) {
            yield $status . ' by id' => [$status, false];
            yield $status . ' by key' => [$status, true];
        }
    }

    public function testNewCallsUseTheRunWorkloadPrincipal(): void
    {
        $result = $this->bridge->complete($this->task->id, [$this->command()]);
        $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
        $call = WorkflowServiceCall::query()->sole();
        $this->assertSame('caller', $call->namespace);
        $this->assertSame('caller', $call->caller_namespace);
        $this->assertSame('workflow:' . $this->run->id, $call->caller_principal_subject);
        $this->assertSame('workflow', $call->caller_principal_method);
        $this->assertNull($call->caller_principal_roles);
        $this->assertNull($call->caller_principal_tenant);
        $this->assertNull($call->caller_principal_claims);
    }

    public function testMissingTrustedContextRefusesBeforeAnyCommandWrites(): void
    {
        $this->app->forgetInstance(WorkflowServiceOperationAuthorizer::class);
        $this->assertInstanceOf(
            DenyWorkflowServiceOperationAuthorizer::class,
            $this->app->make(WorkflowServiceOperationAuthorizer::class)
        );
        $before = $this->records();
        $result = $this->bridge->complete($this->task->id, [
            [
                'type' => 'start_timer',
                'delay_seconds' => 60,
            ],
            $this->command(),
        ]);
        $this->assertFalse($result['completed']);
        $this->assertSame('service_operation_authority_invalid', $result['reason']);
        $this->assertSame($before, $this->records());
    }

    public function testNullRunNamespaceDoesNotUseAmbientConfiguration(): void
    {
        $this->run->instance->forceFill([
            'namespace' => null,
        ])->save();
        $this->run->forceFill([
            'namespace' => null,
        ])->save();
        $this->task->forceFill([
            'namespace' => null,
        ])->save();
        config([
            'workflows.v2.namespace' => 'ambient-namespace',
        ]);

        $result = $this->bridge->complete($this->task->id, [$this->command()]);
        $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
        $call = WorkflowServiceCall::query()->sole();
        $this->assertNull($call->namespace);
        $this->assertNull($call->target_namespace);
        $this->assertNull($call->caller_namespace);
        $this->assertSame($this->run->id, $call->caller_workflow_run_id);
    }

    public function testDefaultIdempotencyKeyCannotReplayAnotherRunsCall(): void
    {
        $call = $this->serviceCall('completed');
        $call->forceFill([
            'caller_workflow_run_id' => 'another-run',
            'idempotency_key' => 'workflow-service-operation:' . $this->run->workflow_instance_id . ':' . $this->run->id . ':2',
        ])->save();
        $before = $this->records();
        $result = $this->bridge->complete($this->task->id, [
            [
                'type' => 'start_timer',
                'delay_seconds' => 60,
            ],
            $this->command(),
        ]);
        $this->assertFalse($result['completed']);
        $this->assertSame('service_call_not_owned', $result['reason']);
        $this->assertSame($before, $this->records());
    }

    public function testRetainedClaimPrefixRefusesForeignCallBeforeWritingItsSibling(): void
    {
        $call = $this->serviceCall('completed');
        $call->forceFill([
            'caller_workflow_instance_id' => 'another-instance',
        ])->save();
        $before = $this->records();
        $result = $this->app->make(\Workflow\V2\Contracts\PreparedLocalActivityTaskBridge::class)
            ->checkpointLocalActivityPrefix(
                $this->task->id,
                'authority-worker',
                $this->task->fresh()
->attempt_count,
                'authority-checkpoint',
                1,
                [
                    [
                        'type' => 'start_timer',
                        'delay_seconds' => 60,
                    ],
                    $this->command() + [
                        'service_call_id' => $call->id,
                    ],
                ],
                '1.20'
            );
        $this->assertFalse($result['checkpointed'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame('service_call_not_owned', $result['reason']);
        $this->assertSame($before, $this->records());
    }

    public function testDuplicatePrefixStillRequiresCurrentTrustedAuthority(): void
    {
        $call = $this->serviceCall('completed');
        $bridge = $this->app->make(\Workflow\V2\Contracts\PreparedLocalActivityTaskBridge::class);
        $commands = [
            $this->command() + [
                'service_call_id' => $call->id,
            ]];
        $attempt = $this->task->fresh()
->attempt_count;
        $first = $bridge->checkpointLocalActivityPrefix(
            $this->task->id,
            'authority-worker',
            $attempt,
            'authority-checkpoint',
            1,
            $commands,
            '1.20'
        );
        $this->assertTrue($first['checkpointed'], json_encode($first, JSON_THROW_ON_ERROR));
        $before = $this->records();
        $this->app->instance(WorkflowServiceOperationAuthorizer::class, new DenyWorkflowServiceOperationAuthorizer());
        $duplicate = $bridge->checkpointLocalActivityPrefix(
            $this->task->id,
            'authority-worker',
            $attempt,
            'authority-checkpoint',
            1,
            $commands,
            '1.20'
        );
        $this->assertFalse($duplicate['checkpointed'], json_encode($duplicate, JSON_THROW_ON_ERROR));
        $this->assertSame('service_operation_authority_invalid', $duplicate['reason']);
        $this->assertSame($before, $this->records());
    }

    public function testOwnedResolutionFailureCanBeReplayed(): void
    {
        $first = $this->bridge->complete($this->task->id, [$this->command()]);
        $this->assertTrue($first['completed']);
        $call = WorkflowServiceCall::query()->sole();
        $before = $call->getRawOriginal();
        $this->assertSame('failed', $call->status);
        $task = WorkflowTask::query()->whereKey($first['created_task_ids'][0])->sole();
        $this->assertTrue($this->bridge->claimStatus($task->id, 'authority-worker')['claimed']);
        $replay = $this->bridge->complete($task->id, [
            $this->command() + [
                'service_call_id' => $call->id,
            ]]);
        $this->assertTrue($replay['completed'], json_encode($replay, JSON_THROW_ON_ERROR));
        $this->assertSame(1, WorkflowServiceCall::query()->count());
        $this->assertSame($before, $call->fresh()->getRawOriginal());
    }

    /**
     * @return array<string, mixed>
     */
    private function command(): array
    {
        return [
            'type' => 'start_service_operation',
            'endpoint_name' => 'billing',
            'service_name' => 'invoices',
            'operation_name' => 'create',
        ];
    }

    private function serviceCall(string $status): WorkflowServiceCall
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'caller',
            'endpoint_name' => 'billing',
        ]);
        $service = WorkflowService::query()->create([
            'namespace' => 'caller',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'invoices',
        ]);
        $operation = WorkflowServiceOperation::query()->create([
            'namespace' => 'caller',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'operation_name' => 'create',
            'operation_mode' => 'async',
            'handler_binding_kind' => ServiceCallBindingKind::WorkflowRun->value,
            'handler_target_reference' => 'service-authority-workflow',
        ]);
        return WorkflowServiceCall::query()->create([
            'namespace' => 'caller',
            'target_namespace' => 'caller',
            'caller_namespace' => 'caller',
            'caller_workflow_instance_id' => $this->run->workflow_instance_id,
            'caller_workflow_run_id' => $this->run->id,
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'workflow_service_operation_id' => $operation->id,
            'endpoint_name' => 'billing',
            'service_name' => 'invoices',
            'operation_name' => 'create',
            'operation_mode' => 'async',
            'status' => $status,
            'outcome' => $status === 'completed' ? ServiceCallOutcome::Completed->value
                            : ServiceCallOutcome::Accepted->value,
            'resolved_binding_kind' => ServiceCallBindingKind::WorkflowRun->value,
            'idempotency_key' => 'shared-idempotency-key',
            'accepted_at' => $status === ServiceCallStatus::Pending->value ? null : now(),
            'started_at' => $status === ServiceCallStatus::Started->value ? now() : null,
            'completed_at' => $status === ServiceCallStatus::Completed->value ? now() : null,
        ]);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function records(): array
    {
        $records = [];
        foreach ([WorkflowInstance::class, WorkflowRun::class, WorkflowTask::class, WorkflowCommand::class,
            WorkflowHistoryEvent::class, WorkflowRunSummary::class, WorkflowServiceCall::class,
            WorkflowServiceEndpoint::class, WorkflowService::class, WorkflowServiceOperation::class,
            WorkflowTimer::class] as $model) {
            $records[$model] = $model::query()->orderBy('id')->get()->map(
                static fn (Model $row): array => $row->getRawOriginal(),
            )->all();
        }
        return $records;
    }
}

#[Type('service-authority-workflow')]
final class ServiceAuthorityWorkflow extends Workflow
{
    public function handle(): string
    {
        return 'done';
    }
}
