<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Foundation\Bus\PendingDispatch;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowTask;

final class TaskDispatcher
{
    public static function dispatch(WorkflowTask $task): void
    {
        $task->forceFill([
            'last_dispatched_at' => now(),
        ])->save();

        $dispatch = match ($task->task_type) {
            TaskType::Workflow => RunWorkflowTask::dispatch($task->id),
            TaskType::Activity => RunActivityTask::dispatch($task->id),
            TaskType::Timer => RunTimerTask::dispatch($task->id),
        };

        self::configure($dispatch, $task);
    }

    private static function configure(PendingDispatch $dispatch, WorkflowTask $task): void
    {
        if ($task->connection !== null) {
            $dispatch->onConnection($task->connection);
        }

        if ($task->queue !== null) {
            $dispatch->onQueue($task->queue);
        }

        if ($task->task_type === TaskType::Timer && $task->available_at !== null) {
            $dispatch->delay($task->available_at);
        }
    }
}
