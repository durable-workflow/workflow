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
    public static function formatRecordedAt(?CarbonInterface $time): ?string
    {
        if ($time === null) {
            return null;
        }
        return $time->format('u') === '000000'
            ? $time->toIso8601String()
            : $time->format('Y-m-d\TH:i:s.uP');
    }

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
        return self::recordedAt($event);
    }

    /**
     * The workflow time of a recorded step. A step that redrive reused from
     * an earlier run keeps the time it was first recorded.
     */
    public static function recordedAt(?WorkflowHistoryEvent $event): ?CarbonInterface
    {
        if ($event === null) {
            return null;
        }

        $payload = $event->payload;

        return is_array($payload) && is_string($payload['reused_recorded_at'] ?? null)
            ? Carbon::parse($payload['reused_recorded_at'])
            : $event->recorded_at;
    }
}
