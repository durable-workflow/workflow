<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestParallelChildWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class V2EmbeddedParallelChildConcurrencyTest extends TestCase
{
    public function testOlderCompletionSnapshotStillObservesCommittedSiblingAndWakesParent(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('MySQL repeatable-read and process control are required.');
        }

        self::stopWorkers();
        Queue::fake();
        $workflow = WorkflowStub::make(TestParallelChildWorkflow::class, 'embedded-parallel-snapshot');
        $workflow->start(0, 0);
        $parentId = $workflow->runId();
        $this->runWorkflowTask($parentId);
        $childIds = WorkflowLink::query()->where('parent_workflow_run_id', $parentId)
            ->orderBy('sequence')->pluck('child_workflow_run_id')->all();
        $this->assertCount(2, $childIds);

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
                DB::transaction(function () use ($childIds, $sockets): void {
                    // Both child rows are visible before the sibling commits.
                    WorkflowRun::query()->whereIn('id', $childIds)->get();
                    fwrite($sockets[1], "snapshot\n");
                    if (fgets($sockets[1]) !== "complete\n") {
                        throw new \RuntimeException('Missing completion release.');
                    }
                    $this->runWorkflowTask($childIds[1]);
                });
                fwrite($sockets[1], "completed\n");
                fclose($sockets[1]);
                exit(0);
            } catch (\Throwable $error) {
                fwrite($sockets[1], $error::class.': '.$error->getMessage()."\n");
                fclose($sockets[1]);
                exit(1);
            }
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 20);
        try {
            $this->assertSame("snapshot\n", fgets($sockets[0]));
            DB::reconnect();
            $this->runWorkflowTask($childIds[0]);
            $this->assertSame(0, $this->openTasks($parentId)->count());
            fwrite($sockets[0], "complete\n");
            $this->assertSame("completed\n", fgets($sockets[0]));
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
            $pid = 0;
        } finally {
            fclose($sockets[0]);
            if ($pid > 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            DB::purge();
            DB::reconnect();
        }

        $this->assertSame(2, WorkflowRun::query()->whereIn('id', $childIds)
            ->where('status', RunStatus::Completed->value)->count());
        $this->assertSame(1, $this->openTasks($parentId)->count());
        $this->assertSame(2, WorkflowHistoryEvent::query()->where('workflow_run_id', $parentId)
            ->where('event_type', HistoryEventType::ChildRunCompleted->value)->count());
        $this->runWorkflowTask($parentId);
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame($childIds, array_column($workflow->output()['children'], 'run_id'));
    }

    private function openTasks(string $runId): \Illuminate\Database\Eloquent\Builder
    {
        return WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value]);
    }

    private function runWorkflowTask(string $runId): void
    {
        $task = $this->openTasks($runId)->sole();
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }
}
