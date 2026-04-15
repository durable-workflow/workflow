<?php

declare(strict_types=1);

namespace Workflow\V2\Observers;

use Workflow\V2\Contracts\LongPollWakeStore;
use Workflow\V2\Models\WorkflowHistoryEvent;

/**
 * Triggers long-poll wake signals when history events are created.
 *
 * Automatically registered in V2ServiceProvider when the package is installed.
 * Wake signals notify waiting history pollers (wait_new_event) to re-probe
 * immediately instead of waiting for poll timeout.
 */
class WorkflowHistoryEventObserver
{
    public function __construct(
        private readonly LongPollWakeStore $wakeStore,
    ) {}

    public function created(WorkflowHistoryEvent $event): void
    {
        $this->wakeStore->signalHistoryEvent($event);
    }
}
