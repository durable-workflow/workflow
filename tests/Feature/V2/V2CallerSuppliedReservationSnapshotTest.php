<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use RuntimeException;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;

final class V2CallerSuppliedReservationSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Native MySQL and separate PHP processes are required.');
        }

        self::stopWorkers();
        Queue::fake();
        config()
            ->set('workflows.v2.task_dispatch_mode', 'poll');
    }

    public function testOlderSnapshotCanReturnCommittedActiveRun(): void
    {
        [$runId, $childResult] = $this->raceAgainstCommittedStart('return-existing');

        $this->assertSame('duplicate:' . $runId, $childResult);
        $this->assertSame(1, WorkflowInstance::query()->whereKey('snapshot-reservation')->count());
        $this->assertSame(1, WorkflowRun::query()->where('workflow_instance_id', 'snapshot-reservation')->count());
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $runId)->count());
        $this->assertSame(2, WorkflowCommand::query()->where('workflow_instance_id', 'snapshot-reservation')->count());
    }

    public function testOlderSnapshotStillRejectsCompetingWorkflowType(): void
    {
        [$runId, $childResult] = $this->raceAgainstCommittedStart('competing-type');

        $this->assertSame('type-rejected', $childResult);
        $this->assertSame(1, WorkflowInstance::query()->whereKey('snapshot-reservation')->count());
        $this->assertSame(1, WorkflowRun::query()->where('workflow_instance_id', 'snapshot-reservation')->count());
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $runId)->count());
        $this->assertSame(1, WorkflowCommand::query()->where('workflow_instance_id', 'snapshot-reservation')->count());
    }

    public function testOlderSnapshotDuplicateStartRollsBackWithoutChangingCommittedRun(): void
    {
        [$runId, $childResult] = $this->raceAgainstCommittedStart('rollback');

        $this->assertSame('duplicate:' . $runId, $childResult);
        $this->assertSame(1, WorkflowInstance::query()->whereKey('snapshot-reservation')->count());
        $this->assertSame(1, WorkflowRun::query()->where('workflow_instance_id', 'snapshot-reservation')->count());
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $runId)->count());
        $this->assertSame(1, WorkflowCommand::query()->where('workflow_instance_id', 'snapshot-reservation')->count());
    }

    public function testNewReservationRollsBackWithItsOuterTransaction(): void
    {
        DB::beginTransaction();

        try {
            WorkflowStub::make(TestSignalWorkflow::class, 'snapshot-new-rollback');
        } finally {
            DB::rollBack();
        }

        $this->assertSame(0, WorkflowInstance::query()->whereKey('snapshot-new-rollback')->count());
    }

    /**
     * @return array{string, string}
     */
    private function raceAgainstCommittedStart(string $scenario): array
    {
        $instanceId = 'snapshot-reservation';
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        DB::purge();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);

        if ($pid === 0) {
            fclose($sockets[0]);

            try {
                DB::reconnect();
                DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                DB::beginTransaction();

                if (WorkflowInstance::query()->find($instanceId) !== null) {
                    throw new RuntimeException('The workflow instance existed before the snapshot.');
                }

                fwrite($sockets[1], "snapshot\n");

                $release = fgets($sockets[1]);

                if (! is_string($release) || ! str_starts_with($release, 'committed:')) {
                    throw new RuntimeException('The competing start was not released.');
                }

                $committedRunId = trim(substr($release, strlen('committed:')));

                if (WorkflowInstance::query()->find($instanceId) !== null) {
                    throw new RuntimeException('The older consistent snapshot unexpectedly saw the committed row.');
                }

                if ($scenario === 'competing-type') {
                    try {
                        WorkflowStub::make(TestGreetingWorkflow::class, $instanceId);
                        throw new RuntimeException('A competing durable type was accepted.');
                    } catch (LogicException $error) {
                        if (! str_contains($error->getMessage(), 'is reserved for durable type')) {
                            throw $error;
                        }
                    }

                    $result = 'type-rejected';
                } else {
                    $stub = WorkflowStub::make(TestSignalWorkflow::class, $instanceId);

                    if ($stub->runId() !== $committedRunId) {
                        throw new RuntimeException('Reservation did not resolve the committed current run.');
                    }

                    $start = $stub->attemptStart(StartOptions::returnExistingActive());

                    if (! $start->returnedExistingActive()) {
                        throw new RuntimeException('The committed active run was not returned.');
                    }

                    $result = 'duplicate:' . $start->runId();
                }

                if ($scenario === 'rollback') {
                    DB::rollBack();
                } else {
                    DB::commit();
                }

                fwrite($sockets[1], $result . "\n");
                fclose($sockets[1]);
                exit(0);
            } catch (Throwable $error) {
                DB::rollBack();
                fwrite($sockets[1], $error::class . ': ' . $error->getMessage() . "\n");
                fclose($sockets[1]);
                exit(1);
            }
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 20);

        try {
            $this->assertSame("snapshot\n", fgets($sockets[0]));
            DB::reconnect();
            $workflow = WorkflowStub::make(TestSignalWorkflow::class, $instanceId);
            $workflow->start();
            $runId = $workflow->runId();
            fwrite($sockets[0], 'committed:' . $runId . "\n");
            $childResult = fgets($sockets[0]);
            pcntl_waitpid($pid, $status);
            $pid = 0;

            $this->assertSame(0, pcntl_wexitstatus($status), (string) $childResult);
            $this->assertIsString($childResult);

            return [$runId, trim($childResult)];
        } finally {
            fclose($sockets[0]);

            if ($pid > 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }

            DB::purge();
            DB::reconnect();
        }
    }
}
