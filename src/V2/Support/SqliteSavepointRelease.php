<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEvent;
use PDO;
use WeakMap;

final class SqliteSavepointRelease
{
    /**
     * @var WeakMap<Connection, array{pdo: PDO, minimum_level: int}>|null
     */
    private static ?WeakMap $scopes = null;

    /**
     * @template T
     * @param Closure(Connection): T $operation
     * @return T
     */
    public static function transaction(Connection $connection, Closure $operation, int $attempts = 1): mixed
    {
        if ($connection->getDriverName() !== 'sqlite') {
            return $connection->transaction($operation, $attempts);
        }

        self::$scopes ??= new WeakMap();
        $previous = self::$scopes[$connection] ?? null;
        $initialLevel = $connection->transactionLevel();
        $scope = [
            'pdo' => $connection->getPdo(),
            'minimum_level' => max(1, $initialLevel),
        ];

        try {
            return $connection->transaction(static function (Connection $database) use (
                $operation,
                $scope,
                $initialLevel,
                $previous
            ): mixed {
                self::$scopes[$database] = $scope;
                try {
                    return $operation($database);
                } finally {
                    // Root after-commit callbacks may start unrelated application
                    // transactions before Connection::transaction() returns.
                    if ($initialLevel === 0) {
                        self::restore($database, $previous);
                    }
                }
            }, $attempts);
        } finally {
            self::restore($connection, $previous);
        }
    }

    public static function release(ConnectionEvent $event): void
    {
        $connection = $event->connection;
        $scope = self::$scopes[$connection] ?? null;
        if ($scope === null || $connection->transactionLevel() < $scope['minimum_level']
            || $connection->getRawPdo() !== $scope['pdo'] || ! $scope['pdo']->inTransaction()) {
            return;
        }

        // Laravel decrements nested transaction depth without releasing SQLite's
        // savepoint. Thousands of finished nested writes otherwise retain undo
        // state until the root commit and make later projection writes quadratic.
        // RELEASE merges into the enclosing transaction. Its rollback and
        // after-commit boundary remain intact. Only this operation's connection,
        // PDO and owned depths participate; application transactions stay outside.
        $scope['pdo']->exec('RELEASE SAVEPOINT trans' . ($connection->transactionLevel() + 1));
    }

    /**
     * @param array{pdo: PDO, minimum_level: int}|null $previous
     */
    private static function restore(Connection $connection, ?array $previous): void
    {
        if ($previous === null) {
            unset(self::$scopes[$connection]);
        } else {
            self::$scopes[$connection] = $previous;
        }
    }
}
