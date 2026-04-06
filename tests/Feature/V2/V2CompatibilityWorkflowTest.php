<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

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
use Workflow\V2\TaskWatchdog;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\WorkflowStub;

final class V2CompatibilityWorkflowTest extends TestCase
{
    public function testStartAndContinueAsNewPreserveCompatibilityMarkerAcrossRunsAndTasks(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-2026-04');
        config()->set('workflows.v2.compatibility.supported', ['build-2026-04']);

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
        config()->set('workflows.v2.compatibility.supported', ['build-2026-04']);

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
        config()->set('workflows.v2.compatibility.supported', ['build-a']);

        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'compat-claim');
        $workflow->start('Taylor');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        config()->set('workflows.v2.compatibility.supported', ['build-b']);

        $job = new RunWorkflowTask($task->id);
        $this->app->call([$job, 'handle']);

        $task->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(0, $task->attempt_count);
        $this->assertNull($task->leased_at);
        $this->assertNull($task->lease_owner);
        $this->assertNull($task->lease_expires_at);
    }

    public function testTaskWatchdogSkipsRedispatchForUnsupportedCompatibilityMarker(): void
    {
        config()->set('workflows.v2.compatibility.supported', ['build-b']);
        Queue::fake();

        $lastDispatchedAt = now()->subSeconds(30);

        $instance = WorkflowInstance::query()->create([
            'id' => 'compat-watchdog',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'reserved_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
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
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
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
            'available_at' => now()->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'last_dispatched_at' => $lastDispatchedAt,
        ]);

        TaskWatchdog::wake();

        $task->refresh();

        Queue::assertNothingPushed();
        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(0, $task->repair_count);
        $this->assertSame('build-a', $task->compatibility);
        $this->assertTrue($task->last_dispatched_at?->equalTo($lastDispatchedAt) ?? false);
    }

    public function testRunDetailViewIncludesCompatibilityMetadata(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-b']);

        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'compat-detail');
        $workflow->start('Taylor');

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()
            ->with(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
            ->findOrFail($workflow->runId());

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
        $this->assertSame('build-a', $detail['tasks'][0]['compatibility']);
        $this->assertFalse($detail['tasks'][0]['compatibility_supported']);
        $this->assertSame(
            'Requires compatibility [build-a]; this worker supports [build-b].',
            $detail['tasks'][0]['compatibility_reason'],
        );
        $this->assertSame('Workflow task is waiting for a compatible worker.', $detail['tasks'][0]['summary']);
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
}
