<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestAuthoredRetryReplayWorkflow;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\HealthCheck;
use Workflow\V2\Support\WorkflowTaskProblem;
use Workflow\V2\WorkflowStub;

final class WorkflowTaskFailureLivenessTest extends TestCase
{
    protected function tearDown(): void
    {
        TestAuthoredRetryReplayWorkflow::$overrides = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testServiceWorkerFailuresAndRepairKeepHistoricalAndActiveProblemsDistinct(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();
        Carbon::setTestNow('2026-11-05T03:00:00Z');
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'workflow-task-failure-classification');
        $workflow->start('Taylor');
        $runId = $workflow->runId();
        $this->assertIsString($runId);
        $bridge = $this->app->make(WorkflowTaskBridge::class);
        $this->assertSame([
            'open' => true,
            'liveness' => 'workflow_task_ready',
            'task_problem' => false,
            'badge' => null,
            'repair_needed' => 0,
            'resume_health' => 'ok',
        ], $this->classificationFor($runId));

        for ($failure = 1; $failure <= 2; ++$failure) {
            $task = WorkflowTask::query()->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Workflow->value)
                ->where('status', TaskStatus::Ready->value)->sole();
            $this->assertNotNull($bridge->claim($task->id, 'source-classification-worker'));
            Carbon::setTestNow(now()->addSecond());
            $result = $bridge->fail($task->id, 'Synthetic service replay failure');
            $this->assertTrue($result['recorded']);
            $this->assertSame(TaskStatus::Failed, $task->fresh()->status);
            $this->assertFalse((bool) ($task->fresh()->payload['replay_blocked'] ?? false));
            $this->assertSame([
                'open' => true,
                'liveness' => 'repair_needed',
                'task_problem' => true,
                'badge' => 'active',
                'repair_needed' => 1,
                'resume_health' => 'warning',
            ], $this->classificationFor($runId));

            Carbon::setTestNow(now()->addSecond());
            $this->assertTrue($workflow->repair()->accepted());
            $this->assertSame([
                'open' => true,
                'liveness' => 'workflow_task_ready',
                'task_problem' => true,
                'badge' => 'history',
                'repair_needed' => 0,
                'resume_health' => 'ok',
            ], $this->classificationFor($runId));
        }

        $this->assertTrue($workflow->terminate('Source classification closure')->accepted());
        $this->assertSame([
            'open' => false,
            'liveness' => 'closed',
            'task_problem' => true,
            'badge' => 'history',
            'repair_needed' => 0,
            'resume_health' => 'ok',
        ], $this->classificationFor($runId));
    }

    public function testEmbeddedReplayBlockClearsOnCompatibleRepairAndCompletion(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();
        Carbon::setTestNow('2026-11-05T03:00:00Z');
        $workflow = WorkflowStub::make(TestAuthoredRetryReplayWorkflow::class, 'embedded-replay-block-classification');
        $workflow->start(120, 'direct');
        $runId = $workflow->runId();
        $this->assertIsString($runId);
        $this->runReadyTask($runId, TaskType::Workflow);
        $this->runReadyTask($runId, TaskType::Activity);

        TestAuthoredRetryReplayWorkflow::$overrides = new ActivityOptions(
            startToCloseTimeout: 120,
            scheduleToCloseTimeout: 600,
            maxAttempts: 1,
            backoff: [1, 2, 5, 10, 15, 30, 60, 121],
        );
        $blocked = $this->runReadyTask($runId, TaskType::Workflow);
        $this->assertSame(TaskStatus::Failed, $blocked->fresh()->status);
        $this->assertTrue($blocked->fresh()->payload['replay_blocked']);
        $this->assertSame([
            'open' => true,
            'liveness' => 'workflow_replay_blocked',
            'task_problem' => true,
            'badge' => 'replay_blocked',
            'repair_needed' => 0,
            'resume_health' => 'ok',
        ], $this->classificationFor($runId));

        TestAuthoredRetryReplayWorkflow::$overrides = null;
        $this->assertTrue($workflow->attemptRepair()->accepted());
        $this->assertSame([
            'open' => true,
            'liveness' => 'workflow_task_ready',
            'task_problem' => true,
            'badge' => 'history',
            'repair_needed' => 0,
            'resume_health' => 'ok',
        ], $this->classificationFor($runId));
        $this->runReadyTask($runId, TaskType::Workflow);
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'open' => false,
            'liveness' => 'closed',
            'task_problem' => true,
            'badge' => 'history',
            'repair_needed' => 0,
            'resume_health' => 'ok',
        ], $this->classificationFor($runId));
    }

    private function runReadyTask(string $runId, TaskType $type): WorkflowTask
    {
        $task = WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('task_type', $type->value)
            ->where('status', TaskStatus::Ready->value)->sole();
        $job = $type === TaskType::Activity ? new RunActivityTask($task->id) : new RunWorkflowTask($task->id);
        $this->app->call([$job, 'handle']);

        return $task;
    }

    /**
     * @return array{open: bool, liveness: string|null, task_problem: bool, badge: string|null, repair_needed: int, resume_health: string}
     */
    private function classificationFor(string $runId): array
    {
        $run = WorkflowRun::query()->findOrFail($runId);
        $summary = WorkflowRunSummary::query()->findOrFail($runId);
        $health = HealthCheck::snapshot();
        $checks = array_column($health['checks'], 'status', 'name');
        $problem = WorkflowTaskProblem::metadata($summary->task_problem, $summary->liveness_state, $summary->wait_kind);

        return [
            'open' => ! $run->status->isTerminal(),
            'liveness' => $summary->liveness_state,
            'task_problem' => $summary->task_problem,
            'badge' => $problem['code'] ?? null,
            'repair_needed' => $health['operator_metrics']['runs']['repair_needed'],
            'resume_health' => $checks['durable_resume_paths'],
        ];
    }
}
