<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Recovery;

use Workflow\V2\Support\ActivityLease;
use Workflow\V2\Support\TaskRepairPolicy;

/**
 * @internal Retry schedule for compensation inside a canonical recovery run (#1205).
 *
 * A recovery run compensating a child participant can wait on a child attempt
 * whose worker crashed; that attempt holds its lease until task repair
 * reclaims it. Short retries cover ordinary progress. The last wait outlasts
 * one lease plus one repair pass. A persistent failure still fails the run
 * after the last attempt. RecoveryWorkflow records the computed schedule with
 * sideEffect(), so a later configuration change cannot alter replay.
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final class CompensationRetrySchedule
{
    public const ATTEMPTS = 6;

    /**
     * @return list<int>
     */
    public static function backoff(): array
    {
        return [
            1,
            2,
            5,
            10,
            ActivityLease::seconds()
            + TaskRepairPolicy::loopThrottleSeconds()
            + TaskRepairPolicy::failureBackoffMaxSeconds(),
        ];
    }
}
