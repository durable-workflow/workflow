<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

/**
 * Binding seam for the matching role.
 *
 * Queue-loop wake hooks and the dedicated repair-pass daemon use this
 * contract when they need the canonical matching-role implementation
 * without hard-coding the in-process TaskWatchdog.
 */
interface MatchingRole
{
    public function wake(?string $connection = null, ?string $queue = null): void;

    /**
     * @param  list<string>  $runIds
     * @return array{
     *     connection: string|null,
     *     queue: string|null,
     *     run_ids: list<string>,
     *     instance_id: string|null,
     *     respect_throttle: bool,
     *     throttled: bool,
     *     selected_existing_task_candidates: int,
     *     selected_missing_task_candidates: int,
     *     selected_total_candidates: int,
     *     repaired_existing_tasks: int,
     *     repaired_missing_tasks: int,
     *     dispatched_tasks: int,
     *     existing_task_failures: list<array{candidate_id: string, message: string}>,
     *     missing_run_failures: list<array{run_id: string, message: string}>,
     *     deadline_expired_candidates: int,
     *     deadline_expired_tasks_created: int,
     *     deadline_expired_failures: list<array{run_id: string, message: string}>,
     *     activity_timeout_candidates: int,
     *     activity_timeouts_enforced: int,
     *     activity_timeout_failures: list<array{execution_id: string, message: string}>
     * }
     */
    public function runPass(
        ?string $connection = null,
        ?string $queue = null,
        bool $respectThrottle = false,
        array $runIds = [],
        ?string $instanceId = null,
    ): array;
}
