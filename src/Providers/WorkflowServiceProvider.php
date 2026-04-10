<?php

declare(strict_types=1);

namespace Workflow\Providers;

use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\SerializableClosure\SerializableClosure;
use Workflow\Commands\ActivityMakeCommand;
use Workflow\Commands\V2BackfillCommandContractsCommand;
use Workflow\Commands\V2DoctorCommand;
use Workflow\Commands\V2HistoryExportCommand;
use Workflow\Commands\V2RebuildProjectionsCommand;
use Workflow\Commands\WorkflowMakeCommand;
use Workflow\V2\TaskWatchdog;
use Workflow\Watchdog;

final class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/workflows.php', 'workflows');
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
            V2DoctorCommand::class,
            V2HistoryExportCommand::class,
            V2RebuildProjectionsCommand::class,
        ]);

        Event::listen(Looping::class, static function (Looping $event): void {
            Watchdog::wake($event->connectionName, $event->queue);
            TaskWatchdog::wake($event->connectionName, $event->queue);
        });
    }
}
