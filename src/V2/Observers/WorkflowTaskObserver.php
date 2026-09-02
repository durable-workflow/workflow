<?php

declare(strict_types=1);

namespace Workflow\V2\Observers;

use Throwable;
use Workflow\V2\Contracts\LongPollWakeStore;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\CacheLongPollWakeStore;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\StickyExecution;

/**
 * Triggers long-poll wake signals when workflow tasks change.
 *
 * Automatically registered in V2ServiceProvider when the package is installed.
 * Wake signals notify waiting pollers to re-probe immediately instead of
 * waiting for poll timeout.
 */
class WorkflowTaskObserver
{
    public function __construct(
        private readonly LongPollWakeStore $wakeStore,
    ) {
    }

    public function creating(WorkflowTask $task): void
    {
        if ($task->task_type !== TaskType::Workflow && $task->task_type !== TaskType::Workflow->value) {
            return;
        }

        if (is_string($task->sticky_worker_id) && trim($task->sticky_worker_id) !== '') {
            return;
        }

        if (! is_string($task->workflow_run_id) || trim($task->workflow_run_id) === '') {
            return;
        }

        /** @var WorkflowRun|null $run */
        $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
            ->find($task->workflow_run_id);

        if (! $run instanceof WorkflowRun || ! StickyExecution::shouldInherit($run)) {
            return;
        }

        $task->sticky_worker_id = $run->sticky_worker_id;
        $task->sticky_until = $run->sticky_until;
    }

    public function created(WorkflowTask $task): void
    {
        $this->signalTask($task);
    }

    public function updated(WorkflowTask $task): void
    {
        $this->signalTask($task);
    }

    public function deleted(WorkflowTask $task): void
    {
        $this->signalTask($task);
    }

    private function signalTask(WorkflowTask $task): void
    {
        // The acceleration layer is not the correctness boundary. Any
        // publisher failure — unreachable cache, partitioned backend,
        // dropped signal — must not prevent the underlying task write
        // from completing. The durable dispatch row is already
        // persisted; pollers will discover it on their next interval.
        try {
            if ($this->wakeStore instanceof CacheLongPollWakeStore) {
                $this->wakeStore->signalTask($task);

                return;
            }

            $this->wakeStore->signal(...$this->wakeStore->workflowTaskPollChannels(
                is_string($task->namespace) ? $task->namespace : '',
                is_string($task->connection) ? $task->connection : null,
                is_string($task->queue) ? $task->queue : null,
            ));
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }
}
