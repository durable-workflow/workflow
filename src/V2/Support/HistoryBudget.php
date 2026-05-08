<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use JsonException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class HistoryBudget
{
    public const PRESSURE_OK = 'ok';

    public const PRESSURE_APPROACHING = 'approaching';

    public const PRESSURE_CONTINUE_AS_NEW_RECOMMENDED = 'continue_as_new_recommended';

    public const DIMENSION_EVENT_COUNT = 'event_count';

    public const DIMENSION_SIZE_BYTES = 'size_bytes';

    public const DIMENSION_FAN_OUT = 'fan_out';

    private const DEFAULT_EVENT_HARD_THRESHOLD = 10000;

    private const DEFAULT_EVENT_WARNING_THRESHOLD = 8000;

    private const DEFAULT_SIZE_BYTES_HARD_THRESHOLD = 5242880;

    private const DEFAULT_SIZE_BYTES_WARNING_THRESHOLD = 4194304;

    private const DEFAULT_FAN_OUT_HARD_THRESHOLD = 200;

    private const DEFAULT_FAN_OUT_WARNING_THRESHOLD = 160;

    /**
     * @return array{
     *     history_event_count: int,
     *     history_size_bytes: int,
     *     history_fan_out: int,
     *     continue_as_new_recommended: bool,
     *     pressure: string,
     *     pressure_dimensions: list<string>
     * }
     */
    public static function forRun(WorkflowRun $run): array
    {
        $run->loadMissing('historyEvents');

        $historyEventCount = $run->historyEvents->count();
        $historySizeBytes = $run->historyEvents
            ->sum(static fn (WorkflowHistoryEvent $event): int => self::eventSizeBytes($event));
        $historyFanOut = self::maxParallelFanOut($run);

        return self::summarize($historyEventCount, $historySizeBytes, $historyFanOut);
    }

    /**
     * Build the budget payload from already-aggregated counters (e.g. cached
     * on a projection table) without re-loading history events.
     *
     * @return array{
     *     history_event_count: int,
     *     history_size_bytes: int,
     *     history_fan_out: int,
     *     continue_as_new_recommended: bool,
     *     pressure: string,
     *     pressure_dimensions: list<string>
     * }
     */
    public static function fromCounters(
        int $historyEventCount,
        int $historySizeBytes,
        int $historyFanOut,
    ): array {
        return self::summarize(
            max(0, $historyEventCount),
            max(0, $historySizeBytes),
            max(0, $historyFanOut),
        );
    }

    public static function eventHardThreshold(): int
    {
        return self::positiveIntegerConfig(
            'workflows.v2.history_budget.continue_as_new_event_threshold',
            self::DEFAULT_EVENT_HARD_THRESHOLD,
        );
    }

    public static function eventWarningThreshold(): int
    {
        return self::warningThreshold(
            'workflows.v2.history_budget.event_warning_threshold',
            self::DEFAULT_EVENT_WARNING_THRESHOLD,
            self::eventHardThreshold(),
        );
    }

    /**
     * Backwards-compatible alias for {@see eventHardThreshold()}. Existing
     * callers that asked for "the event threshold" want the continue-as-new
     * boundary (the hard threshold).
     */
    public static function eventThreshold(): int
    {
        return self::eventHardThreshold();
    }

    public static function sizeBytesHardThreshold(): int
    {
        return self::positiveIntegerConfig(
            'workflows.v2.history_budget.continue_as_new_size_bytes_threshold',
            self::DEFAULT_SIZE_BYTES_HARD_THRESHOLD,
        );
    }

    public static function sizeBytesWarningThreshold(): int
    {
        return self::warningThreshold(
            'workflows.v2.history_budget.size_bytes_warning_threshold',
            self::DEFAULT_SIZE_BYTES_WARNING_THRESHOLD,
            self::sizeBytesHardThreshold(),
        );
    }

    /**
     * Backwards-compatible alias for {@see sizeBytesHardThreshold()}.
     */
    public static function sizeBytesThreshold(): int
    {
        return self::sizeBytesHardThreshold();
    }

    public static function fanOutHardThreshold(): int
    {
        return self::positiveIntegerConfig(
            'workflows.v2.history_budget.continue_as_new_fan_out_threshold',
            self::DEFAULT_FAN_OUT_HARD_THRESHOLD,
        );
    }

    public static function fanOutWarningThreshold(): int
    {
        return self::warningThreshold(
            'workflows.v2.history_budget.fan_out_warning_threshold',
            self::DEFAULT_FAN_OUT_WARNING_THRESHOLD,
            self::fanOutHardThreshold(),
        );
    }

    /**
     * @return array{
     *     history_event_count: int,
     *     history_size_bytes: int,
     *     history_fan_out: int,
     *     continue_as_new_recommended: bool,
     *     pressure: string,
     *     pressure_dimensions: list<string>
     * }
     */
    private static function summarize(
        int $historyEventCount,
        int $historySizeBytes,
        int $historyFanOut,
    ): array {
        $eventHard = self::eventHardThreshold();
        $sizeHard = self::sizeBytesHardThreshold();
        $fanOutHard = self::fanOutHardThreshold();

        $continueAsNew = ($eventHard > 0 && $historyEventCount >= $eventHard)
            || ($sizeHard > 0 && $historySizeBytes >= $sizeHard)
            || ($fanOutHard > 0 && $historyFanOut >= $fanOutHard);

        $pressure = self::PRESSURE_OK;
        $dimensions = [];

        if ($continueAsNew) {
            $pressure = self::PRESSURE_CONTINUE_AS_NEW_RECOMMENDED;

            if ($eventHard > 0 && $historyEventCount >= $eventHard) {
                $dimensions[] = self::DIMENSION_EVENT_COUNT;
            }
            if ($sizeHard > 0 && $historySizeBytes >= $sizeHard) {
                $dimensions[] = self::DIMENSION_SIZE_BYTES;
            }
            if ($fanOutHard > 0 && $historyFanOut >= $fanOutHard) {
                $dimensions[] = self::DIMENSION_FAN_OUT;
            }
        } else {
            $eventWarn = self::eventWarningThreshold();
            $sizeWarn = self::sizeBytesWarningThreshold();
            $fanOutWarn = self::fanOutWarningThreshold();

            if ($eventWarn > 0 && $historyEventCount >= $eventWarn) {
                $dimensions[] = self::DIMENSION_EVENT_COUNT;
            }
            if ($sizeWarn > 0 && $historySizeBytes >= $sizeWarn) {
                $dimensions[] = self::DIMENSION_SIZE_BYTES;
            }
            if ($fanOutWarn > 0 && $historyFanOut >= $fanOutWarn) {
                $dimensions[] = self::DIMENSION_FAN_OUT;
            }

            if ($dimensions !== []) {
                $pressure = self::PRESSURE_APPROACHING;
            }
        }

        return [
            'history_event_count' => $historyEventCount,
            'history_size_bytes' => $historySizeBytes,
            'history_fan_out' => $historyFanOut,
            'continue_as_new_recommended' => $continueAsNew,
            'pressure' => $pressure,
            'pressure_dimensions' => $dimensions,
        ];
    }

    /**
     * Maximum parallel-group breadth observed in this run's history.
     *
     * Counts each `parallel_group_size` payload value once per distinct
     * `parallel_group_id`, then returns the largest. Replay-stable because
     * the values come straight from frozen history payloads.
     */
    private static function maxParallelFanOut(WorkflowRun $run): int
    {
        $maxSize = 0;
        $seenGroups = [];

        foreach ($run->historyEvents as $event) {
            $payload = $event->payload ?? [];

            if (! is_array($payload)) {
                continue;
            }

            $groupId = $payload['parallel_group_id'] ?? null;
            $groupSize = $payload['parallel_group_size'] ?? null;

            if (! is_string($groupId) || $groupId === '') {
                continue;
            }
            if (! is_numeric($groupSize)) {
                continue;
            }
            if (isset($seenGroups[$groupId])) {
                continue;
            }

            $seenGroups[$groupId] = true;
            $size = (int) $groupSize;

            if ($size > $maxSize) {
                $maxSize = $size;
            }
        }

        return $maxSize;
    }

    private static function eventSizeBytes(WorkflowHistoryEvent $event): int
    {
        $eventType = $event->event_type instanceof \BackedEnum
            ? (string) $event->event_type->value
            : (string) $event->event_type;

        try {
            $payload = json_encode(
                $event->payload ?? [],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            $payload = serialize($event->payload ?? []);
        }

        return strlen($eventType) + strlen($payload);
    }

    private static function positiveIntegerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        return $default;
    }

    /**
     * Resolve a warning threshold, clamping it so it never exceeds the hard
     * threshold (a warning above the action boundary cannot fire before
     * continue-as-new is already recommended) and disabling it entirely when
     * the hard threshold is disabled (hard=0 means the dimension is off).
     */
    private static function warningThreshold(string $key, int $default, int $hardThreshold): int
    {
        if ($hardThreshold === 0) {
            return 0;
        }

        $configured = config($key);

        if (is_numeric($configured)) {
            $value = max(0, (int) $configured);
        } else {
            $value = $default;
        }

        if ($value > 0 && $value > $hardThreshold) {
            return $hardThreshold;
        }

        return $value;
    }
}
