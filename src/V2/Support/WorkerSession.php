<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use function Workflow\V2\activity;

/**
 * Authoring handle for scheduling activities inside one worker session.
 *
 * @api Stable v2 authoring API for worker-session activity calls.
 */
final class WorkerSession
{
    public function __construct(
        public readonly WorkerSessionOptions $options,
    ) {
    }

    public function activity(string $activity, mixed ...$arguments): mixed
    {
        $options = null;

        if (($arguments[0] ?? null) instanceof ActivityOptions) {
            $options = array_shift($arguments);
        }

        $options = ($options ?? new ActivityOptions())
            ->withWorkerSession($this->options);

        return activity($activity, $options, ...$arguments);
    }
}
