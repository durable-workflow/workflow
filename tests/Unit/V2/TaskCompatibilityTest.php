<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\TaskCompatibility;

final class TaskCompatibilityTest extends TestCase
{
    public function testResolveUsesThePassedRunWithoutLoadingTheTaskRun(): void
    {
        $run = $this->createRun(null);
        $task = $this->createTask($run);

        $this->assertNull(TaskCompatibility::resolve($task, $run));
        $this->assertFalse($task->relationLoaded('run'));
    }

    public function testResolveFallsBackToTheTaskRunWhenNoRunIsPassed(): void
    {
        $run = $this->createRun('build-a');
        $task = $this->createTask($run);

        $this->assertSame('build-a', TaskCompatibility::resolve($task));
    }

    private function createRun(?string $compatibility): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => 'TestWorkflow',
            'workflow_type' => 'test-workflow',
            'reserved_at' => now(),
            'run_count' => 1,
        ]);

        return WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'TestWorkflow',
            'workflow_type' => 'test-workflow',
            'status' => 'running',
            'compatibility' => $compatibility,
        ]);
    }

    private function createTask(WorkflowRun $run): WorkflowTask
    {
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'payload' => [],
            'compatibility' => null,
        ]);

        return WorkflowTask::query()->findOrFail($task->id);
    }
}
