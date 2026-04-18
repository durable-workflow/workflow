<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Mockery;
use Tests\TestCase;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowTaskOwnership;

final class WorkflowTaskOwnershipTest extends TestCase
{
    public function testGuardReportsRunClosedWhenTerminalRunAlreadyClosedTask(): void
    {
        $guard = new WorkflowTaskOwnership($this->bridgeStatus([
            'task_id' => 'task-1',
            'task_status' => TaskStatus::Cancelled->value,
            'run_status' => 'cancelled',
            'workflow_run_id' => 'run-1',
            'workflow_instance_id' => 'workflow-1',
            'lease_owner' => null,
            'lease_expires_at' => null,
            'lease_expired' => false,
            'attempt_count' => 1,
            'reason' => null,
        ]));

        $result = $guard->guard(
            static fn (string $namespace, string $taskId): WorkflowTask => new WorkflowTask(),
            'default',
            'task-1',
            1,
            'worker-1',
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('run_closed', $result['reason']);
        $this->assertSame('cancelled', $result['status']['run_status']);
        $this->assertSame(TaskStatus::Cancelled->value, $result['status']['task_status']);
    }

    public function testGuardStillReportsTaskNotLeasedForOpenRuns(): void
    {
        $guard = new WorkflowTaskOwnership($this->bridgeStatus([
            'task_id' => 'task-1',
            'task_status' => TaskStatus::Ready->value,
            'run_status' => 'waiting',
            'workflow_run_id' => 'run-1',
            'workflow_instance_id' => 'workflow-1',
            'lease_owner' => null,
            'lease_expires_at' => null,
            'lease_expired' => false,
            'attempt_count' => 1,
            'reason' => null,
        ]));

        $result = $guard->guard(
            static fn (string $namespace, string $taskId): WorkflowTask => new WorkflowTask(),
            'default',
            'task-1',
            1,
            'worker-1',
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('task_not_leased', $result['reason']);
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function bridgeStatus(array $status): WorkflowTaskBridge
    {
        $bridge = Mockery::mock(WorkflowTaskBridge::class);
        $bridge->shouldReceive('status')
            ->once()
            ->with('task-1')
            ->andReturn($status);

        return $bridge;
    }
}
