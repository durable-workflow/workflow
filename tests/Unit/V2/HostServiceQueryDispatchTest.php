<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceCallDispatch;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\ServiceCallPrincipal;
use Workflow\V2\Support\ServiceCallScheduler;

final class HostServiceQueryDispatchTest extends TestCase
{
    public function testAnAdvancingClockQueryWaitsOutsideTheWriterAndResumesFromDurableDispatch(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() === ':memory:') {
            $this->markTestSkipped('Independent writer acceptance requires file-backed SQLite.');
        }
        Queue::fake();
        Carbon::setTestNow('2026-10-27T09:00:00Z');
        $connection->statement('PRAGMA journal_mode=WAL');
        config([
            'database.connections.host_query_writer' => config('database.connections.' . $connection->getName()),
        ]);
        $writer = DB::connection('host_query_writer');
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
        $options = [
            'namespace' => 'billing',
            'caller_namespace' => 'client',
            'principal' => new ServiceCallPrincipal(subject: 'http-client', method: 'token', roles: ['worker']),
        ];
        $accepted = (new DefaultServiceControlPlane())->execute('billing', 'invoices', 'query', $options + [
            'dispatch_handler' => false,
        ]);
        Carbon::setTestNow('2026-10-27T09:00:01Z');
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $reads = 0;
        $workflows->shouldReceive('query')
            ->once()
            ->andReturnUsing(function () use ($writer, &$reads): array {
                $reads++;
                $this->assertSame(
                    0,
                    DB::connection()->transactionLevel(),
                    'An external query must not hold an admission/write transaction.'
                );
                WorkflowServiceEndpoint::on($writer->getName())->create([
                    'namespace' => 'maintenance',
                    'endpoint_name' => 'writer-during-query',
                ]);

                return [
                    'success' => true,
                    'workflow_instance_id' => 'worker-owned',
                    'run_id' => 'worker-run',
                    'result' => [
                        'value' => PHP_INT_MAX,
                        'nil' => null,
                    ],
                ];
            });
        try {
            $control = new DefaultServiceControlPlane($workflows);
            $scheduled = $control->execute(
                'billing',
                'invoices',
                'query',
                ServiceCallScheduler::withHostQueryAuthority($options + [
                    'service_call_id' => $accepted['service_call_id'],
                    'boundary_policy_outcome' => 'accepted',
                ])
            );
            $this->assertSame('accepted', $scheduled['status']);
            $this->assertSame(0, $reads);
            $this->assertSame('scheduled', WorkflowServiceCallDispatch::query()->sole()->state);
            unset($control);
            $claim = ServiceCallScheduler::claim($accepted['service_call_id']);
            $this->assertNotNull($claim);
            $this->assertSame(0, DB::connection()->transactionLevel());
            $resumed = new DefaultServiceControlPlane($workflows);
            $resumed->executeScheduledAttempt($claim);
            $result = $resumed->describeCall($accepted['service_call_id'], [
                'namespace' => 'billing',
            ]);
            $this->assertSame('completed', $result['status']);
            $this->assertSame(1, $reads);
            $this->assertSame(1, WorkflowServiceEndpoint::query()->where('namespace', 'maintenance')->count());
            $this->assertSame(1, WorkflowServiceCall::query()->count());
            $this->assertSame('completed', WorkflowServiceCallDispatch::query()->sole()->state);
            $this->assertNull(ServiceCallScheduler::claim($accepted['service_call_id']));
        } finally {
            DB::purge('host_query_writer');
            Carbon::setTestNow();
        }
    }
}
