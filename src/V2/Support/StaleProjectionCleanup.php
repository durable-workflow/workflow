<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Removes stale projection rows for a workflow run without taking secondary-index
 * gap locks.
 *
 * The legacy `DELETE FROM tbl WHERE workflow_run_id = ? AND id NOT IN (...)` shape
 * drives the scan through the `workflow_run_id` secondary index. Under InnoDB
 * REPEATABLE-READ that takes next-key (record + gap) locks over the scanned
 * range, so two concurrent projector runs for the same run form a lock cycle
 * (#399 / #425). This helper resolves the reconcile step via a snapshot read of
 * primary keys followed by a point-lock DELETE against the clustered index,
 * which takes record locks only and cannot gap-deadlock with a sibling projector.
 *
 * Stale ids are sorted before the DELETE so two concurrent projectors that
 * compute overlapping (but non-identical) stale sets acquire row locks in the
 * same order, removing the remaining cycle on the clustered index. A short
 * deadlock-retry loop covers the residual case where a sibling projector held
 * a lock the snapshot did not anticipate.
 */
final class StaleProjectionCleanup
{
    private const MAX_ATTEMPTS = 5;

    /**
     * @param class-string<Model> $model
     * @param list<string> $seen
     */
    public static function forRun(string $model, string $runId, array $seen): void
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                self::pruneOnce($model, $runId, $seen);

                return;
            } catch (QueryException $e) {
                if (! self::isConcurrencyError($e) || $attempt === self::MAX_ATTEMPTS) {
                    throw $e;
                }

                usleep(self::backoffMicroseconds($attempt));
            }
        }
    }

    /**
     * @param class-string<Model> $model
     * @param list<string> $seen
     */
    private static function pruneOnce(string $model, string $runId, array $seen): void
    {
        /** @var list<string> $existingIds */
        $existingIds = $model::query()
            ->where('workflow_run_id', $runId)
            ->pluck('id')
            ->all();

        if ($existingIds === []) {
            return;
        }

        $staleIds = $seen === []
            ? $existingIds
            : array_values(array_diff($existingIds, $seen));

        if ($staleIds === []) {
            return;
        }

        sort($staleIds);

        $model::query()
            ->whereIn('id', $staleIds)
            ->delete();
    }

    private static function isConcurrencyError(Throwable $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        if ($sqlState === '40001' || $sqlState === '40P01') {
            return true;
        }

        // MySQL: 1213 deadlock, 1205 lock wait timeout
        if ($driverCode === 1213 || $driverCode === 1205) {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'deadlock')
            || str_contains($message, 'lock wait timeout')
            || str_contains($message, 'try restarting transaction');
    }

    private static function backoffMicroseconds(int $attempt): int
    {
        // 5ms, 10ms, 20ms, 40ms (capped) with small jitter to break ties.
        $base = min(40_000, 5_000 * (1 << ($attempt - 1)));

        return $base + random_int(0, 2_000);
    }
}
