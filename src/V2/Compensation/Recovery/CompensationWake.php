<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Recovery;

use Workflow\V2\Compensation\Activities\CompensateObligationActivity;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeStorage;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\CurrentRunResolver;
use Workflow\V2\Support\SqliteTransaction;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\Support\TypeRegistry;

/**
 * @internal Ends the wait before a scope's next compensation retry.
 *
 * Compensation retries wait long enough to outlast one crashed attempt (#1205).
 * When something that retry depends on has changed, such as a child scope
 * becoming verified or a fresh authorized request, the waiting retry runs now.
 * Attempt numbers, the recorded retry policy and history are unchanged.
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final class CompensationWake
{
    /**
     * Wake the scope's open origin run and its open canonical recovery run.
     */
    public static function scope(ScopeIdentity $scope): void
    {
        $database = ConfiguredV2Models::connection();
        $row = $database->table(ScopeStorage::SCOPES)
            ->where('id', $scope->id())
            ->first(['original_run_id']);
        $runIds = $row === null ? [] : [(string) $row->original_run_id];
        $instance = ConfiguredV2Models::query('instance_model', WorkflowInstance::class)
            ->where('namespace', $scope->namespace)
            ->find($scope->recoveryInstanceId());
        $recovery = $instance instanceof WorkflowInstance ? CurrentRunResolver::forInstance($instance) : null;
        if ($recovery instanceof WorkflowRun) {
            $runIds[] = $recovery->id;
        }
        foreach (array_unique($runIds) as $runId) {
            self::run($runId);
        }
    }

    /**
     * Wake the parent of a child scope that has just become verified.
     */
    public static function parentOf(ScopeIdentity $child): void
    {
        $database = ConfiguredV2Models::connection();
        $edge = $database->table('workflow_compensation_children')
            ->where('child_id', $child->id())
            ->first(['parent_id']);
        $parent = $edge === null ? null : $database->table(ScopeStorage::SCOPES)
            ->where('id', $edge->parent_id)
            ->first(['identity']);
        if ($parent !== null) {
            self::scope(ScopeIdentity::fromArray(json_decode($parent->identity, true, flags: JSON_THROW_ON_ERROR)));
        }
    }

    private static function run(string $runId): void
    {
        $compensationType = TypeRegistry::for(CompensateObligationActivity::class);
        $woken = SqliteTransaction::run(static function () use ($runId, $compensationType): array {
            $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->lockForUpdate()
                ->find($runId);
            if (! $run instanceof WorkflowRun || $run->status->isTerminal()) {
                return [];
            }
            $woken = [];
            $tasks = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                ->where('workflow_run_id', $run->id)
                ->where('task_type', TaskType::Activity->value)
                ->where('status', TaskStatus::Ready->value)
                ->where('available_at', '>', now())
                ->lockForUpdate()
                ->get();
            foreach ($tasks as $task) {
                $executionId = is_array($task->payload) ? ($task->payload['activity_execution_id'] ?? null) : null;
                if (! is_string($executionId) || ! ConfiguredV2Models::query(
                    'activity_execution_model',
                    ActivityExecution::class
                )->whereKey($executionId)
                    ->where('workflow_run_id', $run->id)
                    ->where('activity_type', $compensationType)
                    ->exists()) {
                    continue;
                }
                $task->forceFill([
                    'available_at' => now(),
                ])->save();
                $woken[] = $task;
            }
            if ($woken !== []) {
                app(HistoryProjectionRole::class)->projectRun(
                    $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
                );
            }
            return $woken;
        }, connection: ConfiguredV2Models::connection());
        foreach ($woken as $task) {
            TaskDispatcher::dispatch($task);
        }
    }
}
