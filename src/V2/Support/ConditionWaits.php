<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class ConditionWaits
{
    /**
     * @return list<array{
     *     id: string,
     *     condition_wait_id: string,
     *     sequence: int|null,
     *     status: string,
     *     source_status: string,
     *     timeout_seconds: int|null,
     *     timer_id: string|null,
     *     opened_at: \Carbon\CarbonInterface|null,
     *     resolved_at: \Carbon\CarbonInterface|null
     * }>
     */
    public static function forRun(WorkflowRun $run): array
    {
        $run->loadMissing('historyEvents');

        $waits = [];
        $openWaitIds = [];

        foreach ($run->historyEvents->sortBy('sequence') as $event) {
            if (! $event instanceof WorkflowHistoryEvent) {
                continue;
            }

            if ($event->event_type === HistoryEventType::ConditionWaitOpened) {
                $waitId = self::waitIdForEvent($event);

                if ($waitId === null) {
                    continue;
                }

                $waits[$waitId] = [
                    'id' => $waitId,
                    'condition_wait_id' => $waitId,
                    'sequence' => self::intValue($event->payload['sequence'] ?? null),
                    'status' => 'open',
                    'source_status' => 'waiting',
                    'timeout_seconds' => self::intValue($event->payload['timeout_seconds'] ?? null),
                    'timer_id' => self::stringValue($event->payload['timer_id'] ?? null),
                    'opened_at' => $event->recorded_at ?? $event->created_at,
                    'resolved_at' => null,
                ];

                $openWaitIds[] = $waitId;

                continue;
            }

            if (! in_array($event->event_type, [
                HistoryEventType::ConditionWaitSatisfied,
                HistoryEventType::ConditionWaitTimedOut,
            ], true)) {
                if (in_array($event->event_type, [
                    HistoryEventType::WorkflowCompleted,
                    HistoryEventType::WorkflowFailed,
                    HistoryEventType::WorkflowCancelled,
                    HistoryEventType::WorkflowTerminated,
                    HistoryEventType::WorkflowContinuedAsNew,
                ], true)) {
                    self::closeOpenWaits($waits, $openWaitIds, $event);
                }

                continue;
            }

            $waitId = self::waitIdForEvent($event);

            if ($waitId === null) {
                continue;
            }

            if (! isset($waits[$waitId])) {
                $waits[$waitId] = [
                    'id' => $waitId,
                    'condition_wait_id' => $waitId,
                    'sequence' => self::intValue($event->payload['sequence'] ?? null),
                    'status' => 'open',
                    'source_status' => 'waiting',
                    'timeout_seconds' => self::intValue($event->payload['timeout_seconds'] ?? null),
                    'timer_id' => self::stringValue($event->payload['timer_id'] ?? null),
                    'opened_at' => null,
                    'resolved_at' => null,
                ];
            }

            $waits[$waitId]['status'] = 'resolved';
            $waits[$waitId]['source_status'] = $event->event_type === HistoryEventType::ConditionWaitTimedOut
                ? 'timed_out'
                : 'satisfied';
            $waits[$waitId]['timer_id'] = self::stringValue($event->payload['timer_id'] ?? null)
                ?? $waits[$waitId]['timer_id'];
            $waits[$waitId]['resolved_at'] = $event->recorded_at ?? $event->created_at;

            $index = array_search($waitId, $openWaitIds, true);

            if ($index !== false) {
                array_splice($openWaitIds, $index, 1);
            }
        }

        return array_values($waits);
    }

    public static function waitIdForSequence(WorkflowRun $run, int $sequence): ?string
    {
        foreach (self::forRun($run) as $wait) {
            if (($wait['sequence'] ?? null) === $sequence) {
                return $wait['condition_wait_id'];
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $waits
     * @param list<string> $openWaitIds
     */
    private static function closeOpenWaits(
        array &$waits,
        array &$openWaitIds,
        WorkflowHistoryEvent $event,
    ): void {
        $sourceStatus = match ($event->event_type) {
            HistoryEventType::WorkflowCancelled => 'cancelled',
            HistoryEventType::WorkflowTerminated => 'terminated',
            HistoryEventType::WorkflowContinuedAsNew => 'continued',
            default => 'closed',
        };

        while ($openWaitIds !== []) {
            $waitId = array_shift($openWaitIds);

            if ($waitId === null || ! isset($waits[$waitId])) {
                continue;
            }

            $waits[$waitId]['status'] = 'cancelled';
            $waits[$waitId]['source_status'] = $sourceStatus;
            $waits[$waitId]['resolved_at'] = $event->recorded_at ?? $event->created_at;
        }
    }

    private static function waitIdForEvent(WorkflowHistoryEvent $event): ?string
    {
        $waitId = self::stringValue($event->payload['condition_wait_id'] ?? null);

        if ($waitId !== null) {
            return $waitId;
        }

        $sequence = self::intValue($event->payload['sequence'] ?? null);

        return $sequence === null
            ? null
            : sprintf('condition:%d', $sequence);
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value)
            ? (int) $value
            : null;
    }
}
