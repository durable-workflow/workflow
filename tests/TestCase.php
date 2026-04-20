<?php

declare(strict_types=1);

namespace Tests;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Symfony\Component\Process\Process;
use Workflow\V2\TaskWatchdog;

abstract class TestCase extends BaseTestCase
{
    public const NUMBER_OF_WORKERS = 2;

    private static $workers = [];

    public static function setUpBeforeClass(): void
    {
        if (TestSuiteSubscriber::getCurrentSuite() === 'feature') {
            Dotenv::createImmutable(__DIR__, '.env.feature')->safeLoad();
        } elseif (TestSuiteSubscriber::getCurrentSuite() === 'unit') {
            Dotenv::createImmutable(__DIR__, '.env.unit')->safeLoad();
        }

        foreach ($_ENV as $key => $value) {
            if (is_string($value) && getenv($key) === false) {
                putenv("{$key}={$value}");
            }
        }

        self::flushRedis();

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
            Cache::put(TaskWatchdog::LOOP_THROTTLE_KEY, true, 600);
            Cache::put('workflow:watchdog:looping', true, 600);
        }
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadLaravelMigrations();

        $this->artisan('migrate:fresh');
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
        $redisHost = getenv('REDIS_HOST') ?: ($_ENV['REDIS_HOST'] ?? null);
        $redisPort = getenv('REDIS_PORT') ?: ($_ENV['REDIS_PORT'] ?? 6379);
        if ($redisHost && class_exists(\Redis::class)) {
            try {
                $redis = new \Redis();
                $redis->connect($redisHost, (int) $redisPort);
                $redis->flushDB();
            } catch (\Throwable $e) {
                // Ignore if no redis
            }
        }
    }
}
