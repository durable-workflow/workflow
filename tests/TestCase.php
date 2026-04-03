<?php

declare(strict_types=1);

namespace Tests;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Symfony\Component\Process\Process;

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

        for ($i = 0; $i < self::NUMBER_OF_WORKERS; $i++) {
            self::$workers[$i] = new Process(['php', __DIR__ . '/../vendor/bin/testbench', 'queue:work']);
            if (! self::shouldCaptureWorkerOutput()) {
                self::$workers[$i]->disableOutput();
                self::$workers[$i]->start();
                continue;
            }

            file_put_contents(self::workerLogPath($i), '');

            self::$workers[$i]->start(static function (string $type, string $output) use ($i): void {
                file_put_contents(self::workerLogPath($i), $output, FILE_APPEND | LOCK_EX);
            });
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$workers as $worker) {
            $worker->stop();
        }
    }

    protected function setUp(): void
    {
        if (TestSuiteSubscriber::getCurrentSuite() === 'feature') {
            Dotenv::createImmutable(__DIR__, '.env.feature')->safeLoad();
        } elseif (TestSuiteSubscriber::getCurrentSuite() === 'unit') {
            Dotenv::createImmutable(__DIR__, '.env.unit')->safeLoad();
        }

        parent::setUp();

        Cache::flush();

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

    private static function shouldCaptureWorkerOutput(): bool
    {
        $value = getenv(
            'WORKFLOW_TEST_CAPTURE_WORKER_OUTPUT'
        ) ?: ($_ENV['WORKFLOW_TEST_CAPTURE_WORKER_OUTPUT'] ?? null);

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private static function workerLogPath(int $worker): string
    {
        return dirname(
            __DIR__
        ) . '/vendor/orchestra/testbench-core/laravel/storage/logs/workflow-test-worker-' . $worker . '.log';
    }
}
