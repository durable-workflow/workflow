<?php

declare(strict_types=1);

namespace Tests;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Symfony\Component\Process\Process;
use Workflow\WorkflowStub;

abstract class TestCase extends BaseTestCase
{
    public const NUMBER_OF_WORKERS = 2;

    private static $workers = [];

    public static function setUpBeforeClass(): void
    {
        if (self::currentSuite() === 'feature') {
            Dotenv::createImmutable(__DIR__, '.env.feature')->safeLoad();
        } elseif (self::currentSuite() === 'unit') {
            Dotenv::createImmutable(__DIR__, '.env.unit')->safeLoad();
        }

        foreach ($_ENV as $key => $value) {
            if (is_string($value) && getenv($key) === false) {
                putenv("{$key}={$value}");
            }
        }

        self::flushRedis();

        $workerEnvironment = self::currentSuite() === 'feature'
            ? [
                'WORKFLOW_WATCHDOG_ENABLED' => 'false',
            ]
            : null;

        for ($i = 0; $i < self::NUMBER_OF_WORKERS; $i++) {
            self::$workers[$i] = new Process(
                ['php', __DIR__ . '/../vendor/bin/testbench', 'queue:work'],
                env: $workerEnvironment,
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
        if (self::currentSuite() === 'feature') {
            Dotenv::createImmutable(__DIR__, '.env.feature')->safeLoad();
        } elseif (self::currentSuite() === 'unit') {
            Dotenv::createImmutable(__DIR__, '.env.unit')->safeLoad();
        }

        parent::setUp();

        Cache::flush();

        self::flushRedis();
    }

    protected function defineDatabaseMigrations()
    {
        $this->artisan('migrate:fresh', [
            '--path' => dirname(__DIR__) . '/src/migrations',
            '--realpath' => true,
        ]);

        $this->loadLaravelMigrations();
    }

    protected function getPackageProviders($app)
    {
        return [\Workflow\Providers\WorkflowServiceProvider::class];
    }

    /**
     * @param (callable(WorkflowStub): bool)|null $condition
     */
    protected function waitForWorkflow(
        WorkflowStub $workflow,
        ?callable $condition = null,
        string $expectedState = 'a terminal state',
        float $timeoutSeconds = 30.0,
    ): void {
        if ($timeoutSeconds <= 0) {
            throw new InvalidArgumentException('Workflow polling timeout must be greater than zero.');
        }

        $condition ??= static fn (WorkflowStub $workflow): bool => ! $workflow->running();
        $deadline = hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000);

        do {
            if ($condition($workflow)) {
                return;
            }

            $remainingNanoseconds = $deadline - hrtime(true);
            if ($remainingNanoseconds <= 0) {
                break;
            }

            usleep((int) min(50_000, max(1, (int) ceil($remainingNanoseconds / 1_000))));
        } while (true);

        $this->fail(sprintf(
            'Timed out after %.3f seconds waiting for workflow %s to reach %s; status=%s, logs=%d, exceptions=%d.',
            $timeoutSeconds,
            (string) $workflow->id(),
            $expectedState,
            (string) $workflow->status(),
            $workflow->logs()
                ->count(),
            $workflow->exceptions()
                ->count(),
        ));
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

    private static function currentSuite(): string
    {
        if (str_starts_with(static::class, __NAMESPACE__ . '\\Feature\\')) {
            return 'feature';
        }

        if (str_starts_with(static::class, __NAMESPACE__ . '\\Unit\\')) {
            return 'unit';
        }

        if (! interface_exists(\PHPUnit\Event\TestSuite\StartedSubscriber::class)) {
            return '';
        }

        return TestSuiteSubscriber::getCurrentSuite();
    }
}
