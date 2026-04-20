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

        for ($i = 0; $i < self::NUMBER_OF_WORKERS; $i++) {
            self::$workers[$i] = new Process(['php', __DIR__ . '/../vendor/bin/testbench', 'queue:work']);
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
            // Block the V2 TaskWatchdog for the duration of every feature test.
            // The two testbench queue workers spawned in setUpBeforeClass run
            // TaskWatchdog::wake() on every Looping event in separate PHP
            // processes with real-time now(). Almost every V2 feature test
            // uses Queue::fake() with Carbon::setTestNow() pointing at a past
            // date, which leaves Ready tasks whose created_at is days behind
            // the workers' wall clock — TaskRepairPolicy::dispatchOverdue
            // flags them and a worker re-claims via the real Bus before the
            // test's next runReadyTaskForRun / waitFor lookup. Setting the
            // throttle key here short-circuits those wakes cleanly; tests
            // that legitimately need the watchdog call $this->wakeTaskWatchdog()
            // (or the equivalent inline Cache::forget) and reset it.
            //
            // TTL is 600s (10 minutes) so slow CI runners can take any
            // individual test well beyond the 60s original without the
            // throttle expiring mid-test and letting a worker's runPass
            // interleave. Each setUp re-arms the key regardless, so even
            // long classes stay protected.
            //
            // V1 Watchdog is scoped under a different cache key
            // ('workflow:watchdog:looping') and has its own 60s add-throttle
            // inside Watchdog::wake, so this does not affect V1 tests.
            Cache::put(TaskWatchdog::LOOP_THROTTLE_KEY, true, 600);
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
