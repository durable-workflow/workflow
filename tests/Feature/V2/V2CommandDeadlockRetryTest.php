<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Fixtures\V2\TestPendingTimerSignalWorkflow;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;

final class V2CommandDeadlockRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
            'workflows.storage.transaction_attempts' => 3,
            'cache.default' => 'array',
        ]);
        if (DB::connection()->getDriverName() !== 'mysql'
            || str_contains(strtolower((string) DB::selectOne('SELECT VERSION() AS version')->version), 'mariadb')) {
            $this->markTestSkipped('MySQL 8 InnoDB row-lock observation is required.');
        }
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')
            || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('Process control and local sockets are required.');
        }
        Schema::create('command_deadlock_weights', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
        });
    }

    protected function tearDown(): void
    {
        if (Schema::hasTable('command_deadlock_weights')) {
            Schema::drop('command_deadlock_weights');
        }
        parent::tearDown();
    }

    public function testCancellationRetriesARealDeadlockAfterRootRollback(): void
    {
        $race = $this->raceCommands('cancel');
        $this->assertSuccessfulRace($race);
        $this->assertSame(2, $race['cancel']['attempts']);
    }

    public function testSignalRetriesARealDeadlockAfterRootRollback(): void
    {
        $race = $this->raceCommands('signal');
        $this->assertSuccessfulRace($race);
        $this->assertSame(2, $race['signal']['attempts']);
    }

    public function testCallerOwnedCancellationTransactionIsNotRetried(): void
    {
        $race = $this->raceCommands('cancel', 'cancel');
        $this->assertSame(1, $race['cancel']['attempts']);
        $this->assertSame(1213, $race['cancel']['error_code']);
        $this->assertTrue($race['signal']['accepted']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertSame(RunStatus::Waiting, WorkflowRun::query()->findOrFail($race['cancel_run'])->status);
        $this->assertSame(0, WorkflowFailure::query()->where('workflow_run_id', $race['cancel_run'])->count());
        $this->assertSame(0, DB::table('command_deadlock_weights')->where('id', 1000)->count());
        $this->assertOriginalState($race, false);
    }

    public function testCallerOwnedSignalTransactionIsNotRetried(): void
    {
        $race = $this->raceCommands('signal', 'signal');
        $this->assertTrue($race['cancel']['accepted']);
        $this->assertSame(1, $race['signal']['attempts']);
        $this->assertSame(1213, $race['signal']['error_code']);
        $this->assertSame(0, WorkflowSignal::query()->where('workflow_run_id', $race['signal_run'])->count());
        $this->assertSame(0, DB::table('command_deadlock_weights')->where('id', 1000)->count());
    }

    public function testConfiguredSingleAttemptPreservesTheOriginalDeadlock(): void
    {
        config(['workflows.storage.transaction_attempts' => 1]);
        $race = $this->raceCommands('cancel');
        $this->assertSame(1, $race['cancel']['attempts']);
        $this->assertSame(1213, $race['cancel']['error_code']);
        $this->assertTrue($race['signal']['accepted']);
        $this->assertOriginalState($race, false);
    }

    /** @return array<string, mixed> */
    private function raceCommands(string $victim, ?string $outer = null): array
    {
        $options = new StartOptions(executionTimeoutSeconds: 900, runTimeoutSeconds: 600);
        // The signal run precedes the cancellation run in the captured secondary-index gap.
        $signal = WorkflowStub::make(TestSignalWorkflow::class);
        $signal->start($options);
        $this->executeInitialTask($signal->runId());
        $cancel = WorkflowStub::make(TestPendingTimerSignalWorkflow::class);
        $cancel->start(300, $options);
        $this->executeInitialTask($cancel->runId());
        $signalRun = WorkflowRun::query()->findOrFail($signal->runId());
        $cancelRun = WorkflowRun::query()->findOrFail($cancel->runId());
        $race = [
            'signal_run' => $signalRun->id,
            'cancel_run' => $cancelRun->id,
            'prefix' => $cancelRun->historyEvents()->orderBy('sequence')->get()->toArray(),
            'deadlines' => [
                $cancelRun->getRawOriginal('run_deadline_at'),
                $cancelRun->getRawOriginal('execution_deadline_at'),
            ],
        ];
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        DB::purge();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runSignalActor($sockets[1], $signalRun, $victim, $outer);
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 15);
        $reaped = false;
        try {
            $ready = $this->readMessage($sockets[0]);
            $this->assertTrue($ready['failures_locked'] ?? false, json_encode($ready));
            DB::reconnect();
            DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
            $attempts = 0;
            $released = false;
            DB::listen(function (QueryExecuted $query) use (
                &$attempts, &$released, $cancelRun, $victim, $sockets, $ready
            ): void {
                if (str_contains($query->sql, 'workflow_instances')
                    && str_contains(strtolower($query->sql), 'for update')
                    && in_array($cancelRun->workflow_instance_id, $query->bindings, true)) {
                    $attempts++;
                }
                if ($released || ! str_contains($query->sql, 'workflow_tasks')
                    || ! str_contains(strtolower($query->sql), 'for update')
                    || ! in_array($cancelRun->id, $query->bindings, true)) {
                    return;
                }
                $released = true;
                if ($victim === 'signal') {
                    $this->addTransactionWeight();
                }
                fwrite($sockets[0], "proceed\n");
                $this->awaitTaskInsertWait($ready['connection_id']);
            });
            $operation = static fn () => WorkflowStub::loadRun($cancelRun->id)->cancel('deadlock control');
            try {
                if ($outer === 'cancel') {
                    $result = DB::transaction(static function () use ($operation) {
                        DB::table('command_deadlock_weights')->insert(['id' => 1000]);
                        return $operation();
                    });
                } else {
                    $result = $operation();
                }
                $race['cancel'] = ['accepted' => $result->accepted(), 'attempts' => $attempts];
            } catch (Throwable $error) {
                $race['cancel'] = $this->errorResult($error) + ['attempts' => $attempts];
            }
            $race['signal'] = $this->readMessage($sockets[0]);
            pcntl_waitpid($pid, $status);
            $reaped = true;
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        } finally {
            if (! $reaped) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            fclose($sockets[0]);
            DB::purge();
            DB::reconnect();
        }
        return $race;
    }

    /** @param resource $socket */
    private function runSignalActor($socket, WorkflowRun $run, string $victim, ?string $outer): never
    {
        stream_set_timeout($socket, 15);
        $attempts = 0;
        try {
            DB::reconnect();
            DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
            $connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $paused = false;
            DB::listen(function (QueryExecuted $query) use (
                &$paused, &$attempts, $run, $victim, $socket, $connectionId
            ): void {
                if (str_contains($query->sql, 'workflow_instances')
                    && str_contains(strtolower($query->sql), 'for update')
                    && in_array($run->workflow_instance_id, $query->bindings, true)) {
                    $attempts++;
                }
                if ($paused || ! str_contains($query->sql, 'workflow_failures')
                    || ! str_contains(strtolower($query->sql), 'for update')
                    || ! in_array($run->id, $query->bindings, true)) {
                    return;
                }
                $paused = true;
                if ($victim === 'cancel') {
                    $this->addTransactionWeight();
                }
                fwrite($socket, json_encode([
                    'connection_id' => $connectionId, 'failures_locked' => true,
                ], JSON_THROW_ON_ERROR) . PHP_EOL);
                if (fgets($socket) !== "proceed\n") {
                    throw new RuntimeException('Cancellation did not acquire its task locks.');
                }
            });
            $operation = static fn () => WorkflowStub::loadRun($run->id)->signal('name-provided', 'Ada');
            if ($outer === 'signal') {
                $result = DB::transaction(static function () use ($operation) {
                    DB::table('command_deadlock_weights')->insert(['id' => 1000]);
                    return $operation();
                });
            } else {
                $result = $operation();
            }
            $message = ['accepted' => $result->accepted(), 'attempts' => $attempts];
        } catch (Throwable $error) {
            $message = $this->errorResult($error) + ['attempts' => $attempts];
        }
        fwrite($socket, json_encode($message, JSON_THROW_ON_ERROR) . PHP_EOL);
        DB::disconnect();
        fclose($socket);
        exit(0);
    }

    private function addTransactionWeight(): void
    {
        // Control InnoDB's victim choice without changing either workflow's business rows.
        DB::table('command_deadlock_weights')->insert(array_map(
            static fn (int $id): array => ['id' => $id], range(1, 100),
        ));
    }

    private function executeInitialTask(string $runId): void
    {
        $task = WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)->where('status', TaskStatus::Ready->value)->sole();
        app()->call([new RunWorkflowTask($task->id), 'handle']);
    }

    private function awaitTaskInsertWait(int $connectionId): void
    {
        $until = microtime(true) + 5;
        do {
            $row = DB::selectOne(
                'SELECT COUNT(*) AS waiting FROM performance_schema.data_lock_waits w '
                . 'JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID '
                . 'WHERE t.PROCESSLIST_ID = ?', [$connectionId],
            );
            if ((int) $row->waiting > 0) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $until);
        throw new RuntimeException('Signal did not reach the captured task-insert lock wait.');
    }

    /** @param resource $socket
     * @return array<string, mixed>
     */
    private function readMessage($socket): array
    {
        $line = fgets($socket);
        $this->assertIsString($line, 'Concurrent command did not respond.');
        $message = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($message);
        return $message;
    }

    /** @return array<string, mixed> */
    private function errorResult(Throwable $error): array
    {
        $code = null;
        for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof \PDOException && isset($cause->errorInfo[1])) {
                $code = (int) $cause->errorInfo[1];
                break;
            }
        }
        return [
            'accepted' => false,
            'error' => $error->getMessage(),
            'error_code' => $code,
        ];
    }

    /** @param array<string, mixed> $race */
    private function assertSuccessfulRace(array $race): void
    {
        $this->assertTrue($race['cancel']['accepted'], json_encode($race['cancel']));
        $this->assertTrue($race['signal']['accepted'], json_encode($race['signal']));
        $this->assertSame(RunStatus::Cancelled, WorkflowRun::query()->findOrFail($race['cancel_run'])->status);
        $this->assertSame(1, WorkflowFailure::query()->where('workflow_run_id', $race['cancel_run'])->count());
        $this->assertSame(1, WorkflowCommand::query()->where('workflow_run_id', $race['cancel_run'])
            ->where('command_type', CommandType::Cancel->value)->count());
        $this->assertSame(1, WorkflowSignal::query()->where('workflow_run_id', $race['signal_run'])->count());
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $race['signal_run'])
            ->where('task_type', TaskType::Workflow->value)->where('status', TaskStatus::Ready->value)->count());
        $this->assertSame(2, WorkflowRun::query()->count());
        $this->assertOriginalState($race, true);
    }

    /** @param array<string, mixed> $race */
    private function assertOriginalState(array $race, bool $cancelled): void
    {
        $run = WorkflowRun::query()->findOrFail($race['cancel_run']);
        $this->assertSame($race['deadlines'], [
            $run->getRawOriginal('run_deadline_at'), $run->getRawOriginal('execution_deadline_at'),
        ]);
        $history = $run->historyEvents()->orderBy('sequence')->get()->toArray();
        $this->assertSame($race['prefix'], array_slice($history, 0, count($race['prefix'])));
        $this->assertSame($cancelled ? 1 : 0, $run->historyEvents()
            ->where('event_type', HistoryEventType::WorkflowCancelled->value)->count());
    }
}
