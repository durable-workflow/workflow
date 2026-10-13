<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Connectors\DatabaseConnector;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\QueueServiceProvider;
use PHPUnit\Framework\TestCase;
use Workflow\Queue\DatabaseQueueCompatibility;
use Workflow\Queue\SqliteAcknowledgingDatabaseQueue;
use Workflow\Queue\SqliteDatabaseQueue;

final class DatabaseQueueCompatibilityTest extends TestCase
{
    public function testDefaultSqliteConnectorKeepsConfigurationAndManagerIdentity(): void
    {
        $app = $this->application();
        $manager = $app['queue'];
        $config = $app['config']->all();
        DatabaseQueueCompatibility::install($manager);
        DatabaseQueueCompatibility::install($manager);
        $queue = $manager->connection('database');

        $expected = version_compare(Application::VERSION, '12.54.0', '>=')
            ? SqliteDatabaseQueue::class : SqliteAcknowledgingDatabaseQueue::class;
        self::assertSame($expected, $queue::class);
        self::assertSame($manager, $app['queue']);
        self::assertSame($config, $app['config']->all());
        self::assertSame('database', $queue->getConnectionName());
        self::assertSame('original', $queue->getQueue(null));
        self::assertSame($app['db']->connection('sqlite'), $queue->getDatabase());
    }

    public function testNonSqliteUsesTheNativeQueueWithoutConnecting(): void
    {
        $app = $this->application();
        DatabaseQueueCompatibility::install($app['queue']);
        $queue = $app['queue']->connection('mysql');

        self::assertSame(DatabaseQueue::class, $queue::class);
        self::assertSame('mysql', $queue->getConnectionName());
        self::assertInstanceOf(\Closure::class, $queue->getDatabase()->getRawPdo());
    }

    public function testApplicationConnectorResolverRemainsLazyAndUnmodified(): void
    {
        $app = $this->application();
        $manager = $app['queue'];
        $calls = 0;
        $manager->addConnector('database', static function () use ($app, &$calls) {
            $calls++;

            return new DatabaseConnector($app['db']);
        });
        DatabaseQueueCompatibility::install($manager);
        self::assertSame(0, $calls);
        $queue = $manager->connection('database');
        self::assertSame(1, $calls);
        self::assertSame(DatabaseQueue::class, $queue::class);
    }

    public function testApplicationCanOverrideTheDefaultAfterInstallation(): void
    {
        $app = $this->application();
        $manager = $app['queue'];
        DatabaseQueueCompatibility::install($manager);
        $manager->addConnector('database', static fn () => new DatabaseConnector($app['db']));

        self::assertSame(DatabaseQueue::class, $manager->connection('database')::class);
    }

    public function testApplicationQueueManagerRemainsOwnedByTheApplication(): void
    {
        $app = $this->application();
        $manager = new class($app) extends QueueManager {
        };
        $calls = 0;
        $manager->addConnector('database', static function () use ($app, &$calls) {
            $calls++;

            return new DatabaseConnector($app['db']);
        });
        DatabaseQueueCompatibility::install($manager);

        self::assertSame(0, $calls);
        self::assertSame(DatabaseQueue::class, $manager->connection('database')::class);
        self::assertSame(1, $calls);
    }

    private function application(): Application
    {
        $app = new Application(sys_get_temp_dir());
        $app->instance('config', new Repository([
            'database' => [
                'default' => 'sqlite',
                'connections' => [
                    'sqlite' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                        'prefix' => '',
                    ],
                    'mysql' => [
                        'driver' => 'mysql',
                        'database' => 'qualification',
                        'host' => '127.0.0.1',
                        'username' => 'qualification',
                        'password' => '',
                        'prefix' => '',
                    ],
                ],
            ],
            'queue' => [
                'default' => 'database',
                'connections' => [
                    'database' => [
                        'driver' => 'database',
                        'connection' => 'sqlite',
                        'table' => 'jobs',
                        'queue' => 'original',
                        'retry_after' => 123,
                        'after_commit' => true,
                    ],
                    'mysql' => [
                        'driver' => 'database',
                        'connection' => 'mysql',
                        'table' => 'jobs',
                        'queue' => 'original',
                        'retry_after' => 123,
                        'after_commit' => true,
                    ],
                ],
            ],
        ]));
        $app->instance('events', new Dispatcher($app));
        (new DatabaseServiceProvider($app))->register();
        (new QueueServiceProvider($app))->register();

        return $app;
    }
}
