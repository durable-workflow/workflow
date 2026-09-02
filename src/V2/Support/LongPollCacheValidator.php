<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;

/**
 * Validates cache backend configuration for long-poll wake acceleration in multi-node deployments.
 *
 * Shared cache backends (Redis, database, Memcached) are required only for
 * cross-node wake propagation. Durable dispatch correctness does not depend on
 * this layer, but latency and rollout-safety diagnostics do.
 *
 * @see \Workflow\V2\Contracts\LongPollWakeStore
 */
class LongPollCacheValidator
{
    /**
     * Check if cache backend supports multi-node coordination.
     *
     * @return array{
     *     capable: bool,
     *     backend: string,
     *     reason: string|null
     * }
     */
    public function validateMultiNodeCapable(CacheRepository $cache): array
    {
        return $this->describeMultiNodeCapability($this->detectBackend($cache));
    }

    /**
     * Same multi-node capability decision as
     * {@see self::validateMultiNodeCapable()} but driven from the configured
     * driver name rather than a resolved {@see CacheRepository}. This is the
     * source-of-truth path for the long-poll wake acceleration health check:
     * it reads `cache.default` and `cache.stores.<default>.driver` directly,
     * so the returned capability cannot drift from the operator-visible
     * configuration even when an earlier resolution memoized a Repository
     * wrapping a different store under the same name in the
     * {@see \Illuminate\Cache\CacheManager} `$stores` array.
     *
     * @return array{
     *     capable: bool,
     *     backend: string,
     *     reason: string|null
     * }
     */
    public function validateMultiNodeCapableFromDriver(?string $driver): array
    {
        $backend = $this->normalizeDriverName($driver);

        return $this->describeMultiNodeCapability($backend);
    }

    /**
     * Variant of {@see self::checkMultiNodeSafety()} that decides safety from
     * the configured driver name rather than a resolved cache repository.
     * Use this from health-surface callers that want capability to track the
     * operator-visible `cache.stores.<default>.driver` config without going
     * through the {@see \Illuminate\Cache\CacheManager} memoization layer.
     *
     * @return array{
     *     safe: bool,
     *     message: string|null
     * }
     */
    public function checkMultiNodeSafetyFromDriver(?string $driver, bool $multiNode): array
    {
        return $this->describeMultiNodeSafety($this->validateMultiNodeCapableFromDriver($driver), $multiNode);
    }

    /**
     * Check if current configuration is safe for multi-node wake acceleration.
     *
     * @return array{
     *     safe: bool,
     *     message: string|null
     * }
     */
    public function checkMultiNodeSafety(CacheRepository $cache, bool $multiNode): array
    {
        return $this->describeMultiNodeSafety($this->validateMultiNodeCapable($cache), $multiNode);
    }

    /**
     * @param array{capable: bool, backend: string, reason: string|null} $validation
     * @return array{safe: bool, message: string|null}
     */
    private function describeMultiNodeSafety(array $validation, bool $multiNode): array
    {
        if (! $multiNode) {
            return [
                'safe' => true,
                'message' => null,
            ];
        }

        if ($validation['capable']) {
            return [
                'safe' => true,
                'message' => null,
            ];
        }

        return [
            'safe' => false,
            'message' => sprintf(
                'Multi-node wake acceleration is enabled (DW_V2_MULTI_NODE=true) but cache backend is "%s". %s',
                $validation['backend'],
                $validation['reason']
            ),
        ];
    }

    /**
     * @return array{capable: bool, backend: string, reason: string|null}
     */
    private function describeMultiNodeCapability(string $backend): array
    {
        return match ($backend) {
            'file' => [
                'capable' => false,
                'backend' => 'file',
                'reason' => 'File cache is per-node and cannot propagate wake signals across nodes. Use Redis, database cache, or Memcached for multi-node deployments.',
            ],
            'redis', 'database', 'memcached' => [
                'capable' => true,
                'backend' => $backend,
                'reason' => null,
            ],
            default => [
                'capable' => false,
                'backend' => $backend,
                'reason' => "Unknown cache backend '{$backend}'. Supported multi-node backends: redis, database, memcached.",
            ],
        };
    }

    private function normalizeDriverName(?string $driver): string
    {
        if ($driver === null) {
            return 'unknown';
        }

        $normalized = strtolower(trim($driver));

        if ($normalized === '') {
            return 'unknown';
        }

        return $normalized;
    }

    /**
     * Detect cache backend type from repository.
     */
    private function detectBackend(CacheRepository $cache): string
    {
        if ($cache instanceof LaravelCacheRepository) {
            $store = $cache->getStore();

            return match (true) {
                $store instanceof FileStore => 'file',
                $store instanceof \Illuminate\Cache\RedisStore => 'redis',
                $store instanceof \Illuminate\Cache\DatabaseStore => 'database',
                $store instanceof \Illuminate\Cache\MemcachedStore => 'memcached',
                $store instanceof \Illuminate\Cache\ArrayStore => 'array',
                $store instanceof \Illuminate\Cache\NullStore => 'null',
                default => $this->detectBackendByClass($store),
            };
        }

        return 'unknown';
    }

    /**
     * Fallback detection by class name when direct instance checks fail.
     */
    private function detectBackendByClass(mixed $store): string
    {
        $class = get_class($store);

        if (Str::contains($class, 'Redis')) {
            return 'redis';
        }

        if (Str::contains($class, 'Database')) {
            return 'database';
        }

        if (Str::contains($class, 'Memcached')) {
            return 'memcached';
        }

        if (Str::contains($class, 'File')) {
            return 'file';
        }

        return 'unknown';
    }
}
