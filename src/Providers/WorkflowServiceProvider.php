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
use Workflow\Commands\V2HistoryImportCommand;
use Workflow\Commands\V2NamespaceConformanceCommand;
use Workflow\Commands\V2RebuildProjectionsCommand;
use Workflow\Commands\V2RepairPassCommand;
use Workflow\Commands\V2ReplayConformanceCommand;
use Workflow\Commands\V2ReplaySimulateCommand;
use Workflow\Commands\V2ReplayVerifyCommand;
use Workflow\Commands\V2ScheduleConformanceCommand;
use Workflow\Commands\V2ScheduleTickCommand;
use Workflow\Commands\V2SearchAttributesConformanceCommand;
use Workflow\Commands\V2WorkflowUpdatesConformanceCommand;
use Workflow\Commands\WorkflowMakeCommand;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Contracts\HistoryProjectionMaintenanceRole;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Contracts\LongPollWakeStore;
use Workflow\V2\Contracts\MatchingRole;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Contracts\SchedulerRole;
use Workflow\V2\Contracts\ScheduleWorkflowStarter;
use Workflow\V2\Contracts\ServiceBoundaryPolicy;
use Workflow\V2\Contracts\ServiceControlPlane;
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
use Workflow\V2\Support\DefaultServiceBoundaryPolicy;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\DefaultWorkflowControlPlane;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\HistoryProjectionMaintenanceFallback;
use Workflow\V2\Support\InMemoryTaskFairnessState;
use Workflow\V2\Support\LongPollCacheValidator;
use Workflow\V2\Support\PhpClassScheduleStarter;
use Workflow\V2\Support\TaskFairnessState;
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

        $this->app->singletonIf(ServiceControlPlane::class, DefaultServiceControlPlane::class);

        $this->app->singletonIf(SchedulerRole::class, DefaultSchedulerRole::class);

        $this->app->singleton(ScheduleWorkflowStarter::class, PhpClassScheduleStarter::class);

        $this->app->singletonIf(ServiceBoundaryPolicy::class, static function ($app): ServiceBoundaryPolicy {
            $rules = $app['config']->get('workflows.v2.service_boundary.rules', []);

            return new DefaultServiceBoundaryPolicy(is_array($rules) ? $rules : []);
        });

        // Register default LongPollWakeStore implementation if not already bound
        if (! $this->app->bound(LongPollWakeStore::class)) {
            $this->app->singleton(LongPollWakeStore::class, CacheLongPollWakeStore::class);
        }

        $this->app->singletonIf(TaskFairnessState::class, static function (): TaskFairnessState {
            $halfLife = (float) config('workflows.v2.fairness.half_life_seconds', 30.0);

            return new InMemoryTaskFairnessState($halfLife > 0.0 ? $halfLife : 30.0);
        });
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
            V2HistoryImportCommand::class,
            V2NamespaceConformanceCommand::class,
            V2RepairPassCommand::class,
            V2RebuildProjectionsCommand::class,
            V2ReplayConformanceCommand::class,
            V2ReplaySimulateCommand::class,
            V2ReplayVerifyCommand::class,
            V2ScheduleConformanceCommand::class,
            V2ScheduleTickCommand::class,
            V2SearchAttributesConformanceCommand::class,
            V2WorkflowUpdatesConformanceCommand::class,
        ]);

        TypeRegistry::validateTypeMap();
        WorkflowModeGuard::check();
        ConfiguredV2Models::validateConfiguration();
        $this->configureSqliteStorageConnection();

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
        }
    }

    private function configureSqliteStorageConnection(): void
    {
        $timeoutMilliseconds = (int) config('workflows.storage.sqlite_busy_timeout_ms', 5000);

        if ($timeoutMilliseconds <= 0) {
            return;
        }

        $connectionName = config('workflows.storage.connection') ?? config('database.default');

        if (! is_string($connectionName) || $connectionName === '') {
            return;
        }

        if (config("database.connections.{$connectionName}.driver") !== 'sqlite') {
            return;
        }

        $busyTimeoutKey = "database.connections.{$connectionName}.busy_timeout";

        if (config($busyTimeoutKey) === null) {
            config()->set($busyTimeoutKey, $timeoutMilliseconds);
        }

        $optionsKey = "database.connections.{$connectionName}.options";
        $options = config($optionsKey, []);
        $options = is_array($options) ? $options : [];

        if (! array_key_exists(\PDO::ATTR_TIMEOUT, $options)) {
            $options[\PDO::ATTR_TIMEOUT] = max(1, (int) ceil($timeoutMilliseconds / 1000));
            config()->set($optionsKey, $options);
        }

        $this->applySqliteBusyTimeoutToOpenConnection($connectionName, $timeoutMilliseconds);
    }

    private function applySqliteBusyTimeoutToOpenConnection(string $connectionName, int $timeoutMilliseconds): void
    {
        $database = $this->app->bound('db') ? $this->app->make('db') : null;

        if (! is_object($database) || ! method_exists($database, 'getConnections')) {
            return;
        }

        /** @var array<string, \Illuminate\Database\Connection> $connections */
        $connections = $database->getConnections();
        $connection = $connections[$connectionName] ?? null;

        if ($connection === null) {
            return;
        }

        try {
            if ($connection->getDriverName() === 'sqlite') {
                $connection->statement('PRAGMA busy_timeout = ' . $timeoutMilliseconds);
            }
        } catch (\Throwable) {
            // The config mutation above still protects connections opened after
            // boot; never make package boot fail while only hardening SQLite.
        }
    }
}
