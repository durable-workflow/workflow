<?php

declare(strict_types=1);

namespace Workflow\Queue;

use Illuminate\Foundation\Application;
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
        $queueClass = DatabaseQueue::class;
        if ($database->getDriverName() === 'sqlite') {
            $queueClass = version_compare(Application::VERSION, '12.54.0', '>=')
                ? SqliteDatabaseQueue::class
                : SqliteAcknowledgingDatabaseQueue::class;
        }

        return new $queueClass(
            $database,
            $config['table'],
            $config['queue'],
            $config['retry_after'] ?? 60,
            $config['after_commit'] ?? null,
        );
    }
}
