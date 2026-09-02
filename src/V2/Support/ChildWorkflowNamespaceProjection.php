<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Builder;
use Workflow\V2\Contracts\LongPollWakeStore;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowTask;

/**
 * @api Stable class surface consumed by the standalone workflow-server.
 */
final class ChildWorkflowNamespaceProjection
{
    public function __construct(
        private readonly LongPollWakeStore $wakeStore,
    ) {
    }

    public function projectLink(WorkflowLink $link): void
    {
        if ($link->link_type !== 'child_workflow') {
            return;
        }

        $this->bindChildWorkflow(
            self::stringValue($link->parent_workflow_instance_id ?? null),
            self::stringValue($link->parent_workflow_run_id ?? null),
            self::stringValue($link->child_workflow_instance_id ?? null),
            self::stringValue($link->child_workflow_run_id ?? null),
        );
    }

    public function projectLineageEntry(WorkflowRunLineageEntry $entry): void
    {
        if ($entry->direction !== 'child' || $entry->link_type !== 'child_workflow') {
            return;
        }

        $this->bindChildWorkflow(
            self::stringValue($entry->workflow_instance_id ?? null),
            self::stringValue($entry->workflow_run_id ?? null),
            self::stringValue($entry->related_workflow_instance_id ?? null),
            self::stringValue($entry->related_workflow_run_id ?? null),
        );
    }

    private function bindChildWorkflow(
        ?string $parentInstanceId,
        ?string $parentRunId,
        ?string $childInstanceId,
        ?string $childRunId,
    ): void {
        if ($childInstanceId === null) {
            return;
        }

        $namespace = $this->namespaceForParent($parentInstanceId, $parentRunId);

        if ($namespace === null) {
            return;
        }

        $updated = 0;

        /** @var class-string<WorkflowInstance> $instanceModel */
        $instanceModel = ConfiguredV2Models::resolve('instance_model', WorkflowInstance::class);
        $updated += $instanceModel::query()
            ->whereKey($childInstanceId)
            ->where(static function (Builder $query): void {
                $query->whereNull('namespace')
                    ->orWhere('namespace', '');
            })
            ->update([
                'namespace' => $namespace,
            ]);

        if ($childRunId !== null) {
            /** @var class-string<WorkflowRun> $runModel */
            $runModel = ConfiguredV2Models::resolve('run_model', WorkflowRun::class);
            $updated += $runModel::query()
                ->whereKey($childRunId)
                ->where(static function (Builder $query): void {
                    $query->whereNull('namespace')
                        ->orWhere('namespace', '');
                })
                ->update([
                    'namespace' => $namespace,
                ]);

            /** @var class-string<WorkflowTask> $taskModel */
            $taskModel = ConfiguredV2Models::resolve('task_model', WorkflowTask::class);
            $updated += $taskModel::query()
                ->where('workflow_run_id', $childRunId)
                ->where(static function (Builder $query): void {
                    $query->whereNull('namespace')
                        ->orWhere('namespace', '');
                })
                ->update([
                    'namespace' => $namespace,
                ]);
        }

        if ($updated > 0) {
            $this->signalChildWorkflowTasks($childRunId, $namespace);
        }
    }

    private function namespaceForParent(?string $parentInstanceId, ?string $parentRunId): ?string
    {
        /** @var class-string<WorkflowRun> $runModel */
        $runModel = ConfiguredV2Models::resolve('run_model', WorkflowRun::class);

        if ($parentRunId !== null) {
            $namespace = $runModel::query()
                ->whereKey($parentRunId)
                ->value('namespace');

            if (is_string($namespace) && $namespace !== '') {
                return $namespace;
            }
        }

        if ($parentInstanceId === null) {
            return null;
        }

        /** @var class-string<WorkflowInstance> $instanceModel */
        $instanceModel = ConfiguredV2Models::resolve('instance_model', WorkflowInstance::class);

        $namespace = $instanceModel::query()
            ->whereKey($parentInstanceId)
            ->value('namespace');

        return is_string($namespace) && $namespace !== ''
            ? $namespace
            : null;
    }

    private function signalChildWorkflowTasks(?string $childRunId, string $namespace): void
    {
        if ($childRunId === null) {
            return;
        }

        /** @var class-string<WorkflowTask> $taskModel */
        $taskModel = ConfiguredV2Models::resolve('task_model', WorkflowTask::class);

        /** @var iterable<int, WorkflowTask> $tasks */
        $tasks = $taskModel::query()
            ->where('workflow_run_id', $childRunId)
            ->get();

        foreach ($tasks as $task) {
            $taskType = $task->task_type instanceof TaskType
                ? $task->task_type->value
                : (string) $task->task_type;

            $channels = $taskType === TaskType::Activity->value
                ? $this->wakeStore->activityTaskPollChannels($namespace, $task->connection, $task->queue)
                : $this->wakeStore->workflowTaskPollChannels($namespace, $task->connection, $task->queue);

            $this->wakeStore->signal(...$channels);
        }
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
