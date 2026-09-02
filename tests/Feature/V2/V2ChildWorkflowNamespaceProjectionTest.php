<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Tests\TestCase;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowTask;

final class V2ChildWorkflowNamespaceProjectionTest extends TestCase
{
    public function testWorkflowLinkObserverProjectsChildNamespaceFromParent(): void
    {
        $parentRun = $this->createRun('projection-parent', 'production');
        $childRun = $this->createRun('projection-child', null);

        /** @var WorkflowTask $childTask */
        $childTask = WorkflowTask::query()->create([
            'workflow_run_id' => $childRun->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now(),
            'payload' => [],
        ]);

        WorkflowLink::query()->create([
            'id' => 'projection-link',
            'link_type' => 'child_workflow',
            'parent_workflow_instance_id' => $parentRun->workflow_instance_id,
            'parent_workflow_run_id' => $parentRun->id,
            'child_workflow_instance_id' => $childRun->workflow_instance_id,
            'child_workflow_run_id' => $childRun->id,
            'is_primary_parent' => true,
        ]);

        $this->assertSame(
            'production',
            WorkflowInstance::query()->whereKey($childRun->workflow_instance_id)->value('namespace'),
        );
        $this->assertSame('production', $childRun->fresh()->namespace);
        $this->assertSame('production', $childTask->fresh()->namespace);
    }

    public function testLineageEntryObserverProjectsChildNamespaceFromParent(): void
    {
        $parentRun = $this->createRun('lineage-parent', 'operations');
        $childRun = $this->createRun('lineage-child', null);

        /** @var WorkflowTask $childTask */
        $childTask = WorkflowTask::query()->create([
            'workflow_run_id' => $childRun->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now(),
            'payload' => [],
        ]);

        WorkflowRunLineageEntry::query()->create([
            'id' => $parentRun->id . ':child:projection',
            'workflow_run_id' => $parentRun->id,
            'workflow_instance_id' => $parentRun->workflow_instance_id,
            'direction' => 'child',
            'lineage_id' => 'projection',
            'position' => 0,
            'link_type' => 'child_workflow',
            'is_primary_parent' => true,
            'related_workflow_instance_id' => $childRun->workflow_instance_id,
            'related_workflow_run_id' => $childRun->id,
            'related_workflow_type' => 'tests.projected-child',
            'payload' => [],
            'linked_at' => now(),
        ]);

        $this->assertSame(
            'operations',
            WorkflowInstance::query()->whereKey($childRun->workflow_instance_id)->value('namespace'),
        );
        $this->assertSame('operations', $childRun->fresh()->namespace);
        $this->assertSame('operations', $childTask->fresh()->namespace);
    }

    private function createRun(string $instanceId, ?string $namespace): WorkflowRun
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => 'tests.projected-workflow',
            'workflow_type' => 'tests.projected-workflow',
            'namespace' => $namespace,
            'run_count' => 1,
            'reserved_at' => now(),
            'started_at' => now(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'tests.projected-workflow',
            'workflow_type' => 'tests.projected-workflow',
            'namespace' => $namespace,
            'status' => RunStatus::Pending->value,
            'started_at' => now(),
            'last_progress_at' => now(),
            'last_history_sequence' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }
}
