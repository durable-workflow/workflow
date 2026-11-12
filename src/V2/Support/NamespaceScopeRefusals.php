<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;
use Workflow\Support\CacheThrottle;
use Workflow\V2\Models\WorkflowRun;

/**
 * Counts work this app left alone because its namespace is outside the
 * app's namespace allowlist (`workflows.v2.namespaces`).
 *
 * A refusal is correct on its own, since another app may serve that
 * namespace. A refusal that keeps happening usually means an app that
 * forgot to list a namespace it uses, and its tasks wait with no other sign.
 * The counts make that visible in the operator metrics and health snapshot.
 *
 * Paths:
 *
 * - `job`: a queued task or service-call job skipped a task or call
 *   outside the allowlist. One count per skipped job.
 * - `repair`, `timeout`, `schedule_tick`: these passes only query their
 *   own namespaces, so they never see foreign rows. At most once per
 *   minute per path and scope, a pass samples the work it left: repair
 *   candidates, expired activity and run deadlines, and due schedules that
 *   have waited longer than {@see self::STALE_AFTER_SECONDS} in a namespace
 *   outside the allowlist. Each sample adds that number. The grace keeps a
 *   healthy app that serves the namespace from showing up here.
 *
 * Counts live in the cache in one-minute buckets and expire with the
 * window, so nothing grows without bound and no table write lands on the
 * job path. With a shared cache store the counts cover every process that
 * shares it. A process-local store such as `array` only sees its own.
 */
final class NamespaceScopeRefusals
{
    public const PATH_JOB = 'job';

    public const PATH_REPAIR = 'repair';

    public const PATH_TIMEOUT = 'timeout';

    public const PATH_SCHEDULE_TICK = 'schedule_tick';

    public const PATHS = [self::PATH_JOB, self::PATH_REPAIR, self::PATH_TIMEOUT, self::PATH_SCHEDULE_TICK];

    public const BUCKET_SECONDS = 60;

    public const WINDOW_BUCKETS = 15;

    public const STALE_AFTER_SECONDS = 120;

    /**
     * Namespace and path pairs listed per bucket. Past this, a new pair is
     * still logged but not listed, and the snapshot flags the window as
     * truncated.
     */
    public const MAX_TRACKED_PER_BUCKET = 100;

    /**
     * Namespace columns a sampled query can name.
     */
    public const NAMESPACE_OWN = 'own';

    public const NAMESPACE_TASK = 'task';

    public const NAMESPACE_RUN = 'run';

    private const PREFIX = 'workflow:v2:namespace-scope-refusals:';

