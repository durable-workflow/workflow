<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * Rich outcome of a single {@see ScheduleManager::trigger()} attempt.
 *
 * Outcome values:
 *   - `triggered`   — a workflow run was started; `instanceId` is set.
 *   - `buffered`    — the overlap policy buffered this occurrence for later.
 *   - `buffer_full` — the overlap policy wanted to buffer but hit capacity.
 *   - `skipped`     — trigger was declined (exhausted, overlap blocked, status).
 */
final class ScheduleTriggerResult
{
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $instanceId,
        public readonly ?string $runId,
        public readonly ?string $reason,
    ) {}
}
