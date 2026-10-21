<?php

declare(strict_types=1);

namespace Tests\Support;

use ReflectionClass;
use Tests\Fixtures\V2\TestContinuationGrowthActivity;
use Tests\Fixtures\V2\TestContinuationGrowthWorkflow;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Support\WorkflowContinuationCache;
use Workflow\V2\Support\WorkflowContinuationSession;
use Workflow\V2\Support\WorkflowExecution;
use Workflow\V2\Support\WorkflowExecutor;

final class ContinuationGrowthEnvironment
{
    public static function providers(): array
    {
        return [TestDatabaseServiceProvider::class, WorkflowServiceProvider::class];
    }

    public static function configure($app, string $directory, bool $enabled): void
    {
        $app['config']->set('database.default', 'growth');
        $app['config']->set('database.connections.growth', [
            'driver' => 'sqlite',
            'database' => $directory . '/database.sqlite',
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
        ]);
        $app['config']->set('workflows.storage.connection', 'growth');
        $app['config']->set('workflows.v1.enabled', false);
        $app['config']->set('workflows.v2.task_dispatch_mode', 'queue');
        $app['config']->set('workflows.v2.live_execution', [
            'workflows' => $enabled ? [TestContinuationGrowthWorkflow::class] : [],
            'build' => 'continuation-growth-v1',
        ]);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'growth',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 360,
        ]);
        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => 'growth',
            'table' => 'failed_jobs',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'null');
    }

    public static function cpuMicroseconds(): int
    {
        $usage = getrusage();

        return ((int) $usage['ru_utime.tv_sec'] + (int) $usage['ru_stime.tv_sec']) * 1_000_000
            + (int) $usage['ru_utime.tv_usec'] + (int) $usage['ru_stime.tv_usec'];
    }

    public static function loadedSources(): array
    {
        $loaded = [];
        foreach ([WorkflowExecutor::class, RunWorkflowTask::class, WorkflowContinuationCache::class,
            WorkflowContinuationSession::class, WorkflowExecution::class,
            \Workflow\V2\Support\WorkflowExecutionProfile::class, ContinuationHistoryProfile::class,
            ContinuationTaskMaintenance::class,
            TestContinuationGrowthWorkflow::class, TestContinuationGrowthActivity::class] as $class) {
            $loaded[$class] = hash_file('sha256', (new ReflectionClass($class))->getFileName());
        }

        return $loaded;
    }

    public static function distribution(array $values): array
    {
        sort($values);
        $count = count($values);

        return [
            'count' => $count,
            'min_us' => $values[0] ?? null,
            'p50_us' => $count > 0 ? $values[(int) floor(($count - 1) * 0.5)] : null,
            'p95_us' => $count > 0 ? $values[(int) floor(($count - 1) * 0.95)] : null,
            'max_us' => $values[$count - 1] ?? null,
        ];
    }

    public static function writeReport(string $path, array $report): void
    {
        file_put_contents($path . '.pending', json_encode($report, JSON_THROW_ON_ERROR));
        rename($path . '.pending', $path);
    }
}
