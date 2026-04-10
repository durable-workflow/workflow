<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Carbon;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTimer;

final class RunTimerView
{
    /**
     * @return list<array{
     *     id: string,
     *     sequence: int|null,
     *     status: string,
     *     delay_seconds: int|null,
     *     fire_at: \Carbon\CarbonInterface|null,
     *     fired_at: \Carbon\CarbonInterface|null,
     *     created_at: \Carbon\CarbonInterface|null,
     *     timer_kind: string|null,
     *     condition_wait_id: string|null
     * }>
     */
    public static function timersForRun(WorkflowRun $run): array
    {
        $run->loadMissing(['timers', 'historyEvents']);

        $states = [];
        $timersById = $run->timers->keyBy('id');

        foreach (self::timerEvents($run) as $event) {
            $snapshot = self::stateFromEvent($event);

            if ($snapshot === null) {
                continue;
            }

            $timerId = $snapshot['id'];
            $existing = $states[$timerId] ?? null;
            $timer = $timersById->get($timerId);

            $states[$timerId] = self::mergeState(
                $existing ?? ($timer instanceof WorkflowTimer ? self::stateFromTimer($timer) : self::emptyState($timerId)),
                $snapshot,
            );
        }

        foreach ($run->timers as $timer) {
            if (! $timer instanceof WorkflowTimer) {
                continue;
            }

            if (! array_key_exists($timer->id, $states)) {
                $states[$timer->id] = self::stateFromTimer($timer);

                continue;
            }

            $states[$timer->id] = self::mergeTimerRow($states[$timer->id], $timer);
        }

        $timers = array_values($states);

        usort($timers, static function (array $left, array $right): int {
            $leftSequence = is_int($left['sequence'] ?? null) ? $left['sequence'] : PHP_INT_MAX;
            $rightSequence = is_int($right['sequence'] ?? null) ? $right['sequence'] : PHP_INT_MAX;

            if ($leftSequence !== $rightSequence) {
                return $leftSequence <=> $rightSequence;
            }

            $leftFireAt = self::timestampToMilliseconds($left['fire_at'] ?? null);
            $rightFireAt = self::timestampToMilliseconds($right['fire_at'] ?? null);

            if ($leftFireAt !== $rightFireAt) {
                return $leftFireAt <=> $rightFireAt;
            }

            $leftCreatedAt = self::timestampToMilliseconds($left['created_at'] ?? null);
            $rightCreatedAt = self::timestampToMilliseconds($right['created_at'] ?? null);

            if ($leftCreatedAt !== $rightCreatedAt) {
                return $leftCreatedAt <=> $rightCreatedAt;
            }

            return $left['id'] <=> $right['id'];
        });

        return $timers;
    }

    /**
     * @return array{
     *     id: string,
     *     sequence: int|null,
     *     status: string,
     *     delay_seconds: int|null,
     *     fire_at: \Carbon\CarbonInterface|null,
     *     fired_at: \Carbon\CarbonInterface|null,
     *     created_at: \Carbon\CarbonInterface|null,
     *     timer_kind: string|null,
     *     condition_wait_id: string|null
     * }|null
     */
    public static function timerForSequence(WorkflowRun $run, int $sequence, bool $includeConditionTimeout = true): ?array
    {
        foreach (self::timersForRun($run) as $timer) {
            if (($timer['sequence'] ?? null) !== $sequence) {
                continue;
            }

            if (! $includeConditionTimeout && ($timer['timer_kind'] ?? null) === 'condition_timeout') {
                continue;
            }

            return $timer;
        }

        return null;
    }

    /**
     * @return array{
     *     id: string,
     *     sequence: int|null,
     *     status: string,
     *     delay_seconds: int|null,
     *     fire_at: \Carbon\CarbonInterface|null,
     *     fired_at: \Carbon\CarbonInterface|null,
     *     created_at: \Carbon\CarbonInterface|null,
     *     timer_kind: string|null,
     *     condition_wait_id: string|null
     * }|null
     */
    public static function timerById(WorkflowRun $run, string $timerId): ?array
    {
        foreach (self::timersForRun($run) as $timer) {
            if (($timer['id'] ?? null) === $timerId) {
                return $timer;
            }
        }

        return null;
    }

    /**
     * @return iterable<WorkflowHistoryEvent>
     */
    private static function timerEvents(WorkflowRun $run): iterable
    {
        return $run->historyEvents
            ->filter(static fn (WorkflowHistoryEvent $event): bool => in_array($event->event_type, [
                HistoryEventType::TimerScheduled,
                HistoryEventType::TimerFired,
            ], true))
            ->sortBy('sequence');
    }

