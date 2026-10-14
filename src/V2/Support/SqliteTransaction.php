<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Closure;
use Illuminate\Database\Connection;
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
     * @return T
     */
    public static function run(Closure $operation): mixed
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite' || $connection->transactionLevel() !== 0) {
            return $connection->transaction($operation);
        }

        $pdo = $connection->getPdo();
        if ($pdo->inTransaction()) {
            return $connection->transaction($operation);
        }

        $deadline = hrtime(true) + 5_000_000_000;
        for ($attempt = 1; ; $attempt++) {
            $failedInRoot = false;
            $returned = false;
            $mutationError = null;
            try {
                return $connection->transaction(static function (Connection $database) use (
                    $operation,
                    $pdo,
                    &$failedInRoot,
                    &$returned,
                    &$mutationError
                ): mixed {
                    try {
                        $result = $operation($database);
                    } catch (Throwable $exception) {
                        $mutationError = $exception;
                        $failedInRoot = $database->transactionLevel() === 1
                            && $database->getPdo() === $pdo && $pdo->inTransaction();

                        throw $exception;
                    }
                    $returned = true;

                    return $result;
                });
            } catch (Throwable $exception) {
                if ($exception !== $mutationError || ! $failedInRoot || $returned || $attempt >= 3 || hrtime(
                    true
                ) >= $deadline
                    || ! self::isContention($exception) || $connection->transactionLevel() !== 0
                    || $connection->getPdo() !== $pdo || $pdo->inTransaction()) {
                    throw $exception;
                }

                usleep($attempt * 20_000);
                if (hrtime(true) >= $deadline) {
                    throw $exception;
                }
            }
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
