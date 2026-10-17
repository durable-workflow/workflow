<?php

declare(strict_types=1);

namespace TracingBenchmark;

use Monolog\Formatter\JsonFormatter;
use Orchestra\Testbench\Foundation\Application;
use Workflow\V2\Tracing\LogContext;

final class BenchmarkApplication extends Application
{
    protected function defineEnvironment($app): void
    {
        $enabled = getenv('BENCH_TRACING') === 'true';
        $queue = getenv('BENCH_QUEUE_DRIVER') ?: 'database';
        if (! in_array($queue, ['database', 'redis'], true)) {
            throw new \InvalidArgumentException('Expected the database or redis benchmark queue.');
        }
        $app['config']->set([
            'database.default' => 'benchmark',
            'database.connections.benchmark' => [
                'driver' => 'mysql', 'host' => getenv('DB_HOST') ?: 'mysql', 'port' => 3306,
                'database' => getenv('DB_DATABASE') ?: 'benchmark',
                'username' => getenv('DB_USERNAME') ?: 'benchmark',
                'password' => getenv('DB_PASSWORD') ?: 'disposable-benchmark',
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '', 'strict' => true,
            ],
            'workflows.storage.connection' => 'benchmark',
            'workflows.v1.enabled' => false,
            'workflows.v2.compatibility.namespace' => 'default',
            'workflows.v2.task_dispatch_mode' => 'queue',
            'workflows.v2.matching_role.queue_wake_enabled' => getenv('BENCH_QUEUE_WAKE') !== 'false',
            'workflows.tracing.enabled' => $enabled,
            'workflows.tracing.baggage_allowlist' => [],
            'queue.default' => $queue,
            'queue.connections.database' => [
                'driver' => 'database', 'connection' => 'benchmark', 'table' => 'jobs',
                'queue' => 'default', 'retry_after' => 130,
            ],
            'queue.connections.redis' => [
                'driver' => 'redis', 'connection' => 'default',
                'queue' => 'default', 'retry_after' => 130, 'block_for' => 1,
            ],
            'queue.failed' => [
                'driver' => 'database-uuids', 'database' => 'benchmark', 'table' => 'failed_jobs',
            ],
            'database.redis.client' => 'phpredis',
            'database.redis.default' => ['host' => getenv('REDIS_HOST') ?: 'redis', 'port' => 6379],
            'database.redis.cache' => ['host' => getenv('REDIS_HOST') ?: 'redis', 'port' => 6379, 'database' => 1],
            'cache.default' => 'redis',
            'cache.prefix' => 'tracing-benchmark:',
            'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'cache', 'lock_connection' => 'cache'],
            'workflows.v2.long_poll.multi_node' => true,
            'workflows.v2.long_poll.validation_mode' => 'fail',
            'logging.default' => 'benchmark',
            'logging.channels.benchmark' => [
                'driver' => 'single', 'path' => $app->basePath('callbacks.log'),
                'formatter' => JsonFormatter::class, 'tap' => $enabled ? [LogContext::class] : [],
            ],
        ]);
    }
}
