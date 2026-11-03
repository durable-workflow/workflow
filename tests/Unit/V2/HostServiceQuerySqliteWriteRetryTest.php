<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Jobs\RunServiceCall;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceCallDispatch;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\ServiceCallPrincipal;
use Workflow\V2\Support\ServiceCallScheduler;

final class HostServiceQuerySqliteWriteRetryTest extends TestCase
{
    #[DataProvider('writeBoundaries')]
    public function testShortWritesWaitOnlyAfterAnOwnedRootRollback(string $phase, bool $callerOwned): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() === ':memory:'
            || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('The real writer barrier requires file SQLite and independent processes.');
        }
        Queue::fake();
        $this->useSqliteWalJournal();
        $options = $this->acceptedQuery();
        $callId = $options['service_call_id'];
        $control = new DefaultServiceControlPlane();
        $prepare = static fn (): array => $control->execute(
            'billing',
            'invoices',
            'query',
            ServiceCallScheduler::withHostQueryAuthority($options)
        );
        if ($phase === 'claim') {
            $prepare();
        }
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new RuntimeException('Cannot create the writer barrier.');
        }
        DB::purge();
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Cannot fork the writer.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            try {
                stream_set_timeout($pair[1], 5);
                if (fgets($pair[1]) !== "lock\n") {
                    throw new RuntimeException('The reader did not reach the writer barrier.');
                }
                DB::reconnect();
                DB::beginTransaction();
                DB::table('workflow_service_calls')->where('id', $callId)->update([
                    'id' => $callId,
                ]);
                fwrite($pair[1], "locked\n");
                usleep(20_000);
                DB::commit();
                fclose($pair[1]);
                exit(0);
            } catch (Throwable $failure) {
                fwrite($pair[1], 'failure: ' . $failure->getMessage() . "\n");
                fclose($pair[1]);
                exit(1);
            }
        }
        fclose($pair[1]);
        stream_set_timeout($pair[0], 5);
        DB::reconnect();
        $connection = DB::connection();
        $connection->statement('PRAGMA busy_timeout=0');
        $entered = false;
        DB::listen(static function ($event) use (&$entered, $pair): void {
            if ($entered || ! str_starts_with($event->sql, 'select * from "workflow_service_calls"')) {
                return;
            }
            $entered = true;
            fwrite($pair[0], "lock\n");
            if (fgets($pair[0]) !== "locked\n") {
                throw new RuntimeException('The competing writer was not acquired.');
            }
        });
        try {
            if ($callerOwned) {
                $connection->beginTransaction();
            }
            $failure = null;
            $result = null;
            try {
                $result = $phase === 'prepare' ? $prepare() : ServiceCallScheduler::claim($callId);
            } catch (Throwable $error) {
                $failure = $error;
            }
            $this->assertTrue($entered, 'The writer begins after the original admission read.');
            if ($callerOwned) {
                $this->assertInstanceOf(Throwable::class, $failure);
                $this->assertStringContainsString('database is locked', $failure->getMessage());
                $this->assertSame(1, $connection->transactionLevel());
                $this->assertTrue($connection->getPdo()->inTransaction());
                $connection->rollBack();
            } else {
                $this->assertNull($failure, $failure?->getMessage() ?? 'The root write completes.');
                $this->assertNotNull($result);
                $this->assertSame(0, $connection->transactionLevel());
            }
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertSame(1, WorkflowServiceCall::query()->count());
            $this->assertSame(
                $callerOwned && $phase === 'prepare' ? 0 : 1,
                WorkflowServiceCallDispatch::query()->count()
            );
            Queue::assertPushed(RunServiceCall::class, $callerOwned && $phase === 'prepare' ? 0 : 1);
            if ($phase === 'claim') {
                $dispatch = WorkflowServiceCallDispatch::query()->sole();
                $this->assertSame($callerOwned ? 'scheduled' : 'claimed', $dispatch->state);
                $this->assertSame($callerOwned ? 0 : 1, $dispatch->claim_generation);
            }
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            fclose($pair[0]);
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
        }
    }

    public static function writeBoundaries(): iterable
    {
        foreach (['prepare', 'claim'] as $phase) {
            yield $phase . ' owned root' => [$phase, false];
            yield $phase . ' caller transaction' => [$phase, true];
        }
    }

    private function acceptedQuery(): array
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
        $options = [
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
}
