<?php

declare(strict_types=1);

namespace Workflow\Providers;

use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\SerializableClosure\SerializableClosure;
use Workflow\Commands\ActivityMakeCommand;
use Workflow\Commands\V2BackfillCommandContractsCommand;
use Workflow\Commands\V2BackfillCommandLifecyclesCommand;
use Workflow\Commands\V2BackfillFailureTypesCommand;
use Workflow\Commands\V2BackfillParallelGroupMetadataCommand;
use Workflow\Commands\V2DoctorCommand;
use Workflow\Commands\V2HistoryExportCommand;
use Workflow\Commands\V2RebuildProjectionsCommand;
use Workflow\Commands\V2RepairPassCommand;
use Workflow\Commands\WorkflowMakeCommand;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Support\DefaultActivityTaskBridge;
use Workflow\V2\Support\DefaultOperatorObservabilityRepository;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\TaskWatchdog;
use Workflow\Watchdog;

final class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/workflows.php', 'workflows');

        $this->app->singleton(
            OperatorObservabilityRepository::class,
            DefaultOperatorObservabilityRepository::class,
        );

        $this->app->singleton(WorkflowTaskBridge::class, DefaultWorkflowTaskBridge::class);

        $this->app->singleton(ActivityTaskBridge::class, DefaultActivityTaskBridge::class);
    }

    public function boot(): void
    {
        SerializableClosure::setSecretKey(config('app.key'));

        $this->loadMigrationsFrom(__DIR__ . '/../migrations');

        $this->publishes([
            __DIR__ . '/../config/workflows.php' => config_path('workflows.php'),
        ], 'config');

        $this->publishes([
            __DIR__ . '/../migrations/' => database_path('/migrations'),
        ], 'migrations');

        $this->commands([
            ActivityMakeCommand::class,
            WorkflowMakeCommand::class,
            V2BackfillCommandContractsCommand::class,
            V2BackfillCommandLifecyclesCommand::class,
            V2BackfillFailureTypesCommand::class,
            V2BackfillParallelGroupMetadataCommand::class,
            V2DoctorCommand::class,
            V2HistoryExportCommand::class,
            V2RepairPassCommand::class,
            V2RebuildProjectionsCommand::class,
        ]);

        Event::listen(Looping::class, static function (Looping $event): void {
            Watchdog::wake($event->connectionName, $event->queue);
            TaskWatchdog::wake($event->connectionName, $event->queue);
        });
    }
}
