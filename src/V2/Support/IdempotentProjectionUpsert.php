<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use LogicException;
use Throwable;

/**
 * Wraps a per-row updateOrCreate with retry on unique-key violations so the
 * timeline / wait / timer / lineage / summary projectors stay correct when
 * two workers race the same projection row.
 *
 * The race shape (#438): updateOrCreate is firstOrNew + save. Two concurrent
 * callers both observe no row, both INSERT, and the loser hits SQLSTATE 23000
 * (MySQL/SQLite) or 23505 (Postgres) on the projection's primary or unique
 * key. On retry, firstOrNew sees the row the winning side just wrote and the
 * second call falls through to UPDATE, which has no analogous race because
 * UPDATE does not collide on existing primary keys.
 *
 * Sibling DELETE-side races for these projectors are handled by
 * {@see StaleProjectionCleanup} (#425).
 */
final class IdempotentProjectionUpsert
{
    private const MAX_ATTEMPTS = 5;

    /**
     * @template TModel of Model
     * @param class-string<TModel> $model
     * @param array<string, mixed> $key
     * @param array<string, mixed> $values
     * @return TModel
     */
    public static function upsert(string $model, array $key, array $values): Model
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                /** @var TModel $row */
                $row = $model::query()->updateOrCreate($key, $values);

                return $row;
            } catch (QueryException $e) {
                if (! self::isUniqueViolation($e) || $attempt === self::MAX_ATTEMPTS) {
                    throw $e;
                }

                usleep(self::backoffMicroseconds($attempt));
            }
        }

        throw new LogicException('IdempotentProjectionUpsert exhausted attempts without resolving.');
    }

    private static function isUniqueViolation(Throwable $e): bool
    {
        $errorInfo = $e instanceof QueryException ? ($e->errorInfo ?? null) : null;
        $sqlState = is_array($errorInfo) ? (string) ($errorInfo[0] ?? '') : '';
        $driverCode = is_array($errorInfo) ? (int) ($errorInfo[1] ?? 0) : 0;

        // 23505 is the Postgres-specific unique_violation SQLSTATE; it never
        // overlaps with other constraint failures so we can short-circuit.
        if ($sqlState === '23505') {
            return true;
        }

        // 23000 is the generic integrity-constraint family (MySQL, SQLite,
        // SQL Server). Narrow to driver codes that mean "duplicate key" so we
        // don't retry e.g. foreign-key or NOT NULL violations that won't clear
        // on the next pass.
        if ($sqlState === '23000') {
            // MySQL 1062 = ER_DUP_ENTRY
            // SQLite 19 = SQLITE_CONSTRAINT (covers UNIQUE; message-disambiguated below)
            // SQL Server 2627 = unique-constraint violation; 2601 = duplicate index key
            if ($driverCode === 1062 || $driverCode === 2627 || $driverCode === 2601) {
                return true;
            }

            $message = strtolower($e->getMessage());

            return str_contains($message, 'duplicate entry')
                || str_contains($message, 'unique constraint failed')
                || str_contains($message, 'duplicate key');
        }

        return false;
    }

    private static function backoffMicroseconds(int $attempt): int
    {
        // 2ms, 4ms, 8ms, 16ms (capped) with small jitter to break ties between
        // racing retriers.
        $base = min(16_000, 2_000 * (1 << ($attempt - 1)));

        return $base + random_int(0, 1_000);
    }
}
