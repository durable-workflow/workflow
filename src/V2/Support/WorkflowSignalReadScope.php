<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Closure;
use Illuminate\Database\Connection;
use PDO;
use WeakMap;
use Workflow\V2\Models\WorkflowRun;

/** @internal The embedded task has locked this run before its first consistent read. */
final class WorkflowSignalReadScope
{
    /**
     * @var WeakMap<Connection, array{pdo: PDO, run_id: string}>|null
     */
    private static ?WeakMap $scopes = null;

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public static function forLockedRun(WorkflowRun $run, Closure $operation): mixed
    {
        $connection = $run->getConnection();
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() !== 1) {
            return $operation();
        }

        self::$scopes ??= new WeakMap();
        $previous = self::$scopes[$connection] ?? null;
        self::$scopes[$connection] = [
            'pdo' => $connection->getPdo(),
            'run_id' => $run->id,
        ];
        try {
            return $operation();
        } finally {
            if ($previous === null) {
                unset(self::$scopes[$connection]);
            } else {
                self::$scopes[$connection] = $previous;
            }
        }
    }

    public static function allowsConsistentRead(WorkflowRun $run, Connection $connection): bool
    {
        $scope = self::$scopes[$connection] ?? null;

        return $scope !== null
            && $connection->transactionLevel() > 0
            && $scope['pdo'] === $connection->getRawPdo()
            && $scope['run_id'] === $run->id;
    }
}
