<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fixtures\V2\TestContinueAsNewWorkflow;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\Fixtures\V2\TestTimerWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;

/**
 * Every task of a run carries the run's priority and fairness (#971), not
 * only its first workflow task and its activities.
 */
final class V2ResumeTaskSchedulingTest extends TestCase
{
    private const RUN_SCHEDULING = [1, 'x', 3];

    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'redis');
        Queue::fake();
    }

    public function testTimerTaskAndTimerResumeTaskInheritRunScheduling(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'scheduling-timer');
        $workflow->start(60, $this->urgentStart());
        $runId = (string) $workflow->runId();

        $this->assertSame(self::RUN_SCHEDULING, $this->runFields($runId));
        $this->assertSame([self::RUN_SCHEDULING], $this->scheduling($runId, TaskType::Workflow));

        $this->runReady($runId, TaskType::Workflow);
        $this->assertSame([self::RUN_SCHEDULING], $this->scheduling($runId, TaskType::Timer));

        WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Timer->value)
            ->update([
                'available_at' => now()
                    ->subSecond(),
            ]);
        WorkflowRun::query()->findOrFail($runId)->timers()->update([
            'fire_at' => now()
                ->subSecond(),
        ]);
        $this->runReady($runId, TaskType::Timer);

        $this->assertSame(
            [self::RUN_SCHEDULING, self::RUN_SCHEDULING],
            $this->scheduling($runId, TaskType::Workflow),
        );
    }

    public function testSignalResumeTaskInheritsRunScheduling(): void
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'scheduling-signal');
        $workflow->start($this->urgentStart());
        $runId = (string) $workflow->runId();

        $this->assertSame(self::RUN_SCHEDULING, $this->runFields($runId));
        $this->assertSame([self::RUN_SCHEDULING], $this->scheduling($runId, TaskType::Workflow));

        $this->runReady($runId, TaskType::Workflow);
        $workflow->signal('name-provided', 'Taylor');

        $this->assertSame(
            [self::RUN_SCHEDULING, self::RUN_SCHEDULING],
            $this->scheduling($runId, TaskType::Workflow),
        );

        $this->runReady($runId, TaskType::Workflow);
        $this->assertSame([self::RUN_SCHEDULING], $this->scheduling($runId, TaskType::Activity));
    }

    public function testContinueAsNewRunKeepsRunScheduling(): void
    {
        $workflow = WorkflowStub::make(TestContinueAsNewWorkflow::class, 'scheduling-continue');
        $workflow->start(0, 1, $this->urgentStart());
        $firstRun = WorkflowRun::query()->findOrFail($workflow->runId());

        $this->assertSame(self::RUN_SCHEDULING, $this->runFields($firstRun->id));

        $this->runReady($firstRun->id, TaskType::Workflow);
        $this->assertSame([self::RUN_SCHEDULING], $this->scheduling($firstRun->id, TaskType::Activity));
        $this->runReady($firstRun->id, TaskType::Activity);
        $this->runReady($firstRun->id, TaskType::Workflow);

        /** @var WorkflowRun $continuedRun */
        $continuedRun = WorkflowRun::query()
            ->where('workflow_instance_id', $firstRun->workflow_instance_id)
            ->where('run_number', $firstRun->run_number + 1)
            ->firstOrFail();

        $this->assertSame(self::RUN_SCHEDULING, $this->runFields($continuedRun->id));
        $this->assertSame([self::RUN_SCHEDULING], $this->scheduling($continuedRun->id, TaskType::Workflow));
    }

    public function testTaskCreatorValuesAndActivityOverridesStillWin(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'scheduling-explicit');
        $workflow->start(60, $this->urgentStart());
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $this->assertSame(self::RUN_SCHEDULING, $this->runFields($run->id));

        $explicit = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Cancelled->value,
            'payload' => [],
            'priority' => 7,
        ]);

        $this->assertSame([7, 'x', 3], $this->fields($explicit->fresh()));

        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 99,
            'activity_class' => 'example.activity',
            'activity_type' => 'example.activity',
            'status' => 'pending',
            'activity_options' => [
                'priority' => 2,
                'fairness_key' => 'Override',
            ],
        ]);
        $retry = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Cancelled->value,
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
        ]);

        $this->assertSame([2, 'override', 3], $this->fields($retry->fresh()));

        // Default-valued overrides still win against the urgent run.
        $explicitDefault = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Cancelled->value,
            'payload' => [],
            'priority' => 5,
            'fairness_weight' => 1,
        ]);

        $this->assertSame([5, 'x', 1], $this->fields($explicitDefault->fresh()));

        $defaultOverride = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 100,
            'activity_class' => 'example.activity',
            'activity_type' => 'example.activity',
            'status' => 'pending',
            'activity_options' => [
                'priority' => 5,
                'fairness_weight' => 1,
            ],
        ]);
        $defaultOverrideTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Cancelled->value,
            'payload' => [
                'activity_execution_id' => $defaultOverride->id,
            ],
        ]);

        $this->assertSame([5, 'x', 1], $this->fields($defaultOverrideTask->fresh()));
    }

    public function testAnotherRunsActivityOptionsNeverApply(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'scheduling-fenced');
        $workflow->start(60, $this->urgentStart());
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        $other = WorkflowStub::make(TestTimerWorkflow::class, 'scheduling-other');
        $other->start(60);
        $foreignExecution = ActivityExecution::query()->create([
            'workflow_run_id' => $other->runId(),
            'sequence' => 99,
            'activity_class' => 'example.activity',
            'activity_type' => 'example.activity',
            'status' => 'pending',
            'activity_options' => [
                'priority' => 2,
                'fairness_key' => 'foreign',
                'fairness_weight' => 9,
            ],
        ]);

        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Cancelled->value,
            'payload' => [
                'activity_execution_id' => $foreignExecution->id,
            ],
        ]);

        $this->assertSame(self::RUN_SCHEDULING, $this->fields($task->fresh()));
    }

    public function testRunWithoutSchedulingOptionsKeepsTheDefaults(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'scheduling-default');
        $workflow->start(60);
        $runId = (string) $workflow->runId();

        $this->assertSame([5, null, 1], $this->runFields($runId));
        $this->runReady($runId, TaskType::Workflow);

        $this->assertSame([[5, null, 1]], $this->scheduling($runId, TaskType::Workflow));
        $this->assertSame([[5, null, 1]], $this->scheduling($runId, TaskType::Timer));
    }

    public function testTaskForAMissingRunKeepsTheColumnDefaults(): void
    {
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => (string) Str::ulid(),
            'namespace' => 'default',
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Cancelled->value,
            'payload' => [],
        ]);

        $this->assertSame([5, null, 1], $this->fields($task->fresh()));
    }

    private function urgentStart(): StartOptions
    {
        return StartOptions::rejectDuplicate()
            ->withPriority(1)
            ->withFairness('x', 3);
    }

    private function runReady(string $runId, TaskType $type): void
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', $type->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->firstOrFail();

        $job = match ($type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            TaskType::Timer => new RunTimerTask($task->id),
        };

        $this->app->call([$job, 'handle']);
    }

    /**
     * @return list<array{int|null, string|null, int|null}>
     */
    private function scheduling(string $runId, TaskType $type): array
    {
        return WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', $type->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (WorkflowTask $task): array => $this->fields($task))
            ->values()
            ->all();
    }

    /**
     * @return array{int|null, string|null, int|null}
     */
    private function runFields(string $runId): array
    {
        $run = WorkflowRun::query()->findOrFail($runId);

        return [$run->priority, $run->fairness_key, $run->fairness_weight];
    }

    /**
     * @return array{int|null, string|null, int|null}
     */
    private function fields(WorkflowTask $task): array
    {
        return [$task->priority, $task->fairness_key, $task->fairness_weight];
    }
}
