<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;

/**
 * Deadline-triggered cleanup (#863, contract in
 * docs/architecture/timeout-cleanup.md).
 *
 * A start that opts in reserves the last `allowance_seconds` of each hard
 * timeout for workflow-authored cleanup. The work deadline is the hard
 * deadline minus the allowance; the hard deadline never moves. The admitted
 * values are recorded once in `WorkflowStarted` and read from there.
 *
 * @internal
 */
final class TimeoutCleanup
{
    /**
     * `WorkflowStarted` payload key.
     */
    public const STARTED_KEY = 'timeout_cleanup';

    /**
     * @return array{allowance_seconds: int, work_deadlines: array<string, string>}|null
     */
    public static function startedPayload(
        ?int $allowanceSeconds,
        ?CarbonInterface $executionDeadlineAt,
        ?CarbonInterface $runDeadlineAt,
    ): ?array {
        if ($allowanceSeconds === null) {
            return null;
        }

        $workDeadlines = [];
        foreach ([
            'execution' => $executionDeadlineAt,
            'run' => $runDeadlineAt,
        ] as $kind => $hardDeadline) {
            if ($hardDeadline !== null) {
                $workDeadlines[$kind] = $hardDeadline->copy()
                    ->subSeconds($allowanceSeconds)
                    ->toIso8601String();
            }
        }

        return [
            'allowance_seconds' => $allowanceSeconds,
            'work_deadlines' => $workDeadlines,
        ];
    }
}
