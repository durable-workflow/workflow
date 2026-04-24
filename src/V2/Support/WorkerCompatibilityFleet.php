<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;
use Workflow\V2\Models\WorkerCompatibilityHeartbeat;

/**
 * Tracks the fleet of worker compatibility heartbeats used by fleet and
 * queue visibility APIs to determine which protocol versions are currently
 * live against a given namespace/queue.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 *      The public static method signatures on this class are covered by
 *      the workflow package's semver guarantee. See docs/api-stability.md.
 */
final class WorkerCompatibilityFleet
{
    private const LEGACY_CACHE_KEY = 'workflow:v2:compatibility:fleet';

    private const SOURCE_DATABASE = 'database';

    private const SOURCE_CACHE = 'cache';

    /**
     * @var array<string, array{supported: list<string>, namespace: ?string, connection: ?string, queues: list<string>, source: string, recorded_at: int}>
     */
    private static array $lastRecorded = [];

    /**
     * @var list<array{worker_id: string, namespace: ?string, host: ?string, process_id: ?string, connection: ?string, queue: ?string, supported: list<string>, recorded_at: \Illuminate\Support\Carbon|null, expires_at: \Illuminate\Support\Carbon|null, source: string}>|null
     */
    private static ?array $snapshotCache = null;

    private static ?int $snapshotCacheSecond = null;

    private static ?int $lastPrunedAt = null;

    private static ?string $workerId = null;

    public static function heartbeat(?string $connection = null, ?string $queue = null): void
    {
        self::record(WorkerCompatibility::supported(), $connection, $queue, self::workerId());
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
        self::recordInNamespace(self::scopeNamespace(), $supported, $connection, $queue, $workerId);
    }

    /**
     * @param list<string> $supported
     */
    public static function recordForNamespace(
        string $namespace,
        array $supported,
        ?string $connection = null,
        ?string $queue = null,
        ?string $workerId = null,
    ): void {
        self::recordInNamespace(self::normalizeValue($namespace), $supported, $connection, $queue, $workerId);
    }

    /**
     * @return list<array{
     *     worker_id: string,
     *     namespace: string|null,
     *     host: string|null,
     *     process_id: string|null,
     *     connection: string|null,
     *     queue: string|null,
     *     supported: list<string>,
     *     supports_required: bool,
     *     recorded_at: \Illuminate\Support\Carbon|null,
     *     expires_at: \Illuminate\Support\Carbon|null,
     *     source: string
     * }>
     */
    public static function detailsForNamespace(
        string $namespace,
        ?string $required,
        ?string $connection = null,
        ?string $queue = null,
    ): array {
        return self::detailsInNamespace(self::normalizeValue($namespace), $required, $connection, $queue);
    }

    /**
     * @return array{
     *     namespace: string,
     *     active_workers: int,
     *     active_worker_scopes: int,
     *     queues: list<string>,
     *     build_ids: list<string>,
     *     workers: list<array{
     *         worker_id: string,
     *         queues: list<string>,
     *         build_ids: list<string>,
     *         recorded_at: string|null,
     *         expires_at: string|null
     *     }>
     * }
     */
    public static function summaryForNamespace(string $namespace): array
    {
        $namespace = self::normalizeValue($namespace) ?? $namespace;
        $details = self::detailsForNamespace($namespace, null);
        $workerSnapshots = [];

        foreach ($details as $snapshot) {
            $workerId = self::normalizeValue($snapshot['worker_id'] ?? null);

            if ($workerId === null) {
                continue;
            }

            $workerSnapshots[$workerId] ??= [
                'worker_id' => $workerId,
                'queues' => [],
                'build_ids' => [],
                'recorded_at' => null,
                'expires_at' => null,
            ];
            $workerSnapshots[$workerId]['queues'][] = $snapshot['queue'] ?? null;
            $workerSnapshots[$workerId]['build_ids'] = array_merge(
                $workerSnapshots[$workerId]['build_ids'],
                is_array($snapshot['supported'] ?? null) ? $snapshot['supported'] : [],
            );
            $workerSnapshots[$workerId]['recorded_at'] = self::latestCarbon(
                $workerSnapshots[$workerId]['recorded_at'],
                $snapshot['recorded_at'] ?? null,
            );
            $workerSnapshots[$workerId]['expires_at'] = self::latestCarbon(
                $workerSnapshots[$workerId]['expires_at'],
                $snapshot['expires_at'] ?? null,
            );
        }

        ksort($workerSnapshots);

        $workers = array_map(
            static fn (array $worker): array => [
                'worker_id' => $worker['worker_id'],
                'queues' => self::uniqueStrings($worker['queues']),
                'build_ids' => self::uniqueStrings($worker['build_ids']),
                'recorded_at' => $worker['recorded_at']?->toJSON(),
                'expires_at' => $worker['expires_at']?->toJSON(),
            ],
            array_values($workerSnapshots),
        );

        return [
            'namespace' => $namespace,
            'active_workers' => count($workers),
            'active_worker_scopes' => count($details),
            'queues' => self::uniqueStrings(array_map(
                static fn (array $snapshot): mixed => $snapshot['queue'] ?? null,
                $details,
            )),
            'build_ids' => self::uniqueStrings($details === []
                ? []
                : array_merge(
                    ...array_map(
                        static fn (array $snapshot): array => is_array($snapshot['supported'] ?? null)
                            ? $snapshot['supported']
                            : [],
                        $details,
                    ),
                )),
            'workers' => $workers,
        ];
    }

