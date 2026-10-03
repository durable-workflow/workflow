<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Fixtures\V2\TestParentChildPolicyWorkflow;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Enums\CancellationPolicy;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\CooperativeCancellationDelivery;
use Workflow\V2\WorkflowStub;

final class V2CancellationRequestConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
            'queue.default' => 'database',
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
        Carbon::setTestNow('2026-10-03T00:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testConcurrentDirectDuplicateKeepsOriginalIdentityAndBudget(): void
    {
        [$winner, $loser, $child] = $this->raceRequests(false, false);
        $this->assertTrue($winner['accepted']);
        $this->assertTrue($loser['accepted']);
        $this->assertSame($winner['command_id'], $loser['command_id']);
        $this->assertSameJsonObject($winner['context'], $loser['context']);
        $this->assertSame('winner', $loser['context']['reason']);
        $this->assertSame('2026-10-03T00:00:25.000000Z', $loser['context']['cleanup_deadline_at']);
        $this->assertCanonicalRequest($child, $winner['context']);
    }

    public function testConcurrentDirectDuplicateKeepsInheritedRootAndDeadline(): void
    {
        [$winner, $loser, $child, $parentContext] = $this->raceRequests(true, false);
        $this->assertTrue($winner['accepted']);
        $this->assertTrue($loser['accepted']);
        $this->assertSame($winner['command_id'], $loser['command_id']);
        $this->assertSameJsonObject($winner['context'], $loser['context']);
        $this->assertSame($parentContext['root_request_id'], $loser['context']['root_request_id']);
        $this->assertSame($parentContext['request_id'], $loser['context']['parent_request_id']);
        $this->assertSame($parentContext['cleanup_deadline_at'], $loser['context']['cleanup_deadline_at']);
        $this->assertSame($parentContext['requested_at'], $loser['context']['requested_at']);
        $this->assertCanonicalRequest($child, $winner['context']);
    }

    public function testConcurrentParentPropagationReportsConflictWithoutReplacingDirectRoot(): void
    {
        [$winner, $loser, $child, $parentContext] = $this->raceRequests(false, true);
        $this->assertTrue($winner['accepted']);
        $this->assertFalse($loser['accepted']);
        $this->assertSame('cancellation_root_conflict', $loser['rejection_reason']);
        $this->assertNull($loser['context']);
        $this->assertNotSame($winner['context']['root_request_id'], $parentContext['root_request_id']);
        $this->assertSameJsonObject([
            'existing_request_id' => $winner['command_id'],
            'existing_root_request_id' => $winner['context']['root_request_id'],
            'existing_cleanup_deadline_at' => $winner['context']['cleanup_deadline_at'],
            'incoming_root_request_id' => $parentContext['root_request_id'],
            'incoming_cleanup_deadline_at' => $parentContext['cleanup_deadline_at'],
        ], $loser['conflict']);
        $this->assertSame(1, WorkflowCommand::query()->where('workflow_run_id', $child->runId())
            ->where('command_type', CommandType::RequestCancellation->value)
            ->where('status', CommandStatus::Rejected->value)->count());
        $duplicate = $child->requestCancellation('later direct request', 300);
        $this->assertSame($winner['command_id'], $duplicate->commandId());
        $this->assertSameJsonObject($winner['context'], $duplicate->cancellationContext()?->toArray());
        $this->assertCanonicalRequest($child, $winner['context']);
    }

    /**
     * @return array{array<string, mixed>, array<string, mixed>, WorkflowStub, array<string, mixed>}
     */
    private function raceRequests(bool $winnerInherited, bool $loserInherited): array
    {
        $parent = WorkflowStub::make(TestParentChildPolicyWorkflow::class);
        $parent->start(CancellationPolicy::WaitCancellationCompleted->value);
        $this->runWorkflowTask($parent);
        $link = WorkflowLink::query()->where('parent_workflow_run_id', $parent->runId())->sole();
        $child = WorkflowStub::loadRun($link->child_workflow_run_id);
        $this->runWorkflowTask($child);
        $parentContext = $parent->requestCancellation('parent request', 30)
            ->cancellationContext()
            ->toArray();
        Carbon::setTestNow(now()->addSeconds(5));
        $childRunId = $child->runId();
        $parentRunId = $parent->runId();
        $operations = [];
        DB::purge();

        try {
            $operations['winner'] = $this->forkRequest($childRunId, $parentRunId, $winnerInherited, true);
            $this->assertIsArray($this->readMessage($operations['winner']));
            $operations['loser'] = $this->forkRequest($childRunId, $parentRunId, $loserInherited, false);
            $loserReady = $this->readMessage($operations['loser']);
            $this->assertIsInt($loserReady['connection_id']);
            fwrite($operations['winner']['socket'], "go\n");
            $uncommitted = $this->readMessage($operations['winner']);
            $this->assertTrue($uncommitted['accepted']);
            fwrite($operations['loser']['socket'], "go\n");
            $this->awaitBlockedConnection($loserReady['connection_id']);

            // An actual database waiter has reached the locked request. Neither
            // a sleep nor a second sequential call stands in for this race.
            $this->assertNull($child->run()->fresh()->cancellation_request_command_id);
            $this->assertSame(0, $child->run()->historyEvents()
                ->where('event_type', HistoryEventType::CooperativeCancellationRequested)->count());
            fwrite($operations['winner']['socket'], "commit\n");
            $winner = $this->finishRequest($operations['winner']);
            unset($operations['winner']);
            $loser = $this->finishRequest($operations['loser']);
            unset($operations['loser']);
        } finally {
            foreach ($operations as $operation) {
                posix_kill($operation['pid'], SIGKILL);
                pcntl_waitpid($operation['pid'], $status);
                fclose($operation['socket']);
            }
            DB::purge();
            DB::reconnect();
        }

        $this->assertSameJsonObject(
            $parentContext,
            CooperativeCancellationDelivery::context($parent->run()->fresh())->toArray()
        );
        return [$winner, $loser, $child, $parentContext];
    }

    /**
     * @return array{pid: int, socket: resource}
     */
    private function forkRequest(string $childRunId, string $parentRunId, bool $inherited, bool $holdCommit): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            stream_set_timeout($sockets[1], 10);
            $ok = false;
            try {
                DB::reconnect();
                $query = DB::connection()->getDriverName() === 'mysql'
                    ? 'SELECT CONNECTION_ID() AS connection_id' : 'SELECT pg_backend_pid() AS connection_id';
                fwrite($sockets[1], json_encode([
                    'connection_id' => (int) DB::selectOne($query)->connection_id,
                ], JSON_THROW_ON_ERROR) . PHP_EOL);
                if (fgets($sockets[1]) !== "go\n") {
                    throw new RuntimeException('Cancellation actor was not released.');
                }
                if ($holdCommit) {
                    DB::beginTransaction();
                }
                $stub = WorkflowStub::loadRun($childRunId);
                $result = $inherited ? $stub->attemptRequestCancellationFromParent($parentRunId)
                    : $stub->requestCancellation($holdCommit ? 'winner' : 'duplicate', $holdCommit ? 20 : 300);
                $observation = [
                    'accepted' => $result->accepted(),
                    'command_id' => $result->commandId(),
                    'rejection_reason' => $result->rejectionReason(),
                    'context' => $result->cancellationContext()?->toArray(),
                    'conflict' => $result->payloadValues([
                        'existing_request_id', 'existing_root_request_id', 'existing_cleanup_deadline_at',
                        'incoming_root_request_id', 'incoming_cleanup_deadline_at',
                    ]),
                ];
                if ($holdCommit) {
                    fwrite($sockets[1], json_encode($observation, JSON_THROW_ON_ERROR) . PHP_EOL);
                    if (fgets($sockets[1]) !== "commit\n") {
                        throw new RuntimeException('Cancellation commit was not released.');
                    }
                    DB::commit();
                }
                fwrite($sockets[1], json_encode([
                    'result' => $observation,
                ], JSON_THROW_ON_ERROR) . PHP_EOL);
                $ok = true;
            } catch (Throwable $error) {
                fwrite($sockets[1], json_encode([
                    'error' => $error::class . ': ' . $error->getMessage(),
                ], JSON_THROW_ON_ERROR) . PHP_EOL);
            } finally {
                DB::disconnect();
                fclose($sockets[1]);
            }
            exit($ok ? 0 : 1);
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);
        return [
            'pid' => $pid,
            'socket' => $sockets[0],
        ];
    }

    private function awaitBlockedConnection(int $connectionId): void
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
        $this->fail('The losing cancellation request did not wait on the database lock.');
    }

    /** @param array{pid: int, socket: resource} $operation
     * @return array<string, mixed>
     */
    private function readMessage(array $operation): array
    {
        $line = fgets($operation['socket']);
        $this->assertIsString($line, 'Cancellation actor did not respond.');
        $message = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($message);
        $this->assertArrayNotHasKey('error', $message, $message['error'] ?? '');
        return $message;
    }

    /** @param array{pid: int, socket: resource} $operation
     * @return array<string, mixed>
     */
    private function finishRequest(array $operation): array
    {
        $message = $this->readMessage($operation);
        pcntl_waitpid($operation['pid'], $status);
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        fclose($operation['socket']);
        $this->assertIsArray($message['result']);
        return $message['result'];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function assertCanonicalRequest(WorkflowStub $child, array $context): void
    {
        $run = $child->run()
            ->fresh();
        $this->assertSame($context['request_id'], $run->cancellation_request_command_id);
        $this->assertSame($context['cleanup_deadline_at'], $run->cancellation_deadline_at->toISOString());
        $this->assertSame(RunStatus::Waiting, $run->status);
        $this->assertSame(
            1,
            $run->historyEvents()->where('event_type', HistoryEventType::CooperativeCancellationRequested)->count()
        );
        $this->assertSameJsonObject($context, CooperativeCancellationDelivery::context($run)->toArray());
        $this->assertSame(1, WorkflowCommand::query()->where('workflow_run_id', $run->id)
            ->where('command_type', CommandType::RequestCancellation->value)
            ->where('status', CommandStatus::Accepted->value)->count());
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)->where('status', TaskStatus::Ready->value)->count());
    }

    private function runWorkflowTask(WorkflowStub $workflow): void
    {
        $task = WorkflowTask::query()->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)->where('status', TaskStatus::Ready->value)->sole();
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }
}
