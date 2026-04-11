<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use Tests\TestCase;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;

final class V2CompatibilityBackfillMigrationTest extends TestCase
{
    public function testBackfillMigrationCopiesRunCompatibilityToLegacyNullTaskAndSummaryRows(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'compat-backfill-instance',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'id' => '01JTESTFLOWRUNBACKFILL0001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'compatibility' => 'build-a',
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        $task = WorkflowTask::query()->create([
            'id' => '01JTESTFLOWTASKBACKFILL001',
            'workflow_run_id' => $run->id,
            'task_type' => 'workflow',
            'status' => 'ready',
            'payload' => [],
            'compatibility' => null,
        ]);

        $summary = WorkflowRunSummary::query()->create([
            'id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'run_number' => $run->run_number,
            'engine_source' => 'v2',
            'class' => $run->workflow_class,
            'workflow_type' => $run->workflow_type,
            'compatibility' => null,
            'status' => $run->status->value,
            'status_bucket' => 'running',
            'exception_count' => 0,
        ]);

        $migration = require dirname(
            __DIR__,
            3
        ) . '/src/migrations/2026_04_08_000121_backfill_workflow_task_and_summary_compatibility.php';
        $migration->up();

        $this->assertSame('build-a', $task->fresh()->compatibility);
        $this->assertSame('build-a', $summary->fresh()->compatibility);
    }
}
