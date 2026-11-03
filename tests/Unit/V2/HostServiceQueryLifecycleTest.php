<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Contracts\ServiceBoundaryPolicy;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceCallDispatch;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\DefaultServiceBoundaryPolicy;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\ServiceCallPrincipal;
use Workflow\V2\Support\ServiceCallScheduler;

final class HostServiceQueryLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-10-27T09:00:00Z');
    }

    protected function tearDown(): void
    {
        DB::purge('query_result_writer');
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testJsonCannotForgeHostQuerySchedulingAuthority(): void
    {
        $options = $this->admittedQuery();
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('query')
            ->once()
            ->andReturn([
                'success' => true,
                'result' => 7,
            ]);
        $forged = json_decode(
            '{"__schedule_host_query_after_commit":{},"dispatch_origin":"authenticated_host_query"}',
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $result = (new DefaultServiceControlPlane($workflows))->execute(
            'billing',
            'invoices',
            'query',
            $options + $forged
        );
        $this->assertSame('completed', $result['status']);
        $this->assertSame(0, WorkflowServiceCallDispatch::query()->count());
    }

    public function testHostCapabilityRequiresAnExplicitAdmittedCall(): void
    {
        $options = $this->admittedQuery();
        unset($options['service_call_id']);
        try {
            (new DefaultServiceControlPlane())->execute(
                'billing',
                'invoices',
                'query',
                ServiceCallScheduler::withHostQueryAuthority($options)
            );
            $this->fail('A host capability must not start a fresh Core admission.');
        } catch (\LogicException $failure) {
            $this->assertSame('Host query dispatch requires an admitted service_call_id.', $failure->getMessage());
            $this->assertSame(1, WorkflowServiceCall::query()->count());
            $this->assertSame(0, WorkflowServiceCallDispatch::query()->count());
        }
    }

    public function testHostCapabilityCannotScheduleAMutatingBinding(): void
    {
        $options = $this->admittedQuery();
        WorkflowServiceOperation::query()->sole()->update([
            'handler_binding_kind' => 'activity_execution',
        ]);
        try {
            (new DefaultServiceControlPlane())->execute(
                'billing',
                'invoices',
                'query',
                ServiceCallScheduler::withHostQueryAuthority($options)
            );
            $this->fail('A host query capability must not schedule an activity.');
        } catch (\LogicException $failure) {
            $this->assertSame('Host query dispatch requires a workflow query binding.', $failure->getMessage());
            $this->assertSame(0, WorkflowServiceCallDispatch::query()->count());
            $this->assertSame('accepted', WorkflowServiceCall::query()->sole()->status);
        }
    }

    public function testWorkflowSchedulingCannotForgeAHostOriginToBypassItsMissingCaller(): void
    {
        $options = $this->admittedQuery([], [
            'caller_workflow_instance_id' => 'missing',
            'caller_workflow_run_id' => 'missing-run',
        ]);
        (new DefaultServiceControlPlane())->execute(
            'billing',
            'invoices',
            'query',
            ServiceCallScheduler::withAfterCommitAuthority($options + [
                'dispatch_origin' => 'authenticated_host_query',
            ])
        );
        $this->assertArrayNotHasKey('dispatch_origin', WorkflowServiceCallDispatch::query()->sole()->dispatch_options);
        $this->assertNull(ServiceCallScheduler::claim($options['service_call_id']));
        $this->assertSame('cancelled', WorkflowServiceCall::query()->sole()->status);
    }

    public function testLostDispatchIsRepairableAndExpiredGenerationCannotPublish(): void
    {
        $options = $this->scheduleQuery();
        Carbon::setTestNow(now()->addSeconds(6));
        $this->assertContains($options['service_call_id'], ServiceCallScheduler::dueIds(10));
        $first = ServiceCallScheduler::claim($options['service_call_id']);
        $this->assertNotNull($first);
        Carbon::setTestNow(now()->addSeconds(61));
        $this->assertFalse(ServiceCallScheduler::ownsClaim($first));
        $second = ServiceCallScheduler::claim($options['service_call_id']);
        $this->assertNotNull($second);
        $this->assertSame($first->generation + 1, $second->generation);
        $this->assertNotSame($first->token, $second->token);
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('query')
            ->once()
            ->andReturn([
                'success' => true,
                'result' => 8,
            ]);
        $control = new DefaultServiceControlPlane($workflows);
        $control->executeScheduledAttempt($first);
        $this->assertSame('accepted', WorkflowServiceCall::query()->sole()->status);
        $control->executeScheduledAttempt($second);
        $this->assertSame('completed', WorkflowServiceCall::query()->sole()->status);
        $this->assertSame('completed', WorkflowServiceCallDispatch::query()->sole()->state);
    }

    public function testHostClaimRechecksCurrentPolicyWithTheAcceptedPrincipal(): void
    {
        $policy = new DefaultServiceBoundaryPolicy();
        $this->app->instance(ServiceBoundaryPolicy::class, $policy);
        $options = $this->scheduleQuery();
        $policy->setRules([
            'default_action' => 'deny',
        ]);
        $this->assertNull(ServiceCallScheduler::claim($options['service_call_id']));
        $this->assertSame('failed', WorkflowServiceCall::query()->sole()->status);
        $this->assertSame('default_action_denied', WorkflowServiceCall::query()->sole()->outcome_reason);
    }

    public function testReplayAndCatalogChangesCannotRetargetTheDurableQuery(): void
    {
        $options = $this->admittedQuery([], [
            'arguments' => [PHP_INT_MAX, null, '雪'],
        ]);
        $control = new DefaultServiceControlPlane();
        $control->execute('billing', 'invoices', 'query', ServiceCallScheduler::withHostQueryAuthority($options, 150));
        WorkflowServiceOperation::query()->sole()->update([
            'handler_binding_kind' => 'activity_execution',
            'handler_target_reference' => 'different-handler',
            'handler_binding' => [
                'workflow_instance_id' => 'different-instance',
            ],
        ]);
        $replay = $control->execute(
            'billing',
            'invoices',
            'query',
            ServiceCallScheduler::withHostQueryAuthority(array_replace($options, [
                'arguments' => ['different-arguments'],
            ]))
        );
        $this->assertSame('workflow_query', $replay['resolved_binding_kind']);
        $claim = ServiceCallScheduler::claim($options['service_call_id']);
        $this->assertNotNull($claim);
        $this->assertSame(
            150,
            (int) now()
                ->diffInSeconds(WorkflowServiceCallDispatch::query()->sole()->claim_expires_at)
        );
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('query')
            ->once()
            ->withArgs(static function (string $instance, string $name, array $queryOptions): bool {
                return $instance === 'worker-owned' && $name === 'snapshot'
                    && $queryOptions['arguments'] === [PHP_INT_MAX, null, '雪'];
            })->andReturn([
                'success' => true,
                'result' => 'original-intent',
            ]);
        (new DefaultServiceControlPlane($workflows))->executeScheduledAttempt($claim);
        $this->assertSame('completed', WorkflowServiceCall::query()->sole()->status);
        $this->assertSame('workflow_query', WorkflowServiceCall::query()->sole()->resolved_binding_kind);
    }

    #[DataProvider('terminalRaces')]
    public function testTerminalWinnerDuringQueryIsPreserved(string $winner): void
    {
        $options = $this->scheduleQuery();
        $claim = ServiceCallScheduler::claim($options['service_call_id']);
        $this->assertNotNull($claim);
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('query')
            ->once()
            ->andReturnUsing(static function () use ($winner, $options): array {
                if ($winner === 'cancelled') {
                    (new DefaultServiceControlPlane())->cancelCall($options['service_call_id'], [
                        'namespace' => 'billing',
                    ]);
                } else {
                    WorkflowServiceCall::query()->whereKey($options['service_call_id'])->update([
                        'call_deadline_at' => now()
                            ->subSecond(),
                    ]);
                }

                return [
                    'success' => true,
                    'result' => 'late-result',
                ];
            });
        (new DefaultServiceControlPlane($workflows))->executeScheduledAttempt($claim);
        $call = WorkflowServiceCall::query()->sole();
        $this->assertSame($winner === 'cancelled' ? 'cancelled' : 'failed', $call->status);
        $this->assertArrayNotHasKey('nexus_result', $call->metadata ?? []);
    }

    public static function terminalRaces(): iterable
    {
        yield 'cancellation' => ['cancelled'];
        yield 'deadline' => ['deadline'];
    }

    #[DataProvider('businessRetries')]
    public function testBusinessAttemptsEachObserveTheWorker(bool $eventualSuccess): void
    {
        $options = $this->scheduleQuery([
            'max_attempts' => 2,
            'backoff_seconds' => 0,
        ]);
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $reads = 0;
        $workflows->shouldReceive('query')
            ->twice()
            ->andReturnUsing(function (string $instance, string $query, array $queryOptions) use (
                &$reads,
                $eventualSuccess
            ): array {
                $reads++;
                $this->assertSame($reads, $queryOptions['service_call_attempt']);
                $this->assertSame(0, DB::connection()->transactionLevel());

                return $eventualSuccess && $reads === 2
                    ? [
                        'success' => true,
                        'result' => 'recovered',
                    ]
                    : [
                        'success' => false,
                        'reason' => 'temporary_query_failure',
                        'message' => 'retry the worker',
                        'failure_type' => 'TransientQueryFailure',
                    ];
            });
        $control = new DefaultServiceControlPlane($workflows);
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $claim = ServiceCallScheduler::claim($options['service_call_id']);
            $this->assertNotNull($claim);
            $this->assertSame($attempt, $claim->attempt);
            $control->executeScheduledAttempt($claim);
        }
        $call = WorkflowServiceCall::query()->sole();
        $this->assertSame($eventualSuccess ? 'completed' : 'failed', $call->status);
        $this->assertSame(2, $reads);
        $this->assertCount(2, $call->metadata['service_call_attempts']);
        $this->assertSame('completed', WorkflowServiceCallDispatch::query()->sole()->state);
    }

    public static function businessRetries(): iterable
    {
        yield 'failure then success' => [true];
        yield 'exhausted failures' => [false];
    }

    public function testQueryCompletionReusesItsReturnedValueAcrossAShortSqliteWriteRetry(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() === ':memory:') {
            $this->markTestSkipped('The stale completion snapshot requires file-backed SQLite.');
        }
        $this->useSqliteWalJournal();
        config([
            'database.connections.query_result_writer' => config('database.connections.' . $connection->getName()),
        ]);
        $writer = DB::connection('query_result_writer');
        $options = $this->scheduleQuery();
        $claim = ServiceCallScheduler::claim($options['service_call_id']);
        $this->assertNotNull($claim);
        $armed = false;
        $writes = 0;
        $connection->beforeExecuting(static function (string $sql) use ($writer, &$armed, &$writes): void {
            if (! $armed || ! str_starts_with($sql, 'update "workflow_service_calls"')) {
                return;
            }
            $armed = false;
            $writes++;
            WorkflowServiceEndpoint::on($writer->getName())->create([
                'namespace' => 'maintenance',
                'endpoint_name' => 'completion-race',
            ]);
        });
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('query')
            ->once()
            ->andReturnUsing(static function () use (&$armed): array {
                $armed = true;

                return [
                    'success' => true,
                    'result' => [
                        'maximum' => PHP_INT_MAX,
                        'nil' => null,
                        'text' => '雪',
                    ],
                ];
            });
        (new DefaultServiceControlPlane($workflows))->executeScheduledAttempt($claim);
        $this->assertSame(1, $writes);
        $this->assertSame('completed', WorkflowServiceCall::query()->sole()->status);
        $this->assertSame(1, WorkflowServiceEndpoint::query()->where('namespace', 'maintenance')->count());
    }

    /**
     * @param array<string, mixed> $retryPolicy
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function admittedQuery(array $retryPolicy = [], array $extra = []): array
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'billing',
            'endpoint_name' => 'billing',
        ]);
        $service = WorkflowService::query()->create([
            'namespace' => 'billing',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'invoices',
        ]);
        WorkflowServiceOperation::query()->create([
            'namespace' => 'billing',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'operation_name' => 'query',
            'operation_mode' => 'async',
            'handler_binding_kind' => 'query_workflow',
            'handler_target_reference' => 'snapshot',
            'handler_binding' => [
                'workflow_instance_id' => 'worker-owned',
            ],
            'retry_policy' => $retryPolicy,
        ]);
        $options = $extra + [
            'namespace' => 'billing',
            'caller_namespace' => 'client',
            'principal' => new ServiceCallPrincipal(subject: 'http-client', method: 'token', roles: ['worker']),
        ];
        $accepted = (new DefaultServiceControlPlane())->execute('billing', 'invoices', 'query', $options + [
            'dispatch_handler' => false,
        ]);

        return $options + [
            'service_call_id' => $accepted['service_call_id'],
            'boundary_policy_outcome' => 'accepted',
        ];
    }

    /**
     * @param array<string, mixed> $retryPolicy
     * @return array<string, mixed>
     */
    private function scheduleQuery(array $retryPolicy = []): array
    {
        $options = $this->admittedQuery($retryPolicy);
        (new DefaultServiceControlPlane())->execute(
            'billing',
            'invoices',
            'query',
            ServiceCallScheduler::withHostQueryAuthority($options)
        );

        return $options;
    }
}
