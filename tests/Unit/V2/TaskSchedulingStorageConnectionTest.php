<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Str;
use Tests\Fixtures\V2\BootedCallbackWorkflowTask;
use Tests\SchemaTestCase;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ConfiguredV2Models;

/**
 * A new task reads its run and activity execution on its own storage
 * connection when inheriting priority and fairness (#971).
 */
final class TaskSchedulingStorageConnectionTest extends SchemaTestCase
{
    private string $secondaryDatabase = '';

    protected function tearDown(): void
    {
        config()->set('workflows.storage.connection', null);

        parent::tearDown();

        if ($this->secondaryDatabase !== '' && is_file($this->secondaryDatabase)) {
            @unlink($this->secondaryDatabase);
        }
    }

    public function testConfiguredStorageConnectionTaskInheritsItsRunScheduling(): void
    {
        $this->assertNotSame('secondary', (string) config('database.default'));

        $run = $this->createRun();
        $task = WorkflowTask::query()->create($this->taskAttributes($run, TaskType::Timer));

        $this->assertSame('secondary', $task->getConnectionName());
        $this->assertSame([1, 'x', 3], $this->fields($task->fresh()));
    }

    public function testExplicitTaskConnectionIsUsedForRunAndExecutionLookups(): void
    {
        // Without a configured storage connection, only the task's own
        // connection can find the run; the default connection has no tables.
        config()
            ->set('workflows.storage.connection', null);

        $run = $this->createRun('secondary');
        $execution = (new ActivityExecution())->setConnection('secondary');
        $execution->fill([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'example.activity',
            'activity_type' => 'example.activity',
            'status' => 'pending',
            'activity_options' => [
                'priority' => 2,
            ],
        ])->save();

        $timer = (new WorkflowTask())->setConnection('secondary');
        $timer->fill($this->taskAttributes($run, TaskType::Timer))
            ->save();

        $activity = (new WorkflowTask())->setConnection('secondary');
        $activity->fill($this->taskAttributes($run, TaskType::Activity, [
            'activity_execution_id' => $execution->id,
        ]))->save();

        $this->assertSame([1, 'x', 3], $this->fields($timer->fresh()));
        $this->assertSame([2, 'x', 3], $this->fields($activity->fresh()));
    }

    public function testConfiguredTaskModelWithItsOwnBootedCallbackStillInherits(): void
    {
        config()
            ->set('workflows.v2.task_model', BootedCallbackWorkflowTask::class);
        BootedCallbackWorkflowTask::$creatingCallbacks = 0;

        $run = $this->createRun();
        $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
            ->create($this->taskAttributes($run, TaskType::Workflow));

        $this->assertInstanceOf(BootedCallbackWorkflowTask::class, $task);
        $this->assertSame('secondary', $task->getConnectionName());
        $this->assertSame(1, BootedCallbackWorkflowTask::$creatingCallbacks);
        $this->assertSame([1, 'x', 3], $this->fields($task->fresh()));
    }

    protected function defineEnvironment($app): void
    {
        $this->secondaryDatabase = (string) tempnam(sys_get_temp_dir(), 'wf_scheduling_');

        $app['config']->set('database.connections.secondary', [
            'driver' => 'sqlite',
            'database' => $this->secondaryDatabase,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $app['config']->set('workflows.storage.connection', 'secondary');
    }

    private function createRun(?string $connection = null): WorkflowRun
    {
        $run = new WorkflowRun();

        if ($connection !== null) {
            $run->setConnection($connection);
        }

        $run->fill([
            'workflow_instance_id' => (string) Str::ulid(),
            'run_number' => 1,
            'workflow_class' => 'Tests\\Unit\\DummyWorkflow',
            'workflow_type' => 'dummy',
            'status' => RunStatus::Running,
            'priority' => 1,
            'fairness_key' => 'x',
            'fairness_weight' => 3,
        ])->save();

        return $run;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function taskAttributes(WorkflowRun $run, TaskType $type, array $payload = []): array
    {
        return [
            'workflow_run_id' => $run->id,
            'namespace' => 'default',
            'task_type' => $type->value,
            'status' => TaskStatus::Cancelled->value,
            'payload' => $payload,
        ];
    }

    /**
     * @return array{int|null, string|null, int|null}
     */
    private function fields(WorkflowTask $task): array
    {
        return [$task->priority, $task->fairness_key, $task->fairness_weight];
    }
}
