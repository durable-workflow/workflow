<?php

declare(strict_types=1);

namespace Workflow\Tests\LoadTest;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;
use Workflow\V2\Contracts\LongPollWakeStore;
use Workflow\V2\Support\CacheLongPollWakeStore;

/**
 * Load tests for long-poll coordination across cache backends.
 *
 * Validates wake signal propagation latency and throughput under concurrent load.
 * Run with: vendor/bin/phpunit tests/LoadTest/LongPollCoordinationLoadTest.php
 *
 * Requirements:
 * - Redis: redis-server running on localhost:6379
 * - Memcached: memcached running on localhost:11211
 * - Database: MySQL/PostgreSQL configured in .env.testing
 */
class LongPollCoordinationLoadTest extends TestCase
{
    use RefreshDatabase;

    private const CONCURRENCY_LOW = 10;
    private const CONCURRENCY_MEDIUM = 50;
    private const CONCURRENCY_HIGH = 100;

    private const ITERATIONS = 100;

    /**
     * @test
     * @group load
     */
    public function it_measures_redis_backend_wake_latency(): void
    {
        $this->markTestSkippedIfRedisUnavailable();

        $cache = $this->makeRedisCache();
        $results = $this->measureWakeLatency($cache, 'Redis', self::CONCURRENCY_MEDIUM, self::ITERATIONS);

        $this->assertWakeLatencyBaseline($results, 'Redis', 10); // p99 < 10ms
        $this->dumpResults('Redis', $results);
    }

    /**
     * @test
     * @group load
     */
    public function it_measures_database_cache_wake_latency(): void
    {
        $cache = $this->makeDatabaseCache();
        $results = $this->measureWakeLatency($cache, 'Database', self::CONCURRENCY_MEDIUM, self::ITERATIONS);

        $this->assertWakeLatencyBaseline($results, 'Database', 50); // p99 < 50ms
        $this->dumpResults('Database Cache', $results);
    }

    /**
     * @test
     * @group load
     */
    public function it_measures_memcached_backend_wake_latency(): void
    {
        $this->markTestSkippedIfMemcachedUnavailable();

        $cache = $this->makeMemcachedCache();
        $results = $this->measureWakeLatency($cache, 'Memcached', self::CONCURRENCY_MEDIUM, self::ITERATIONS);

        $this->assertWakeLatencyBaseline($results, 'Memcached', 20); // p99 < 20ms
        $this->dumpResults('Memcached', $results);
    }

    /**
     * @test
     * @group load
     */
    public function it_measures_file_cache_baseline_wake_latency(): void
    {
        $cache = $this->makeFileCache();
        $results = $this->measureWakeLatency($cache, 'File', self::CONCURRENCY_LOW, 50);

        // File cache is single-process, so latency should be very low but no cross-process coordination
        $this->dumpResults('File Cache (Baseline)', $results);
    }

    /**
     * @test
     * @group load
     */
    public function it_measures_redis_throughput_under_high_concurrency(): void
    {
        $this->markTestSkippedIfRedisUnavailable();

        $cache = $this->makeRedisCache();
        $results = $this->measureThroughput($cache, 'Redis', self::CONCURRENCY_HIGH, 500);

        $this->assertThroughputBaseline($results, 'Redis', 1000); // > 1000 signals/sec
        $this->dumpResults('Redis (High Concurrency)', $results);
    }

    /**
     * @test
     * @group load
     */
    public function it_measures_database_cache_throughput_under_high_concurrency(): void
    {
        $cache = $this->makeDatabaseCache();
        $results = $this->measureThroughput($cache, 'Database', self::CONCURRENCY_HIGH, 500);

        $this->assertThroughputBaseline($results, 'Database', 500); // > 500 signals/sec
        $this->dumpResults('Database Cache (High Concurrency)', $results);
    }

    /**
     * Measure wake signal propagation latency.
     *
     * @return array{
     *     latencies_ms: array<float>,
     *     p50_ms: float,
     *     p95_ms: float,
     *     p99_ms: float,
     *     mean_ms: float,
     *     min_ms: float,
     *     max_ms: float
     * }
     */
    private function measureWakeLatency(
        CacheRepository $cache,
        string $backend,
        int $concurrency,
        int $iterations
    ): array {
        $store = new CacheLongPollWakeStore($cache);
        $latencies = [];

        for ($i = 0; $i < $iterations; $i++) {
            $channel = sprintf('test-channel-%d', $i);

            // Take snapshot before signal
            $snapshot = $store->snapshot([$channel]);

            // Record signal timestamp
            $signalStart = microtime(true);
            $store->signal($channel);

            // Measure time until change detected
            $changed = false;
            $timeout = microtime(true) + 1.0; // 1 second timeout

            while (! $changed && microtime(true) < $timeout) {
                $changed = $store->changed($snapshot);
                usleep(100); // 0.1ms poll interval
            }

            $signalEnd = microtime(true);

            if ($changed) {
                $latencies[] = ($signalEnd - $signalStart) * 1000; // Convert to ms
            }
        }

        return $this->calculateLatencyMetrics($latencies);
    }

