<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Closure;
use Illuminate\Support\Collection;
use WeakMap;

/**
 * Memoizes derived lookups over one loaded history collection (#1231).
 *
 * Replay checks every member of a fan-out against the run's history. Scanning
 * the whole history for each member made a workflow task's cost grow with the
 * square of the fan-out width. An index is reused only while the collection is
 * the same object with the same size and the same first and last events, so an
 * appended, reloaded or replaced history builds a fresh index.
 *
 * @internal
 */
final class HistoryEventIndex
{
    /**
     * @var WeakMap<Collection<array-key, mixed>, array<string, array{fingerprint: array{int, int|null, int|null}, index: mixed}>>|null
     */
    private static ?WeakMap $indexes = null;

    /**
     * @template T
     * @param Collection<array-key, mixed> $events
     * @param Closure(Collection<array-key, mixed>): T $build
     * @return T
     */
    public static function remember(Collection $events, string $name, Closure $build): mixed
    {
        $fingerprint = self::fingerprint($events);
        self::$indexes ??= new WeakMap();
        $indexes = self::$indexes[$events] ?? [];
        $cached = $indexes[$name] ?? null;

        if ($cached !== null && $cached['fingerprint'] === $fingerprint) {
            return $cached['index'];
        }

        $index = $build($events);
        $indexes[$name] = [
            'fingerprint' => $fingerprint,
            'index' => $index,
        ];
        self::$indexes[$events] = $indexes;

        return $index;
    }

    /**
     * @param Collection<array-key, mixed> $events
     * @return array{int, int|null, int|null}
     */
    private static function fingerprint(Collection $events): array
    {
        $first = $events->first();
        $last = $events->last();

        return [
            $events->count(),
            is_object($first) ? spl_object_id($first) : null,
            is_object($last) ? spl_object_id($last) : null,
        ];
    }
}
