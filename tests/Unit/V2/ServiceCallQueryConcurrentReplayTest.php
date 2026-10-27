<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\ServiceCallPrincipal;

final class ServiceCallQueryConcurrentReplayTest extends TestCase
{
    public function testConcurrentAdmittedReplaysKeepOneWorkerQueryWhileTheFirstWaits(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork') || ! function_exists(
            'posix_kill'
        )) {
            $this->markTestSkipped('This independent-process row-lock regression requires PostgreSQL and pcntl.');
        }
        Carbon::setTestNow('2026-10-27T09:00:00Z');
        Queue::fake();
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
        $options += [
            'service_call_id' => $accepted['service_call_id'],
            'boundary_policy_outcome' => 'accepted',
        ];
        $configuration = config('database.connections.' . DB::connection()->getName());
        config([
            'database.connections.query_counter' => $configuration,
        ]);
        DB::purge();
        $children = [];
        $sockets = [];
        $backend = [];
        try {
            foreach (['first', 'second'] as $name) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                if ($pair === false) {
                    throw new RuntimeException('Cannot create the query replay barrier.');
                }
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Cannot fork the query replay client.');
                }
                if ($pid === 0) {
                    fclose($pair[0]);
                    foreach ($sockets as $inherited) {
                        fclose($inherited);
                    }
                    $this->child($name, $pair[1], $options);
                }
                fclose($pair[1]);
                $children[] = $pid;
                $sockets[$name] = $pair[0];
                stream_set_timeout($pair[0], 20);
                $backend[$name] = $this->message($pair[0])['backend'];
                if ($name === 'first') {
                    $this->assertSame('query-started', $this->message($pair[0])['event']);
                }
            }
            $blocked = false;
            $secondStarted = false;
            $deadline = microtime(true) + 5;
            do {
                $wait = DB::selectOne(
                    'SELECT ?::int = ANY(pg_blocking_pids(?::int)) AS blocked',
                    [$backend['first'],
                    $backend['second'],
                ]);
                $blocked = (bool) $wait->blocked;
                $read = [$sockets['second']];
                $write = $except = [];
                if (stream_select($read, $write, $except, 0, 0) > 0) {
                    $message = $this->message($sockets['second']);
                    $secondStarted = ($message['event'] ?? null) === 'query-started';
                    break;
                }
                if (! $blocked) {
                    usleep(10_000);
                }
            } while (! $blocked && microtime(true) < $deadline);
            fwrite($sockets['first'], "release\n");
            $first = $this->message($sockets['first']);
            $second = $this->message($sockets['second']);
            $this->assertTrue($blocked, 'The second request must wait on the first request\'s real database lock.');
            $this->assertFalse($secondStarted, 'A concurrent replay must not issue another worker query.');
            foreach ([$first, $second] as $result) {
                $this->assertSame('completed', $result['status'] ?? null, json_encode($result));
                $this->assertSame($accepted['service_call_id'], $result['service_call_id']);
            }
            $this->assertSame(1, WorkflowServiceEndpoint::query()->where('namespace', 'query-observations')->count());
            $this->assertSame(1, WorkflowServiceCall::query()->count());
        } finally {
            if (isset($sockets['first']) && is_resource($sockets['first'])) {
                @fwrite($sockets['first'], "release\n");
            }
            foreach ($children as $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                    posix_kill($pid, SIGTERM);
                    pcntl_waitpid($pid, $status);
                }
            }
            foreach ($sockets as $socket) {
                fclose($socket);
            }
            DB::purge('query_counter');
            Carbon::setTestNow();
        }
    }

    /**
     * @param resource $socket
     * @param array<string, mixed> $options
     */
    private function child(string $name, $socket, array $options): never
    {
        try {
            DB::reconnect();
            stream_set_timeout($socket, 20);
            fwrite($socket, json_encode([
                'backend' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
            ]) . "\n");
            $workflows = Mockery::mock(WorkflowControlPlane::class);
            $workflows->shouldReceive('query')
                ->andReturnUsing(static function () use ($name, $socket): array {
                    WorkflowServiceEndpoint::on('query_counter')->create([
                        'namespace' => 'query-observations',
                        'endpoint_name' => $name,
                    ]);
                    fwrite($socket, json_encode([
                        'event' => 'query-started',
                    ]) . "\n");
                    if ($name === 'first' && fgets($socket) !== "release\n") {
                        throw new RuntimeException('The query replay barrier was not released.');
                    }

                    return [
                        'success' => true,
                        'result' => 'one-worker-observation',
                    ];
                });
            $result = (new DefaultServiceControlPlane($workflows))->execute('billing', 'invoices', 'query', $options);
            fwrite($socket, json_encode([
                'event' => 'result',
                'status' => $result['status'],
                'service_call_id' => $result['service_call_id'],
            ]) . "\n");
            fclose($socket);
            exit(0);
        } catch (Throwable $failure) {
            fwrite($socket, json_encode([
                'event' => 'failure',
                'class' => $failure::class,
                'message' => $failure->getMessage(),
            ]) . "\n");
            fclose($socket);
            exit(1);
        }
    }

    /**
     * @param resource $socket
     * @return array<string, mixed>
     */
    private function message($socket): array
    {
        $line = fgets($socket);
        if ($line === false) {
            throw new RuntimeException('The query replay client did not report its barrier/result.');
        }

        return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    }
}
