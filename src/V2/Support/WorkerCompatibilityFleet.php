<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

final class WorkerCompatibilityFleet
{
    private const CACHE_KEY = 'workflow:v2:compatibility:fleet';

    private const LOCK_KEY = 'workflow:v2:compatibility:fleet:lock';

    private static ?string $workerId = null;

    public static function heartbeat(?string $connection = null, ?string $queue = null): void
    {
        self::record(
            WorkerCompatibility::supported(),
            $connection,
            $queue,
            self::workerId(),
        );
    }

    /**
     * @param list<string> $supported
     */
    public static function record(
        array $supported,
        ?string $connection = null,
        ?string $queue = null,
        ?string $workerId = null,
    ): void {
        $workerId ??= self::workerId();
        $supported = self::normalizeMarkers($supported);

        self::mutate(static function (array &$fleet) use ($connection, $queue, $supported, $workerId): void {
            $fleet[$workerId] = [
                'supported' => $supported,
                'connection' => self::normalizeValue($connection),
                'queues' => self::normalizeQueues($queue),
                'recorded_at' => now()->getTimestamp(),
                'expires_at' => now()->addSeconds(self::ttlSeconds())->getTimestamp(),
            ];
        });
    }

    public static function clear(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function supports(?string $required, ?string $connection = null, ?string $queue = null): bool
    {
        $required = self::normalizeValue($required);

        if ($required === null) {
            return true;
        }

        foreach (self::matchingSnapshots($connection, $queue) as $snapshot) {
            $supported = $snapshot['supported'] ?? [];

            if (! is_array($supported)) {
                continue;
            }

            if (in_array('*', $supported, true) || in_array($required, $supported, true)) {
                return true;
            }
        }

        return false;
    }

    public static function mismatchReason(?string $required, ?string $connection = null, ?string $queue = null): ?string
    {
        $required = self::normalizeValue($required);

        if ($required === null || self::supports($required, $connection, $queue)) {
            return null;
        }

        $advertised = self::advertisedMarkers($connection, $queue);
        $reason = sprintf(
            'No active worker heartbeat%s advertises compatibility [%s].',
            self::scopeLabel($connection, $queue),
            $required,
        );

        if ($advertised === []) {
            return $reason;
        }

        return sprintf(
            '%s Active workers there advertise [%s].',
            $reason,
            implode(', ', $advertised),
        );
    }

    private static function workerId(): string
    {
        return self::$workerId ??= sprintf(
            '%s:%s:%s',
            gethostname() ?: 'unknown-host',
            (string) getmypid(),
            (string) Str::ulid(),
        );
    }

    private static function ttlSeconds(): int
    {
        return max(
            1,
            (int) config('workflows.v2.compatibility.heartbeat_ttl_seconds', 30)
        );
    }

    /**
     * @return list<array{supported: list<string>, connection: ?string, queues: list<string>, recorded_at: int, expires_at: int}>
     */
    private static function matchingSnapshots(?string $connection = null, ?string $queue = null): array
    {
        $requiredConnection = self::normalizeValue($connection);
        $requiredQueue = self::normalizeValue($queue);

        return array_values(array_filter(
            self::snapshots(),
            static function (array $snapshot) use ($requiredConnection, $requiredQueue): bool {
                $snapshotConnection = self::normalizeValue($snapshot['connection'] ?? null);

                if ($requiredConnection !== null && $snapshotConnection !== null && $snapshotConnection !== $requiredConnection) {
                    return false;
                }

                $queues = self::normalizeMarkers($snapshot['queues'] ?? []);

                if ($requiredQueue !== null && $queues !== [] && ! in_array($requiredQueue, $queues, true)) {
                    return false;
                }

                return true;
            }
        ));
    }

    /**
     * @return list<string>
     */
    private static function advertisedMarkers(?string $connection = null, ?string $queue = null): array
    {
        $markers = [];

        foreach (self::matchingSnapshots($connection, $queue) as $snapshot) {
            foreach (self::normalizeMarkers($snapshot['supported'] ?? []) as $marker) {
                $markers[] = $marker;
            }
        }

        $markers = array_values(array_unique($markers));
        sort($markers);

        return $markers;
    }

    /**
     * @return array<string, array{supported: list<string>, connection: ?string, queues: list<string>, recorded_at: int, expires_at: int}>
     */
    private static function snapshots(): array
    {
        return self::mutate(static fn (array &$fleet): array => $fleet);
    }

    /**
     * @template T
     *
     * @param  callable(array<string, array{supported: list<string>, connection: ?string, queues: list<string>, recorded_at: int, expires_at: int}> &$fleet): T  $callback
     * @return T
     */
    private static function mutate(callable $callback): mixed
    {
        try {
            return Cache::lock(self::LOCK_KEY, 3)->block(1, static function () use ($callback): mixed {
                $fleet = self::prune(self::cachedFleet());
                $result = $callback($fleet);

                Cache::forever(self::CACHE_KEY, $fleet);

                return $result;
            });
        } catch (Throwable) {
            $fleet = self::prune(self::cachedFleet());
            $result = $callback($fleet);

            Cache::forever(self::CACHE_KEY, $fleet);

            return $result;
        }
    }

    /**
     * @return array<string, array{supported: list<string>, connection: ?string, queues: list<string>, recorded_at: int, expires_at: int}>
     */
    private static function cachedFleet(): array
    {
        $fleet = Cache::get(self::CACHE_KEY);

        return is_array($fleet) ? $fleet : [];
    }

    /**
     * @param  array<string, array{supported: list<string>, connection: ?string, queues: list<string>, recorded_at: int, expires_at: int}>  $fleet
     * @return array<string, array{supported: list<string>, connection: ?string, queues: list<string>, recorded_at: int, expires_at: int}>
     */
    private static function prune(array $fleet): array
    {
        $now = now()->getTimestamp();

        return array_filter(
            $fleet,
            static fn (array $snapshot): bool => (int) ($snapshot['expires_at'] ?? 0) >= $now
        );
    }

    private static function scopeLabel(?string $connection, ?string $queue): string
    {
        $parts = [];
        $connection = self::normalizeValue($connection);
        $queue = self::normalizeValue($queue);

        if ($connection !== null) {
            $parts[] = sprintf('connection [%s]', $connection);
        }

        if ($queue !== null) {
            $parts[] = sprintf('queue [%s]', $queue);
        }

        if ($parts === []) {
            return '';
        }

        return ' for ' . implode(' ', $parts);
    }

    private static function normalizeValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    private static function normalizeMarkers(mixed $values): array
    {
        if (is_string($values)) {
            $values = explode(',', $values);
        }

        if (! is_array($values)) {
            return [];
        }

        $normalized = array_map(static fn (mixed $value): ?string => self::normalizeValue($value), $values);

        return array_values(array_unique(array_filter(
            $normalized,
            static fn (?string $value): bool => $value !== null,
        )));
    }

    /**
     * @return list<string>
     */
    private static function normalizeQueues(?string $queue): array
    {
        if ($queue === null) {
            return [];
        }

        return self::normalizeMarkers(explode(',', $queue));
    }
}
