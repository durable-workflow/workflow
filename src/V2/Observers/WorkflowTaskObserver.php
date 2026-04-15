<?php

declare(strict_types=1);

namespace Workflow\V2\Observers;

use Workflow\V2\Contracts\LongPollWakeStore;
use Workflow\V2\Models\WorkflowTask;

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
    ) {}

    public function created(WorkflowTask $task): void
    {
        $this->wakeStore->signalTask($task);
    }

    public function updated(WorkflowTask $task): void
    {
        $this->wakeStore->signalTask($task);
    }

    public function deleted(WorkflowTask $task): void
    {
        $this->wakeStore->signalTask($task);
    }
}