    /**
     * Count refusals and report whether they are the first for this path and
     * namespace in the current bucket, so the caller can log once per minute
     * instead of once per refusal. Counting never throws: if the cache fails,
     * the caller is told to log.
     */
    public static function record(string $path, ?string $namespace, int $count = 1): bool
    {
        if ($count < 1) {
            return false;
        }

        $namespace = NamespaceScope::canonical($namespace);
        $bucket = self::bucket(Carbon::now());

        try {
            $key = self::counterKey($bucket, $path, $namespace);

            if (Cache::add($key, $count, self::ttlSeconds())) {
                self::index($bucket, $path, $namespace);

                return true;
            }

            Cache::increment($key, $count);

            return false;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Sample the work a pass left outside its namespaces, at most once per
     * bucket for this path and scope.
     *
     * @param Closure(CarbonInterface): list<array{0: Builder, 1: string}> $queries
     *     Builds candidate queries over every namespace, as of the stale cutoff,
     *     each paired with how the row names its namespace.
     */
    public static function sample(string $path, NamespaceScope $scope, Closure $queries, string $key = ''): void
    {
        if ($scope->isAll()) {
            return;
        }

        try {
            $throttle = self::PREFIX . 'sample:' . $path . ':' . hash('sha256', $scope->key() . '|' . $key);

            if (! CacheThrottle::acquire($throttle, true, self::BUCKET_SECONDS)) {
                return;
            }

            $counts = [];
            $staleBefore = Carbon::now()->subSeconds(self::STALE_AFTER_SECONDS);

            foreach ($queries($staleBefore) as [$query, $namespaceSource]) {
                foreach (self::countByNamespace($query, $namespaceSource) as $namespace => $count) {
                    if (! $scope->allows($namespace)) {
                        $counts[$namespace] = ($counts[$namespace] ?? 0) + $count;
                    }
                }
            }

            foreach ($counts as $namespace => $count) {
                if (self::record($path, (string) $namespace, $count)) {
                    self::logPassRefusal($path, (string) $namespace, $count, $scope);
                }
            }
        } catch (Throwable $throwable) {
            // The count is advisory. It must never stop the pass it observes.
            report($throwable);
        }
    }

    /**
     * Refusals within the trailing window, optionally for one namespace.
     *
     * @return array{
     *     window_seconds: int,
     *     bucket_seconds: int,
     *     stale_after_seconds: int,
     *     window_started_at: string,
     *     total: int,
     *     by_path: array<string, int>,
     *     namespaces: list<array{namespace: string, path: string, count: int, last_seen_at: string}>,
     *     truncated: bool,
     *     available: bool
     * }
     */
    public static function snapshot(?CarbonInterface $now = null, ?string $namespace = null): array
    {
        $now ??= Carbon::now();
        $current = self::bucket($now);
        $first = $current - self::WINDOW_BUCKETS + 1;
        $only = $namespace === null ? null : NamespaceScope::canonical($namespace);
        $entries = [];
        $truncated = false;
        $available = true;

        try {
            for ($bucket = $first; $bucket <= $current; $bucket++) {
                $index = Cache::get(self::indexKey($bucket));

                if (! is_array($index)) {
                    continue;
                }

                $truncated = $truncated || ($index['truncated'] ?? false) === true;
                $pairs = self::pairs($index['entries'] ?? [], $only);

                if ($pairs === []) {
                    continue;
                }

                $keys = array_map(
                    static fn (array $pair): string => self::counterKey($bucket, $pair[0], $pair[1]),
                    $pairs,
                );
                $values = Cache::many($keys);

                foreach ($pairs as $offset => [$path, $pairNamespace]) {
                    $count = $values[$keys[$offset]] ?? null;

                    if (! is_numeric($count) || (int) $count < 1) {
                        continue;
                    }

                    $id = $path . "\0" . $pairNamespace;
                    $entries[$id] ??= [
                        'namespace' => $pairNamespace,
                        'path' => $path,
                        'count' => 0,
                        'last_seen_at' => '',
                    ];
                    $entries[$id]['count'] += (int) $count;
                    $entries[$id]['last_seen_at'] = Carbon::createFromTimestampUTC($bucket * self::BUCKET_SECONDS)
                        ->toJSON();
                }
            }
        } catch (Throwable) {
            $available = false;
        }

        $byPath = array_fill_keys(self::PATHS, 0);

        foreach ($entries as $entry) {
            $byPath[$entry['path']] = ($byPath[$entry['path']] ?? 0) + $entry['count'];
        }

        $entries = array_values($entries);
        usort($entries, static fn (array $left, array $right): int => [
            $right['count'],
            $left['namespace'],
            $left['path'],
        ] <=> [$left['count'], $right['namespace'], $right['path']]);

        return [
            'window_seconds' => self::WINDOW_BUCKETS * self::BUCKET_SECONDS,
            'bucket_seconds' => self::BUCKET_SECONDS,
            'stale_after_seconds' => self::STALE_AFTER_SECONDS,
            'window_started_at' => Carbon::createFromTimestampUTC($first * self::BUCKET_SECONDS)->toJSON(),
            'total' => array_sum($byPath),
            'by_path' => $byPath,
            'namespaces' => $entries,
            'truncated' => $truncated,
            'available' => $available,
        ];
    }

    /**
     * @return array<string, int> Canonical namespace => rows.
     */
    private static function countByNamespace(Builder $query, string $namespaceSource): array
    {
        $model = $query->getModel();
        $connection = $model->getConnection();
        $grammar = $connection->getQueryGrammar();
        $inner = (clone $query)->toBase();
        $inner->columns = [];
        $inner->orders = null;
        $inner->limit = null;
        $inner->offset = null;

        $own = $grammar->wrap($model->qualifyColumn('namespace'));

        if (! in_array($namespaceSource, [self::NAMESPACE_OWN, self::NAMESPACE_TASK, self::NAMESPACE_RUN], true)) {
            throw new InvalidArgumentException(sprintf('Unknown namespace source [%s].', $namespaceSource));
        }

        if ($namespaceSource === self::NAMESPACE_OWN) {
            $inner->selectRaw($own . ' as scope_namespace');
        } else {
            $run = ConfiguredV2Models::query('run_model', WorkflowRun::class);
            $runNamespace = $run->toBase()
                ->select($run->getModel()->qualifyColumn('namespace'))
                ->whereColumn(
                    $run->getModel()
                        ->qualifyColumn($run->getModel()->getKeyName()),
                    $model->qualifyColumn('workflow_run_id'),
                )
                ->limit(1);
            $expression = $namespaceSource === self::NAMESPACE_TASK
                ? 'COALESCE(' . $own . ', (' . $runNamespace->toSql() . '))'
                : '(' . $runNamespace->toSql() . ')';

            $inner->selectRaw($expression . ' as scope_namespace', $runNamespace->getBindings());
        }

        $rows = $connection->query()
            ->fromSub($inner, 'namespace_scope_refusals')
            ->selectRaw('scope_namespace, count(*) as aggregate')
            ->groupBy('scope_namespace')
            ->get();
        $counts = [];

        foreach ($rows as $row) {
            $namespace = NamespaceScope::canonical(is_string($row->scope_namespace) ? $row->scope_namespace : null);
            $counts[$namespace] = ($counts[$namespace] ?? 0) + (int) $row->aggregate;
        }

        return $counts;
    }

    private static function logPassRefusal(string $path, string $namespace, int $count, NamespaceScope $scope): void
    {
        Log::warning('Durable Workflow left work in a namespace outside this application\'s namespaces.', [
            'path' => $path,
            'namespace' => $namespace,
            'stale_rows' => $count,
            'stale_after_seconds' => self::STALE_AFTER_SECONDS,
            'namespaces' => $scope->namespaces(),
        ]);
    }

    private static function index(int $bucket, string $path, string $namespace): void
    {
        $write = static function () use ($bucket, $path, $namespace): void {
            $key = self::indexKey($bucket);
            $index = Cache::get($key);
            $index = is_array($index) ? $index : [
                'entries' => [],
                'truncated' => false,
            ];
            $entries = is_array($index['entries'] ?? null) ? $index['entries'] : [];

            if (in_array([$path, $namespace], $entries, true)) {
                return;
            }

            if (count($entries) >= self::MAX_TRACKED_PER_BUCKET) {
                $index['truncated'] = true;
            } else {
                $entries[] = [$path, $namespace];
            }

            $index['entries'] = $entries;
            Cache::put($key, $index, self::ttlSeconds());
        };

        $store = Cache::getStore();

        if (! $store instanceof LockProvider) {
            $write();

            return;
        }

        try {
            Cache::lock(self::indexKey($bucket) . ':lock', 5)->block(2, $write);
        } catch (Throwable) {
            // A busy lock costs at most this pair's listing for one bucket.
            $write();
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function pairs(mixed $entries, ?string $only): array
    {
        $pairs = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (
                is_array($entry)
                && in_array($entry[0] ?? null, self::PATHS, true)
                && is_string($entry[1] ?? null)
                && ($only === null || $entry[1] === $only)
            ) {
                $pairs[] = [$entry[0], $entry[1]];
            }
        }

        return $pairs;
    }

    private static function bucket(CarbonInterface $at): int
    {
        return intdiv($at->getTimestamp(), self::BUCKET_SECONDS);
    }

    private static function ttlSeconds(): int
    {
        return (self::WINDOW_BUCKETS + 1) * self::BUCKET_SECONDS;
    }

    private static function indexKey(int $bucket): string
    {
        return self::PREFIX . 'index:' . $bucket;
    }

    private static function counterKey(int $bucket, string $path, string $namespace): string
    {
        return self::PREFIX . 'count:' . $bucket . ':' . $path . ':' . hash('sha256', $namespace);
    }
}
