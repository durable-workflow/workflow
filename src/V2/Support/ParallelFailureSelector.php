<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Throwable;

final class ParallelFailureSelector
{
    /**
     * @param array{index: int, exception: Throwable, recorded_at: int}|null $current
     * @return array{index: int, exception: Throwable, recorded_at: int}
     */
    public static function select(?array $current, int $index, Throwable $exception, int $recordedAt): array
    {
        if (
            $current === null
            || $recordedAt < $current['recorded_at']
            || ($recordedAt === $current['recorded_at'] && $index < $current['index'])
        ) {
            return [
                'index' => $index,
                'exception' => $exception,
                'recorded_at' => $recordedAt,
            ];
        }

        return $current;
    }
}
