<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\TestCase;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\StickyExecution;

final class StickyExecutionTest extends TestCase
{
    public function testWorkflowTaskInheritsActiveStickyAffinityFromRun(): void
    {
        $stickyUntil = now()
            ->addMinutes(5);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => 'wf-sticky-inherit',
            'run_number' => 1,
            'workflow_class' => self::class,
            'workflow_type' => 'tests.sticky-inherit',
            'namespace' => 'default',
            'status' => RunStatus::Waiting->value,
            'sticky_worker_id' => 'worker-sticky',
            'sticky_until' => $stickyUntil,
        ]);

        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => 'default',
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now(),
        ]);

        $this->assertSame('worker-sticky', $task->sticky_worker_id);
        $this->assertSame($stickyUntil->toJSON(), $task->sticky_until?->toJSON());
    }

    public function testWorkflowTaskDoesNotInheritExpiredStickyAffinity(): void
    {
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => 'wf-sticky-expired',
            'run_number' => 1,
            'workflow_class' => self::class,
            'workflow_type' => 'tests.sticky-expired',
            'namespace' => 'default',
            'status' => RunStatus::Waiting->value,
            'sticky_worker_id' => 'worker-sticky',
            'sticky_until' => now()
                ->subSecond(),
        ]);

        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => 'default',
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now(),
        ]);

        $this->assertNull($task->sticky_worker_id);
        $this->assertNull($task->sticky_until);
    }

    public function testClaimReplayModeSeparatesStickyHitFromForcedColdReplay(): void
    {
        $task = new WorkflowTask([
            'sticky_worker_id' => 'worker-a',
            'sticky_until' => now()
                ->addMinute(),
        ]);

        $this->assertSame(
            StickyExecution::MODE_STICKY_HIT_EXPECTED,
            StickyExecution::claimReplayMode($task, 'worker-a'),
        );
        $this->assertSame(
            StickyExecution::MODE_FORCED_COLD_REPLAY,
            StickyExecution::claimReplayMode($task, 'worker-b'),
        );
    }
}
