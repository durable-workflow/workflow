<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestContinueAsNewWorkflow;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestParentChildWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

final class V2CompatibilityWorkflowTest extends TestCase
{
    public function testStartAndContinueAsNewPreserveCompatibilityMarkerAcrossRunsAndTasks(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-2026-04');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-2026-04']);

        Queue::fake();

        $workflow = WorkflowStub::make(TestContinueAsNewWorkflow::class, 'compat-continue');
        $workflow->start(0, 1);

        $this->drainReadyTasks();

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'compat-continue')
            ->orderBy('run_number')
            ->get();

        $this->assertCount(2, $runs);
        $this->assertSame(['build-2026-04', 'build-2026-04'], $runs->pluck('compatibility')->all());
        $this->assertSame(['build-2026-04'], WorkflowTask::query()
            ->whereIn('workflow_run_id', $runs->pluck('id'))
            ->distinct()
            ->pluck('compatibility')
            ->all());
    }

    public function testChildRunsAndTasksInheritParentCompatibilityMarker(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-2026-04');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-2026-04']);

        Queue::fake();

        $workflow = WorkflowStub::make(TestParentChildWorkflow::class, 'compat-child');
        $workflow->start('Taylor');

        $this->drainReadyTasks();

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        /** @var WorkflowRun $parentRun */
        $parentRun = WorkflowRun::query()->findOrFail($link->parent_workflow_run_id);
        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);

        $this->assertSame('build-2026-04', $parentRun->compatibility);
        $this->assertSame($parentRun->compatibility, $childRun->compatibility);
        $this->assertTrue(WorkflowTask::query()
            ->where('workflow_run_id', $childRun->id)
            ->where('compatibility', $parentRun->compatibility)
            ->exists());
    }

    public function testIncompatibleWorkerDoesNotClaimWorkflowTask(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'compat-claim');
        $workflow->start('Taylor');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        config()
            ->set('workflows.v2.compatibility.supported', ['build-b']);

        $job = new RunWorkflowTask($task->id);
        $this->app->call([$job, 'handle']);

        $task->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(0, $task->attempt_count);
        $this->assertNull($task->leased_at);
        $this->assertNull($task->lease_owner);
        $this->assertNull($task->lease_expires_at);
    }

    public function testIncompatibleWorkerBackfillsNullTaskCompatibilityFromRunMarkerBeforeClaim(): void
    {
        config()->set('workflows.v2.compatibility.supported', ['build-b']);
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'compat-null-task-claim',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'compatibility' => 'build-a',
            'payload_codec' => config('workflows.serializer'),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
            'last_history_sequence' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => null,
        ]);

        $job = new RunWorkflowTask($task->id);
        $this->app->call([$job, 'handle']);

        $task->refresh();
        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
        $detail = RunDetailView::forRun($run->fresh([
            'summary',
            'commands',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
            'parentLinks.parentRun.summary',
            'childLinks.childRun.summary',
            'instance.currentRun.summary',
        ]));

        Queue::assertNothingPushed();
        $this->assertSame('build-a', $task->compatibility);
        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(0, $task->attempt_count);
        $this->assertSame('build-a', $summary->compatibility);
        $this->assertSame('workflow_task_waiting_for_compatible_worker', $summary->liveness_state);
        $this->assertSame('build-a', $detail['tasks'][0]['compatibility']);
        $this->assertFalse($detail['tasks'][0]['compatibility_supported']);
        $this->assertSame(
            'Requires compatibility [build-a]; this worker supports [build-b].',
            $detail['tasks'][0]['compatibility_reason'],
        );
    }

    public function testTaskWatchdogSkipsRedispatchForUnsupportedCompatibilityMarker(): void
    {
        config()->set('workflows.v2.compatibility.supported', ['build-b']);
        Queue::fake();

        $lastDispatchedAt = now()
            ->subSeconds(30);

        $instance = WorkflowInstance::query()->create([
            'id' => 'compat-watchdog',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'compatibility' => 'build-a',
            'payload_codec' => config('workflows.serializer'),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
            'last_history_sequence' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'last_dispatched_at' => $lastDispatchedAt,
        ]);

        $this->wakeTaskWatchdog();

        $task->refresh();

        Queue::assertNothingPushed();
        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(0, $task->repair_count);
        $this->assertSame('build-a', $task->compatibility);
        $this->assertTrue($task->last_dispatched_at?->equalTo($lastDispatchedAt) ?? false);
    }

    public function testTaskWatchdogBackfillsNullTaskCompatibilityFromRunMarkerBeforeSkippingRedispatch(): void
    {
        config()->set('workflows.v2.compatibility.supported', ['build-b']);
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'compat-null-task-watchdog',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'compatibility' => 'build-a',
            'payload_codec' => config('workflows.serializer'),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
            'last_history_sequence' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => null,
            'last_dispatched_at' => now()
                ->subSeconds(30),
        ]);

        $this->wakeTaskWatchdog();

        $task->refresh();
        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        Queue::assertNothingPushed();
        $this->assertSame('build-a', $task->compatibility);
        $this->assertSame(0, $task->repair_count);
        $this->assertSame('build-a', $summary->compatibility);
        $this->assertSame('workflow_task_waiting_for_compatible_worker', $summary->liveness_state);
        $this->assertSame('Workflow task waiting for a compatible worker', $summary->wait_reason);
    }

    public function testTaskWatchdogSkipsMissingTaskRecoveryForUnsupportedCompatibilityMarker(): void
    {
        config()->set('workflows.v2.compatibility.supported', ['build-b']);
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'compat-missing-task',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'compatibility' => 'build-a',
            'payload_codec' => config('workflows.serializer'),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
            'last_history_sequence' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertSame('repair_needed', $summary->liveness_state);
        $this->assertNull($summary->next_task_id);

        $this->wakeTaskWatchdog();

        Queue::assertNothingPushed();
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $run->id)->count());

        $summary = $summary->fresh();

        $this->assertNotNull($summary);
        $this->assertSame('repair_needed', $summary->liveness_state);
        $this->assertNull($summary->next_task_id);
    }

    public function testRunDetailViewIncludesCompatibilityMetadata(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-b']);

        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'compat-detail');
        $workflow->start('Taylor');

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()
            ->with(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
            ->findOrFail($workflow->runId());

        /** @var WorkflowTask $task */
        $task = $run->tasks->firstOrFail();

        RunSummaryProjector::project($run);

        $detail = RunDetailView::forRun($run->fresh([
            'summary',
            'commands',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
            'parentLinks.parentRun.summary',
            'childLinks.childRun.summary',
            'instance.currentRun.summary',
        ]));

        $this->assertSame('build-a', $detail['compatibility']);
        $this->assertFalse($detail['compatibility_supported']);
        $this->assertSame(
            'Requires compatibility [build-a]; this worker supports [build-b].',
            $detail['compatibility_reason'],
        );
        $this->assertSame('workflow_task_waiting_for_compatible_worker', $detail['liveness_state']);
        $this->assertSame('Workflow task waiting for a compatible worker', $detail['wait_reason']);
        $this->assertSame(
            sprintf(
                'Workflow task %s is ready but waiting for a compatible worker. Requires compatibility [build-a]; this worker supports [build-b].',
                $task->id,
            ),
            $detail['liveness_reason'],
        );
        $this->assertFalse($detail['can_repair']);
        $this->assertSame('build-a', $detail['tasks'][0]['compatibility']);
        $this->assertFalse($detail['tasks'][0]['compatibility_supported']);
        $this->assertSame(
            'Requires compatibility [build-a]; this worker supports [build-b].',
            $detail['tasks'][0]['compatibility_reason'],
        );
        $this->assertSame('Workflow task is waiting for a compatible worker.', $detail['tasks'][0]['summary']);
    }

    public function testIncompatibleOverdueReadyTaskDoesNotSurfaceAsRepairNeeded(): void
    {
        config()->set('workflows.v2.compatibility.supported', ['build-b']);

        $instance = WorkflowInstance::query()->create([
            'id' => 'compat-overdue',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'reserved_at' => now()
                ->subMinutes(2),
            'started_at' => now()
                ->subMinutes(2),
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'compatibility' => 'build-a',
            'payload_codec' => config('workflows.serializer'),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(2),
            'last_progress_at' => now()
                ->subMinutes(2),
            'last_history_sequence' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'last_dispatched_at' => now()
                ->subSeconds(30),
        ]);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($run->fresh([
            'summary',
            'commands',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
            'parentLinks.parentRun.summary',
            'childLinks.childRun.summary',
            'instance.currentRun.summary',
        ]));

        $this->assertSame('workflow_task_waiting_for_compatible_worker', $summary->liveness_state);
        $this->assertSame('Workflow task waiting for a compatible worker', $summary->wait_reason);
        $this->assertSame(
            sprintf(
                'Workflow task %s is ready but dispatch is overdue and is waiting for a compatible worker. Requires compatibility [build-a]; this worker supports [build-b].',
                $task->id,
            ),
            $summary->liveness_reason,
        );
        $this->assertSame('workflow_task_waiting_for_compatible_worker', $detail['liveness_state']);
        $this->assertFalse($detail['can_repair']);
        $this->assertSame(
            'Workflow task is waiting for a compatible worker; dispatch is overdue.',
            $detail['tasks'][0]['summary'],
        );
    }

    public function testIncompatibleExpiredLeaseDoesNotSurfaceAsRepairNeeded(): void
    {
        config()->set('workflows.v2.compatibility.supported', ['build-b']);

        $instance = WorkflowInstance::query()->create([
            'id' => 'compat-expired-lease',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'reserved_at' => now()
                ->subMinutes(2),
            'started_at' => now()
                ->subMinutes(2),
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'compatibility' => 'build-a',
            'payload_codec' => config('workflows.serializer'),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(2),
            'last_progress_at' => now()
                ->subMinutes(2),
            'last_history_sequence' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subMinute(),
            'leased_at' => now()
                ->subMinutes(2),
            'lease_owner' => 'worker-build-a',
            'lease_expires_at' => now()
                ->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'last_dispatched_at' => now()
                ->subMinutes(2),
        ]);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($run->fresh([
            'summary',
            'commands',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
            'parentLinks.parentRun.summary',
            'childLinks.childRun.summary',
            'instance.currentRun.summary',
        ]));

        $this->assertSame('workflow_task_waiting_for_compatible_worker', $summary->liveness_state);
        $this->assertSame('Workflow task waiting for a compatible worker', $summary->wait_reason);
        $this->assertSame(
            sprintf(
                'Workflow task %s lease expired and is waiting for a compatible worker. Requires compatibility [build-a]; this worker supports [build-b].',
                $task->id,
            ),
            $summary->liveness_reason,
        );
        $this->assertSame('workflow_task_waiting_for_compatible_worker', $detail['liveness_state']);
        $this->assertFalse($detail['can_repair']);
        $this->assertSame(
            'Workflow task lease expired and is waiting for a compatible worker.',
            $detail['tasks'][0]['summary'],
        );
    }

    private function drainReadyTasks(): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->where('status', TaskStatus::Ready->value)
                ->orderBy('created_at')
                ->first();

            if ($task === null) {
                return;
            }

            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new RunTimerTask($task->id),
            };

            $this->app->call([$job, 'handle']);
        }

        $this->fail('Timed out draining ready workflow tasks.');
    }

    private function wakeTaskWatchdog(): void
    {
        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);
        TaskWatchdog::wake();
    }
}
