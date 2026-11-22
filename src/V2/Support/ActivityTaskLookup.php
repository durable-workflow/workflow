<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Collection;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/** @internal */
final class ActivityTaskLookup
{
    public const COLUMN = 'indexed_activity_execution_id';

    /**
     * @return Collection<int, WorkflowTask>|null
     */
    public static function forExecution(WorkflowRun $run, string $executionId): ?Collection
    {
        if ($run->relationLoaded('tasks')) {
            return null;
        }
        $tasks = $run->tasks();
        $model = $tasks->getRelated();
        if ($model::class !== WorkflowTask::class) {
            return null;
        }

        $connection = $model->getConnection();
        $driver = $connection->getDriverName();
        $expression = self::expression($driver);
        if ($expression === null) {
            return null;
        }

        $tasks->where('task_type', TaskType::Activity->value);
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            if (! $connection->getSchemaBuilder()->hasColumn($model->getTable(), self::COLUMN)) {
                return null;
            }
            $tasks->where(self::COLUMN, $executionId);
        } else {
            $tasks->whereRaw("({$expression}) = ?", [$executionId]);
        }

        return $tasks->get();
    }

    public static function expression(string $driver): ?string
    {
        return match ($driver) {
            'sqlite' => "CASE WHEN json_valid(payload) THEN CASE WHEN json_type(payload, '$.activity_execution_id') = 'text' "
                . "THEN json_extract(payload, '$.activity_execution_id') END END",
            'pgsql' => "CASE WHEN json_typeof(payload->'activity_execution_id') = 'string' "
                . "THEN payload->>'activity_execution_id' END",
            'mysql', 'mariadb' => "CASE WHEN JSON_TYPE(JSON_EXTRACT(payload, '$.activity_execution_id')) = 'STRING' "
                . "THEN LEFT(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.activity_execution_id')), 26) END",
            default => null,
        };
    }
}
