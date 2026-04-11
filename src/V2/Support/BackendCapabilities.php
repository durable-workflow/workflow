<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;

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
        $issues = array_values(array_merge($database['issues'], $queue['issues'], $cache['issues']));

        return [
            'generated_at' => $now->toJSON(),
            'supported' => self::hasErrors($issues) === false,
            'database' => $database,
            'queue' => $queue,
            'cache' => $cache,
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

        if ($connection === null || $driver === null) {
            $issues[] = self::issue(
                'queue',
                'error',
                'queue_connection_missing',
                'Workflow v2 requires a configured asynchronous queue connection for durable task delivery.',
            );
        } elseif ($driver === 'sync') {
            $issues[] = self::issue(
                'queue',
                'error',
                'queue_sync_unsupported',
                'Workflow v2 requires an asynchronous queue worker; the sync queue driver executes jobs inline and cannot provide the worker/lease boundary.',
            );
        }

        return [
            'connection' => $connection,
            'driver' => $driver,
            'supported' => self::hasErrors($issues) === false,
            'capabilities' => [
                'async_delivery' => $driver !== null && $driver !== 'sync',
                'delayed_delivery' => $driver !== null && $driver !== 'sync',
                'requires_worker' => true,
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
