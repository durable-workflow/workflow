<?php

declare(strict_types=1);

namespace Tests;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Symfony\Component\Process\Process;
use Workflow\V2\TaskWatchdog;

abstract class TestCase extends BaseTestCase
{
    public const NUMBER_OF_WORKERS = 2;

    private const V1_WATCHDOG_LOOP_THROTTLE_KEY = 'workflow:watchdog:looping';

    private const WATCHDOG_THROTTLE_TTL_SECONDS = 600;

    private static $workers = [];

    public static function setUpBeforeClass(): void
    {
        $currentSuite = TestSuiteSubscriber::getCurrentSuite();

        if ($currentSuite === 'feature') {
            Dotenv::createImmutable(__DIR__, '.env.feature')->safeLoad();
        } elseif ($currentSuite === 'unit') {
            Dotenv::createImmutable(__DIR__, '.env.unit')->safeLoad();
        }

        foreach ($_ENV as $key => $value) {
            if (is_string($value) && getenv($key) === false) {
                putenv("{$key}={$value}");
            }
        }

        self::flushRedis();

        if ($currentSuite !== 'feature') {
            return;
        }

        // The first feature test's migrate:fresh runs before setUp() can write
        // the Cache facade throttle keys, so prime Redis directly before the
        // background queue workers start polling.
        self::primeWatchdogThrottle();

        // Explicitly hand our env to Process. testbench/laravel/.env hardcodes
        // CACHE_DRIVER=file; without an inherited env (or when Symfony Process
        // ignores the parent's env on some runners) the worker bootstraps its
        // own file-backed cache and cannot see the LOOP_THROTTLE_KEY the test
        // process writes to redis — original CI shape under #427. Merging
        // $_ENV + $_SERVER + getenv() rather than relying on Process's
        // default null-env inherit covers every environment variable source
        // GitHub Actions, Orchestra, and the loaded .env.feature might have
        // populated by this point.
        $workerEnv = array_merge(array_filter($_SERVER, 'is_string'), array_filter($_ENV, 'is_string'));

        for ($i = 0; $i < self::NUMBER_OF_WORKERS; $i++) {
            self::$workers[$i] = new Process(
                ['php', __DIR__ . '/../vendor/bin/testbench', 'queue:work'],
                null,
                $workerEnv,
            );
            self::$workers[$i]->disableOutput();
            self::$workers[$i]->start();
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$workers as $worker) {
            $worker->stop();
        }

        self::$workers = [];

        self::flushRedis();
    }

    protected function setUp(): void
    {
        if (TestSuiteSubscriber::getCurrentSuite() === 'feature') {
            Dotenv::createImmutable(__DIR__, '.env.feature')->safeLoad();
        } elseif (TestSuiteSubscriber::getCurrentSuite() === 'unit') {
            Dotenv::createImmutable(__DIR__, '.env.unit')->safeLoad();
        }

        parent::setUp();

        Queue::swap($this->app->make('queue'));

        Cache::flush();

        self::flushRedis();

        if (TestSuiteSubscriber::getCurrentSuite() === 'feature') {
            // Block BOTH the V2 TaskWatchdog and the V1 Watchdog for the
            // duration of every feature test. The two testbench queue
            // workers spawned in setUpBeforeClass run both wake()s on every
            // Looping event in separate PHP processes.
            //
            // V2: almost every V2 feature test uses Queue::fake() with
            // Carbon::setTestNow() pointing at a past date, so Ready tasks
            // carry created_at days behind the workers' real wall clock
            // and TaskRepairPolicy::dispatchOverdue re-claims them before
            // the test's next runReadyTaskForRun / waitFor lookup.
            //
            // V1: Watchdog::wake queries workflow_logs on every poll, and
            // during migrate:fresh's DROP TABLE on PostgreSQL that read
            // lock deadlocks against the test's exclusive-lock drop
            // (seen on CI run 24671180438 for V2ActivityTimeoutTest).
            // Blocking V1's 'workflow:watchdog:looping' here makes
            // Watchdog::wake's Cache::add return false and short-circuit
            // before the SELECT.
            //
            // TTL is 600s (10 minutes) so slow CI runners can take any
            // individual test well beyond either watchdog's internal
            // throttle without expiring mid-test. Each setUp re-arms
            // the keys.
            //
            // Tests that legitimately need either watchdog to fire call
            // their local wakeTaskWatchdog() helper, which forgets the
            // key and calls runPass(respectThrottle: false) directly.
            Cache::put(TaskWatchdog::LOOP_THROTTLE_KEY, true, self::WATCHDOG_THROTTLE_TTL_SECONDS);
            Cache::put(self::V1_WATCHDOG_LOOP_THROTTLE_KEY, true, self::WATCHDOG_THROTTLE_TTL_SECONDS);
        }
    }

