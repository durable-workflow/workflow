<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

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
     * @return T
     */
    public static function run(Closure $operation, ?int $attempts = null, ?Closure $admission = null): mixed
    {
        $connection = DB::connection();
        $storageName = Config::get('workflows.storage.connection');
        if (is_string($storageName) && $storageName !== '') {
            $storage = DB::connection($storageName);
            if ($storage->getDriverName() === 'sqlite') {
                $connection = $storage;
            }
        }
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

        $deadline = hrtime(true) + 5_000_000_000;
        $maximumAttempts = max(1, $attempts ?? 3);
        for ($attempt = 1; ; $attempt++) {
            $failedInRoot = false;
            $returned = false;
            $entered = false;
            $mutationError = null;
            try {
                return $connection->transaction(static function (Connection $database) use (
                    $operation,
                    $admission,
                    $pdo,
                    &$failedInRoot,
                    &$returned,
                    &$entered,
                    &$mutationError
                ): mixed {
                    try {
                        if ($admission !== null) {
                            $admission($database);
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
            } catch (Throwable $exception) {
                $bodyMustNotRepeat = $admission !== null && $entered;
                if ($exception !== $mutationError || ! $failedInRoot || $returned || $bodyMustNotRepeat
                    || $attempt >= $maximumAttempts || hrtime(true) >= $deadline
                    || ! self::isContention($exception) || $connection->transactionLevel() !== 0
                    || $connection->getRawPdo() !== $pdo || $pdo->inTransaction()) {
                    throw $exception;
                }

                usleep($attempt * 20_000);
                if (hrtime(true) >= $deadline) {
                    throw $exception;
                }
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
    public static function forTask(string $taskId, Closure $operation): mixed
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
        });
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
