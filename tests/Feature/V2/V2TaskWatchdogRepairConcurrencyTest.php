<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

final class V2TaskWatchdogRepairConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
            'cache.default' => 'array',
        ]);
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'pgsql'], true)
            || ($driver === 'mysql' && str_contains(
                strtolower((string) DB::selectOne('SELECT VERSION() AS version')->version),
                'mariadb'
            ))) {
            $this->markTestSkipped('MySQL or PostgreSQL row-lock observation is required.');
        }
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')
            || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('Process control and local sockets are required.');
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function watchdogPaths(): array
    {
        return [
            'expired lease' => [false],
            'expired deadline' => [true],
        ];
    }

    #[DataProvider('watchdogPaths')]
    public function testWatchdogLeavesTasksAvailableWhileWaitingForRepairRunLock(bool $deadline): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class);
        $workflow->start('Ada');
        $runId = $workflow->runId();
        if ($deadline) {
            $this->assertTrue($workflow->requestCancellation('repair deadline race', 1)->accepted());
            Carbon::setTestNow(now()->addSeconds(2));
        }
        $task = WorkflowTask::query()->where('workflow_run_id', $runId)->sole();
        $task->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'repair-race-worker',
            'lease_expires_at' => $deadline ? now()
                ->addMinute() : now()
                ->subMinute(),
            'attempt_count' => 1,
        ])->save();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        DB::purge();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            stream_set_timeout($sockets[1], 15);
            $ok = false;
            try {
                DB::reconnect();
                $query = DB::connection()->getDriverName() === 'mysql'
                    ? 'SELECT CONNECTION_ID() AS id' : 'SELECT pg_backend_pid() AS id';
                fwrite($sockets[1], json_encode([
                    'connection_id' => (int) DB::selectOne($query)->id,
                ]) . PHP_EOL);
                if (fgets($sockets[1]) !== "go\n") {
                    throw new RuntimeException('Watchdog was not released.');
                }
                $report = TaskWatchdog::runPass(runIds: [$runId]);
                fwrite($sockets[1], json_encode([
                    'report' => $report,
                ], JSON_THROW_ON_ERROR) . PHP_EOL);
                $ok = true;
            } catch (Throwable $error) {
                fwrite($sockets[1], json_encode([
                    'error' => $error->getMessage(),
                ]) . PHP_EOL);
            } finally {
                DB::disconnect();
                fclose($sockets[1]);
            }
            exit($ok ? 0 : 1);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 15);
        $reaped = false;
        try {
            $ready = $this->readMessage($sockets[0]);
            $this->assertIsInt($ready['connection_id']);
            DB::reconnect();
            $paused = false;
            DB::listen(function (QueryExecuted $query) use (&$paused, $sockets, $ready, $task): void {
                if ($paused || ! str_contains($query->sql, 'workflow_runs')
                    || ! str_contains(strtolower($query->sql), 'for update')) {
                    return;
                }
                $paused = true;
                fwrite($sockets[0], "go\n");
                $this->awaitRunWait($ready['connection_id']);

                // Actual repair holds the run. The waiting watchdog must not
                // hold its task, the second half of the captured deadlock.
                $availableTask = WorkflowTask::query()->whereKey($task->id)
                    ->lock('for update nowait')
                    ->firstOrFail();
                $this->assertSame($task->id, $availableTask->id);
                $this->assertSame('repair-race-worker', $availableTask->lease_owner);
            });
            $this->assertTrue($workflow->repair()->accepted());
            $this->assertTrue($paused);
            $result = $this->readMessage($sockets[0]);
            pcntl_waitpid($pid, $status);
            $reaped = true;
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
            $report = $result['report'];
            $this->assertIsArray($report);
            $this->assertSame([], $report['existing_task_failures']);
            $this->assertSame([], $report['deadline_expired_failures']);
            $this->assertSame(
                1,
                $report[$deadline ? 'deadline_expired_candidates' : 'selected_existing_task_candidates']
            );
            $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $runId)->count());
        } finally {
            if (! $reaped) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            fclose($sockets[0]);
            DB::purge();
            DB::reconnect();
        }
    }

    private function awaitRunWait(int $connectionId): void
    {
        $query = DB::connection()->getDriverName() === 'mysql'
            ? 'SELECT COUNT(*) AS waiting FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID = ?'
            : 'SELECT COUNT(*) AS waiting FROM pg_locks WHERE pid = ? AND NOT granted';
        $until = microtime(true) + 5;
        do {
            if ((int) DB::selectOne($query, [$connectionId])->waiting > 0) {
                $this->addToAssertionCount(1);
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $until);
        $this->fail('Watchdog did not wait on the repair run lock.');
    }

    /** @param resource $socket
     * @return array<string, mixed>
     */
    private function readMessage($socket): array
    {
        $line = fgets($socket);
        $this->assertIsString($line, 'Watchdog did not respond.');
        $message = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($message);
        $this->assertArrayNotHasKey('error', $message, $message['error'] ?? '');
        return $message;
    }
}
