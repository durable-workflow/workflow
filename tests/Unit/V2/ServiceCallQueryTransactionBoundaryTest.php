<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\DeadlockException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Exceptions\ServiceOperationAuthorizationException;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\ServiceCallPrincipal;
use Workflow\V2\Support\ServiceCallResultEnvelope;

final class ServiceCallQueryTransactionBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-27T09:00:00Z');
    }

    protected function tearDown(): void
    {
        DB::purge('query_race');
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testAnAdmittedQueryRetainsItsObservationAcrossAnIndependentCommit(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() === ':memory:') {
            $this->markTestSkipped('The stale reader/writer regression requires file-backed SQLite.');
        }
        Carbon::setTestNow('2026-10-27T09:00:00Z');
        $connection->statement('PRAGMA journal_mode=WAL');
        $configuration = config('database.connections.' . $connection->getName());
        config([
            'database.connections.query_pressure_writer' => $configuration,
        ]);
        $writer = DB::connection('query_pressure_writer');
        $writer->statement('PRAGMA busy_timeout=100');
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
        ]);
        $value = [
            'integer' => PHP_INT_MAX,
            'nil' => null,
            'text' => 'worker Ω',
        ];
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('query')
            ->once()
            ->andReturnUsing(static function () use ($writer, $value): array {
                // A real worker or maintenance process commits on its own
                // connection while the HTTP caller waits for the query result.
                WorkflowServiceEndpoint::on($writer->getName())->create([
                    'namespace' => 'maintenance',
                    'endpoint_name' => 'independent-write',
                ]);

                return [
                    'success' => true,
                    'workflow_instance_id' => 'worker-owned',
                    'run_id' => 'worker-run',
                    'result' => $value,
                ];
            });
        $control = new DefaultServiceControlPlane($workflows);
        $options = [
            'namespace' => 'billing',
            'caller_namespace' => 'client',
            'principal' => new ServiceCallPrincipal(subject: 'http-client', method: 'token', roles: ['worker']),
        ];
        try {
            $accepted = $control->execute('billing', 'invoices', 'query', $options + [
                'dispatch_handler' => false,
            ]);
            $result = $control->execute('billing', 'invoices', 'query', $options + [
                'service_call_id' => $accepted['service_call_id'],
                'boundary_policy_outcome' => 'accepted',
            ]);
            $this->assertSame('completed', $result['status']);
            $this->assertSame($accepted['service_call_id'], $result['service_call_id']);
            $this->assertSame(1, WorkflowServiceCall::query()->count());
            $this->assertSame(1, WorkflowServiceEndpoint::query()->where('namespace', 'maintenance')->count());
            $envelope = ServiceCallResultEnvelope::fromCall(WorkflowServiceCall::query()->sole());
            $this->assertNotNull($envelope);
            $this->assertSame(
                $value,
                \Workflow\Serializers\Serializer::unserializeWithCodec($envelope['codec'], $envelope['blob'])
            );
        } finally {
            DB::purge('query_pressure_writer');
            Carbon::setTestNow();
        }
    }

    public function testANewKeyedCallRemainsUncommittedUntilItsQueryReturns(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() === ':memory:') {
            $this->markTestSkipped('Independent visibility requires file-backed SQLite.');
        }
        $connection->statement('PRAGMA journal_mode=WAL');
        config([
            'database.connections.query_visibility' => config('database.connections.' . $connection->getName()),
        ]);
        $reader = DB::connection('query_visibility');
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
        ]);
        $visibleDuringQuery = null;
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('query')
            ->once()
            ->andReturnUsing(static function () use ($reader, &$visibleDuringQuery): array {
                $visibleDuringQuery = $reader->table('workflow_service_calls')
                    ->count();

                return [
                    'success' => true,
                    'result' => 'atomic-query',
                ];
            });
        try {
            $result = (new DefaultServiceControlPlane($workflows))->execute('billing', 'invoices', 'query', [
                'namespace' => 'billing',
                'idempotency_key' => 'fresh-key',
            ]);
            $this->assertSame('completed', $result['status']);
            $this->assertSame(
                0,
                $visibleDuringQuery,
                'A crash before the query must leave no committed accepted-only call.'
            );
            $this->assertSame(1, $reader->table('workflow_service_calls')->count());
        } finally {
            DB::purge('query_visibility');
        }
    }

    public function testAnExistingCallerTransactionRemainsOwnedByItsCaller(): void
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
            'operation_mode' => 'sync',
            'handler_binding_kind' => 'query_workflow',
            'handler_target_reference' => 'snapshot',
            'handler_binding' => [
                'workflow_instance_id' => 'worker-owned',
            ],
        ]);
        $connection = DB::connection();
        $level = $connection->transactionLevel();
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('query')
            ->once()
            ->andReturnUsing(function () use ($connection, $level): array {
                $this->assertSame($level + 1, $connection->transactionLevel());

                return [
                    'success' => true,
                    'result' => 'caller-owned',
                ];
            });
        $connection->beginTransaction();
        try {
            $result = (new DefaultServiceControlPlane($workflows))->execute('billing', 'invoices', 'query', [
                'namespace' => 'billing',
                'idempotency_key' => 'caller-owned-key',
            ]);
            $this->assertSame('completed', $result['status']);
            $this->assertSame($level + 1, $connection->transactionLevel());
        } finally {
            $connection->rollBack($level);
        }
        $this->assertSame(0, WorkflowServiceCall::query()->count());
    }

    public function testCancellationThatWinsTheWriteRaceIsPreservedWithoutAnotherQuery(): void
    {
        $writer = $this->independentWriter();
        $this->catalogQuery();
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $control = new DefaultServiceControlPlane($workflows);
        $accepted = $control->execute('billing', 'invoices', 'query', $this->executionOptions() + [
            'dispatch_handler' => false,
        ]);
        $workflows->shouldReceive('query')
            ->once()
            ->andReturnUsing(static function () use ($writer, $accepted): array {
                $writer->table('workflow_service_calls')
                    ->where('id', $accepted['service_call_id'])
                    ->update([
                        'status' => 'cancelled',
                        'outcome' => 'cancelled',
                        'cancelled_at' => '2026-10-27 09:00:00',
                    ]);

                return [
                    'success' => true,
                    'result' => 'obsolete-query-result',
                ];
            });
        $result = $control->execute('billing', 'invoices', 'query', $this->executionOptions() + [
            'service_call_id' => $accepted['service_call_id'],
            'boundary_policy_outcome' => 'accepted',
        ]);
        $this->assertSame('cancelled', $result['status']);
        $this->assertSame($accepted['service_call_id'], $result['service_call_id']);
        $this->assertArrayNotHasKey('result_envelope', $result);
        $this->assertSame(1, WorkflowServiceCall::query()->count());
    }

    #[DataProvider('retargets')]
    public function testAChangedQueryTargetCannotReceiveAnEarlierObservation(array $changes): void
    {
        $writer = $this->independentWriter();
        $operation = $this->catalogQuery();
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $control = new DefaultServiceControlPlane($workflows);
        $accepted = $control->execute('billing', 'invoices', 'query', $this->executionOptions() + [
            'dispatch_handler' => false,
        ]);
        $workflows->shouldReceive('query')
            ->once()
            ->andReturnUsing(static function () use ($writer, $operation, $changes): array {
                $writer->table('workflow_service_operations')
                    ->where('id', $operation->id)
                    ->update($changes);

                return [
                    'success' => true,
                    'result' => 'original-target-result',
                ];
            });
        try {
            $control->execute('billing', 'invoices', 'query', $this->executionOptions() + [
                'service_call_id' => $accepted['service_call_id'],
                'boundary_policy_outcome' => 'accepted',
            ]);
            $this->fail('A changed query scope must refuse the old observation.');
        } catch (ServiceOperationAuthorizationException $failure) {
            $this->assertSame('service_query_scope_changed', $failure->getMessage());
        }
        $call = WorkflowServiceCall::query()->sole();
        $this->assertSame($accepted['service_call_id'], $call->id);
        $this->assertSame('accepted', $call->status);
        $this->assertNull(ServiceCallResultEnvelope::fromCall($call));
        $this->assertArrayNotHasKey('activity_execution_id', $call->metadata);
    }

    public function testATypedFailureIsReusedAfterTheWriteRollbackWithoutAnotherQuery(): void
    {
        $writer = $this->independentWriter();
        $this->catalogQuery();
        $failure = [
            'success' => false,
            'reason' => 'application_refused',
            'message' => 'Fixture business refusal',
            'error_type' => 'FixtureBusinessError',
            'details' => [
                'code' => 7,
                'nil' => null,
            ],
        ];
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('query')
            ->once()
            ->andReturnUsing(static function () use ($writer, $failure): array {
                WorkflowServiceEndpoint::on($writer->getName())->create([
                    'namespace' => 'maintenance',
                    'endpoint_name' => 'typed-failure-writer',
                ]);

                return $failure;
            });
        $control = new DefaultServiceControlPlane($workflows);
        $accepted = $control->execute('billing', 'invoices', 'query', $this->executionOptions() + [
            'dispatch_handler' => false,
        ]);
        $result = $control->execute('billing', 'invoices', 'query', $this->executionOptions() + [
            'service_call_id' => $accepted['service_call_id'],
            'boundary_policy_outcome' => 'accepted',
        ]);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('FixtureBusinessError', $result['failure_type']);
        $call = WorkflowServiceCall::query()->sole();
        $this->assertSame($failure, $call->metadata['control_plane']);
        $this->assertSame($accepted['service_call_id'], $call->id);
    }

    public function testAnExpiredDeadlineWinsWithoutReissuingTheObservedQuery(): void
    {
        $writer = $this->independentWriter();
        $this->catalogQuery();
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $control = new DefaultServiceControlPlane($workflows);
        $accepted = $control->execute('billing', 'invoices', 'query', $this->executionOptions() + [
            'dispatch_handler' => false,
        ]);
        $workflows->shouldReceive('query')
            ->once()
            ->andReturnUsing(static function () use ($writer, $accepted): array {
                $writer->table('workflow_service_calls')
                    ->where('id', $accepted['service_call_id'])->update([
                'call_deadline_at' => '2026-10-27 08:59:00',
            ]);

                return [
                    'success' => true,
                    'result' => 'late-result',
                ];
            });
        $result = $control->execute('billing', 'invoices', 'query', $this->executionOptions() + [
            'service_call_id' => $accepted['service_call_id'],
            'boundary_policy_outcome' => 'accepted',
        ]);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('timed_out', $result['outcome']);
        $this->assertSame($accepted['service_call_id'], $result['service_call_id']);
        $this->assertArrayNotHasKey('result_envelope', $result);
    }

    public function testALockBeforeAnyQueryObservationIsNotAutomaticallyRetried(): void
    {
        $writer = $this->independentWriter();
        $this->catalogQuery();
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('query')
            ->once()
            ->andReturnUsing(static function () use ($writer): never {
                WorkflowServiceEndpoint::on($writer->getName())->create([
                    'namespace' => 'maintenance',
                    'endpoint_name' => 'unobserved-writer',
                ]);

                throw new \RuntimeException('No worker query result was observed.');
            });
        $control = new DefaultServiceControlPlane($workflows);
        $accepted = $control->execute('billing', 'invoices', 'query', $this->executionOptions() + [
            'dispatch_handler' => false,
        ]);
        try {
            $control->execute('billing', 'invoices', 'query', $this->executionOptions() + [
                'service_call_id' => $accepted['service_call_id'],
                'boundary_policy_outcome' => 'accepted',
            ]);
            $this->fail('A lock before an observation must retain the original failure.');
        } catch (DeadlockException) {
            $this->assertSame('accepted', WorkflowServiceCall::query()->sole()->status);
        }
    }

    public static function retargets(): iterable
    {
        yield 'query name' => [[
            'handler_target_reference' => 'different-query',
        ]];
        yield 'workflow instance' => [[
            'handler_binding' => '{"workflow_instance_id":"different-workflow"}',
        ]];
        yield 'arguments' => [[
            'handler_binding' => '{"workflow_instance_id":"worker-owned","arguments":["different"]}',
        ]];
        yield 'binding kind' => [[
            'handler_binding_kind' => 'activity_execution',
        ]];
    }

    private function independentWriter(): \Illuminate\Database\Connection
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() === ':memory:') {
            $this->markTestSkipped('The independent writer race requires file-backed SQLite.');
        }
        $connection->statement('PRAGMA journal_mode=WAL');
        config([
            'database.connections.query_race' => config('database.connections.' . $connection->getName()),
        ]);
        $writer = DB::connection('query_race');
        $writer->statement('PRAGMA busy_timeout=100');

        return $writer;
    }

    private function catalogQuery(): WorkflowServiceOperation
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

        return WorkflowServiceOperation::query()->create([
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
        ]);
    }

    private function executionOptions(): array
    {
        return [
            'namespace' => 'billing',
            'caller_namespace' => 'client',
            'principal' => new ServiceCallPrincipal(subject: 'http-client', method: 'token', roles: ['worker']),
        ];
    }
}
