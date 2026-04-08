<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Workflow\V2\Models\WorkerCompatibilityHeartbeat;

final class WorkerCompatibilityFleet
{
    /**
     * @var array<string, array{supported: list<string>, connection: ?string, queues: list<string>, recorded_at: int}>
     */
    private static array $lastRecorded = [];

    /**
     * @var list<array{worker_id: string, host: ?string, process_id: ?string, connection: ?string, queue: ?string, supported: list<string>, recorded_at: \Illuminate\Support\Carbon|null, expires_at: \Illuminate\Support\Carbon|null}>|null
     */
    private static ?array $snapshotCache = null;

    private static ?int $snapshotCacheSecond = null;

    private static ?int $lastPrunedAt = null;

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
        $connection = self::normalizeValue($connection);
        $queues = self::normalizeQueues($queue);

        if (self::shouldSkipRecord($workerId, $supported, $connection, $queues)) {
            return;
        }

        self::pruneExpired();

        $now = now();
        $expiresAt = $now->copy()->addSeconds(self::ttlSeconds());
        $rows = collect($queues === [] ? [null] : $queues)
            ->map(static function (?string $scopeQueue) use (
                $workerId,
                $connection,
                $supported,
                $now,
                $expiresAt,
            ): array {
                return [
                    'worker_id' => $workerId,
                    'scope_key' => self::scopeKey($connection, $scopeQueue),
                    'host' => self::hostName(),
                    'process_id' => self::processId(),
                    'connection' => $connection,
                    'queue' => $scopeQueue,
                    'supported' => json_encode($supported),
                    'recorded_at' => $now,
                    'expires_at' => $expiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->all();

        DB::transaction(static function () use ($workerId, $rows): void {
            WorkerCompatibilityHeartbeat::query()
                ->where('worker_id', $workerId)
                ->delete();

            WorkerCompatibilityHeartbeat::query()
                ->insert($rows);
        });

        self::$lastRecorded[$workerId] = [
            'supported' => $supported,
            'connection' => $connection,
            'queues' => $queues,
            'recorded_at' => $now->getTimestamp(),
        ];
        self::forgetSnapshotCache();
    }

    public static function details(?string $required, ?string $connection = null, ?string $queue = null): array
    {
        $required = self::normalizeValue($required);

        return array_map(
            static function (array $snapshot) use ($required): array {
                $supported = self::normalizeMarkers($snapshot['supported'] ?? []);

                return [
                    'worker_id' => $snapshot['worker_id'],
                    'host' => $snapshot['host'],
                    'process_id' => $snapshot['process_id'],
                    'connection' => $snapshot['connection'],
                    'queue' => $snapshot['queue'],
                    'supported' => $supported,
                    'supports_required' => $required === null
                        || in_array('*', $supported, true)
                        || in_array($required, $supported, true),
                    'recorded_at' => $snapshot['recorded_at'],
                    'expires_at' => $snapshot['expires_at'],
                ];
            },
            self::matchingSnapshots($connection, $queue),
        );
    }

    public static function clear(): void
    {
        WorkerCompatibilityHeartbeat::query()->delete();
        self::$lastRecorded = [];
        self::$lastPrunedAt = null;
        self::forgetSnapshotCache();
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

    private static function shouldSkipRecord(
        string $workerId,
        array $supported,
        ?string $connection,
        array $queues,
    ): bool {
        $last = self::$lastRecorded[$workerId] ?? null;

        if ($last === null) {
            return false;
        }

        if (
            $last['supported'] !== $supported
            || $last['connection'] !== $connection
            || $last['queues'] !== $queues
        ) {
            return false;
        }

        return now()->getTimestamp() - $last['recorded_at'] < self::writeIntervalSeconds();
    }

    private static function writeIntervalSeconds(): int
    {
        return max(1, (int) floor(self::ttlSeconds() / 3));
    }

    private static function pruneExpired(): void
    {
        $now = now()->getTimestamp();

        if (self::$lastPrunedAt !== null && self::$lastPrunedAt >= $now - self::writeIntervalSeconds()) {
            return;
        }

        WorkerCompatibilityHeartbeat::query()
            ->where('expires_at', '<', now())
            ->delete();

        self::$lastPrunedAt = $now;
        self::forgetSnapshotCache();
    }

    private static function activeSnapshots(): array
    {
        $nowSecond = now()->getTimestamp();

        if (self::$snapshotCache !== null && self::$snapshotCacheSecond === $nowSecond) {
            return self::$snapshotCache;
        }

        self::pruneExpired();

        self::$snapshotCache = WorkerCompatibilityHeartbeat::query()
            ->where('expires_at', '>=', now())
            ->orderBy('connection')
            ->orderBy('queue')
            ->orderBy('worker_id')
            ->get()
            ->map(static function (WorkerCompatibilityHeartbeat $snapshot): array {
                return [
                    'worker_id' => (string) $snapshot->worker_id,
                    'host' => self::normalizeValue($snapshot->host),
                    'process_id' => self::normalizeValue($snapshot->process_id),
                    'connection' => self::normalizeValue($snapshot->connection),
                    'queue' => self::normalizeValue($snapshot->queue),
                    'supported' => self::normalizeMarkers($snapshot->supported),
                    'recorded_at' => $snapshot->recorded_at,
                    'expires_at' => $snapshot->expires_at,
                ];
            })
            ->values()
            ->all();
        self::$snapshotCacheSecond = $nowSecond;

        return self::$snapshotCache;
    }

    private static function forgetSnapshotCache(): void
    {
        self::$snapshotCache = null;
        self::$snapshotCacheSecond = null;
    }

    private static function hostName(): ?string
    {
        return self::normalizeValue(gethostname() ?: null);
    }

    private static function processId(): ?string
    {
        $pid = getmypid();

        if (! is_int($pid) || $pid <= 0) {
            return null;
        }

        return (string) $pid;
    }

    private static function scopeKey(?string $connection, ?string $queue): string
    {
        return hash('sha256', json_encode([
            'connection' => $connection,
            'queue' => $queue,
        ]));
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
     * @return list<array{worker_id: string, host: ?string, process_id: ?string, connection: ?string, queue: ?string, supported: list<string>, recorded_at: \Illuminate\Support\Carbon|null, expires_at: \Illuminate\Support\Carbon|null}>
     */
    private static function matchingSnapshots(?string $connection = null, ?string $queue = null): array
    {
        $requiredConnection = self::normalizeValue($connection);
        $requiredQueue = self::normalizeValue($queue);

        return array_values(array_filter(
            self::activeSnapshots(),
            static function (array $snapshot) use ($requiredConnection, $requiredQueue): bool {
                $snapshotConnection = self::normalizeValue($snapshot['connection'] ?? null);

                if ($requiredConnection !== null && $snapshotConnection !== null && $snapshotConnection !== $requiredConnection) {
                    return false;
                }

                $snapshotQueue = self::normalizeValue($snapshot['queue'] ?? null);

                if ($requiredQueue !== null && $snapshotQueue !== null && $snapshotQueue !== $requiredQueue) {
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
