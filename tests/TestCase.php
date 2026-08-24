<?php

declare(strict_types=1);

namespace Tests;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Cache;
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

    protected function waitForWorkflow(WorkflowStub $workflow, float $timeoutSeconds = 30.0): void
    {
        $deadline = hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000);

        do {
            if (! $workflow->running()) {
                return;
            }

            usleep(50_000);
        } while (hrtime(true) < $deadline);

        $this->fail(sprintf(
            'Timed out after %.1f seconds waiting for workflow %s; status=%s, logs=%d, exceptions=%d.',
            $timeoutSeconds,
            (string) $workflow->id(),
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
        if (! interface_exists(\PHPUnit\Event\TestSuite\StartedSubscriber::class)) {
            return '';
        }

        return TestSuiteSubscriber::getCurrentSuite();
    }
}
