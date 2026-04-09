<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Carbon;
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
     *     deadline_at: \Carbon\CarbonInterface|null,
     *     resolved_at: \Carbon\CarbonInterface|null,
     *     resume_source_kind: string,
     *     resume_source_id: string|null
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

                $waits[$waitId] = self::openWait($waitId, $event);
                self::rememberOpenWait($openWaitIds, $waitId);

                continue;
            }

            if (
                $event->event_type === HistoryEventType::TimerScheduled
                && self::stringValue($event->payload['timer_kind'] ?? null) === 'condition_timeout'
            ) {
                $waitId = self::waitIdForEvent($event);

                if ($waitId === null) {
                    continue;
                }

                if (! isset($waits[$waitId])) {
                    $waits[$waitId] = self::wait($waitId, $event);
                    self::rememberOpenWait($openWaitIds, $waitId);
                }

                $timerId = self::stringValue($event->payload['timer_id'] ?? null);

                $waits[$waitId]['sequence'] = self::intValue($waits[$waitId]['sequence'] ?? null)
                    ?? self::intValue($event->payload['sequence'] ?? null);
                $waits[$waitId]['timeout_seconds'] = self::intValue($waits[$waitId]['timeout_seconds'] ?? null)
                    ?? self::intValue($event->payload['delay_seconds'] ?? null);
                $waits[$waitId]['timer_id'] = $timerId ?? $waits[$waitId]['timer_id'];
                $waits[$waitId]['deadline_at'] = self::timestamp($event->payload['fire_at'] ?? null)
                    ?? $waits[$waitId]['deadline_at'];

                if ($waits[$waitId]['timer_id'] !== null) {
                    $waits[$waitId]['resume_source_kind'] = 'timer';
                    $waits[$waitId]['resume_source_id'] = $waits[$waitId]['timer_id'];
                }

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
                $waits[$waitId] = self::wait($waitId, $event);
            }

            $waits[$waitId]['status'] = 'resolved';
            $waits[$waitId]['source_status'] = $event->event_type === HistoryEventType::ConditionWaitTimedOut
                ? 'timed_out'
                : 'satisfied';
            $waits[$waitId]['sequence'] = self::intValue($waits[$waitId]['sequence'] ?? null)
                ?? self::intValue($event->payload['sequence'] ?? null);
            $waits[$waitId]['timeout_seconds'] = self::intValue($event->payload['timeout_seconds'] ?? null)
                ?? $waits[$waitId]['timeout_seconds'];
            $waits[$waitId]['timer_id'] = self::stringValue($event->payload['timer_id'] ?? null)
                ?? $waits[$waitId]['timer_id'];
            $waits[$waitId]['resolved_at'] = $event->recorded_at ?? $event->created_at;
            $waits[$waitId]['resume_source_kind'] = $event->event_type === HistoryEventType::ConditionWaitTimedOut
                ? 'timer'
                : 'external_input';
            $waits[$waitId]['resume_source_id'] = $event->event_type === HistoryEventType::ConditionWaitTimedOut
                ? $waits[$waitId]['timer_id']
                : null;

            $index = array_search($waitId, $openWaitIds, true);

            if ($index !== false) {
                array_splice($openWaitIds, $index, 1);
            }
        }

        return array_values($waits);
    }

    /**
     * @return array{
     *     id: string,
     *     condition_wait_id: string,
     *     sequence: int|null,
     *     status: string,
     *     source_status: string,
     *     timeout_seconds: int|null,
     *     timer_id: string|null,
     *     opened_at: \Carbon\CarbonInterface|null,
     *     deadline_at: \Carbon\CarbonInterface|null,
     *     resolved_at: \Carbon\CarbonInterface|null,
     *     resume_source_kind: string,
     *     resume_source_id: string|null
     * }
     */
    private static function openWait(string $waitId, WorkflowHistoryEvent $event): array
    {
        $wait = self::wait($waitId, $event);
        $wait['opened_at'] = $event->recorded_at ?? $event->created_at;

        if ($wait['timer_id'] !== null) {
            $wait['resume_source_kind'] = 'timer';
            $wait['resume_source_id'] = $wait['timer_id'];
        }

        return $wait;
    }

    /**
     * @return array{
     *     id: string,
     *     condition_wait_id: string,
     *     sequence: int|null,
     *     status: string,
     *     source_status: string,
     *     timeout_seconds: int|null,
     *     timer_id: string|null,
     *     opened_at: \Carbon\CarbonInterface|null,
     *     deadline_at: \Carbon\CarbonInterface|null,
     *     resolved_at: \Carbon\CarbonInterface|null,
     *     resume_source_kind: string,
     *     resume_source_id: string|null
     * }
     */
    private static function wait(string $waitId, WorkflowHistoryEvent $event): array
    {
        $timerId = self::stringValue($event->payload['timer_id'] ?? null);

        return [
            'id' => $waitId,
            'condition_wait_id' => $waitId,
            'sequence' => self::intValue($event->payload['sequence'] ?? null),
            'status' => 'open',
            'source_status' => 'waiting',
            'timeout_seconds' => self::intValue($event->payload['timeout_seconds'] ?? null)
                ?? self::intValue($event->payload['delay_seconds'] ?? null),
            'timer_id' => $timerId,
            'opened_at' => null,
            'deadline_at' => self::timestamp($event->payload['fire_at'] ?? null),
            'resolved_at' => null,
            'resume_source_kind' => $timerId === null ? 'external_input' : 'timer',
            'resume_source_id' => $timerId,
        ];
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

    /**
     * @param list<string> $openWaitIds
     */
    private static function rememberOpenWait(array &$openWaitIds, string $waitId): void
    {
        if (! in_array($waitId, $openWaitIds, true)) {
            $openWaitIds[] = $waitId;
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

    private static function timestamp(mixed $value): ?\Carbon\CarbonInterface
    {
        if ($value instanceof \Carbon\CarbonInterface) {
            return $value;
        }

        return is_string($value) && $value !== ''
            ? Carbon::parse($value)
            : null;
    }
}