    protected function defineDatabaseMigrations()
    {
        // migrate:fresh drops everything and runs migrations from registered
        // paths (the package's service provider). loadLaravelMigrations only
        // executes the defaults — it does not register them — so we must run
        // it AFTER migrate:fresh or the users table gets dropped and never
        // recreated.
        $this->artisan('migrate:fresh');

        $this->loadLaravelMigrations();
    }

    protected function getPackageProviders($app)
    {
        return [\Workflow\Providers\WorkflowServiceProvider::class];
    }

    protected function assertSameJsonObject(mixed $expected, mixed $actual): void
    {
        $this->assertIsArray($expected);
        $this->assertIsArray($actual);

        $this->assertSame(self::normalizeJsonObject($expected), self::normalizeJsonObject($actual));
    }

    private static function normalizeJsonObject(array $value): array
    {
        $normalized = array_map(
            static fn (mixed $item): mixed => is_array($item) ? self::normalizeJsonObject($item) : $item,
            $value,
        );

        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    private static function flushRedis(): void
    {
        $redis = self::redisConnection();

        if ($redis instanceof \Redis) {
            try {
                foreach (self::redisDatabases() as $database) {
                    $redis->select($database);
                    $redis->flushDB();
                }
            } catch (\Throwable $e) {
                // Ignore if no redis
            } finally {
                $redis->close();
            }
        }
    }

    private static function primeWatchdogThrottle(): void
    {
        $redis = self::redisConnection();

        if ($redis instanceof \Redis) {
            try {
                $redis->select(self::redisCacheDatabase());

                foreach ([TaskWatchdog::LOOP_THROTTLE_KEY, self::V1_WATCHDOG_LOOP_THROTTLE_KEY] as $key) {
                    foreach (self::cacheKeyCandidates($key) as $candidate) {
                        $redis->setex($candidate, self::WATCHDOG_THROTTLE_TTL_SECONDS, serialize(true));
                    }
                }
            } catch (\Throwable $e) {
                // Ignore if no redis
            } finally {
                $redis->close();
            }
        }
    }

    private static function redisConnection(): ?\Redis
    {
        $redisHost = getenv('REDIS_HOST') ?: ($_ENV['REDIS_HOST'] ?? null);
        if (! $redisHost || ! class_exists(\Redis::class)) {
            return null;
        }

        try {
            $redis = new \Redis();
            $redis->connect($redisHost, self::redisPort());

            return $redis;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return list<int>
     */
    private static function redisDatabases(): array
    {
        return array_values(array_unique([self::redisDatabase(), self::redisCacheDatabase()]));
    }

    /**
     * @return list<string>
     */
    private static function cacheKeyCandidates(string $key): array
    {
        $prefix = getenv('CACHE_PREFIX');

        if ($prefix === false) {
            $appName = getenv('APP_NAME') ?: ($_ENV['APP_NAME'] ?? 'Laravel');
            $prefix = Str::slug($appName, '_') . '_cache_';
        }

        return array_values(array_unique([$key, $prefix === '' ? $key : $prefix . ':' . $key]));
    }

    private static function redisPort(): int
    {
        return (int) (getenv('REDIS_PORT') ?: ($_ENV['REDIS_PORT'] ?? 6379));
    }

    private static function redisDatabase(): int
    {
        return (int) (getenv('REDIS_DB') ?: ($_ENV['REDIS_DB'] ?? 0));
    }

    private static function redisCacheDatabase(): int
    {
        return (int) (getenv('REDIS_CACHE_DB') ?: ($_ENV['REDIS_CACHE_DB'] ?? 1));
    }
}
