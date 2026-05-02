<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\ReplayDiff;
use Workflow\V2\WorkflowStub;

final class V2ReplayDiffTest extends TestCase
{
    public function testReplayDiffReportsCleanReplayForFreshBundle(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'replay-diff-clean');
        $workflow->start('Ada');
        $runId = $workflow->runId();
        $this->assertNotNull($runId);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);
        $this->runReadyTaskForRun($runId, TaskType::Activity);
        $this->runReadyTaskForRun($runId, TaskType::Workflow);

        $bundle = HistoryExport::forRun(WorkflowRun::query()->findOrFail($runId));

        $report = (new ReplayDiff())->diffExport($bundle);

        $this->assertSame(ReplayDiff::REPORT_SCHEMA, $report['schema']);
        $this->assertSame(ReplayDiff::STATUS_REPLAYED, $report['status']);
        $this->assertSame(ReplayDiff::REASON_NONE, $report['reason']);
        $this->assertSame($runId, $report['workflow']['workflow_run_id']);
        $this->assertNull($report['divergence']);
        $this->assertNull($report['error']);
        $this->assertIsInt($report['replay']['sequence']);
    }

    public function testReplayDiffSurfacesShapeMismatchAsDrift(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'replay-diff-drifted');
        $workflow->start('Ada');
        $runId = $workflow->runId();
        $this->assertNotNull($runId);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);
        $this->runReadyTaskForRun($runId, TaskType::Activity);
        $this->runReadyTaskForRun($runId, TaskType::Workflow);

        $bundle = HistoryExport::forRun(WorkflowRun::query()->findOrFail($runId));

        // Rewrite the activity-shaped events at workflow sequence 1 to
        // timer-shaped events so the current workflow code (which still
        // yields an activity) drifts from the recorded history.
        foreach ($bundle['history_events'] as &$event) {
            $type = $event['type'] ?? null;

            if ($type === 'ActivityScheduled' || $type === 'ActivityStarted') {
                $event['type'] = 'TimerScheduled';
                $event['payload']['timer_kind'] = 'durable_timer';
            } elseif ($type === 'ActivityCompleted' || $type === 'ActivityFailed') {
                $event['type'] = 'TimerFired';
                $event['payload']['timer_kind'] = 'durable_timer';
            }
        }
        unset($event);

        $report = (new ReplayDiff())->diffExport($bundle);

        $this->assertSame(ReplayDiff::STATUS_DRIFTED, $report['status']);
        $this->assertSame(ReplayDiff::REASON_SHAPE_MISMATCH, $report['reason']);
        $this->assertIsArray($report['divergence']);
        $this->assertSame(1, $report['divergence']['workflow_sequence']);
        $this->assertContains('TimerFired', $report['divergence']['recorded_event_types']);
        $this->assertNull($report['error']);
    }

    public function testReplayDiffReportsBundleInvalidWhenSchemaMissing(): void
    {
        $report = (new ReplayDiff())->diffExport([
            'workflow' => ['run_id' => 'r-1', 'instance_id' => 'i-1'],
        ]);

        $this->assertSame(ReplayDiff::STATUS_FAILED, $report['status']);
        $this->assertSame(ReplayDiff::REASON_BUNDLE_INVALID, $report['reason']);
        $this->assertNull($report['replay']);
        $this->assertIsArray($report['error']);
    }

    private function runReadyTaskForRun(string $runId, TaskType $taskType): void
    {
        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', $taskType->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->first();

        if ($task === null) {
            $this->fail(sprintf('Expected a ready %s task for run %s.', $taskType->value, $runId));
        }

        $job = match ($task->task_type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            TaskType::Timer => new RunTimerTask($task->id),
        };

        $this->app->call([$job, 'handle']);
    }
}
