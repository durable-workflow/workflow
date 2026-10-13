<?php

declare(strict_types=1);

namespace Workflow\Queue;

use Illuminate\Database\QueryException;
use Illuminate\Queue\DatabaseQueue;
use PDO;
use PDOException;
use Throwable;

/** Keep Laravel's pop lifecycle while acknowledging only the original reservation. */
class SqliteAcknowledgingDatabaseQueue extends DatabaseQueue
{
    public function deleteClaimedJob(string $queue, int|string $id, int $reservedAt, int $attempts): void
    {
        $rootTransaction = $this->database->transactionLevel() === 0;
        $pdo = $this->database->getPdo();
        $deadline = hrtime(true) + 5_000_000_000;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $deleteFailure = null;
            $query = $this->database->table($this->table)
                ->where('id', $id)
                ->where('queue', $queue)
                ->where('reserved_at', $reservedAt)
                ->where('attempts', $attempts);
            $sql = $query->getGrammar()
                ->compileDelete($query);
            $bindings = $query->getBindings();

            try {
                $this->database->transaction(static function () use ($query, &$deleteFailure): void {
                    try {
                        // One conditional write avoids SQLite's SELECT-to-write upgrade.
                        // A newer reservation cannot match the original claim.
                        $query->delete();
                    } catch (QueryException $exception) {
                        $deleteFailure = $exception;
                        throw $exception;
                    }
                });
                return;
            } catch (Throwable $exception) {
                if (! (
                    $rootTransaction
                    && $exception === $deleteFailure
                    && $exception instanceof QueryException
                    && $exception->getPrevious() instanceof PDOException
                    && $pdo instanceof PDO
                    && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                    && in_array(($exception->getPrevious()->errorInfo[1] ?? 0) & 255, [5, 6], true)
                    && $exception->getSql() === $sql
                    && $exception->getBindings() === $bindings
                    && $this->database->getRawPdo() === $pdo
                    && $this->database->transactionLevel() === 0
                    && ! $pdo->inTransaction()
                    && $attempt < 2
                    && hrtime(true) < $deadline
                )) {
                    throw $exception;
                }
                usleep(1_000);
            }
        }
    }

    protected function marshalJob($queue, $job)
    {
        return new SqliteDatabaseJob(
            $this->container,
            $this,
            $this->markJobAsReserved($job),
            $this->connectionName,
            $queue,
        );
    }
}
