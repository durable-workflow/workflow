<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Support\RequestMetrics::class);

        config([
            'workflows.v1.enabled' => false,
            'workflows.v2.instance_model' => \App\Models\QualifiedWorkflowInstance::class,
            'workflows.v2.task_dispatch_mode' => 'queue',
            'workflows.storage.connection' => config('database.default'),
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\DB::listen(static function ($query): void {
            app(\App\Support\RequestMetrics::class)->record($query->sql, $query->connectionName);
        });

        if (config('qualification.eager')) {
            $this->app->make(\Workflow\V2\Contracts\WorkflowControlPlane::class);
            $this->app->make(\Workflow\V2\Contracts\WorkflowTaskBridge::class);
            $this->app->make(\Workflow\V2\Contracts\LongPollWakeStore::class);
        }
    }
}
