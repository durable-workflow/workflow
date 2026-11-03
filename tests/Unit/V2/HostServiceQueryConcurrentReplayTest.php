<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceCallDispatch;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\ServiceCallPrincipal;
use Workflow\V2\Support\ServiceCallScheduler;

final class HostServiceQueryConcurrentReplayTest extends TestCase
{
    #[DataProvider('preparationRaces')]
    public function testIndependentReplayCannotDispatchWhileTheClaimedQueryWaits(bool $simultaneous): void
    {
        $connection = DB::connection();
        if (! in_array($connection->getDriverName(), ['sqlite', 'pgsql'], true)
            || $connection->getDatabaseName() === ':memory:'
            || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Independent process acceptance requires file SQLite or PostgreSQL and pcntl.');
        }
        if ($simultaneous && $connection->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The forced stale-read preparation race is specific to SQLite.');
        }
        Queue::fake();
        Carbon::setTestNow('2026-10-27T09:00:00Z');
        if ($connection->getDriverName() === 'sqlite') {
            $this->useSqliteWalJournal();
            $connection->statement('PRAGMA busy_timeout=1000');
        }
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
        Carbon::setTestNow('2026-10-27T09:00:01Z');
        DB::purge();
        $children = [];
        $sockets = [];
        try {
            foreach (['first', 'second'] as $name) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                if ($pair === false) {
                    throw new RuntimeException('Cannot create the query claim barrier.');
                }
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Cannot fork the query claim client.');
                }
                if ($pid === 0) {
                    fclose($pair[0]);
                    foreach ($sockets as $inherited) {
                        fclose($inherited);
                    }
                    $this->child($name, $pair[1], $options, $simultaneous);
                }
                fclose($pair[1]);
                $children[] = $pid;
                $sockets[$name] = $pair[0];
                stream_set_timeout($pair[0], 10);
                if ($simultaneous) {
                    $this->assertSame('admission-read', $this->message($pair[0])['event']);
                } elseif ($name === 'first') {
                    $this->assertSame('query-started', $this->message($pair[0])['event']);
                }
            }
            $queryName = 'first';
            $pendingName = 'second';
            if ($simultaneous) {
                foreach ($sockets as $socket) {
                    fwrite($socket, "prepare\n");
                }
                $events = [];
                foreach ($sockets as $name => $socket) {
                    $events[$name] = $this->message($socket);
                }
                $queryName = ($events['first']['event'] ?? null) === 'query-started' ? 'first' : 'second';
                $pendingName = $queryName === 'first' ? 'second' : 'first';
                $this->assertSame('query-started', $events[$queryName]['event'] ?? null, json_encode($events));
                $second = $events[$pendingName];
            } else {
                $second = $this->message($sockets['second']);
            }
            $this->assertSame('pending', $second['event'] ?? null, json_encode($second));
            $this->assertSame($accepted['service_call_id'], $second['service_call_id']);
            $this->assertSame(0, $second['transaction_level']);
            $this->assertSame('claimed', WorkflowServiceCallDispatch::query()->sole()->state);
            WorkflowServiceEndpoint::query()->create([
                'namespace' => 'maintenance',
                'endpoint_name' => 'writer-during-query',
            ]);
            fwrite($sockets[$queryName], "release\n");
            $first = $this->message($sockets[$queryName]);
            $this->assertSame('completed', $first['status'] ?? null, json_encode($first));
            fwrite($sockets[$pendingName], "release\n");
            $second = $this->message($sockets[$pendingName]);
            $this->assertSame('completed', $second['status'] ?? null, json_encode($second));
            $this->assertSame($accepted['service_call_id'], $first['service_call_id']);
            $this->assertSame($first['service_call_id'], $second['service_call_id']);
            $this->assertSame(1, WorkflowServiceEndpoint::query()->where('namespace', 'query-observations')->count());
            $this->assertSame(1, WorkflowServiceCall::query()->count());
            $this->assertSame('completed', WorkflowServiceCallDispatch::query()->sole()->state);
        } finally {
            foreach ($sockets as $socket) {
                @fwrite($socket, "release\n");
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
            Carbon::setTestNow();
        }
    }

    public static function preparationRaces(): iterable
    {
        yield 'replay during worker wait' => [false];
        yield 'simultaneous stale-read preparation' => [true];
    }

    /**
     * @param resource $socket
     * @param array<string, mixed> $options
     */
    private function child(string $name, $socket, array $options, bool $simultaneous): never
    {
        try {
            DB::reconnect();
            stream_set_timeout($socket, 10);
            if ($simultaneous) {
                $paused = false;
                DB::listen(static function ($event) use ($socket, &$paused): void {
                    if ($paused || ! str_starts_with($event->sql, 'select * from "workflow_service_calls"')) {
                        return;
                    }
                    $paused = true;
                    fwrite($socket, json_encode([
                        'event' => 'admission-read',
                    ]) . "\n");
                    if (fgets($socket) !== "prepare\n") {
                        throw new RuntimeException('The admission barrier was not released.');
                    }
                });
            }
            $workflows = Mockery::mock(WorkflowControlPlane::class);
            $workflows->shouldReceive('query')
                ->andReturnUsing(static function () use ($socket, $name): array {
                    if (DB::connection()->transactionLevel() !== 0) {
                        throw new RuntimeException('The query is holding a database transaction.');
                    }
                    WorkflowServiceEndpoint::query()->create([
                        'namespace' => 'query-observations',
                        'endpoint_name' => $name,
                    ]);
                    fwrite($socket, json_encode([
                        'event' => 'query-started',
                    ]) . "\n");
                    if (fgets($socket) !== "release\n") {
                        throw new RuntimeException('The query claim barrier was not released.');
                    }

                    return [
                        'success' => true,
                        'result' => 'one-worker-observation',
                    ];
                });
            $control = new DefaultServiceControlPlane($workflows);
            $result = $control->execute(
                'billing',
                'invoices',
                'query',
                ServiceCallScheduler::withHostQueryAuthority($options)
            );
            $claim = ServiceCallScheduler::claim($options['service_call_id']);
            if ($claim !== null) {
                $control->executeScheduledAttempt($claim);
            } else {
                fwrite($socket, json_encode([
                    'event' => 'pending',
                    'service_call_id' => $result['service_call_id'],
                    'transaction_level' => DB::connection()->transactionLevel(),
                ]) . "\n");
                if (fgets($socket) !== "release\n") {
                    throw new RuntimeException('The pending replay barrier was not released.');
                }
            }
            $result = $control->describeCall($options['service_call_id'], [
                'namespace' => 'billing',
            ]);
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

    /** @param resource $socket
     * @return array<string, mixed>
     */
    private function message($socket): array
    {
        $line = fgets($socket);
        if ($line === false) {
            throw new RuntimeException('The query claim client did not report its result.');
        }

        return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    }
}
