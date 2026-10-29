<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Throwable;
use Workflow\Support\SqliteWriteWait;
use Workflow\V2\Exceptions\SqliteWriteAdmissionUnavailableException;

final class SqliteTransaction
{
    /**
     * Retry a SQLite mutation only after its original root transaction rolled back.
     * A returned callback, uncertain commit, nested transaction or changed PDO
     * keeps the original exception. Application activity execution belongs outside.
     *
     * @template T
     * @param Closure(Connection): T $operation
     * @param int|null $attempts Explicit existing attempt limits also pass through to other drivers.
     * @param Closure(Connection): void|null $admission Acquire the write lock before a body that must not repeat.
     * @param int|null $admissionDeadline Captured monotonic nanoseconds for an already claimed outcome.
     * @param Connection|null $connection Use the caller's model storage connection when supplied.
     * @return T
     */
    public static function run(
        Closure $operation,
        ?int $attempts = null,
        ?Closure $admission = null,
        ?int $admissionDeadline = null,
        ?Connection $connection = null,
    ): mixed {
        $connection ??= self::connection();
        if ($connection->getDriverName() !== 'sqlite' || $connection->transactionLevel() !== 0) {
            return $connection->transaction($operation, $attempts ?? 1);
        }

        if ($connection->getRawPdo() === null) {
            $connection->reconnect();
        }
        $pdo = $connection->getPdo();
        if ($pdo->inTransaction()) {
            return $connection->transaction($operation, $attempts ?? 1);
        }

        [$maximumAttempts, $deadline] = self::retryLimits($attempts, $admission !== null, $admissionDeadline);
        for ($attempt = 1; ; $attempt++) {
            $outcome = self::attempt($connection, $pdo, $operation, $admission, $deadline);
            if (! isset($outcome['error'])) {
                return $outcome['value'];
            }
            $exception = $outcome['error'];
            if (! $outcome['retry'] || $attempt >= $maximumAttempts || hrtime(true) >= $deadline) {
                throw $exception;
            }

            usleep($admission === null ? $attempt * 20_000 : min($attempt * 20_000, 100_000));
            if (hrtime(true) >= $deadline) {
                throw $exception;
            }
        }
    }

    /**
     * Acquire SQLite's writer before reading state or executing workflow code.
     * The primary-key value, ORM timestamps and model events remain unchanged.
     *
     * @template T
     * @param Closure(Connection): T $operation
     * @return T
     */
    public static function forTask(string $taskId, Closure $operation, ?int $admissionDeadline = null): mixed
    {
        return self::run($operation, admission: static function () use ($taskId): void {
            $task = ConfiguredV2Models::query('task_model', \Workflow\V2\Models\WorkflowTask::class);
            $key = $task->getModel()
                ->getKeyName();
            $task->whereKey($taskId)
                ->toBase()
                ->update([
                    $key => $taskId,
                ]);
        }, admissionDeadline: $admissionDeadline);
    }

    /**
     * Try one bounded writer admission for maintenance. Only the exact busy
     * admission error, with rollback proved and no body entered, is deferrable.
     *
     * @internal
     * @template T
     * @param Closure(Connection): T $operation
     * @return T
     */
    public static function forTaskOnce(\Workflow\V2\Models\WorkflowTask $task, Closure $operation): mixed
    {
        $connection = $task->getConnection();
        // Metadata and query observers can throw busy-looking application
        // errors. Only a QueryException from the admission write is deferrable.
        $key = $task->getKeyName();
        $taskId = $task->getKey();
        $query = $connection->table($task->getTable())
            ->where($key, $taskId);
        $admissionSql = $query->getGrammar()
            ->compileUpdate($query, [
                $key => $taskId,
            ]);
        $admissionBindings = $connection->prepareBindings([$taskId, $taskId]);
        $pdo = $connection->getPdo();
        $root = $connection->getDriverName() === 'sqlite'
            && $connection->transactionLevel() === 0 && ! $pdo->inTransaction();
        $entered = false;
        $admissionFailure = null;
        $admit = static function () use ($query, $key, $taskId, &$admissionFailure): void {
            try {
                $query->update([
                    $key => $taskId,
                ]);
            } catch (Throwable $error) {
                $admissionFailure = $error;

                throw $error;
            }
        };
        try {
            return self::run(static function (Connection $database) use ($operation, &$entered): mixed {
                $entered = true;

                return $operation($database);
            }, attempts: 1, admission: $admit, connection: $connection);
        } catch (Throwable $error) {
            if ($root && ! $entered && $error instanceof QueryException
                && $error === $admissionFailure && $error->getSql() === $admissionSql
                && $error->getBindings() === $admissionBindings && self::isContention($error)
                && $connection->transactionLevel() === 0 && $connection->getRawPdo() === $pdo
                && ! $pdo->inTransaction()) {
                throw new SqliteWriteAdmissionUnavailableException('SQLite writer admission is busy.', 0, $error);
            }

            throw $error;
        }
    }

