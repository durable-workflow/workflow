<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class RedriveReplayClock
{
    public static function startedAt(WorkflowRun $run): ?CarbonInterface
    {
        if ($run->run_number <= 1) {
            return $run->started_at;
        }

        $event = $run->historyEvents()
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->first();
        $payload = $event instanceof WorkflowHistoryEvent ? $event->payload : null;

        return is_array($payload)
            && ($payload['recovery_kind'] ?? null) === 'redrive'
            && is_string($payload['replayed_started_at'] ?? null)
                ? Carbon::parse($payload['replayed_started_at'])
                : $run->started_at;
    }

    public static function activityCompletedAt(WorkflowHistoryEvent $event): ?CarbonInterface
    {
        $payload = $event->payload;

        return is_array($payload) && is_string($payload['reused_recorded_at'] ?? null)
            ? Carbon::parse($payload['reused_recorded_at'])
            : $event->recorded_at;
    }
}