    /**
     * Measure throughput (signals per second).
     *
     * @return array{
     *     signals_per_sec: float,
     *     total_signals: int,
     *     duration_sec: float
     * }
     */
    private function measureThroughput(
        CacheRepository $cache,
        string $backend,
        int $concurrency,
        int $totalSignals
    ): array {
        $store = new CacheLongPollWakeStore($cache);

        $start = microtime(true);

        for ($i = 0; $i < $totalSignals; $i++) {
            $channel = sprintf('throughput-test-%d', $i % $concurrency);
            $store->signal($channel);
        }

        $end = microtime(true);
        $duration = $end - $start;

        return [
            'signals_per_sec' => $totalSignals / $duration,
            'total_signals' => $totalSignals,
            'duration_sec' => $duration,
        ];
    }

    /**
     * @param  array<float>  $latencies
     * @return array{
     *     latencies_ms: array<float>,
     *     p50_ms: float,
     *     p95_ms: float,
     *     p99_ms: float,
     *     mean_ms: float,
     *     min_ms: float,
     *     max_ms: float
     * }
     */
    private function calculateLatencyMetrics(array $latencies): array
    {
        if (empty($latencies)) {
            return [
                'latencies_ms' => [],
                'p50_ms' => 0.0,
                'p95_ms' => 0.0,
                'p99_ms' => 0.0,
                'mean_ms' => 0.0,
                'min_ms' => 0.0,
                'max_ms' => 0.0,
            ];
        }

        sort($latencies);

        return [
            'latencies_ms' => $latencies,
            'p50_ms' => $this->percentile($latencies, 50),
            'p95_ms' => $this->percentile($latencies, 95),
            'p99_ms' => $this->percentile($latencies, 99),
            'mean_ms' => array_sum($latencies) / count($latencies),
            'min_ms' => min($latencies),
            'max_ms' => max($latencies),
        ];
    }

    /**
     * @param  array<float>  $sorted
     */
    private function percentile(array $sorted, int $percentile): float
    {
        $count = count($sorted);
        $index = (int) ceil(($percentile / 100) * $count) - 1;
        $index = max(0, min($index, $count - 1));

        return $sorted[$index];
    }

    /**
     * @param  array<string, mixed>  $results
     */
    private function assertWakeLatencyBaseline(array $results, string $backend, float $maxP99Ms): void
    {
        $this->assertLessThan(
            $maxP99Ms,
            $results['p99_ms'],
            sprintf('%s backend p99 latency should be under %dms', $backend, (int) $maxP99Ms)
        );
    }

    /**
     * @param  array<string, mixed>  $results
     */
    private function assertThroughputBaseline(array $results, string $backend, float $minSignalsPerSec): void
    {
        $this->assertGreaterThan(
            $minSignalsPerSec,
            $results['signals_per_sec'],
            sprintf('%s backend should handle > %d signals/sec', $backend, (int) $minSignalsPerSec)
        );
    }

    /**
     * @param  array<string, mixed>  $results
     */
    private function dumpResults(string $backend, array $results): void
    {
        $output = sprintf("\n=== %s Results ===\n", $backend);

        if (isset($results['p50_ms'])) {
            // Latency results
            $output .= sprintf("  p50: %.2fms\n", $results['p50_ms']);
            $output .= sprintf("  p95: %.2fms\n", $results['p95_ms']);
            $output .= sprintf("  p99: %.2fms\n", $results['p99_ms']);
            $output .= sprintf("  mean: %.2fms\n", $results['mean_ms']);
            $output .= sprintf("  min: %.2fms\n", $results['min_ms']);
            $output .= sprintf("  max: %.2fms\n", $results['max_ms']);
            $output .= sprintf("  samples: %d\n", count($results['latencies_ms']));
        }

        if (isset($results['signals_per_sec'])) {
            // Throughput results
            $output .= sprintf("  signals/sec: %.0f\n", $results['signals_per_sec']);
            $output .= sprintf("  total signals: %d\n", $results['total_signals']);
            $output .= sprintf("  duration: %.2fs\n", $results['duration_sec']);
        }

        fwrite(STDOUT, $output);
    }

    private function makeRedisCache(): CacheRepository
    {
        $redis = $this->app->make('redis');

        return new LaravelCacheRepository(new RedisStore($redis, 'load-test:', 'default'));
    }

    private function makeDatabaseCache(): CacheRepository
    {
        return Cache::store('database');
    }

    private function makeMemcachedCache(): CacheRepository
    {
        $memcached = new \Memcached;
        $memcached->addServer('localhost', 11211);

        return new LaravelCacheRepository(new MemcachedStore($memcached, 'load-test:'));
    }

    private function makeFileCache(): CacheRepository
    {
        $files = new Filesystem;
        $path = storage_path('framework/cache/load-test');
        $files->ensureDirectoryExists($path);

        return new LaravelCacheRepository(new FileStore($files, $path));
    }

    private function markTestSkippedIfRedisUnavailable(): void
    {
        try {
            $this->app->make('redis')->connection()->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis not available: ' . $e->getMessage());
        }
    }

    private function markTestSkippedIfMemcachedUnavailable(): void
    {
        try {
            $memcached = new \Memcached;
            $memcached->addServer('localhost', 11211);
            $memcached->set('test', 'value', 1);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Memcached not available: ' . $e->getMessage());
        }
    }
}
