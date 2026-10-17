<?php

declare(strict_types=1);

namespace Workflow\Support;

use Closure;
use PDO;

/** @internal The caller owns transaction proof, write identity and retry policy. */
final class SqliteWriteWait
{
    /**
     * @param Closure(): void $write
     */
    public static function run(PDO $pdo, Closure $write, int $deadline): void
    {
        $statement = $pdo->query('PRAGMA busy_timeout');
        if ($statement === false) {
            throw new SqliteWriteWaitException('Cannot inspect the SQLite admission busy timeout.');
        }
        $originalTimeout = (int) $statement->fetchColumn();
        $statement->closeCursor();
        $waitMilliseconds = min(100, max(1, (int) (($deadline - hrtime(true)) / 1_000_000)));
        if ($originalTimeout <= $waitMilliseconds) {
            $write();
            return;
        }

        // A single driver wait must not consume the caller's retry budget.
        // Restore the setting before application code or the next operation.
        $pdo->exec('PRAGMA busy_timeout=' . $waitMilliseconds);
        try {
            $write();
        } finally {
            $pdo->exec('PRAGMA busy_timeout=' . $originalTimeout);
        }
    }
}
