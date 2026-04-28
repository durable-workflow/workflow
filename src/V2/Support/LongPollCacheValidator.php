<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;

/**
 * Validates cache backend configuration for long-poll coordination in multi-node deployments.
 *
 * Multi-node deployments require shared cache backends (Redis, database, Memcached)
 * for wake signal propagation. File-based cache is per-node and cannot coordinate
 * across nodes.
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
        $backend = $this->detectBackend($cache);

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

    /**
     * Check if current configuration is safe for multi-node deployment.
     *
     * @return array{
     *     safe: bool,
     *     message: string|null
     * }
     */
    public function checkMultiNodeSafety(CacheRepository $cache, bool $multiNode): array
    {
        $validation = $this->validateMultiNodeCapable($cache);

        if (! $multiNode) {
            // Single-node deployment, any cache backend is acceptable
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
                'Multi-node deployment detected (DW_V2_MULTI_NODE=true) but cache backend is "%s". %s',
                $validation['backend'],
                $validation['reason']
            ),
        ];
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
