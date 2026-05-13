<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Wraps a per-row updateOrCreate with atomic fallback on unique-key violations so the
 * timeline / wait / timer / lineage / summary projectors stay correct when
 * two workers race the same projection row.
 *
 * The race shape (#438): updateOrCreate is firstOrNew + save. Two concurrent
 * callers both observe no row, both INSERT, and the loser hits SQLSTATE 23000
 * (MySQL/SQLite) or 23505 (Postgres) on the projection's primary or unique key.
 * MySQL repeatable-read transactions can keep the competing row invisible to a
 * retry while the unique index still rejects another INSERT, so duplicate-key
 * recovery switches to the database-native upsert path.
 *
 * Sibling DELETE-side races for these projectors are handled by
 * {@see StaleProjectionCleanup} (#425).
 */
final class IdempotentProjectionUpsert
{
    /**
     * @template TModel of Model
     * @param class-string<TModel> $model
     * @param array<string, mixed> $key
     * @param array<string, mixed> $values
     * @return TModel
     */
    public static function upsert(string $model, array $key, array $values): Model
    {
        try {
            /** @var TModel $row */
            $row = $model::query()->updateOrCreate($key, $values);

            return $row;
        } catch (QueryException $e) {
            if (! self::isUniqueViolation($e)) {
                throw $e;
            }

            return self::atomicUpsert($model, $key, $values);
        }
    }

    /**
     * @template TModel of Model
     * @param class-string<TModel> $model
     * @param array<string, mixed> $key
     * @param array<string, mixed> $values
     * @return TModel
     */
    private static function atomicUpsert(string $model, array $key, array $values): Model
    {
        /** @var TModel $instance */
        $instance = new $model();
        $instance->forceFill($key + $values);

        $updateColumns = array_keys($values);
        $attributes = $instance->getAttributes();

        if ($updateColumns === []) {
            $model::query()->insertOrIgnore($attributes);
        } else {
            $model::query()->upsert(
                [$attributes],
                array_keys($key),
                $updateColumns,
            );
        }

        /** @var TModel|null $row */
        $row = $model::query()
            ->where($key)
            ->first();

        if ($row !== null) {
            return $row;
        }

        $instance->exists = true;
        $instance->syncOriginal();

        return $instance;
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

}
