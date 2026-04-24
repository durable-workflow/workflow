<?php

declare(strict_types=1);

namespace Workflow\V2\Observers;

use Throwable;
use Workflow\V2\Contracts\LongPollWakeStore;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Support\CacheLongPollWakeStore;

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
    ) {
    }

    public function created(WorkflowHistoryEvent $event): void
    {
        // History-event publishers are strictly an acceleration signal
        // for wait_new_event pollers. The durable history row is
        // already persisted; a failing or dropped publisher must not
        // break the write path.
        try {
            if ($this->wakeStore instanceof CacheLongPollWakeStore) {
                $this->wakeStore->signalHistoryEvent($event);

                return;
            }

            if (! is_string($event->workflow_run_id) || $event->workflow_run_id === '') {
                return;
            }

            $this->wakeStore->signal($this->wakeStore->historyRunChannel($event->workflow_run_id));
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }
}
