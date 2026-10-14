<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Container\Container;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workflow\V2\Support\SqliteTransaction;

final class SqliteTransactionTest extends TestCase
{
    private string $path;

    private SQLiteConnection $connection;

    private Dispatcher $events;

    private mixed $previousApplication;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'dw-v2-sqlite-');
        $pdo = new PDO('sqlite:' . $this->path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=20');
        $pdo->exec('CREATE TABLE state (id INTEGER PRIMARY KEY, value INTEGER)');
        $pdo->exec('INSERT INTO state VALUES (1, 0), (2, 0)');
        $this->connection = new SQLiteConnection($pdo, $this->path, '', [
            'driver' => 'sqlite',
        ]);
        $container = new Container();
        $this->events = new Dispatcher($container);
        $this->connection->setEventDispatcher($this->events);
        $this->previousApplication = Facade::getFacadeApplication();
        Facade::setFacadeApplication($container);
        DB::swap(new class($this->connection) {
            public function __construct(
                private readonly SQLiteConnection $database
            ) {
            }

            public function connection(): SQLiteConnection
            {
                return $this->database;
            }
        });
    }

    protected function tearDown(): void
    {
        $pdo = $this->connection->getPdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $this->connection->disconnect();
        DB::clearResolvedInstance('db');
        Facade::setFacadeApplication($this->previousApplication);
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($this->path . $suffix)) {
                unlink($this->path . $suffix);
            }
        }
        parent::tearDown();
    }

    public function testCommittedWalWriterRetriesFreshRootSnapshot(): void
    {
        $writer = $this->writer();
        $calls = 0;
        $result = SqliteTransaction::run(function () use ($writer, &$calls): int {
            $value = $this->connection->selectOne('SELECT value FROM state WHERE id=1')
->value;
            $calls++;
            if ($calls === 1) {
                $writer->exec('UPDATE state SET value=1 WHERE id=2');
            }
            $this->connection->update('UPDATE state SET value=value+1 WHERE id=1');

            return (int) $value;
        });

        $this->assertSame(0, $result);
        $this->assertSame(2, $calls);
        $this->assertSame(1, (int) $this->connection->selectOne('SELECT value FROM state WHERE id=1')->value);
        $this->assertSame(0, $this->connection->transactionLevel());
        $this->assertFalse($this->connection->getPdo()->inTransaction());
    }

    public function testPersistentWriterStopsAfterThreeRolledBackAttempts(): void
    {
        $writer = $this->writer();
        $writer->beginTransaction();
        $writer->exec('UPDATE state SET value=1 WHERE id=2');
        $calls = 0;
        try {
            SqliteTransaction::run(function () use (&$calls): void {
                $calls++;
                $this->connection->selectOne('SELECT value FROM state WHERE id=1');
                $this->connection->update('UPDATE state SET value=1 WHERE id=1');
            });
            $this->fail('The persistent writer must remain an error.');
        } catch (PDOException $exception) {
            $this->assertSame(5, $exception->errorInfo[1] & 255);
        } finally {
            $writer->rollBack();
        }
        $this->assertSame(3, $calls);
        $this->assertSame(0, (int) $this->connection->selectOne('SELECT value FROM state WHERE id=1')->value);
        $this->assertFalse($this->connection->getPdo()->inTransaction());
    }

    public function testNestedTransactionDoesNotRepeatMutation(): void
    {
        $writer = $this->writer();
        $calls = 0;
        $error = null;
        try {
            $this->connection->transaction(function () use ($writer, &$calls): void {
                SqliteTransaction::run(function () use ($writer, &$calls): void {
                    $calls++;
                    $this->connection->selectOne('SELECT value FROM state WHERE id=1');
                    $writer->exec('UPDATE state SET value=1 WHERE id=2');
                    $this->connection->update('UPDATE state SET value=1 WHERE id=1');
                });
            });
        } catch (Throwable $exception) {
            $error = $exception;
        }
        $this->assertNotNull($error, 'Nested contention must propagate.');
        $this->assertSame(1, $calls);
        $this->assertSame(0, $this->connection->transactionLevel());
    }

    public function testCommittedAcknowledgementFailureDoesNotRepeatCallback(): void
    {
        $error = $this->acknowledgementError();
        $this->events->listen(TransactionCommitted::class, static function () use ($error): void {
            throw $error;
        });
        $calls = 0;
        try {
            SqliteTransaction::run(function () use (&$calls): void {
                $calls++;
                $this->connection->update('UPDATE state SET value=1 WHERE id=1');
            });
            $this->fail('The acknowledgement exception must propagate.');
        } catch (PDOException $actual) {
            $this->assertSame($error, $actual);
        }
        $this->assertSame(1, $calls);
        $this->assertSame(1, (int) $this->connection->selectOne('SELECT value FROM state WHERE id=1')->value);
    }

    public function testRollbackAcknowledgementFailureDoesNotRepeatCallback(): void
    {
        $writer = $this->writer();
        $error = $this->acknowledgementError();
        $this->events->listen(TransactionRolledBack::class, static function () use ($error): void {
            throw $error;
        });
        $calls = 0;
        try {
            SqliteTransaction::run(function () use ($writer, &$calls): void {
                $calls++;
                $this->connection->selectOne('SELECT value FROM state WHERE id=1');
                $writer->exec('UPDATE state SET value=1 WHERE id=2');
                $this->connection->update('UPDATE state SET value=1 WHERE id=1');
            });
            $this->fail('The rollback acknowledgement exception must propagate.');
        } catch (PDOException $actual) {
            $this->assertSame($error, $actual);
        }
        $this->assertSame(1, $calls);
        $this->assertFalse($this->connection->getPdo()->inTransaction());
    }

    private function writer(): PDO
    {
        $writer = new PDO('sqlite:' . $this->path);
        $writer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $writer->exec('PRAGMA busy_timeout=20');

        return $writer;
    }

    private function acknowledgementError(): PDOException
    {
        $error = new PDOException('Injected acknowledgement loss after a real transaction boundary.');
        $error->errorInfo = ['HY000', 5, 'database is locked'];

        return $error;
    }
}
