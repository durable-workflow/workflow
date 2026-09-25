<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Fixtures\V2\TestCommandTargetWorkflow;
use Tests\SchemaTestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkerCompatibilityHeartbeat;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\TaskWatchdog;

final class TaskWatchdogStorageConnectionTest extends SchemaTestCase
{
    private string $secondaryDatabase = '';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);
    }

    protected function tearDown(): void
    {
        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);
        config()
            ->set('workflows.storage.connection', null);

        parent::tearDown();

        if ($this->secondaryDatabase !== '' && is_file($this->secondaryDatabase)) {
            @unlink($this->secondaryDatabase);
        }
    }

    public function testRepairPassRunsWhenEngineTablesLiveOnlyOnTheStorageConnection(): void
    {
        Queue::fake();

        $default = (string) config('database.default');

        $this->assertNotSame('secondary', $default);
        $this->assertFalse(Schema::connection($default)->hasTable('workflow_tasks'));
        $this->assertTrue(Schema::connection('secondary')->hasTable('workflow_tasks'));

        $task = $this->createOverdueWorkflowTask('watchdog-storage-connection');

        // Before the fix TaskWatchdog::tablesReady() asked the default connection
        // for the engine tables, found none, and returned the empty report without
        // touching the throttle, the heartbeat table or any candidate.
        $report = TaskWatchdog::runPass();

        $this->assertSame(1, $report['selected_existing_task_candidates']);
        $this->assertSame(1, $report['selected_total_candidates']);
        $this->assertSame(1, $report['repaired_existing_tasks']);
        $this->assertSame(1, $report['dispatched_tasks']);
        $this->assertSame([], $report['existing_task_failures']);
        $this->assertTrue(Cache::has(TaskWatchdog::LOOP_THROTTLE_KEY));
        $this->assertSame(1, WorkerCompatibilityHeartbeat::query()->count());
        $this->assertSame('secondary', WorkerCompatibilityHeartbeat::query()->firstOrFail()->getConnectionName());
        $this->assertNotNull($task->fresh()->last_dispatched_at);
    }

    public function testHeartbeatsAreRecordedOnTheStorageConnection(): void
    {
        WorkerCompatibilityFleet::heartbeat('redis', 'default');

        $this->assertSame(1, WorkerCompatibilityHeartbeat::query()->count());
    }

    protected function defineEnvironment($app): void
    {
        $this->secondaryDatabase = (string) tempnam(sys_get_temp_dir(), 'wf_watchdog_storage_');

        $app['config']->set('database.connections.secondary', [
            'driver' => 'sqlite',
            'database' => $this->secondaryDatabase,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        // The engine's tables exist only on the secondary connection for this class.
        $app['config']->set('workflows.storage.connection', 'secondary');
    }

    private function createOverdueWorkflowTask(string $instanceId): WorkflowTask
    {
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => TestCommandTargetWorkflow::class,
            'workflow_type' => 'test-command-target-workflow',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinutes(5),
            'started_at' => now()
                ->subMinutes(5),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestCommandTargetWorkflow::class,
            'workflow_type' => 'test-command-target-workflow',
            'status' => RunStatus::Waiting->value,
            'payload_codec' => CodecRegistry::defaultCodec(),
            'arguments' => Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), []),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(5),
            'last_progress_at' => now()
                ->subMinutes(4),
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
                ->subSeconds(20),
            'last_dispatched_at' => now()
                ->subSeconds(20),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        return $task;
    }
}
