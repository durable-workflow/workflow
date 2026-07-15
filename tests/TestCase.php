<?php

declare(strict_types=1);

namespace Tests;

use Dotenv\Dotenv;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseServiceProvider;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\TaskWatchdog;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTruncation;
    use WithLaravelMigrations;

    public const NUMBER_OF_WORKERS = 2;

    protected const USE_DATABASE_TRUNCATION = true;

    private const V1_WATCHDOG_LOOP_THROTTLE_KEY = 'workflow:watchdog:looping';

    private const WATCHDOG_THROTTLE_TTL_SECONDS = 600;

    /**
     * Keep this harness's traits out of Testbench's cache for tests that extend
     * Orchestra directly in the same PHPUnit process.
     *
     * @var array<class-string, class-string>|null
     */
    protected static ?array $cachedTestCaseUses = null;

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
    }

    public static function tearDownAfterClass(): void
    {
        self::stopWorkers();
        self::flushRedis();
    }

    protected function setUp(): void
    {
        // DatabaseTruncation needs DBAL for Laravel 10 table discovery. Keep
        // schema mutations on Laravel's native path so installing DBAL does not
        // change rollback behavior exercised by migration tests.
        if (method_exists(SchemaBuilder::class, 'useNativeSchemaOperationsIfPossible')) {
            SchemaBuilder::useNativeSchemaOperationsIfPossible();
        }

        $currentSuite = TestSuiteSubscriber::getCurrentSuite();

        if ($currentSuite === 'feature') {
            Dotenv::createImmutable(__DIR__, '.env.feature')->safeLoad();
        } elseif ($currentSuite === 'unit') {
            Dotenv::createImmutable(__DIR__, '.env.unit')->safeLoad();
        }

        self::stopWorkers();
        self::flushRedis();
        self::resetWorkflowDefinitionRegistrations();

        parent::setUp();

        Queue::swap($this->app->make('queue'));

        Cache::flush();

        self::flushRedis();

        if ($currentSuite === 'feature') {
            // Block BOTH the V2 TaskWatchdog and the V1 Watchdog for the
            // duration of every feature test. The two testbench queue
            // workers spawned after the database reset both wake()s on every
            // Looping event in separate PHP processes.
            //
            // V2: almost every V2 feature test uses Queue::fake() with
            // Carbon::setTestNow() pointing at a past date, so Ready tasks
            // carry created_at days behind the workers' real wall clock
            // and TaskRepairPolicy::dispatchOverdue re-claims them before
            // the test's next runReadyTaskForRun / waitFor lookup.
            //
            // V1: Watchdog::wake queries workflow_logs on every poll, and
            // while the test process resets committed database state that
            // query can race with the reset. Blocking V1's
            // 'workflow:watchdog:looping' here makes
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
            self::primeWatchdogThrottle();
            self::startWorkers();
        }
    }

    protected function tearDown(): void
    {
        try {
            self::stopWorkers();
            self::flushRedis();
        } finally {
            parent::tearDown();
        }
    }

    protected function setUpDatabaseTruncation(): void
    {
        // Testbench's PendingCommand binds a mocked OutputStyle while it runs
        // migrations. Remove that per-command binding so application commands
        // invoked by a test can write to the output buffer supplied by Artisan.
        $this->app->offsetUnset(OutputStyle::class);
    }

    protected function setUpWithLaravelMigrations(): void
    {
        if (! static::USE_DATABASE_TRUNCATION) {
            return;
        }

        $this->app->make('migrator')
            ->path(\Orchestra\Testbench\default_migration_path());
    }

    protected function setUpTheTestEnvironmentTraitToBeIgnored(string $use): bool
    {
        // HandlesDatabases invokes this concern before DatabaseTruncation. Do
        // not invoke it again during generic trait setup after the migrated
        // flag changes, because that would schedule a per-test rollback.
        return $use === WithLaravelMigrations::class
            || parent::setUpTheTestEnvironmentTraitToBeIgnored($use);
    }

    /**
     * @return array<class-string, class-string>
     */
    protected function setUpTraitsWithoutDatabase(): array
    {
        $uses = static::cachedUsesForTestCase();

        unset($uses[DatabaseTruncation::class], $uses[WithLaravelMigrations::class]);

        return $this->setUpTheTestEnvironmentTraits($uses);
    }

    protected function getPackageProviders($app)
    {
        return [TestDatabaseServiceProvider::class, \Workflow\Providers\WorkflowServiceProvider::class];
    }

    protected function assertSameJsonObject(mixed $expected, mixed $actual): void
    {
        $this->assertIsArray($expected);
        $this->assertIsArray($actual);

        $this->assertSame(self::normalizeJsonObject($expected), self::normalizeJsonObject($actual));
    }

    protected static function stopWorkers(): void
    {
        foreach (self::$workers as $worker) {
            $worker->stop(3);
        }

        self::$workers = [];
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

    private static function resetWorkflowDefinitionRegistrations(): void
    {
        $registrations = new \ReflectionProperty(WorkflowDefinition::class, 'workflowTypeRegistrations');
        $registrations->setValue(null, []);
    }

    private static function startWorkers(): void
    {
        if (self::$workers !== []) {
            return;
        }

        $environment = getenv();
        $workerEnv = array_merge(
            is_array($environment) ? array_filter($environment, 'is_string') : [],
            array_filter($_SERVER, 'is_string'),
            array_filter($_ENV, 'is_string'),
        );

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
