<?php

declare(strict_types=1);

namespace Tests\Support;

final class EmbeddedActivityLeaseEnvironment
{
    public const CONNECTION = 'embedded-lease';

    public static function configure($app, string $database): void
    {
        $driver = getenv('DW_EMBEDDED_LEASE_TEST_DRIVER');
        $connection = in_array($driver, ['mysql', 'pgsql'], true)
            ? ['driver' => $driver, 'host' => getenv('DW_EMBEDDED_LEASE_TEST_HOST'),
                'port' => (int) getenv('DW_EMBEDDED_LEASE_TEST_PORT'), 'database' => 'embedded_lease',
                'username' => 'workflow', 'password' => 'workflow', 'prefix' => '',
                'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
                'collation' => 'utf8mb4_unicode_ci', 'timezone' => $driver === 'mysql' ? '+00:00' : 'UTC']
            : ['driver' => 'sqlite', 'database' => $database, 'prefix' => '',
                'foreign_key_constraints' => true, 'busy_timeout' => 50, 'journal_mode' => 'WAL'];
        $app['config']->set([
            'database.default' => self::CONNECTION,
            'database.connections.'.self::CONNECTION => $connection,
            'workflows.storage.connection' => self::CONNECTION,
            'workflows.v1.enabled' => false,
            'workflows.v2.task_dispatch_mode' => 'queue',
            'workflows.v2.activity_task_lease_seconds' => 3,
            'queue.default' => 'database',
            'queue.connections.database' => ['driver' => 'database', 'table' => 'jobs',
                'connection' => self::CONNECTION, 'queue' => 'default', 'retry_after' => 90],
            'queue.failed' => ['driver' => 'database-uuids', 'database' => self::CONNECTION, 'table' => 'failed_jobs'],
            'cache.default' => 'array',
            'logging.default' => 'null',
        ]);
    }
}