    /**
     * @return array{
     *     id: string,
     *     sequence: int|null,
     *     status: string,
     *     delay_seconds: int|null,
     *     fire_at: \Carbon\CarbonInterface|null,
     *     fired_at: \Carbon\CarbonInterface|null,
     *     created_at: \Carbon\CarbonInterface|null,
     *     timer_kind: string|null,
     *     condition_wait_id: string|null
     * }|null
     */
    private static function stateFromEvent(WorkflowHistoryEvent $event): ?array
    {
        $timerId = self::stringValue($event->payload['timer_id'] ?? null);

        if ($timerId === null) {
            return null;
        }

        return [
            'id' => $timerId,
            'sequence' => self::intValue($event->payload['sequence'] ?? null),
            'status' => $event->event_type === HistoryEventType::TimerFired
                ? TimerStatus::Fired->value
                : TimerStatus::Pending->value,
            'delay_seconds' => self::intValue($event->payload['delay_seconds'] ?? null),
            'fire_at' => $event->event_type === HistoryEventType::TimerScheduled
                ? self::timestamp($event->payload['fire_at'] ?? null)
                : null,
            'fired_at' => $event->event_type === HistoryEventType::TimerFired
                ? self::timestamp($event->payload['fired_at'] ?? null)
                : null,
            'created_at' => $event->event_type === HistoryEventType::TimerScheduled
                ? ($event->recorded_at ?? $event->created_at)
                : null,
            'timer_kind' => self::stringValue($event->payload['timer_kind'] ?? null),
            'condition_wait_id' => self::stringValue($event->payload['condition_wait_id'] ?? null),
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     sequence: int|null,
     *     status: string,
     *     delay_seconds: int|null,
     *     fire_at: \Carbon\CarbonInterface|null,
     *     fired_at: \Carbon\CarbonInterface|null,
     *     created_at: \Carbon\CarbonInterface|null,
     *     timer_kind: string|null,
     *     condition_wait_id: string|null
     * }
     */
    private static function stateFromTimer(WorkflowTimer $timer): array
    {
        return [
            'id' => $timer->id,
            'sequence' => $timer->sequence,
            'status' => $timer->status->value,
            'delay_seconds' => $timer->delay_seconds,
            'fire_at' => $timer->fire_at,
            'fired_at' => $timer->fired_at,
            'created_at' => $timer->created_at,
            'timer_kind' => null,
            'condition_wait_id' => null,
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     sequence: int|null,
     *     status: string,
     *     delay_seconds: int|null,
     *     fire_at: \Carbon\CarbonInterface|null,
     *     fired_at: \Carbon\CarbonInterface|null,
     *     created_at: \Carbon\CarbonInterface|null,
     *     timer_kind: string|null,
     *     condition_wait_id: string|null
     * }
     */
    private static function emptyState(string $timerId): array
    {
        return [
            'id' => $timerId,
            'sequence' => null,
            'status' => TimerStatus::Pending->value,
            'delay_seconds' => null,
            'fire_at' => null,
            'fired_at' => null,
            'created_at' => null,
            'timer_kind' => null,
            'condition_wait_id' => null,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private static function mergeState(array $state, array $snapshot): array
    {
        foreach ($snapshot as $key => $value) {
            if ($value !== null) {
                $state[$key] = $value;
            } elseif (! array_key_exists($key, $state)) {
                $state[$key] = null;
            }
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function mergeTimerRow(array $state, WorkflowTimer $timer): array
    {
        $state['sequence'] ??= $timer->sequence;
        $state['delay_seconds'] ??= $timer->delay_seconds;
        $state['fire_at'] ??= $timer->fire_at;
        $state['fired_at'] ??= $timer->fired_at;
        $state['created_at'] ??= $timer->created_at;

        if ($timer->status === TimerStatus::Cancelled) {
            $state['status'] = TimerStatus::Cancelled->value;
        }

        return $state;
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

    private static function timestampToMilliseconds(mixed $timestamp): int
    {
        if ($timestamp instanceof \Carbon\CarbonInterface) {
            return $timestamp->getTimestampMs();
        }

        if (is_string($timestamp) && $timestamp !== '') {
            return Carbon::parse($timestamp)->getTimestampMs();
        }

        return PHP_INT_MAX;
    }
}
