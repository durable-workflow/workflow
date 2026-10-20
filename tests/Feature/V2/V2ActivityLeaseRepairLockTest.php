<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityTaskClaimer;
use Workflow\V2\Support\TaskRepair;
use Workflow\V2\WorkflowStub;

final class V2ActivityLeaseRepairLockTest extends TestCase
{
    public function testLeaseRepairDoesNotWaitForAnAttemptWhileHoldingItsRun(): void
    {
        self::stopWorkers();
        Queue::fake();
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('Two actual row-locking database connections are required.');
        }
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class);
        $workflow->start('Ada');
        $workflowTask = WorkflowTask::query()->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)->sole();
        $this->app->call([new RunWorkflowTask($workflowTask->id), 'handle']);
        $task = WorkflowTask::query()->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Activity->value)->sole();
        [$claim] = ActivityTaskClaimer::claim($task->id, 'lease-original');
        $this->assertNotNull($claim);
        $task->forceFill([
            'lease_expires_at' => now()->subSecond(),
        ])->save();
        $claim->attempt->forceFill([
            'lease_expires_at' => $task->lease_expires_at,
        ])->save();
        $before = [$task->fresh()->getAttributes(), $claim->execution->fresh()->getAttributes()];
        $historyCount = WorkflowHistoryEvent::query()->count();

        config([
            'database.connections.lease_blocker' => DB::connection()->getConfig(),
        ]);
        $blocker = DB::connection('lease_blocker');
        $blocker->beginTransaction();
        try {
            $blocker->table($claim->attempt->getTable())
                ->where('id', $claim->attemptId())
                ->lockForUpdate()
                ->first();
            if ($driver === 'pgsql') {
                DB::selectOne("select set_config('lock_timeout', '5s', false)");
            } else {
                DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
            }
            $failure = null;
            $started = hrtime(true);
            try {
                DB::transaction(static function () use ($task): void {
                    $run = WorkflowRun::query()->lockForUpdate()->findOrFail($task->workflow_run_id);
                    TaskRepair::recoverExistingTask($task->fresh(), $run);
                });
            } catch (QueryException $error) {
                $failure = $error;
            }
            $elapsed = (hrtime(true) - $started) / 1e9;
            $this->assertInstanceOf(QueryException::class, $failure);
            $this->assertLessThan(
                2.0,
                $elapsed,
                'Repair must refuse contention rather than wait while retaining the run lock.'
            );
        } finally {
            $blocker->rollBack();
            DB::purge('lease_blocker');
            if ($driver === 'pgsql') {
                DB::selectOne("select set_config('lock_timeout', '0', false)");
            } else {
                DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
            }
        }
        $this->assertSame($before, [$task->fresh()->getAttributes(), $claim->execution->fresh()->getAttributes()]);
        $this->assertSame($historyCount, WorkflowHistoryEvent::query()->count());
    }
}
