<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityOutcomeRecorder;
use Workflow\V2\Support\DefaultActivityTaskBridge;
use Workflow\V2\Support\EmbeddedV2ImportContract;

final class ActivityOutcomeReferenceTest extends TestCase
{
    #[DataProvider('foreignTaskReferences')]
    public function testLegacyImportedAttemptCannotActOnAnotherRunsTask(string $path, ?string $namespace): void
    {
        Queue::fake();
        $run = $this->makeRun('tenant-a', RunStatus::Waiting);
        $foreignRun = $this->makeRun($namespace, RunStatus::Cancelled);
        [$execution, $task, $attempt] = $this->leasedAttempt($run);
        [$foreignExecution, $foreignTask, $foreignAttempt] = $this->leasedAttempt($foreignRun);
        $attempt->forceFill([
            'workflow_task_id' => $foreignTask->id,
        ])->save();
        $rows = [$run, $execution, $task, $attempt, $foreignRun, $foreignExecution, $foreignTask, $foreignAttempt];
        $before = $this->storedState($rows);

        $outcome = $path === 'bridge'
            ? (new DefaultActivityTaskBridge())->complete($attempt->id, 'ignored')
            : ActivityOutcomeRecorder::record($foreignTask->id, $attempt->id, 1, 'ignored', null, 1, 0);

        $this->assertFalse($outcome['recorded']);
        $this->assertSame($before, $this->storedState($rows));
        $this->assertSame('stale_attempt', $outcome['reason']);
    }

    public static function foreignTaskReferences(): iterable
    {
        foreach (['direct', 'bridge'] as $path) {
            foreach (['tenant-a', 'tenant-b', null] as $namespace) {
                yield $path . '/' . ($namespace ?? 'null') => [$path, $namespace];
            }
        }
    }

    #[DataProvider('foreignTaskObservations')]
    public function testLegacyImportedAttemptObservationCannotUseAnotherRunsTask(string $path, ?string $namespace): void
    {
        Queue::fake();
        $run = $this->makeRun('tenant-a', RunStatus::Cancelled);
        $foreignRun = $this->makeRun($namespace, RunStatus::Waiting);
        [$execution, $task, $attempt] = $this->leasedAttempt($run);
        [$foreignExecution, $foreignTask, $foreignAttempt] = $this->leasedAttempt($foreignRun);
        $attempt->forceFill([
            'workflow_task_id' => $foreignTask->id,
        ])->save();
        $foreignRows = [$foreignRun, $foreignExecution, $foreignTask, $foreignAttempt];
        $before = $this->storedState($foreignRows);
        $bridge = new DefaultActivityTaskBridge();

        $outcome = $path === 'heartbeat' ? $bridge->heartbeat($attempt->id) : $bridge->status($attempt->id);
        $expectedStatus = $path === 'heartbeat' ? ActivityAttemptStatus::Cancelled : ActivityAttemptStatus::Running;

        $this->assertSame($before, $this->storedState($foreignRows));
        $this->assertNull($outcome['task_status']);
        $this->assertSame($expectedStatus, $attempt->fresh()->status);
    }

    public static function foreignTaskObservations(): iterable
    {
        foreach (['status', 'heartbeat'] as $path) {
            foreach (['tenant-a', 'tenant-b', null] as $namespace) {
                yield $path . '/' . ($namespace ?? 'null') => [$path, $namespace];
            }
        }
    }

    #[DataProvider('outcomePaths')]
    public function testAnImportedRunsOwnedAttemptStillCompletes(string $path): void
    {
        Queue::fake();
        [$execution, $task, $attempt] = $this->leasedAttempt($this->makeRun('tenant-a', RunStatus::Waiting));

        $outcome = $path === 'bridge'
            ? (new DefaultActivityTaskBridge())->complete($attempt->id, 'completed')
            : ActivityOutcomeRecorder::record($task->id, $attempt->id, 1, 'completed', null, 1, 0);

        $this->assertTrue($outcome['recorded']);
        $this->assertSame(ActivityAttemptStatus::Completed, $attempt->fresh()->status);
        $this->assertSame(ActivityStatus::Completed, $execution->fresh()->status);
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }

    public static function outcomePaths(): iterable
    {
        yield 'direct' => ['direct'];
        yield 'bridge' => ['bridge'];
    }

    private function makeRun(?string $namespace, RunStatus $status): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => (string) Str::ulid(),
            'namespace' => $namespace,
            'workflow_class' => 'FixtureWorkflow',
            'workflow_type' => 'fixture',
            'run_count' => 1,
        ]);

        return WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'namespace' => $namespace,
            'run_number' => 1,
            'workflow_class' => 'FixtureWorkflow',
            'workflow_type' => 'fixture',
            'status' => $status,
            'payload_codec' => 'avro',
            'import_source' => EmbeddedV2ImportContract::IMPORT_SOURCE,
            'connection' => 'database',
            'queue' => 'fixture',
        ]);
    }

    /**
     * @return array{ActivityExecution, WorkflowTask, ActivityAttempt}
     */
    private function leasedAttempt(WorkflowRun $run): array
    {
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'FixtureActivity',
            'activity_type' => 'fixture.activity',
            'status' => ActivityStatus::Running,
            'payload_codec' => 'avro',
            'attempt_count' => 1,
        ]);
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Activity,
            'status' => TaskStatus::Leased,
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
            'attempt_count' => 1,
            'lease_owner' => 'fixture-worker',
            'leased_at' => now(),
            'lease_expires_at' => now()
                ->addMinutes(10),
        ]);
        $attempt = ActivityAttempt::query()->create([
            'workflow_run_id' => $run->id,
            'activity_execution_id' => $execution->id,
            'workflow_task_id' => $task->id,
            'attempt_number' => 1,
            'status' => ActivityAttemptStatus::Running,
            'lease_owner' => 'fixture-worker',
            'started_at' => now(),
            'lease_expires_at' => now()
                ->addMinutes(10),
        ]);
        $execution->forceFill([
            'current_attempt_id' => $attempt->id,
        ])->save();

        return [$execution, $task, $attempt];
    }

    /**
     * @param list<Model> $rows
     * @return list<array<string, mixed>>
     */
    private function storedState(array $rows): array
    {
        return array_map(static fn (Model $row): array => $row->fresh()->getRawOriginal(), $rows);
    }
}
