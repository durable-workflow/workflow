<?php

declare(strict_types=1);

namespace Workflow\Support;

use Illuminate\Database\Connection;

final class AfterCommit
{
    public static function on(Connection $connection, callable $callback): void
    {
        if ($connection->transactionLevel() === 0) {
            $callback();

            return;
        }

        // Laravel registers callbacks on the latest transaction across all
        // connections. A nested frame pins this callback to its owning root.
        $connection->transaction(static function () use ($connection, $callback): void {
            $connection->afterCommit($callback);
        });
    }
}
