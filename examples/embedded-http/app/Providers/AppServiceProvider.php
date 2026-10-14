<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(\App\Support\RequestMetrics::class);

        config([
            'workflows.v1.enabled' => false,
            'workflows.v2.instance_model' => \App\Models\QualifiedWorkflowInstance::class,
            'workflows.v2.task_dispatch_mode' => 'queue',
            'workflows.storage.connection' => config('qualification.storage_connection') ?: config('database.default'),
        ]);
    }

    public function boot(): void
    {
        $consoleMetrics = $this->app->runningInConsole() && config('qualification.cli_query_metrics')
            ? new \App\Support\RequestMetrics() : null;
        \Illuminate\Support\Facades\DB::listen(static function ($query) use ($consoleMetrics): void {
            app(\App\Support\RequestMetrics::class)->record($query->sql, $query->connectionName);
            $consoleMetrics?->record($query->sql, $query->connectionName);
        });

        if ($consoleMetrics !== null) {
            $process = bin2hex(random_bytes(8));
            $lastWrite = 0.0;
            $path = config('qualification.cli_query_metrics');
            $flush = static function (bool $final = false) use ($consoleMetrics, $process, $path, &$lastWrite): void {
                $now = microtime(true);
                if (! $final && $now - $lastWrite < 1) {
                    return;
                }
                $record = json_encode([
                    'process' => $process, 'pid' => getmypid(), 'time' => $now, 'final' => $final,
                    'queries' => $consoleMetrics->queries, 'history_queries' => $consoleMetrics->historyQueries,
                    'connections' => array_keys($consoleMetrics->connections),
                ], JSON_THROW_ON_ERROR);
                if (file_put_contents($path, $record . "\n", FILE_APPEND | LOCK_EX) === false) {
                    throw new \RuntimeException('Cannot record CLI query measurements.');
                }
                $lastWrite = $now;
            };
            \Illuminate\Support\Facades\Queue::looping(static fn () => $flush());
            register_shutdown_function(static fn () => $flush(true));
        }

        if (config('qualification.eager')) {
            $this->app->make(\Workflow\V2\Contracts\WorkflowControlPlane::class);
            $this->app->make(\Workflow\V2\Contracts\WorkflowTaskBridge::class);
            $this->app->make(\Workflow\V2\Contracts\LongPollWakeStore::class);
        }
    }
}
