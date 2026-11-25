<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

/**
 * Where workflow code is running relative to its committed history.
 *
 * This is a read-only observation for logging and diagnostics. Workflow code
 * must not branch durable commands on it.
 *
 * @api
 */
enum WorkflowReplayState: string
{
    /**
     * Re-running code that already ran before this wake-up, or answering a
     * query or update validator from replayed state.
     */
    case Replaying = 'replaying';

    /**
     * Running code beyond the committed history for the first time. A task
     * that fails before it commits runs this code again.
     */
    case Executing = 'executing';

    /**
     * Outside a workflow task, or the engine running the code does not track
     * its replay position.
     */
    case Unavailable = 'unavailable';
}
