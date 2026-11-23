<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Throwable;
use Workflow\V2\Models\WorkflowHistoryEvent;

/**
 * @phpstan-type Selection array{index: int, exception: Throwable, recorded_at: int, event_sequence?: int, failure_id?: string|null, failure_payload?: array<string, mixed>}
 */
final class ParallelFailureSelector
{
    /**
     * @param Selection|null $current
     * @param array<string, mixed> $failurePayload
     * @return Selection
     */
    public static function selectRecorded(
        ?array $current,
        int $index,
        Throwable $exception,
        WorkflowHistoryEvent $event,
        bool $propagate,
        ?string $failureId = null,
        array $failurePayload = [],
    ): array {
        return self::select(
            $current,
            $index,
            $exception,
            $event->recorded_at?->getTimestampMs()
                ?? ($propagate ? null : $event->created_at?->getTimestampMs())
                ?? PHP_INT_MAX,
            $failureId,
            $failurePayload,
            $propagate ? $event->sequence : null,
        );
    }

    /**
     * @param Selection|null $current
     * @param array<string, mixed> $failurePayload
     * @return Selection
     */
    public static function select(
        ?array $current,
        int $index,
        Throwable $exception,
        int $recordedAt,
        ?string $failureId = null,
        array $failurePayload = [],
        ?int $eventSequence = null,
    ): array {
        if ($current === null || ($eventSequence === null
            ? $recordedAt < $current['recorded_at']
                || ($recordedAt === $current['recorded_at'] && $index < $current['index'])
            : $eventSequence < ($current['event_sequence'] ?? PHP_INT_MAX))) {
            $selected = [
                'index' => $index,
                'exception' => $exception,
                'recorded_at' => $recordedAt,
            ];

            if ($eventSequence !== null) {
                $selected['event_sequence'] = $eventSequence;
            }
            if ($failureId !== null && $failureId !== '') {
                $selected['failure_id'] = $failureId;
            }

            if ($failurePayload !== []) {
                $selected['failure_payload'] = $failurePayload;
            }

            return $selected;
        }

        return $current;
    }
}
