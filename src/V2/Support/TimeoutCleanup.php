<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

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
     * The allowance a run was admitted with, read from its `WorkflowStarted`.
     * A continued or redriven run inherits it, as it inherits the hard
     * deadlines.
     */
    public static function allowanceForRun(WorkflowRun $run): ?int
    {
        /** @var WorkflowHistoryEvent|null $started */
        $started = $run->relationLoaded('historyEvents')
            ? $run->historyEvents->first(
                static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::WorkflowStarted
            )
            : $run->historyEvents()
                ->where('event_type', HistoryEventType::WorkflowStarted->value)
                ->first();
        $allowance = $started?->payload[self::STARTED_KEY]['allowance_seconds'] ?? null;

        return is_int($allowance) && $allowance > 0 ? $allowance : null;
    }

    /**
     * The earliest work deadline: each hard deadline minus the allowance.
     */
    public static function workDeadline(
        ?int $allowanceSeconds,
        ?CarbonInterface $executionDeadlineAt,
        ?CarbonInterface $runDeadlineAt,
    ): ?CarbonInterface {
        if ($allowanceSeconds === null) {
            return null;
        }

        $earliest = null;
        foreach ([$executionDeadlineAt, $runDeadlineAt] as $hardDeadline) {
            if ($hardDeadline === null) {
                continue;
            }
            $work = $hardDeadline->copy()
                ->subSeconds($allowanceSeconds);
            if ($earliest === null || $work->lt($earliest)) {
                $earliest = $work;
            }
        }

        return $earliest;
    }

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
