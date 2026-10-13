<?php

declare(strict_types=1);

namespace Workflow\Queue;

use Illuminate\Queue\Connectors\DatabaseConnector;
use Illuminate\Queue\DatabaseQueue;

final class SqliteDatabaseConnector extends DatabaseConnector
{
    public static function fromDefault(DatabaseConnector $connector): self
    {
        return new self($connector->connections);
    }

    public function connect(array $config)
    {
        $database = $this->connections->connection($config['connection'] ?? null);
        $queueClass = $database->getDriverName() === 'sqlite'
            ? SqliteDatabaseQueue::class
            : DatabaseQueue::class;

        return new $queueClass(
            $database,
            $config['table'],
            $config['queue'],
            $config['retry_after'] ?? 60,
            $config['after_commit'] ?? null,
        );
    }
}
