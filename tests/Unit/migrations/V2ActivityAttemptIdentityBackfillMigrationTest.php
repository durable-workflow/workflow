<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use Illuminate\Support\Carbon;
use Tests\TestCase;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class V2ActivityAttemptIdentityBackfillMigrationTest extends TestCase
{
    public function testBackfillMigrationNormalizesLegacyActivityAttemptIdentity(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'attempt-backfill-instance',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        $startedAt = Carbon::parse('2026-04-08 10:00:00');
        $heartbeatAt = $startedAt->copy()->addMinute();
        $closedAt = $startedAt->copy()->addMinutes(2);

        $running = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'ActivityClass',
            'activity_type' => 'activity.test',
            'status' => 'running',
            'attempt_count' => 0,
            'started_at' => $startedAt,
            'last_heartbeat_at' => $heartbeatAt,
            'connection' => 'redis',
            'queue' => 'activities',
        ]);

        $completed = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'activity_class' => 'ActivityClass',
            'activity_type' => 'activity.test',
            'status' => 'completed',
            'attempt_count' => 0,
            'started_at' => $startedAt,
            'closed_at' => $closedAt,
            'connection' => 'redis',
            'queue' => 'activities',
        ]);

        $pending = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 3,
            'activity_class' => 'ActivityClass',
            'activity_type' => 'activity.test',
            'status' => 'pending',
            'attempt_count' => 0,
            'connection' => 'redis',
            'queue' => 'activities',
        ]);

        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => 'activity',
            'status' => 'leased',
            'payload' => [
                'activity_execution_id' => $running->id,
            ],
            'lease_owner' => 'backfill-worker',
            'lease_expires_at' => $heartbeatAt->copy()->addMinutes(5),
            'attempt_count' => 1,
            'connection' => 'redis',
            'queue' => 'activities',
        ]);

        $migration = require dirname(__DIR__, 3) . '/src/migrations/2026_04_08_000125_backfill_activity_attempt_identity.php';
        $migration->up();

        $running->refresh();
        $completed->refresh();
        $pending->refresh();

        /** @var ActivityAttempt $runningAttempt */
        $runningAttempt = ActivityAttempt::query()
            ->where('activity_execution_id', $running->id)
            ->firstOrFail();
        /** @var ActivityAttempt $completedAttempt */
        $completedAttempt = ActivityAttempt::query()
            ->where('activity_execution_id', $completed->id)
            ->firstOrFail();

        $this->assertNotNull($running->current_attempt_id);
        $this->assertSame(1, $running->attempt_count);
        $this->assertSame($running->current_attempt_id, $runningAttempt->id);
        $this->assertSame(1, $runningAttempt->attempt_number);
        $this->assertSame('running', $runningAttempt->status->value);
        $this->assertSame($task->id, $runningAttempt->workflow_task_id);
        $this->assertSame('backfill-worker', $runningAttempt->lease_owner);
        $this->assertSame($heartbeatAt->jsonSerialize(), $runningAttempt->last_heartbeat_at?->jsonSerialize());
        $this->assertSame(1, $task->fresh()->attempt_count);

        $this->assertNotNull($completed->current_attempt_id);
        $this->assertSame(1, $completed->attempt_count);
        $this->assertSame($completed->current_attempt_id, $completedAttempt->id);
        $this->assertSame(1, $completedAttempt->attempt_number);
        $this->assertSame('completed', $completedAttempt->status->value);
        $this->assertSame($closedAt->jsonSerialize(), $completedAttempt->closed_at?->jsonSerialize());

        $this->assertNull($pending->current_attempt_id);
        $this->assertSame(2, ActivityAttempt::query()->count());
    }
}
