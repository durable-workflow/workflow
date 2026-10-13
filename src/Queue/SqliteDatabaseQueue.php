<?php

declare(strict_types=1);

namespace Workflow\Queue;

use Illuminate\Database\QueryException;
use Illuminate\Queue\Jobs\DatabaseJob;
use PDO;
use PDOException;
use Throwable;

/**
 * Preserve unclaimed SQLite jobs after a proven reservation rollback.
 *
 * The pop lifecycle follows Laravel's DatabaseQueue.
 * Copyright (c) Taylor Otwell. Licensed under the MIT license.
 * The upstream notice is retained in resources/licenses/laravel-database-queue.txt.
 * Selection, reservation, payload validation and job execution remain inherited.
 */
final class SqliteDatabaseQueue extends SqliteAcknowledgingDatabaseQueue
{
    public function pop($queue = null)
    {
        $queue = $this->getQueue($queue);
        $jobRecord = null;
        $reservationFailure = null;
        $reservationPdo = null;
        $rootTransaction = $this->database->transactionLevel() === 0;

        try {
            return $this->database->transaction(function () use (
                $queue,
                &$jobRecord,
                &$reservationFailure,
                &$reservationPdo,
            ) {
                $jobRecord = $this->getNextAvailableJob($queue);

                if ($jobRecord) {
                    $reservationPdo = $this->database->getPdo();

                    try {
                        return $this->marshalJob($queue, $jobRecord);
                    } catch (QueryException $exception) {
                        $reservationFailure = $exception;

                        throw $exception;
                    }
                }
            });
        } catch (Throwable $exception) {
            if ($rootTransaction
                && $exception === $reservationFailure
                && $exception instanceof QueryException
                && $exception->getPrevious() instanceof PDOException
                && $reservationPdo instanceof PDO
                && $reservationPdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                && in_array(($exception->getPrevious()->errorInfo[1] ?? 0) & 255, [5, 6], true)
                && $this->database->getRawPdo() === $reservationPdo
                && $this->database->transactionLevel() === 0
                && ! $reservationPdo->inTransaction()
            ) {
                $query = $this->database->table($this->table)
                    ->where('id', $jobRecord->id);

                if ($exception->getSql() === $query->getGrammar()->compileUpdate($query, [
                    'reserved_at' => null,
                    'attempts' => null,
                ]) && $exception->getBindings() === [
                    $jobRecord->reserved_at,
                    $jobRecord->attempts,
                    $jobRecord->id,
                ]) {
                    // The existing worker reports the error and backs off before reselecting.
                    throw $exception;
                }
            }

            // Preserve Laravel's terminal handling for invalid jobs and uncertain outcomes.
            if ($jobRecord) {
                try {
                    (new DatabaseJob(
                        $this->container,
                        $this,
                        $jobRecord,
                        $this->connectionName,
                        $queue,
                    ))->fail($exception);
                } catch (Throwable) {
                    // Keep the original pop failure, as Laravel does.
                }
            }

            throw $exception;
        }
    }
}
