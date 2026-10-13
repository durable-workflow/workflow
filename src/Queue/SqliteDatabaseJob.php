<?php

declare(strict_types=1);

namespace Workflow\Queue;

use Illuminate\Container\Container;
use Illuminate\Queue\Jobs\DatabaseJob;

final class SqliteDatabaseJob extends DatabaseJob
{
    /**
     * @var array{id: int|string, queue: string, reserved_at: int, attempts: int}
     */
    private array $originalClaim;

    public function __construct(
        Container $container,
        SqliteAcknowledgingDatabaseQueue $database,
        $job,
        $connectionName,
        $queue
    ) {
        parent::__construct($container, $database, $job, $connectionName, $queue);
        $this->originalClaim = [
            'id' => $job->id,
            'queue' => $queue,
            'reserved_at' => (int) $job->reserved_at,
            'attempts' => (int) $job->attempts,
        ];
    }

    public function delete(): void
    {
        // Preserve Laravel's exception semantics: never rerun a handler because
        // its acknowledgement failed or has an uncertain commit outcome.
        $this->deleted = true;
        /** @var SqliteAcknowledgingDatabaseQueue $database */
        $database = $this->database;
        $database->deleteClaimedJob(
            $this->originalClaim['queue'],
            $this->originalClaim['id'],
            $this->originalClaim['reserved_at'],
            $this->originalClaim['attempts'],
        );
    }
}
