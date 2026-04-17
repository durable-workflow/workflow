<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Workflow\Serializers\CodecRegistry;

final class BackendCapabilities
{
    /**
     * @return array<string, mixed>
     */
    public static function snapshot(
        ?CarbonInterface $now = null,
        ?string $databaseConnection = null,
        ?string $queueConnection = null,
        ?string $cacheStore = null,
    ): array {
        $now ??= now();

        $database = self::database($databaseConnection);
        $queue = self::queue($queueConnection);
        $cache = self::cache($cacheStore);
        $codec = self::codec();
        $limits = self::structuralLimits($database, $queue);
        $issues = array_values(array_merge(
            $database['issues'],
            $queue['issues'],
            $cache['issues'],
            $codec['issues'],
            $limits['issues'],
        ));

        return [
            'generated_at' => $now->toJSON(),
            'supported' => self::hasErrors($issues) === false,
            'database' => $database,
            'queue' => $queue,
            'cache' => $cache,
            'codec' => $codec,
            'structural_limits' => $limits,
            'issues' => $issues,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function isSupported(array $snapshot): bool
    {
        return ($snapshot['supported'] ?? false) === true && self::hasErrors($snapshot['issues'] ?? []) === false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function database(?string $configuredConnection = null): array
    {
        $connection = self::normalize($configuredConnection) ?? self::normalize(config('database.default'));
        $driver = $connection === null
            ? null
            : self::normalize(config(sprintf('database.connections.%s.driver', $connection)));
        $issues = [];

        $driverSupported = is_string($driver) && in_array($driver, ['mysql', 'pgsql', 'sqlite', 'sqlsrv'], true);

        if ($connection === null || $driver === null) {
            $issues[] = self::issue(
                'database',
                'error',
                'database_connection_missing',
                'Workflow v2 requires a configured database connection for durable history, projections, and task leases.',
            );
        } elseif (! $driverSupported) {
            $issues[] = self::issue(
                'database',
                'error',
                'database_driver_unsupported',
                sprintf(
                    'Workflow v2 does not have an explicit capability profile for the [%s] database driver.',
                    $driver
                ),
            );
        }

        return [
            'connection' => $connection,
            'driver' => $driver,
            'supported' => self::hasErrors($issues) === false,
            'capabilities' => [
                'transactions' => $driverSupported,
                'after_commit_callbacks' => $driverSupported,
                'durable_ordering' => $driverSupported,
                'row_locks' => $driverSupported && $driver !== 'sqlite',
            ],
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function queue(?string $configuredConnection = null): array
    {
        $connection = self::normalize($configuredConnection) ?? self::normalize(config('queue.default'));
        $driver = $connection === null
            ? null
            : self::normalize(config(sprintf('queue.connections.%s.driver', $connection)));
        $issues = [];

        // In poll mode, tasks are delivered to workers via HTTP poll rather than
        // through a Laravel queue, so a sync or missing queue driver is an
        // acceptable configuration — the queue backend is simply unused for
        // task delivery. We still surface an informational note so operators
        // can see what will happen if they later flip back to queue mode.
        $pollMode = self::normalize(config('workflows.v2.task_dispatch_mode')) === 'poll';

        if ($connection === null || $driver === null) {
            $issues[] = self::issue(
                'queue',
                $pollMode ? 'info' : 'error',
                'queue_connection_missing',
                $pollMode
                    ? 'No queue connection is configured; task dispatch runs in poll mode so workers receive tasks over HTTP instead of a queue worker.'
                    : 'Workflow v2 requires a configured asynchronous queue connection for durable task delivery.',
            );
        } elseif ($driver === 'sync') {
            $issues[] = self::issue(
                'queue',
                $pollMode ? 'info' : 'error',
                'queue_sync_unsupported',
                $pollMode
                    ? 'Queue driver is [sync], which is acceptable in poll mode because tasks are delivered over HTTP instead of a queue worker.'
                    : 'Workflow v2 requires an asynchronous queue worker; the sync queue driver executes jobs inline and cannot provide the worker/lease boundary.',
            );
        }

        return [
            'connection' => $connection,
            'driver' => $driver,
            'supported' => self::hasErrors($issues) === false,
            'capabilities' => [
                'async_delivery' => $driver !== null && $driver !== 'sync',
                'delayed_delivery' => $driver !== null && $driver !== 'sync',
                'requires_worker' => ! $pollMode,
                'max_delay_seconds' => $driver === 'sqs' ? 900 : null,
                'after_commit_option' => $connection === null ? null : config(
                    sprintf('queue.connections.%s.after_commit', $connection)
                ),
            ],
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function cache(?string $configuredStore = null): array
    {
        $store = self::normalize($configuredStore) ?? self::normalize(
            config('cache.default') ?? config('cache.driver')
        );
        $driver = $store === null
            ? null
            : self::normalize(config(sprintf('cache.stores.%s.driver', $store)));
        $lockSupported = $store !== null && self::cacheStoreSupportsLocks($store);
        $issues = [];

        if ($store === null || $driver === null) {
            $issues[] = self::issue(
                'cache',
                'error',
                'cache_store_missing',
                'Workflow v2 requires a configured cache store for worker-loop throttles and compatibility heartbeat fallbacks.',
            );
        } elseif (! $lockSupported) {
            $issues[] = self::issue(
                'cache',
                'error',
                'cache_locks_unsupported',
                sprintf('The [%s] cache store does not advertise Laravel atomic lock support.', $store),
            );
        }

        return [
            'store' => $store,
            'driver' => $driver,
            'supported' => self::hasErrors($issues) === false,
            'capabilities' => [
                'atomic_locks' => $lockSupported,
            ],
            'issues' => $issues,
        ];
    }

    /**
     * Publish the structural-limit contract adjusted for the current backend.
     *
     * Most limits are backend-independent (they are pure config values). The
     * queue driver, however, may impose additional ceiling constraints — for
     * example, SQS caps delayed delivery at 900 seconds, which affects the
     * maximum timer delay that can be expressed in a single queue message.
     *
     * @param array<string, mixed> $database
     * @param array<string, mixed> $queue
     * @return array<string, mixed>
     */
    private static function structuralLimits(array $database, array $queue): array
    {
        $configured = StructuralLimits::snapshot();
        $issues = [];

        $queueDriver = $queue['driver'] ?? null;
        $maxQueueDelay = $queue['capabilities']['max_delay_seconds'] ?? null;

        $backendAdjustments = [];

        if (is_int($maxQueueDelay) && $maxQueueDelay > 0) {
            $backendAdjustments['max_single_timer_delay_seconds'] = $maxQueueDelay;

            $issues[] = self::issue(
                'structural_limits',
                'info',
                'queue_max_delay_constraint',
                sprintf(
                    'The [%s] queue driver limits delayed dispatch to %d seconds; timers exceeding this are chunked by the transport layer.',
                    $queueDriver ?? 'unknown',
                    $maxQueueDelay,
                ),
            );
        }

        $dbDriver = $database['driver'] ?? null;

        if ($dbDriver === 'sqlite') {
            $backendAdjustments['concurrent_write_safety'] = 'limited';

            $issues[] = self::issue(
                'structural_limits',
                'info',
                'sqlite_concurrency_note',
                'SQLite serializes writes; high pending-count limits may cause lock contention under concurrent worker load.',
            );
        }

        return [
            'configured' => $configured,
            'backend_adjustments' => $backendAdjustments,
            'effective' => array_merge($configured, $backendAdjustments),
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function codec(): array
    {
        $configured = config('workflows.serializer');
        $issues = [];

        $canonical = null;
        if (is_string($configured) && $configured !== '') {
            try {
                $canonical = CodecRegistry::canonicalize($configured);
            } catch (\InvalidArgumentException) {
                $universalList = implode('", "', CodecRegistry::universal());
                $issues[] = self::issue(
                    'codec',
                    'error',
                    'codec_unknown',
                    sprintf(
                        'The configured workflows.serializer [%s] is not a known payload codec. '
                        .'v2 does not support custom serializer classes — only the built-in codecs '
                        .'("%s") and the legacy PHP-only codecs ("workflow-serializer-y", "workflow-serializer-base64") '
                        .'are resolvable. '
                        .'To migrate a v1 deployment that used a custom serializer, choose one of: '
                        .'(a) set workflows.serializer to "avro" or "json" for new runs and accept that '
                        .'old history written under the custom codec becomes unreadable, '
                        .'(b) keep the custom serializer classes loaded and re-encode old history to a '
                        .'supported codec before upgrading, '
                        .'(c) stay on v1 until the old runs drain. '
                        .'Meanwhile default-codec resolution silently falls back to "avro" so new runs '
                        .'still encode successfully — but the configured value is never consulted.',
                        $configured,
                        $universalList,
                    ),
                );
            }
        } else {
            $canonical = CodecRegistry::defaultCodec();
        }

        $universal = CodecRegistry::universal();
        $isUniversal = $canonical !== null && in_array($canonical, $universal, true);

        if ($canonical !== null && ! $isUniversal) {
            $issues[] = self::issue(
                'codec',
                'warning',
                'codec_legacy_php_only',
                sprintf(
                    'workflows.serializer is set to [%s], a PHP-only legacy codec. New v2 workflows written with this codec cannot be decoded by Python, Go, or TypeScript workers. Set workflows.serializer to "json" for polyglot compatibility; keep the legacy codec only if you still need to read v1 history with it.',
                    $canonical,
                ),
            );
        }

        return [
            'configured' => $configured,
            'canonical' => $canonical,
            'universal' => $isUniversal,
            'supported' => self::hasErrors($issues) === false,
            'issues' => $issues,
        ];
    }

    private static function cacheStoreSupportsLocks(string $store): bool
    {
        try {
            return Cache::store($store)->getStore() instanceof LockProvider;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function hasErrors(mixed $issues): bool
    {
        if (! is_array($issues)) {
            return true;
        }

        foreach ($issues as $issue) {
            if (is_array($issue) && ($issue['severity'] ?? null) === 'error') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{component: string, severity: string, code: string, message: string}
     */
    private static function issue(string $component, string $severity, string $code, string $message): array
    {
        return [
            'component' => $component,
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
        ];
    }

    private static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
