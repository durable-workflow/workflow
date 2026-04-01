<?php

declare(strict_types=1);

namespace Workflow\Providers;

use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\SerializableClosure\SerializableClosure;
use Workflow\Commands\ActivityMakeCommand;
use Workflow\Commands\WorkflowMakeCommand;
use Workflow\Watchdog;

final class WorkflowServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        SerializableClosure::setSecretKey(config('app.key'));

        $this->publishes([
            __DIR__ . '/../config/workflows.php' => config_path('workflows.php'),
        ], 'config');

        $this->publishes([
            __DIR__ . '/../migrations/' => database_path('/migrations'),
        ], 'migrations');

        $this->commands([ActivityMakeCommand::class, WorkflowMakeCommand::class]);

        Event::listen(Looping::class, static function (): void {
            static $lastKick = 0;
            $now = time();
            if ($now - $lastKick >= 60) {
                $lastKick = $now;
                Watchdog::kick();
            }
        });
    }
}