    private static function connection(): Connection
    {
        $connection = DB::connection();
        $storageName = Config::get('workflows.storage.connection');
        if (! is_string($storageName) || $storageName === '') {
            return $connection;
        }
        $storage = DB::connection($storageName);

        return $storage->getDriverName() === 'sqlite' ? $storage : $connection;
    }

    /**
     * @return array{int, int}
     */
    private static function retryLimits(?int $attempts, bool $admission, ?int $admissionDeadline): array
    {
        $deadline = hrtime(true) + 5_000_000_000;
        $defaultAttempts = $admission ? 64 : 3;
        if ($admission && $admissionDeadline !== null) {
            // Keep an already executed callback's outcome within the original
            // claim. Admission and ordinary mutations retain their default limits.
            $deadline = $admissionDeadline;
            $defaultAttempts = PHP_INT_MAX;
        }

        return [max(1, $attempts ?? $defaultAttempts), $deadline];
    }

    /**
     * Keep the proof for one transaction together, including failures after its body returns.
     *
     * @template T
     * @param Closure(Connection): T $operation
     * @param Closure(Connection): void|null $admission
     * @return array{value: T}|array{error: Throwable, retry: bool}
     */
    private static function attempt(
        Connection $connection,
        PDO $pdo,
        Closure $operation,
        ?Closure $admission,
        int $deadline,
    ): array {
        $failedInRoot = false;
        $returned = false;
        $entered = false;
        $mutationError = null;
        try {
            $value = $connection->transaction(static function (Connection $database) use (
                $operation,
                $admission,
                $pdo,
                $deadline,
                &$failedInRoot,
                &$returned,
                &$entered,
                &$mutationError
            ): mixed {
                try {
                    if ($admission !== null) {
                        SqliteWriteWait::run($pdo, static function () use ($admission, $database): void {
                            $admission($database);
                        }, $deadline);
                    }
                    $entered = true;
                    $result = $operation($database);
                } catch (Throwable $exception) {
                    $mutationError = $exception;
                    $failedInRoot = $database->transactionLevel() === 1
                        && $database->getRawPdo() === $pdo && $pdo->inTransaction();

                    throw $exception;
                }
                $returned = true;

                return $result;
            });

            return [
                'value' => $value,
            ];
        } catch (Throwable $exception) {
            $bodyMustNotRepeat = $admission !== null && $entered;

            return [
                'error' => $exception,
                'retry' => $exception === $mutationError && $failedInRoot && ! $returned && ! $bodyMustNotRepeat
                    && self::isContention($exception) && $connection->transactionLevel() === 0
                    && $connection->getRawPdo() === $pdo && ! $pdo->inTransaction(),
            ];
        }
    }

    private static function isContention(Throwable $exception): bool
    {
        do {
            if ($exception instanceof PDOException && is_array($exception->errorInfo)) {
                $code = $exception->errorInfo[1] ?? null;
                if (is_int($code) && in_array($code & 255, [5, 6], true)) {
                    return true;
                }
            }
            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return false;
    }
}
