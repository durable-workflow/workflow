<?php

declare(strict_types=1);

namespace Workflow\Providers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\SerializableClosure\SerializableClosure;
use Workflow\Commands\ActivityMakeCommand;
use Workflow\Commands\V1ListCommand;
use Workflow\Commands\V2BackfillCommandContractsCommand;
use Workflow\Commands\V2BackfillFailureCategoriesCommand;
use Workflow\Commands\V2BackfillFailureTypesCommand;
use Workflow\Commands\V2BackfillParallelGroupMetadataCommand;
use Workflow\Commands\V2DoctorCommand;
use Workflow\Commands\V2HistoryExportCommand;
use Workflow\Commands\V2RebuildProjectionsCommand;
use Workflow\Commands\V2RepairPassCommand;
use Workflow\Commands\V2ScheduleTickCommand;
use Workflow\Commands\WorkflowMakeCommand;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Contracts\LongPollWakeStore;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Contracts\ScheduleWorkflowStarter;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Observers\WorkflowHistoryEventObserver;
use Workflow\V2\Observers\WorkflowLinkObserver;
use Workflow\V2\Observers\WorkflowRunLineageEntryObserver;
use Workflow\V2\Observers\WorkflowTaskObserver;
use Workflow\V2\Support\CacheLongPollWakeStore;
use Workflow\V2\Support\DefaultActivityTaskBridge;
use Workflow\V2\Support\DefaultOperatorObservabilityRepository;
use Workflow\V2\Support\DefaultWorkflowControlPlane;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\LongPollCacheValidator;
use Workflow\V2\Support\PhpClassScheduleStarter;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\WorkflowModeGuard;
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

        $this->app->singleton(WorkflowControlPlane::class, DefaultWorkflowControlPlane::class);

        $this->app->singleton(ScheduleWorkflowStarter::class, PhpClassScheduleStarter::class);

        // Register default LongPollWakeStore implementation if not already bound
        if (! $this->app->bound(LongPollWakeStore::class)) {
            $this->app->singleton(LongPollWakeStore::class, CacheLongPollWakeStore::class);
        }
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
            V1ListCommand::class,
            V2BackfillCommandContractsCommand::class,
            V2BackfillFailureCategoriesCommand::class,
            V2BackfillFailureTypesCommand::class,
            V2BackfillParallelGroupMetadataCommand::class,
            V2DoctorCommand::class,
            V2HistoryExportCommand::class,
            V2RepairPassCommand::class,
            V2RebuildProjectionsCommand::class,
            V2ScheduleTickCommand::class,
        ]);

        TypeRegistry::validateTypeMap();
        WorkflowModeGuard::check();

        // Register long-poll wake signal observers
        $this->registerLongPollObservers();

        // Validate cache backend for multi-node deployments
        $this->validateCacheBackend();

        Event::listen(Looping::class, static function (Looping $event): void {
            Watchdog::wake($event->connectionName, $event->queue);
            TaskWatchdog::wake($event->connectionName, $event->queue);
        });
    }

    /**
     * Register observers for long-poll wake signals.
     */
    private function registerLongPollObservers(): void
    {
        // Register projection and long-poll wake observers.
        // Note: Laravel's observe() is idempotent - calling it multiple times is safe
        WorkflowLink::observe(WorkflowLinkObserver::class);
        WorkflowRunLineageEntry::observe(WorkflowRunLineageEntryObserver::class);
        WorkflowTask::observe(WorkflowTaskObserver::class);
        WorkflowHistoryEvent::observe(WorkflowHistoryEventObserver::class);
    }

    /**
     * Validate cache backend for multi-node deployments.
     *
     * Checks if cache backend supports cross-node coordination when
     * multi_node is enabled. Behavior controlled by validation_mode:
     * - 'fail': throw exception
     * - 'warn': log warning
     * - 'silent': no action
     */
    private function validateCacheBackend(): void
    {
        $validateEnabled = (bool) config('workflows.v2.long_poll.validate_cache_backend', true);

        if (! $validateEnabled) {
            return;
        }

        $multiNode = (bool) config('workflows.v2.long_poll.multi_node', false);
        $validationMode = config('workflows.v2.long_poll.validation_mode', 'warn');

        $cache = $this->app->make(CacheRepository::class);
        $validator = new LongPollCacheValidator;

        $result = $validator->checkMultiNodeSafety($cache, $multiNode);

        if (! $result['safe']) {
            $message = sprintf(
                '[Workflow] Cache backend validation failed: %s',
                $result['message']
            );

            match ($validationMode) {
                'fail' => throw new \RuntimeException($message),
                'warn' => Log::warning($message),
                default => null, // 'silent' or unknown mode
            };
        } else {
            $validation = $validator->validateMultiNodeCapable($cache);
            Log::info(sprintf(
                '[Workflow] Cache backend validation passed: backend=%s, multi_node=%s',
                $validation['backend'],
                $multiNode ? 'true' : 'false'
            ));
        }
    }
}
