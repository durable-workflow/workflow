<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use Workflow\V2\WorkflowStub;

/** @internal Give waiting foreground writers admission between maintenance candidates. */
final class SqliteMaintenanceYield
{
    public static function pause(Connection $storage): void
    {
        if (config('workflows.v2.task_dispatch_mode') !== 'poll' || WorkflowStub::faked()) {
            return;
        }
        $application = DB::connection();
        if ($storage->getDriverName() !== 'sqlite' || $storage->transactionLevel() !== 0
            || $application->transactionLevel() !== 0) {
            return;
        }
        $pdo = $storage->getRawPdo();
        $applicationPdo = $application->getRawPdo();
        if (! $pdo instanceof PDO || $pdo->inTransaction()
            || ($applicationPdo instanceof PDO && $applicationPdo->inTransaction())) {
            return;
        }

        // Foreground writer admission backs off by at most 100 ms. Release the
        // writer for two such intervals before another maintenance candidate.
        // Never sleep inside an application or storage transaction.
        usleep(200_000);
    }
}
