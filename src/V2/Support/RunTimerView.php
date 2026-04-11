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
    public const HISTORY_AUTHORITY_TYPED = 'typed_history';

    public const HISTORY_AUTHORITY_MUTABLE_OPEN_FALLBACK = 'mutable_open_fallback';

    public const HISTORY_AUTHORITY_UNSUPPORTED_TERMINAL = 'unsupported_terminal_without_history';

    public const UNSUPPORTED_TERMINAL_REASON = 'terminal_timer_row_without_typed_history';

    /**
     * @return list<array{
     *     id: string,
     *     sequence: int|null,
     *     status: string,
     *     source_status: string,
     *     delay_seconds: int|null,
     *     fire_at: \Carbon\CarbonInterface|null,
     *     fired_at: \Carbon\CarbonInterface|null,
     *     cancelled_at: \Carbon\CarbonInterface|null,
     *     created_at: \Carbon\CarbonInterface|null,
     *     timer_kind: string|null,
     *     condition_wait_id: string|null,
     *     condition_key: string|null,
     *     condition_definition_fingerprint: string|null,
     *     history_authority: string,
     *     diagnostic_only: bool,
     *     history_event_types: list<string>,
     *     history_unsupported_reason: string|null,
     *     row_status: string|null
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
                $existing ?? ($timer instanceof WorkflowTimer ? self::stateFromTimer($timer) : self::emptyState(
                    $timerId
                )),
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

        return array_values(array_map(static fn (array $timer): array => self::presentTimer($timer), $timers));
    }

    /**
     * @return array{
     *     id: string,
     *     sequence: int|null,
     *     status: string,
     *     source_status: string,
     *     delay_seconds: int|null,
     *     fire_at: \Carbon\CarbonInterface|null,
     *     fired_at: \Carbon\CarbonInterface|null,
     *     cancelled_at: \Carbon\CarbonInterface|null,
     *     created_at: \Carbon\CarbonInterface|null,
     *     timer_kind: string|null,
     *     condition_wait_id: string|null,
     *     condition_key: string|null,
     *     condition_definition_fingerprint: string|null,
     *     history_authority: string,
     *     diagnostic_only: bool,
     *     history_event_types: list<string>,
     *     history_unsupported_reason: string|null,
     *     row_status: string|null
     * }|null
     */
    public static function timerForSequence(
        WorkflowRun $run,
        int $sequence,
        bool $includeConditionTimeout = true
    ): ?array {
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
     *     source_status: string,
     *     delay_seconds: int|null,
     *     fire_at: \Carbon\CarbonInterface|null,
     *     fired_at: \Carbon\CarbonInterface|null,
     *     cancelled_at: \Carbon\CarbonInterface|null,
     *     created_at: \Carbon\CarbonInterface|null,
     *     timer_kind: string|null,
     *     condition_wait_id: string|null,
     *     condition_key: string|null,
     *     condition_definition_fingerprint: string|null,
     *     history_authority: string,
     *     diagnostic_only: bool,
     *     history_event_types: list<string>,
     *     history_unsupported_reason: string|null,
     *     row_status: string|null
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
                HistoryEventType::TimerCancelled,
            ], true))
            ->sortBy('sequence');
    }

    /**
     * @return array{
     *     id: string,
     *     sequence: int|null,
     *     status: string,
     *     source_status: string,
     *     delay_seconds: int|null,
     *     fire_at: \Carbon\CarbonInterface|null,
     *     fired_at: \Carbon\CarbonInterface|null,
     *     cancelled_at: \Carbon\CarbonInterface|null,
     *     created_at: \Carbon\CarbonInterface|null,
     *     timer_kind: string|null,
     *     condition_wait_id: string|null,
     *     condition_key: string|null,
     *     condition_definition_fingerprint: string|null,
     *     history_authority: string,
     *     diagnostic_only: bool,
     *     history_event_types: list<string>,
     *     history_unsupported_reason: string|null,
     *     row_status: string|null
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
            'status' => match ($event->event_type) {
                HistoryEventType::TimerFired => TimerStatus::Fired->value,
                HistoryEventType::TimerCancelled => TimerStatus::Cancelled->value,
                default => TimerStatus::Pending->value,
            },
            'source_status' => match ($event->event_type) {
                HistoryEventType::TimerFired => TimerStatus::Fired->value,
                HistoryEventType::TimerCancelled => TimerStatus::Cancelled->value,
                default => TimerStatus::Pending->value,
            },
            'delay_seconds' => self::intValue($event->payload['delay_seconds'] ?? null),
            'fire_at' => in_array($event->event_type, [
                HistoryEventType::TimerScheduled,
                HistoryEventType::TimerCancelled,
            ], true)
                ? self::timestamp($event->payload['fire_at'] ?? null)
                : null,
            'fired_at' => $event->event_type === HistoryEventType::TimerFired
                ? self::timestamp($event->payload['fired_at'] ?? null)
                : null,
            'cancelled_at' => $event->event_type === HistoryEventType::TimerCancelled
                ? self::timestamp($event->payload['cancelled_at'] ?? null)
                    ?? $event->recorded_at
                    ?? $event->created_at
                : null,
            'created_at' => $event->event_type === HistoryEventType::TimerScheduled
                ? ($event->recorded_at ?? $event->created_at)
                : null,
            'timer_kind' => self::stringValue($event->payload['timer_kind'] ?? null),
            'condition_wait_id' => self::stringValue($event->payload['condition_wait_id'] ?? null),
            'condition_key' => self::stringValue($event->payload['condition_key'] ?? null),
            'condition_definition_fingerprint' => self::stringValue(
                $event->payload['condition_definition_fingerprint'] ?? null
            ),
            'history_authority' => self::HISTORY_AUTHORITY_TYPED,
            'history_event_types' => [$event->event_type->value],
            'history_unsupported_reason' => null,
            'row_status' => null,
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     sequence: int|null,
     *     status: string,
     *     source_status: string,
     *     delay_seconds: int|null,
     *     fire_at: \Carbon\CarbonInterface|null,
     *     fired_at: \Carbon\CarbonInterface|null,
     *     cancelled_at: \Carbon\CarbonInterface|null,
     *     created_at: \Carbon\CarbonInterface|null,
     *     timer_kind: string|null,
     *     condition_wait_id: string|null,
     *     condition_key: string|null,
     *     condition_definition_fingerprint: string|null,
     *     history_authority: string,
     *     diagnostic_only: bool,
     *     history_event_types: list<string>,
     *     history_unsupported_reason: string|null,
     *     row_status: string|null
     * }
     */
    private static function stateFromTimer(WorkflowTimer $timer): array
    {
        $rowStatus = $timer->status->value;
        $unsupportedTerminal = $timer->status !== TimerStatus::Pending;

        return [
            'id' => $timer->id,
            'sequence' => $timer->sequence,
            'status' => $unsupportedTerminal ? 'unsupported' : $rowStatus,
            'source_status' => $rowStatus,
            'delay_seconds' => $timer->delay_seconds,
            'fire_at' => $timer->fire_at,
            'fired_at' => $unsupportedTerminal ? null : $timer->fired_at,
            'cancelled_at' => null,
            'created_at' => $timer->created_at,
            'timer_kind' => null,
            'condition_wait_id' => null,
            'condition_key' => null,
            'condition_definition_fingerprint' => null,
            'history_authority' => $unsupportedTerminal
                ? self::HISTORY_AUTHORITY_UNSUPPORTED_TERMINAL
                : self::HISTORY_AUTHORITY_MUTABLE_OPEN_FALLBACK,
            'history_event_types' => [],
            'history_unsupported_reason' => $unsupportedTerminal
                ? self::UNSUPPORTED_TERMINAL_REASON
                : null,
            'row_status' => $rowStatus,
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     sequence: int|null,
     *     status: string,
     *     source_status: string,
     *     delay_seconds: int|null,
     *     fire_at: \Carbon\CarbonInterface|null,
     *     fired_at: \Carbon\CarbonInterface|null,
     *     cancelled_at: \Carbon\CarbonInterface|null,
     *     created_at: \Carbon\CarbonInterface|null,
     *     timer_kind: string|null,
     *     condition_wait_id: string|null,
     *     condition_key: string|null,
     *     condition_definition_fingerprint: string|null,
     *     history_authority: string,
     *     diagnostic_only: bool,
     *     history_event_types: list<string>,
     *     history_unsupported_reason: string|null,
     *     row_status: string|null
     * }
     */
    private static function emptyState(string $timerId): array
    {
        return [
            'id' => $timerId,
            'sequence' => null,
            'status' => TimerStatus::Pending->value,
            'source_status' => TimerStatus::Pending->value,
            'delay_seconds' => null,
            'fire_at' => null,
            'fired_at' => null,
            'cancelled_at' => null,
            'created_at' => null,
            'timer_kind' => null,
            'condition_wait_id' => null,
            'condition_key' => null,
            'condition_definition_fingerprint' => null,
            'history_authority' => self::HISTORY_AUTHORITY_TYPED,
            'diagnostic_only' => false,
            'history_event_types' => [],
            'history_unsupported_reason' => null,
            'row_status' => null,
        ];
    }

    /**
     * @param array<string, mixed> $timer
     * @return array<string, mixed>
     */
    private static function presentTimer(array $timer): array
    {
        $timer['history_authority'] = self::stringValue($timer['history_authority'] ?? null);
        $timer['diagnostic_only'] = self::isDiagnosticOnly($timer);

        return $timer;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private static function mergeState(array $state, array $snapshot): array
    {
        foreach ($snapshot as $key => $value) {
            if ($key === 'history_event_types') {
                $state[$key] = array_values(array_unique(array_merge(
                    is_array($state[$key] ?? null) ? $state[$key] : [],
                    is_array($value) ? $value : [],
                )));

                continue;
            }

            if ($key === 'history_unsupported_reason') {
                $state[$key] = $value;

                continue;
            }

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
        $state['cancelled_at'] ??= null;
        $state['created_at'] ??= $timer->created_at;
        $state['row_status'] = $timer->status->value;

        if (($state['history_authority'] ?? null) !== self::HISTORY_AUTHORITY_TYPED) {
            $unsupportedTerminal = $timer->status !== TimerStatus::Pending;
            $state['status'] = $unsupportedTerminal ? 'unsupported' : $timer->status->value;
            $state['source_status'] = $timer->status->value;
            $state['history_authority'] = $unsupportedTerminal
                ? self::HISTORY_AUTHORITY_UNSUPPORTED_TERMINAL
                : self::HISTORY_AUTHORITY_MUTABLE_OPEN_FALLBACK;
            $state['history_unsupported_reason'] = $unsupportedTerminal
                ? self::UNSUPPORTED_TERMINAL_REASON
                : null;
            $state['fired_at'] = $unsupportedTerminal ? null : ($state['fired_at'] ?? $timer->fired_at);

            return $state;
        }

        $state['source_status'] ??= $state['status'] ?? $timer->status->value;

        return $state;
    }

    /**
     * @param array<string, mixed> $timer
     */
    private static function isDiagnosticOnly(array $timer): bool
    {
        $historyAuthority = self::stringValue($timer['history_authority'] ?? null);

        return $historyAuthority !== null && $historyAuthority !== self::HISTORY_AUTHORITY_TYPED;
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
