<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\ServiceCallPrincipal;
use Workflow\V2\Support\ServiceCallResultEnvelope;

final class ServiceCallQueryTransactionBoundaryTest extends TestCase
{
    public function testAnAdmittedQueryCommitsBeforeWaitingForAnIndependentWorker(): void
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

    public function testADeferredQueryObservesCancellationCommittedBeforeDispatch(): void
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
        ]);
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldNotReceive('query');
        $control = new DefaultServiceControlPlane($workflows);
        $options = [
            'namespace' => 'billing',
            'caller_namespace' => 'client',
            'principal' => new ServiceCallPrincipal(subject: 'http-client', method: 'token', roles: ['worker']),
        ];
        $accepted = $control->execute('billing', 'invoices', 'query', $options + [
            'dispatch_handler' => false,
        ]);
        $armed = true;
        $this->app['events']->listen(TransactionCommitted::class, static function (TransactionCommitted $event) use (
            &$armed,
            $accepted
        ): void {
            if ($armed && $event->connection->transactionLevel() === 0) {
                $armed = false;
                WorkflowServiceCall::query()->whereKey($accepted['service_call_id'])->update([
                    'status' => 'cancelled',
                    'outcome' => 'cancelled',
                    'cancelled_at' => now(),
                ]);
            }
        });
        $result = $control->execute('billing', 'invoices', 'query', $options + [
            'service_call_id' => $accepted['service_call_id'],
            'boundary_policy_outcome' => 'accepted',
        ]);
        $this->assertFalse($armed);
        $this->assertSame('cancelled', $result['status']);
        $this->assertSame($accepted['service_call_id'], $result['service_call_id']);
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
}
