<?php

declare(strict_types=1);

namespace Tests\Support;

use Tests\Fixtures\V2\TestContinuationProcessWorkflow;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Support\WorkflowTaskLease;

final class ContinuationProcessEnvironment
{
    public static function providers(): array
    {
        return [TestDatabaseServiceProvider::class, WorkflowServiceProvider::class];
    }

    public static function configure($app, string $database, bool $enabled): void
    {
        $app['config']->set('database.default', 'process');
        $app['config']->set('database.connections.process', [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 1000,
        ]);
        $app['config']->set('workflows.storage.connection', 'process');
        $app['config']->set('workflows.v1.enabled', false);
        $app['config']->set('workflows.v2.task_dispatch_mode', 'queue');
        $app['config']->set(WorkflowTaskLease::CONFIG_KEY, 3);
        $app['config']->set('workflows.v2.live_execution', [
            'workflows' => $enabled ? [TestContinuationProcessWorkflow::class] : [],
            'build' => 'process-loss-test',
        ]);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'process',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 4,
        ]);
        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => 'process',
            'table' => 'failed_jobs',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'null');
    }
}
