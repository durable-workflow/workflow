<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final class RunSummarySortKey
{
    public static function applyDescending(Builder $query): Builder
    {
        return $query
            ->orderByRaw('case when sort_timestamp is null then 1 else 0 end asc')
            ->orderByDesc('sort_timestamp')
            ->orderByDesc('id');
    }

    public static function timestamp(
        CarbonInterface|string|null $startedAt,
        CarbonInterface|string|null $createdAt = null,
        CarbonInterface|string|null $updatedAt = null,
    ): ?CarbonImmutable {
        foreach ([$startedAt, $createdAt, $updatedAt] as $value) {
            $timestamp = self::normalize($value);

            if ($timestamp !== null) {
                return $timestamp->utc();
            }
        }

        return null;
    }

    public static function key(
        CarbonInterface|string|null $startedAt,
        CarbonInterface|string|null $createdAt,
        CarbonInterface|string|null $updatedAt,
        string $runId,
    ): ?string {
        $timestamp = self::timestamp($startedAt, $createdAt, $updatedAt);

        if ($timestamp === null) {
            return null;
        }

        return sprintf('%s#%s', $timestamp->format('Y-m-d\TH:i:s.u\Z'), $runId);
    }

    private static function normalize(CarbonInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        return CarbonImmutable::parse($value);
    }
}
