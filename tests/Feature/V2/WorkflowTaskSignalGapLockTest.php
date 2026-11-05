<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\V2\TestConfiguredContinueSignalWorkflow;
use Tests\Fixtures\V2\TestSignalContinuationWorkflow;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class WorkflowTaskSignalGapLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
            'workflows.storage.transaction_attempts' => 1,
            'cache.default' => 'array',
        ]);
        if (DB::connection()->getDriverName() !== 'mysql'
            || str_contains(strtolower((string) DB::selectOne('SELECT VERSION() AS version')->version), 'mariadb')) {
            $this->markTestSkipped('MySQL 8 InnoDB REPEATABLE READ is required.');
        }
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')
            || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('Process control and local sockets are required.');
        }
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
    }

    public static function races(): iterable
    {
        foreach ([false, true] as $named) {
            foreach (['history', 'task'] as $variant) {
                yield ($named ? 'named' : 'default') . '/' . $variant => [$variant, $named];
            }
        }
    }

    #[DataProvider('races')]
    public function testContinueAsNewDoesNotDeadlockWithAnUnrelatedSignal(string $variant, bool $named): void
    {
        $this->configureStorage($named);
        $runs = [];
        // A < B < C bounds A's signal gap with C. For the successor-task
        // variant, A < D < B bounds B's history gap with D instead.
        foreach ($variant === 'task' ? ['a', 'd', 'b'] : ['a', 'b', 'c'] as $name) {
            $stub = WorkflowStub::make(TestSignalContinuationWorkflow::class);
            $stub->start(0);
            $this->executeReadyTask($stub->runId());
            $runs[$name] = $stub;
        }
        foreach ($variant === 'task' ? ['a'] : ['a', 'c'] as $name) {
            $this->assertTrue($runs[$name]->signal('resume')->accepted());
        }
        $task = WorkflowTask::query()->where('workflow_run_id', $runs['a']->runId())
            ->where('status', TaskStatus::Ready->value)->sole();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        $connectionName = $this->storage()
            ->getName();
        foreach (array_keys(DB::getConnections()) as $openConnection) {
            DB::purge($openConnection);
        }
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            $this->signalActor($sockets[1], $runs['b']->id(), $runs['b']->runId());
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 15);
        $reaped = false;
        $armed = true;
        try {
            DB::listen(function (QueryExecuted $query) use (&$armed, $runs, $sockets): void {
                if (! $armed || ! str_starts_with($query->sql, 'update `workflow_runs` set `status`')
                    || ! in_array('continued', $query->bindings, true)
                    || ! in_array($runs['a']->runId(), $query->bindings, true)) {
                    return;
                }
                $armed = false;
                fwrite($sockets[0], "continue-ready\n");
                $this->assertSame("history-locked\n", fgets($sockets[0]));
                fwrite($sockets[0], "proceed\n");
            });
            $error = null;
            try {
                app()->call([new RunWorkflowTask($task->id), 'handle']);
            } catch (Throwable $exception) {
                $error = $exception;
            }
            $line = fgets($sockets[0]);
            $this->assertIsString($line, 'The signal actor did not finish.');
            $signal = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            pcntl_waitpid($pid, $status);
            $reaped = true;
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertNull($error, $error === null ? '' : $this->describeError($error));
            $this->assertTrue($signal['accepted'] ?? false, json_encode($signal, JSON_THROW_ON_ERROR));
            $this->assertSame(1, $signal['attempts'], 'Signal admission must not hide the cycle with a retry.');
            $this->assertFalse($armed, 'The terminal-turn barrier was not reached.');
            $oldRun = WorkflowRun::query()->findOrFail($runs['a']->runId());
            $this->assertSame('continued', $oldRun->closed_reason);
            $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
            $this->assertSame(2, WorkflowRun::query()->where('workflow_instance_id', $runs['a']->id())->count());
            $this->assertSame(1, $oldRun->historyEvents()
                ->where('event_type', HistoryEventType::WorkflowContinuedAsNew->value)->count());
            $this->assertSame(1, WorkflowSignal::query()->where('workflow_run_id', $runs['b']->runId())->count());
            $this->assertSame(0, $this->storage()->transactionLevel());
        } finally {
            $armed = false;
            if (! $reaped) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            fclose($sockets[0]);
            DB::purge($connectionName);
        }
    }

    public static function storageConnections(): iterable
    {
        yield 'default' => [false];
        yield 'named' => [true];
    }

    #[DataProvider('storageConnections')]
    public function testOwnedContinuationPreservesThePendingInstanceSignalReceipt(bool $named): void
    {
        $this->configureStorage($named);
        $stub = WorkflowStub::make(TestConfiguredContinueSignalWorkflow::class);
        $stub->start(0);
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $receipt = $stub->signal('name-provided', 'Taylor 雪');
        $this->assertTrue($receipt->accepted());
        $signal = WorkflowSignal::query()->where('workflow_command_id', $receipt->commandId())->sole();
        $originalSignal = $signal->getAttributes();
        $originalCommand = WorkflowCommand::query()->findOrFail($receipt->commandId())->getAttributes();

        $this->executeReadyTask($run->id);

        $this->assertSame('continued', $run->fresh()->closed_reason);
        $successor = WorkflowRun::query()->where('workflow_instance_id', $stub->id())
            ->where('id', '!=', $run->id)->sole();
        $signal = $signal->fresh();
        $this->assertSame($successor->id, $signal->workflow_run_id);
        $this->assertSame($successor->id, $signal->resolved_workflow_run_id);
        $this->assertSame(SignalStatus::Received, $signal->status);
        foreach (['id', 'workflow_command_id', 'command_sequence', 'arguments', 'payload_codec', 'received_at'] as $field) {
            $this->assertSame($originalSignal[$field], $signal->getRawOriginal($field));
        }
        $command = WorkflowCommand::query()->findOrFail($receipt->commandId());
        foreach (['id', 'request_id', 'command_type', 'status', 'outcome', 'accepted_at'] as $field) {
            $this->assertSame($originalCommand[$field], $command->getRawOriginal($field));
        }
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SignalRejected->value)->count());
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $successor->id)
            ->where('event_type', HistoryEventType::SignalReceived->value)->count());
        $this->assertSame(0, $this->storage()->transactionLevel());

        DB::purge($this->storage()->getName());
        $this->executeReadyTask($successor->id);

        $signal = WorkflowSignal::query()->whereKey($originalSignal['id'])->sole();
        $this->assertSame(SignalStatus::Applied, $signal->status);
        $this->assertNotNull($signal->closed_at);
        $this->assertSame($receipt->commandId(), $signal->workflow_command_id);
        $this->assertSame('Taylor 雪', $stub->refresh()->output()['name']);
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $successor->id)
            ->where('event_type', HistoryEventType::SignalApplied->value)->count());
        $this->assertSame(2, WorkflowRun::query()->where('workflow_instance_id', $stub->id())->count());
        $this->assertSame(0, $this->storage()->transactionLevel());
    }

    public static function callerTransactions(): iterable
    {
        foreach ([false, true] as $named) {
            foreach (['cancel', 'continue', 'record', 'failed-task'] as $mode) {
                yield ($named ? 'named' : 'default') . '/' . $mode => [$mode, $named];
            }
        }
    }

    #[DataProvider('callerTransactions')]
    public function testCallerReadViewDoesNotHideAnAcceptedSignal(string $mode, bool $named): void
    {
        $this->configureStorage($named);
        if ($mode === 'continue') {
            $stub = WorkflowStub::make(TestConfiguredContinueSignalWorkflow::class);
            $stub->start(0);
        } else {
            $stub = WorkflowStub::make(TestSignalContinuationWorkflow::class);
            $stub->start(0);
            $this->executeReadyTask($stub->runId());
        }
        if ($mode === 'failed-task') {
            $this->failContinuation($stub);
        }
        $runId = $stub->runId();
        $primary = $this->storage();
        $storage = config('workflows.storage.connection');
        $writer = $primary->getConfig();
        unset($writer['name']);
        $writer['url'] = null;
        config([
            'database.connections.signal_gap_writer' => $writer,
        ]);
        $primary->beginTransaction();
        try {
            $primary->table('workflow_signal_records')
                ->where('workflow_run_id', $runId)
                ->count();
            config([
                'workflows.storage.connection' => 'signal_gap_writer',
            ]);
            $receipt = $mode === 'continue'
                ? WorkflowStub::load($stub->id())->signal('name-provided', 'late')
                : WorkflowStub::load($stub->id())->signal('resume');
            $signalId = WorkflowSignal::query()->where('workflow_command_id', $receipt->commandId())->sole()->id;
            config([
                'workflows.storage.connection' => $storage,
            ]);
            if ($mode === 'continue') {
                $this->executeReadyTask($runId);
            } elseif ($mode === 'cancel') {
                WorkflowStub::load($stub->id())->cancel('caller transaction');
            } else {
                // record() itself adds a transaction level. Depth alone is
                // insufficient to establish ownership of the caller's read view.
                $run = WorkflowRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
                $run->forceFill([
                    'status' => RunStatus::Cancelled,
                    'closed_reason' => 'cancelled',
                    'closed_at' => now(),
                ])->save();
                WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowCancelled);
            }
            $signal = WorkflowSignal::query()->whereKey($signalId)->lockForUpdate()->firstOrFail();
            $this->assertTrue($receipt->accepted());
            $this->assertSame($mode === 'continue' ? SignalStatus::Received : SignalStatus::Rejected, $signal->status);
            if ($mode === 'continue') {
                $this->assertNotSame($runId, $signal->workflow_run_id);
            }
        } finally {
            config([
                'workflows.storage.connection' => $storage,
            ]);
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::purge('signal_gap_writer');
        }
    }

    private function configureStorage(bool $named): void
    {
        if ($named) {
            $config = DB::connection()->getConfig();
            unset($config['name']);
            $config['url'] = null;
            config([
                'database.connections.signal_gap_store' => $config,
                'workflows.storage.connection' => 'signal_gap_store',
            ]);
        }
        $this->storage()
            ->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->storage()
            ->statement('SET SESSION innodb_lock_wait_timeout = 5');
    }

    private function storage(): Connection
    {
        return DB::connection(config('workflows.storage.connection'));
    }

    private function executeReadyTask(string $runId): void
    {
        $task = WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('status', TaskStatus::Ready->value)->sole();
        app()
            ->call([new RunWorkflowTask($task->id), 'handle']);
    }

    private function failContinuation(WorkflowStub $stub): void
    {
        $stub->signal('resume');
        $armed = true;
        DB::listen(static function (QueryExecuted $query) use (&$armed, $stub): void {
            if ($armed && str_starts_with($query->sql, 'update `workflow_runs` set `status`')
                && in_array('continued', $query->bindings, true)
                && in_array($stub->runId(), $query->bindings, true)) {
                throw new RuntimeException('terminal-turn rollback control');
            }
        });
        try {
            $this->executeReadyTask($stub->runId());
            $this->fail('The rollback control did not execute.');
        } catch (RuntimeException $error) {
            $this->assertSame('terminal-turn rollback control', $error->getMessage());
        } finally {
            $armed = false;
        }
        $this->assertSame(0, $this->storage()->transactionLevel());
    }

    /**
     * @param resource $socket
     */
    private function signalActor($socket, string $instanceId, string $runId): never
    {
        stream_set_timeout($socket, 15);
        $attempts = 0;
        try {
            if (fgets($socket) !== "continue-ready\n") {
                throw new RuntimeException('The continuation did not reach its terminal turn.');
            }
            $this->storage()
                ->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->storage()
                ->statement('SET SESSION innodb_lock_wait_timeout = 5');
            DB::listen(static function (QueryExecuted $query) use ($socket, $runId, &$attempts): void {
                if (str_contains($query->sql, 'from `workflow_history_events`')
                    && str_contains($query->sql, 'for update') && in_array($runId, $query->bindings, true)) {
                    $attempts++;
                    fwrite($socket, "history-locked\n");
                    if (fgets($socket) !== "proceed\n") {
                        throw new RuntimeException('The continuation did not release the barrier.');
                    }
                }
            });
            $receipt = WorkflowStub::load($instanceId)->signal('resume');
            $result = [
                'accepted' => $receipt->accepted(),
                'attempts' => $attempts,
            ];
        } catch (Throwable $error) {
            $result = [
                'accepted' => false,
                'attempts' => $attempts,
                'error' => $this->describeError($error),
            ];
        }
        fwrite($socket, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
        DB::disconnect($this->storage()->getName());
        fclose($socket);
        exit(0);
    }

    private function describeError(Throwable $error): string
    {
        return $error::class . ': ' . $error->getMessage();
    }
}
