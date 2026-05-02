<?php

declare(strict_types=1);

namespace Workflow\Providers;

use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\SerializableClosure\SerializableClosure;
use Workflow\Commands\ActivityMakeCommand;
use Workflow\Commands\V1ListCommand;
use Workflow\Commands\V2BackfillCommandContractsCommand;
use Workflow\Commands\V2DoctorCommand;
use Workflow\Commands\V2HistoryExportCommand;
use Workflow\Commands\V2RebuildProjectionsCommand;
use Workflow\Commands\V2RepairPassCommand;
use Workflow\Commands\V2ReplayVerifyCommand;
use Workflow\Commands\V2ScheduleTickCommand;
use Workflow\Commands\WorkflowMakeCommand;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Contracts\HistoryProjectionMaintenanceRole;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Contracts\LongPollWakeStore;
use Workflow\V2\Contracts\MatchingRole;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Contracts\SchedulerRole;
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
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\DefaultActivityTaskBridge;
use Workflow\V2\Support\DefaultHistoryProjectionRole;
use Workflow\V2\Support\DefaultMatchingRole;
use Workflow\V2\Support\DefaultOperatorObservabilityRepository;
use Workflow\V2\Support\DefaultSchedulerRole;
use Workflow\V2\Support\DefaultWorkflowControlPlane;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\HistoryProjectionMaintenanceFallback;
use Workflow\V2\Support\LongPollCacheValidator;
use Workflow\V2\Support\PhpClassScheduleStarter;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\WorkflowModeGuard;
use Workflow\Watchdog;

final class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/workflows.php', 'workflows');

        $this->app->singletonIf(
            OperatorObservabilityRepository::class,
            DefaultOperatorObservabilityRepository::class,
        );

        $this->app->singletonIf(MatchingRole::class, DefaultMatchingRole::class);

        $this->app->singletonIf(HistoryProjectionRole::class, DefaultHistoryProjectionRole::class);

        if (! $this->app->bound(HistoryProjectionMaintenanceRole::class)) {
            $this->app->singleton(
                HistoryProjectionMaintenanceRole::class,
                static function ($app): HistoryProjectionMaintenanceRole {
                    $role = $app->make(HistoryProjectionRole::class);

                    if ($role instanceof HistoryProjectionMaintenanceRole) {
                        return $role;
                    }

                    return new HistoryProjectionMaintenanceFallback(
                        $role,
                        new DefaultHistoryProjectionRole(),
                    );
                }
            );
        }

        $this->app->singleton(WorkflowTaskBridge::class, DefaultWorkflowTaskBridge::class);

        $this->app->singleton(ActivityTaskBridge::class, DefaultActivityTaskBridge::class);

        $this->app->singleton(WorkflowControlPlane::class, DefaultWorkflowControlPlane::class);

        $this->app->singletonIf(SchedulerRole::class, DefaultSchedulerRole::class);

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
            V2DoctorCommand::class,
            V2HistoryExportCommand::class,
            V2RepairPassCommand::class,
            V2RebuildProjectionsCommand::class,
            V2ReplayVerifyCommand::class,
            V2ScheduleTickCommand::class,
        ]);

        TypeRegistry::validateTypeMap();
        WorkflowModeGuard::check();
        ConfiguredV2Models::validateConfiguration();

        // Register long-poll wake signal observers
        $this->registerLongPollObservers();

        // Validate cache backend for multi-node deployments
        $this->validateCacheBackend();

        Event::listen(Looping::class, static function (Looping $event): void {
            Watchdog::wake($event->connectionName, $event->queue);

            if (config('workflows.v2.matching_role.queue_wake_enabled', true)) {
                app(MatchingRole::class)->wake($event->connectionName, $event->queue);
            }
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
     * The cache layer is the acceleration substrate, not the correctness
     * substrate, so this admission check is warning-only by contract: a
     * misconfiguration surfaces a diagnostic but never blocks boot.
     * `validation_mode` only chooses whether the diagnostic is logged
     * (`warn`, `fail`) or suppressed (`silent`).
     */
    private function validateCacheBackend(): void
    {
        $validateEnabled = (bool) config('workflows.v2.long_poll.validate_cache_backend', true);

        if (! $validateEnabled) {
            return;
        }

        $multiNode = (bool) config('workflows.v2.long_poll.multi_node', false);
        $validationMode = config('workflows.v2.long_poll.validation_mode', 'warn');

        // Resolve through the CacheManager so the validator inspects the
        // currently configured default store. Calling
        // make(CacheRepository::class) hits Laravel's cache.store singleton
        // binding, which would be resolved here at boot and then pinned for
        // the lifetime of the application — drifting from cache.default if
        // operators (or tests) reconfigure the default store after boot.
        $cache = $this->app->make('cache')->store();
        $validator = new LongPollCacheValidator();

        $result = $validator->checkMultiNodeSafety($cache, $multiNode);

        if (! $result['safe']) {
            if ($validationMode === 'silent') {
                return;
            }

            Log::warning(sprintf('[Workflow] Cache backend validation failed: %s', $result['message']));
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
