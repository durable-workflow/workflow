<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use JsonException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class HistoryBudget
{
    private const DEFAULT_EVENT_THRESHOLD = 10000;

    private const DEFAULT_SIZE_BYTES_THRESHOLD = 5242880;

    /**
     * @return array{
     *     history_event_count: int,
     *     history_size_bytes: int,
     *     continue_as_new_recommended: bool
     * }
     */
    public static function forRun(WorkflowRun $run): array
    {
        $run->loadMissing('historyEvents');

        $historyEventCount = $run->historyEvents->count();
        $historySizeBytes = $run->historyEvents
            ->sum(static fn (WorkflowHistoryEvent $event): int => self::eventSizeBytes($event));

        return [
            'history_event_count' => $historyEventCount,
            'history_size_bytes' => $historySizeBytes,
            'continue_as_new_recommended' => self::shouldContinueAsNew($historyEventCount, $historySizeBytes),
        ];
    }

    public static function eventThreshold(): int
    {
        return self::positiveIntegerConfig(
            'workflows.v2.history_budget.continue_as_new_event_threshold',
            self::DEFAULT_EVENT_THRESHOLD,
        );
    }

    public static function sizeBytesThreshold(): int
    {
        return self::positiveIntegerConfig(
            'workflows.v2.history_budget.continue_as_new_size_bytes_threshold',
            self::DEFAULT_SIZE_BYTES_THRESHOLD,
        );
    }

    private static function shouldContinueAsNew(int $historyEventCount, int $historySizeBytes): bool
    {
        $eventThreshold = self::eventThreshold();
        $sizeBytesThreshold = self::sizeBytesThreshold();

        return ($eventThreshold > 0 && $historyEventCount >= $eventThreshold)
            || ($sizeBytesThreshold > 0 && $historySizeBytes >= $sizeBytesThreshold);
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
}
