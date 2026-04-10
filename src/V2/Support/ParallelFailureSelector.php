<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Throwable;

final class ParallelFailureSelector
{
    /**
     * @param array{index: int, exception: Throwable, recorded_at: int, failure_id?: string|null, failure_payload?: array<string, mixed>}|null $current
     * @param array<string, mixed> $failurePayload
     * @return array{index: int, exception: Throwable, recorded_at: int, failure_id?: string|null, failure_payload?: array<string, mixed>}
     */
    public static function select(
        ?array $current,
        int $index,
        Throwable $exception,
        int $recordedAt,
        ?string $failureId = null,
        array $failurePayload = [],
    ): array {
        if (
            $current === null
            || $recordedAt < $current['recorded_at']
            || ($recordedAt === $current['recorded_at'] && $index < $current['index'])
        ) {
            $selected = [
                'index' => $index,
                'exception' => $exception,
                'recorded_at' => $recordedAt,
            ];

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