    public static function details(?string $required, ?string $connection = null, ?string $queue = null): array
    {
        return self::detailsInNamespace(self::scopeNamespace(), $required, $connection, $queue);
    }

    public static function scopeNamespace(): ?string
    {
        return self::normalizeValue(config('workflows.v2.compatibility.namespace'));
    }

    public static function clear(): void
    {
        if (self::heartbeatTableExists()) {
            WorkerCompatibilityHeartbeat::query()->delete();
        }

        Cache::forget(self::LEGACY_CACHE_KEY);
        self::$lastRecorded = [];
        self::$lastPrunedAt = null;
        self::forgetSnapshotCache();
    }

    public static function activeWorkerCount(?string $connection = null, ?string $queue = null): int
    {
        return count(self::matchingSnapshots(self::scopeNamespace(), $connection, $queue));
    }

    public static function supports(?string $required, ?string $connection = null, ?string $queue = null): bool
    {
        $required = self::normalizeValue($required);

        if ($required === null) {
            return true;
        }

        foreach (self::matchingSnapshots(self::scopeNamespace(), $connection, $queue) as $snapshot) {
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

        $advertised = self::advertisedMarkers(self::scopeNamespace(), $connection, $queue);
        $reason = sprintf(
            'No active worker heartbeat%s advertises compatibility [%s].',
            self::scopeLabel($connection, $queue),
            $required,
        );

        if ($advertised === []) {
            return $reason;
        }

        return sprintf('%s Active workers there advertise [%s].', $reason, implode(', ', $advertised));
    }

    /**
     * @param list<string> $supported
     */
    private static function recordInNamespace(
        ?string $namespace,
        array $supported,
        ?string $connection,
        ?string $queue,
        ?string $workerId,
    ): void {
        $workerId ??= self::workerId();
        $supported = self::normalizeMarkers($supported);
        $connection = self::normalizeValue($connection);
        $queues = self::normalizeQueues($queue);

        $recordSource = self::heartbeatTableExists()
            ? self::SOURCE_DATABASE
            : self::SOURCE_CACHE;

        if (self::shouldSkipRecord($workerId, $supported, $namespace, $connection, $queues, $recordSource)) {
            return;
        }

        if ($recordSource === self::SOURCE_CACHE) {
            self::recordLegacySnapshot($workerId, $supported, $namespace, $connection, $queues);

            return;
        }

        try {
            self::pruneExpired();

            $now = now();
            $expiresAt = $now->copy()
                ->addSeconds(self::ttlSeconds());
            $rows = collect($queues === [] ? [null] : $queues)
                ->map(static function (?string $scopeQueue) use (
                    $workerId,
                    $namespace,
                    $connection,
                    $supported,
                    $now,
                    $expiresAt,
                ): array {
                    return [
                        'worker_id' => $workerId,
                        'namespace' => $namespace,
                        'scope_key' => self::scopeKey($namespace, $connection, $scopeQueue),
                        'host' => self::hostName(),
                        'process_id' => self::processId(),
                        'connection' => $connection,
                        'queue' => $scopeQueue,
                        'supported' => $supported,
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

                if (DB::connection()->getDriverName() !== 'pgsql') {
                    WorkerCompatibilityHeartbeat::query()
                        ->insert(array_map(static fn (array $row): array => [
                            ...$row,
                            'supported' => json_encode($row['supported']),
                        ], $rows));

                    return;
                }

                foreach ($rows as $row) {
                    WorkerCompatibilityHeartbeat::query()
                        ->create($row);
                }
            }, 3);
        } catch (Throwable $throwable) {
            report($throwable);
            self::recordLegacySnapshot($workerId, $supported, $namespace, $connection, $queues);

            return;
        }

        self::rememberRecorded(
            $workerId,
            $supported,
            $namespace,
            $connection,
            $queues,
            self::SOURCE_DATABASE,
            $now->getTimestamp(),
        );
        self::forgetSnapshotCache();
    }

    /**
     * @param list<string> $supported
     * @param list<string> $queues
     */
    private static function recordLegacySnapshot(
        string $workerId,
        array $supported,
        ?string $namespace,
        ?string $connection,
        array $queues,
    ): void {
        $now = now()
            ->getTimestamp();
        $expiresAt = $now + self::ttlSeconds();
        $fleet = Cache::get(self::LEGACY_CACHE_KEY);
        $fleet = is_array($fleet) ? $fleet : [];

        $fleet[$workerId] = [
            'supported' => $supported,
            'namespace' => $namespace,
            'connection' => $connection,
            'queues' => $queues,
            'recorded_at' => $now,
            'expires_at' => $expiresAt,
        ];

        Cache::put(self::LEGACY_CACHE_KEY, $fleet, self::ttlSeconds());
        self::rememberRecorded($workerId, $supported, $namespace, $connection, $queues, self::SOURCE_CACHE, $now);
        self::forgetSnapshotCache();
    }

    /**
     * @param list<string> $supported
     * @param list<string> $queues
     */
    private static function rememberRecorded(
        string $workerId,
        array $supported,
        ?string $namespace,
        ?string $connection,
        array $queues,
        string $source,
        int $recordedAt,
    ): void {
        self::$lastRecorded[$workerId] = [
            'supported' => $supported,
            'namespace' => $namespace,
            'connection' => $connection,
            'queues' => $queues,
            'source' => $source,
            'recorded_at' => $recordedAt,
        ];
    }

    /**
     * @return list<array{
     *     worker_id: string,
     *     namespace: string|null,
     *     host: string|null,
     *     process_id: string|null,
     *     connection: string|null,
     *     queue: string|null,
     *     supported: list<string>,
     *     supports_required: bool,
     *     recorded_at: \Illuminate\Support\Carbon|null,
     *     expires_at: \Illuminate\Support\Carbon|null,
     *     source: string
     * }>
     */
    private static function detailsInNamespace(
        ?string $namespace,
        ?string $required,
        ?string $connection = null,
        ?string $queue = null,
    ): array {
        $required = self::normalizeValue($required);

        return array_map(
            static function (array $snapshot) use ($required): array {
                $supported = self::normalizeMarkers($snapshot['supported'] ?? []);

                return [
                    'worker_id' => $snapshot['worker_id'],
                    'namespace' => $snapshot['namespace'],
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
                    'source' => $snapshot['source'],
                ];
            },
            self::matchingSnapshots($namespace, $connection, $queue),
        );
    }

    private static function shouldSkipRecord(
        string $workerId,
        array $supported,
        ?string $namespace,
        ?string $connection,
        array $queues,
        string $source,
    ): bool {
        $last = self::$lastRecorded[$workerId] ?? null;

        if ($last === null) {
            return false;
        }

        if (
            $last['supported'] !== $supported
            || $last['namespace'] !== $namespace
            || $last['connection'] !== $connection
            || $last['queues'] !== $queues
            || $last['source'] !== $source
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
        $now = now()
            ->getTimestamp();

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
        $nowSecond = now()
            ->getTimestamp();

        if (self::$snapshotCache !== null && self::$snapshotCacheSecond === $nowSecond) {
            return self::$snapshotCache;
        }

        if (! self::heartbeatTableExists()) {
            self::$snapshotCache = self::legacyCacheSnapshots();
            self::$snapshotCacheSecond = $nowSecond;

            return self::$snapshotCache;
        }

        self::pruneExpired();

        $databaseSnapshots = WorkerCompatibilityHeartbeat::query()
            ->where('expires_at', '>=', now())
            ->orderBy('namespace')
            ->orderBy('connection')
            ->orderBy('queue')
            ->orderBy('worker_id')
            ->get()
            ->map(static function (WorkerCompatibilityHeartbeat $snapshot): array {
                return [
                    'worker_id' => (string) $snapshot->worker_id,
                    'namespace' => self::normalizeValue($snapshot->namespace),
                    'host' => self::normalizeValue($snapshot->host),
                    'process_id' => self::normalizeValue($snapshot->process_id),
                    'connection' => self::normalizeValue($snapshot->connection),
                    'queue' => self::normalizeValue($snapshot->queue),
                    'supported' => self::normalizeMarkers($snapshot->supported),
                    'recorded_at' => $snapshot->recorded_at,
                    'expires_at' => $snapshot->expires_at,
                    'source' => self::SOURCE_DATABASE,
                ];
            })
            ->values()
            ->all();

        self::$snapshotCache = self::mergeSnapshots($databaseSnapshots, self::legacyCacheSnapshots());
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

    private static function scopeKey(?string $namespace, ?string $connection, ?string $queue): string
    {
        return hash('sha256', json_encode([
            'namespace' => $namespace,
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
        return max(1, (int) config('workflows.v2.compatibility.heartbeat_ttl_seconds', 30));
    }

    /**
     * @return list<array{worker_id: string, namespace: ?string, host: ?string, process_id: ?string, connection: ?string, queue: ?string, supported: list<string>, recorded_at: \Illuminate\Support\Carbon|null, expires_at: \Illuminate\Support\Carbon|null, source: string}>
     */
    private static function matchingSnapshots(
        ?string $namespace = null,
        ?string $connection = null,
        ?string $queue = null,
    ): array {
        $requiredNamespace = self::normalizeValue($namespace);
        $requiredConnection = self::normalizeValue($connection);
        $requiredQueue = self::normalizeValue($queue);

        return array_values(array_filter(
            self::activeSnapshots(),
            static function (array $snapshot) use ($requiredNamespace, $requiredConnection, $requiredQueue): bool {
                $snapshotNamespace = self::normalizeValue($snapshot['namespace'] ?? null);

                if (
                    $requiredNamespace !== null
                    && $snapshotNamespace !== $requiredNamespace
                    && ! self::isLegacyUnscopedSnapshot($snapshot, $snapshotNamespace)
                ) {
                    return false;
                }

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

    private static function isLegacyUnscopedSnapshot(array $snapshot, ?string $snapshotNamespace): bool
    {
        return ($snapshot['source'] ?? null) === self::SOURCE_CACHE
            && $snapshotNamespace === null;
    }

    /**
     * @return list<string>
     */
    private static function advertisedMarkers(
        ?string $namespace = null,
        ?string $connection = null,
        ?string $queue = null,
    ): array {
        $markers = [];

        foreach (self::matchingSnapshots($namespace, $connection, $queue) as $snapshot) {
            foreach (self::normalizeMarkers($snapshot['supported'] ?? []) as $marker) {
                $markers[] = $marker;
            }
        }

        $markers = array_values(array_unique($markers));
        sort($markers);

        return $markers;
    }

    /**
     * @return list<array{worker_id: string, namespace: ?string, host: ?string, process_id: ?string, connection: ?string, queue: ?string, supported: list<string>, recorded_at: \Illuminate\Support\Carbon|null, expires_at: \Illuminate\Support\Carbon|null, source: string}>
     */
    private static function legacyCacheSnapshots(): array
    {
        $fleet = Cache::get(self::LEGACY_CACHE_KEY);

        if (! is_array($fleet)) {
            return [];
        }

        $now = now()
            ->getTimestamp();
        $snapshots = [];

        foreach ($fleet as $workerId => $snapshot) {
            if (! is_string($workerId) || ! is_array($snapshot)) {
                continue;
            }

            $expiresAt = is_numeric($snapshot['expires_at'] ?? null)
                ? (int) $snapshot['expires_at']
                : null;

            if ($expiresAt === null || $expiresAt < $now) {
                continue;
            }

            $supported = self::normalizeMarkers($snapshot['supported'] ?? []);
            $namespace = self::normalizeValue($snapshot['namespace'] ?? null);
            $connection = self::normalizeValue($snapshot['connection'] ?? null);
            $queues = self::normalizeMarkers($snapshot['queues'] ?? []);
            $recordedAt = self::carbonFromTimestamp($snapshot['recorded_at'] ?? null);
            $expiresAtCarbon = self::carbonFromTimestamp($expiresAt);

            foreach ($queues === [] ? [null] : $queues as $scopeQueue) {
                $snapshots[] = [
                    'worker_id' => $workerId,
                    'namespace' => $namespace,
                    'host' => null,
                    'process_id' => null,
                    'connection' => $connection,
                    'queue' => self::normalizeValue($scopeQueue),
                    'supported' => $supported,
                    'recorded_at' => $recordedAt,
                    'expires_at' => $expiresAtCarbon,
                    'source' => self::SOURCE_CACHE,
                ];
            }
        }

        return self::sortSnapshots($snapshots);
    }

    /**
     * @param  list<array{worker_id: string, namespace: ?string, host: ?string, process_id: ?string, connection: ?string, queue: ?string, supported: list<string>, recorded_at: \Illuminate\Support\Carbon|null, expires_at: \Illuminate\Support\Carbon|null, source: string}>  $preferred
     * @param  list<array{worker_id: string, namespace: ?string, host: ?string, process_id: ?string, connection: ?string, queue: ?string, supported: list<string>, recorded_at: \Illuminate\Support\Carbon|null, expires_at: \Illuminate\Support\Carbon|null, source: string}>  $fallback
     * @return list<array{worker_id: string, namespace: ?string, host: ?string, process_id: ?string, connection: ?string, queue: ?string, supported: list<string>, recorded_at: \Illuminate\Support\Carbon|null, expires_at: \Illuminate\Support\Carbon|null, source: string}>
     */
    private static function mergeSnapshots(array $preferred, array $fallback): array
    {
        $merged = [];
        $seen = [];

        foreach ([$preferred, $fallback] as $snapshots) {
            foreach ($snapshots as $snapshot) {
                $key = self::snapshotKey($snapshot);

                if (isset($seen[$key])) {
                    continue;
                }

                $merged[] = $snapshot;
                $seen[$key] = true;
            }
        }

        return self::sortSnapshots($merged);
    }

    /**
     * @param  array{worker_id: string, namespace: ?string, connection: ?string, queue: ?string}  $snapshot
     */
    private static function snapshotKey(array $snapshot): string
    {
        return implode('|', [
            $snapshot['worker_id'],
            $snapshot['namespace'] ?? '',
            $snapshot['connection'] ?? '',
            $snapshot['queue'] ?? '',
        ]);
    }

    /**
     * @param  list<array{worker_id: string, namespace: ?string, host: ?string, process_id: ?string, connection: ?string, queue: ?string, supported: list<string>, recorded_at: \Illuminate\Support\Carbon|null, expires_at: \Illuminate\Support\Carbon|null, source: string}>  $snapshots
     * @return list<array{worker_id: string, namespace: ?string, host: ?string, process_id: ?string, connection: ?string, queue: ?string, supported: list<string>, recorded_at: \Illuminate\Support\Carbon|null, expires_at: \Illuminate\Support\Carbon|null, source: string}>
     */
    private static function sortSnapshots(array $snapshots): array
    {
        usort($snapshots, static function (array $left, array $right): int {
            $leftNamespace = $left['namespace'] ?? '';
            $rightNamespace = $right['namespace'] ?? '';

            if ($leftNamespace !== $rightNamespace) {
                return $leftNamespace <=> $rightNamespace;
            }

            $leftConnection = $left['connection'] ?? '';
            $rightConnection = $right['connection'] ?? '';

            if ($leftConnection !== $rightConnection) {
                return $leftConnection <=> $rightConnection;
            }

            $leftQueue = $left['queue'] ?? '';
            $rightQueue = $right['queue'] ?? '';

            if ($leftQueue !== $rightQueue) {
                return $leftQueue <=> $rightQueue;
            }

            $leftWorker = $left['worker_id'] ?? '';
            $rightWorker = $right['worker_id'] ?? '';

            if ($leftWorker !== $rightWorker) {
                return $leftWorker <=> $rightWorker;
            }

            return ($left['source'] ?? '') <=> ($right['source'] ?? '');
        });

        return array_values($snapshots);
    }

    private static function carbonFromTimestamp(mixed $value): ?Carbon
    {
        if (! is_numeric($value)) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $value);
    }

    private static function scopeLabel(?string $connection, ?string $queue): string
    {
        $parts = [];
        $namespace = self::scopeNamespace();
        $connection = self::normalizeValue($connection);
        $queue = self::normalizeValue($queue);

        if ($namespace !== null) {
            $parts[] = sprintf('namespace [%s]', $namespace);
        }

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

    private static function latestCarbon(?Carbon $current, mixed $candidate): ?Carbon
    {
        if (! $candidate instanceof Carbon) {
            return $current;
        }

        if ($current === null || $candidate->greaterThan($current)) {
            return $candidate;
        }

        return $current;
    }

    /**
     * @param array<int, mixed> $values
     * @return list<string>
     */
    private static function uniqueStrings(array $values): array
    {
        $strings = array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): ?string => self::normalizeValue($value), $values),
            static fn (?string $value): bool => $value !== null,
        )));

        sort($strings);

        return $strings;
    }

    /**
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

    private static function heartbeatTableExists(): bool
    {
        try {
            return Schema::hasTable((new WorkerCompatibilityHeartbeat())->getTable());
        } catch (Throwable) {
            return false;
        }
    }
}
