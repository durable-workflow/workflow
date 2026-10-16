<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $container->instance('config', new Repository());
        $this->events = new Dispatcher($container);
        $this->connection->setEventDispatcher($this->events);
        $this->previousApplication = Facade::getFacadeApplication();
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('config');
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
        $pdo = $this->connection->getRawPdo();
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $this->connection->disconnect();
        DB::clearResolvedInstance('db');
        Facade::clearResolvedInstance('config');
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

    public function testConfiguredStorageMutationRollsBackItsOwnRoot(): void
    {
        $primary = $this->separateApplicationConnection();
        $boundStorage = false;
        $storageActive = false;
        $primaryActive = false;
        try {
            SqliteTransaction::run(function ($database) use (
                $primary,
                &$boundStorage,
                &$storageActive,
                &$primaryActive
            ): void {
                $boundStorage = $database === $this->connection;
                $storageActive = $this->connection->getPdo()
                    ->inTransaction();
                $primaryActive = $primary->getPdo()
                    ->inTransaction();
                $this->connection->update('UPDATE state SET value=value+1 WHERE id=1');
                $this->connection->insert('INSERT INTO state VALUES (1,99)');
            });
            $this->fail('The real constraint failure must propagate.');
        } catch (PDOException $exception) {
            $this->assertSame(19, $exception->errorInfo[1] & 255);
        }
        $this->assertSame(0, (int) $this->connection->selectOne('SELECT value FROM state WHERE id=1')->value);
        $this->assertTrue($boundStorage);
        $this->assertTrue($storageActive);
        $this->assertFalse($primaryActive);
        $this->assertSame(0, $primary->transactionLevel());
        $this->assertSame(0, $this->connection->transactionLevel());
        $this->assertFalse($this->connection->getPdo()->inTransaction());
    }

    public function testConfiguredStorageRetriesItsFreshWalSnapshot(): void
    {
        $primary = $this->separateApplicationConnection();
        $writer = $this->writer();
        $calls = 0;
        SqliteTransaction::run(function ($database) use ($writer, $primary, &$calls): void {
            $this->assertSame($this->connection, $database);
            $this->assertFalse($primary->getPdo()->inTransaction());
            $calls++;
            $this->connection->selectOne('SELECT value FROM state WHERE id=1');
            if ($calls === 1) {
                $writer->exec('UPDATE state SET value=1 WHERE id=2');
            }
            $this->connection->update('UPDATE state SET value=value+1 WHERE id=1');
        });
        $this->assertSame(2, $calls);
        $this->assertSame(1, (int) $this->connection->selectOne('SELECT value FROM state WHERE id=1')->value);
        $this->assertSame(0, $primary->transactionLevel());
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

    #[DataProvider('admissionBounds')]
    public function testCommittedAcknowledgementFailureDoesNotRepeatCallback(bool $claimed): void
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
            }, admission: $claimed ? function (): void {
                $this->connection->update('UPDATE state SET id=id WHERE id=1');
            } : null, admissionDeadline: $claimed ? hrtime(true) + 30_000_000_000 : null);
            $this->fail('The acknowledgement exception must propagate.');
        } catch (PDOException $actual) {
            $this->assertSame($error, $actual);
        }
        $this->assertSame(1, $calls);
        $this->assertSame(1, (int) $this->connection->selectOne('SELECT value FROM state WHERE id=1')->value);
    }

    #[DataProvider('admissionBounds')]
    public function testRollbackAcknowledgementFailureDoesNotRepeatCallback(bool $claimed): void
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
            }, admission: $claimed ? function (): void {
                $this->connection->update('UPDATE state SET id=id WHERE id=1');
            } : null, admissionDeadline: $claimed ? hrtime(true) + 30_000_000_000 : null);
            $this->fail('The rollback acknowledgement exception must propagate.');
        } catch (PDOException $actual) {
            $this->assertSame($error, $actual);
        }
        $this->assertSame(1, $calls);
        $this->assertFalse($this->connection->getPdo()->inTransaction());
    }

    public function testConstraintViolationDoesNotRepeatMutation(): void
    {
        $calls = 0;
        try {
            SqliteTransaction::run(function () use (&$calls): void {
                $calls++;
                $this->connection->insert('INSERT INTO state VALUES (1, 99)');
            });
            $this->fail('The constraint violation must propagate.');
        } catch (PDOException $exception) {
            $this->assertSame(19, $exception->errorInfo[1] & 255);
        }
        $this->assertSame(1, $calls);
        $this->assertSame(0, (int) $this->connection->selectOne('SELECT value FROM state WHERE id=1')->value);
    }

    public function testChangedPdoAfterRollbackDoesNotRepeatMutation(): void
    {
        $writer = $this->writer();
        $replacement = new PDO('sqlite:' . $this->path);
        $this->events->listen(TransactionRolledBack::class, function () use ($replacement): void {
            $this->connection->setPdo($replacement);
        });
        $calls = 0;
        try {
            SqliteTransaction::run(function () use ($writer, &$calls): void {
                $calls++;
                $this->connection->selectOne('SELECT value FROM state WHERE id=1');
                $writer->exec('UPDATE state SET value=1 WHERE id=2');
                $this->connection->update('UPDATE state SET value=1 WHERE id=1');
            });
            $this->fail('Connection replacement must propagate the original failure.');
        } catch (PDOException $exception) {
            $this->assertSame(5, $exception->errorInfo[1] & 255);
        }
        $this->assertSame(1, $calls);
        $this->assertSame($replacement, $this->connection->getPdo());
        $this->assertSame(0, (int) $this->connection->selectOne('SELECT value FROM state WHERE id=1')->value);
    }

    public function testDisconnectedPdoAfterRollbackPreservesOriginalError(): void
    {
        $writer = $this->writer();
        $reconnections = 0;
        $this->connection->setReconnector(static function () use (&$reconnections): void {
            $reconnections++;
            throw new \RuntimeException('The retry guard must not reconnect.');
        });
        $this->events->listen(TransactionRolledBack::class, function (): void {
            $this->connection->setPdo(null);
        });
        $calls = 0;
        try {
            SqliteTransaction::run(function () use ($writer, &$calls): void {
                $calls++;
                $this->connection->selectOne('SELECT value FROM state WHERE id=1');
                $writer->exec('UPDATE state SET value=1 WHERE id=2');
                $this->connection->update('UPDATE state SET value=1 WHERE id=1');
            });
            $this->fail('The disconnected transaction must keep its native error.');
        } catch (PDOException $exception) {
            $this->assertSame(5, $exception->errorInfo[1] & 255);
        }
        $this->assertSame(1, $calls);
        $this->assertSame(0, $reconnections);
        $this->assertNull($this->connection->getRawPdo());
    }

    public function testLazyPdoReplacementAfterRollbackDoesNotOpenConnection(): void
    {
        $writer = $this->writer();
        $opened = 0;
        $replacement = static function () use (&$opened): PDO {
            $opened++;
            throw new \RuntimeException('The rollback guard must not open the replacement.');
        };
        $this->events->listen(TransactionRolledBack::class, function () use ($replacement): void {
            $this->connection->setPdo($replacement);
        });
        try {
            SqliteTransaction::run(function () use ($writer): void {
                $this->connection->selectOne('SELECT value FROM state WHERE id=1');
                $writer->exec('UPDATE state SET value=1 WHERE id=2');
                $this->connection->update('UPDATE state SET value=1 WHERE id=1');
            });
            $this->fail('The lazy replacement must keep the native error.');
        } catch (PDOException $exception) {
            $this->assertSame(5, $exception->errorInfo[1] & 255);
        }
        $this->assertSame(0, $opened);
        $this->assertSame($replacement, $this->connection->getRawPdo());
    }

    public function testInitiallyDisconnectedConnectionUsesNormalReconnectOnce(): void
    {
        $this->connection->disconnect();
        $connections = 0;
        $this->connection->setReconnector(function ($database) use (&$connections): void {
            $connections++;
            $database->setPdo(new PDO('sqlite:' . $this->path));
        });
        $calls = 0;
        SqliteTransaction::run(function () use (&$calls): void {
            $calls++;
            $this->connection->update('UPDATE state SET value=1 WHERE id=1');
        });
        $this->assertSame(1, $connections);
        $this->assertSame(1, $calls);
        $this->assertSame(1, (int) $this->connection->selectOne('SELECT value FROM state WHERE id=1')->value);
    }

    public function testExplicitExistingAttemptLimitIsPreserved(): void
    {
        $writer = $this->writer();
        $calls = 0;
        SqliteTransaction::run(function () use ($writer, &$calls): void {
            $calls++;
            $this->connection->selectOne('SELECT value FROM state WHERE id=1');
            if ($calls < 5) {
                $writer->exec('UPDATE state SET value=value+1 WHERE id=2');
            }
            $this->connection->update('UPDATE state SET value=1 WHERE id=1');
        }, 5);
        $this->assertSame(5, $calls);
        $this->assertSame(1, (int) $this->connection->selectOne('SELECT value FROM state WHERE id=1')->value);
    }

    public function testExplicitSingleAttemptDoesNotAddRetries(): void
    {
        $writer = $this->writer();
        $calls = 0;
        try {
            SqliteTransaction::run(function () use ($writer, &$calls): void {
                $calls++;
                $this->connection->selectOne('SELECT value FROM state WHERE id=1');
                $writer->exec('UPDATE state SET value=1 WHERE id=2');
                $this->connection->update('UPDATE state SET value=1 WHERE id=1');
            }, 1);
            $this->fail('The configured one-attempt failure must propagate.');
        } catch (PDOException $exception) {
            $this->assertSame(5, $exception->errorInfo[1] & 255);
        }
        $this->assertSame(1, $calls);
    }

    public function testWriteAdmissionRetriesBeforeEnteringBody(): void
    {
        $writer = $this->writer();
        $writer->beginTransaction();
        $writer->exec('UPDATE state SET value=1 WHERE id=2');
        $this->events->listen(TransactionRolledBack::class, static function () use ($writer): void {
            if ($writer->inTransaction()) {
                $writer->commit();
            }
        });
        $admissions = 0;
        $calls = 0;
        SqliteTransaction::run(function () use (&$calls): void {
            $calls++;
            $this->connection->selectOne('SELECT value FROM state WHERE id=1');
            $this->connection->update('UPDATE state SET value=1 WHERE id=1');
        }, admission: function () use (&$admissions): void {
            $admissions++;
            $this->connection->update('UPDATE state SET id=id WHERE id=1');
        });
        $this->assertSame(2, $admissions);
        $this->assertSame(1, $calls);
        $this->assertSame(1, (int) $this->connection->selectOne('SELECT value FROM state WHERE id=1')->value);
    }

    public function testWriteAdmissionCanRecoverAfterThreeProvenRootRollbacks(): void
    {
        $writer = $this->writer();
        $writer->beginTransaction();
        $writer->exec('UPDATE state SET value=1 WHERE id=2');
        $rollbacks = 0;
        $this->events->listen(TransactionRolledBack::class, function () use ($writer, &$rollbacks): void {
            $rollbacks++;
            $this->assertSame(0, $this->connection->transactionLevel());
            $this->assertFalse($this->connection->getPdo()->inTransaction());
            if ($rollbacks === 3) {
                $writer->commit();
            }
        });
        $calls = 0;
        try {
            SqliteTransaction::run(function () use (&$calls): void {
                $calls++;
                $this->connection->update('UPDATE state SET value=value+1 WHERE id=1');
            }, admission: function (): void {
                $this->connection->update('UPDATE state SET id=id WHERE id=1');
            });
        } finally {
            if ($writer->inTransaction()) {
                $writer->rollBack();
            }
        }
        $this->assertSame(3, $rollbacks);
        $this->assertSame(1, $calls);
        $this->assertSame(1, (int) $this->connection->selectOne('SELECT value FROM state WHERE id=1')->value);
        $this->assertSame(1, (int) $this->connection->selectOne('SELECT value FROM state WHERE id=2')->value);
    }

    public static function admissionBounds(): array
    {
        return [
            'default admission' => [false],
            'claimed outcome admission' => [true],
        ];
    }

    #[DataProvider('admissionBounds')]
    public function testExhaustedAdmissionNeverEntersBody(bool $claimed): void
    {
        $writer = $this->writer();
        $writer->beginTransaction();
        $writer->exec('UPDATE state SET value=1 WHERE id=2');
        $admissions = 0;
        $calls = 0;
        try {
            SqliteTransaction::run(static function () use (&$calls): void {
                $calls++;
            }, 3, admission: function () use (&$admissions): void {
                $admissions++;
                $this->connection->update('UPDATE state SET id=id WHERE id=1');
            }, admissionDeadline: $claimed ? hrtime(true) + 30_000_000_000 : null);
            $this->fail('Persistent contention must keep the native error.');
        } catch (PDOException $exception) {
            $this->assertSame(5, $exception->errorInfo[1] & 255);
        } finally {
            $writer->rollBack();
        }
        $this->assertSame(3, $admissions);
        $this->assertSame(0, $calls);
        $this->assertSame(0, $this->connection->transactionLevel());
        $this->assertFalse($this->connection->getPdo()->inTransaction());
    }

    public function testDefaultAdmissionRemainsBoundedWithoutExecutingTheBody(): void
    {
        $writer = $this->writer();
        $writer->beginTransaction();
        $writer->exec('UPDATE state SET value=1 WHERE id=2');
        $admissions = 0;
        $calls = 0;
        try {
            SqliteTransaction::run(static function () use (&$calls): void {
                $calls++;
            }, admission: function () use (&$admissions): void {
                $admissions++;
                $this->connection->update('UPDATE state SET id=id WHERE id=1');
            });
            $this->fail('Persistent contention must keep its original exception.');
        } catch (PDOException $exception) {
            $this->assertSame(5, $exception->errorInfo[1] & 255);
        } finally {
            $writer->rollBack();
        }
        $this->assertGreaterThan(3, $admissions);
        $this->assertLessThanOrEqual(64, $admissions);
        $this->assertSame(0, $calls);
        $this->assertSame(0, $this->connection->transactionLevel());
        $this->assertFalse($this->connection->getPdo()->inTransaction());
    }

    public function testClaimedAdmissionStopsAtItsCapturedDeadlineWithoutEnteringTheBody(): void
    {
        $writer = $this->writer();
        $writer->beginTransaction();
        $writer->exec('UPDATE state SET value=1 WHERE id=2');
        $started = hrtime(true);
        $admissions = 0;
        $calls = 0;
        try {
            SqliteTransaction::run(static function () use (&$calls): void {
                $calls++;
            }, admission: function () use (&$admissions): void {
                $admissions++;
                $this->connection->update('UPDATE state SET id=id WHERE id=1');
            }, admissionDeadline: $started + 120_000_000);
            self::fail('The original admission deadline must keep the native contention error.');
        } catch (PDOException $exception) {
            self::assertSame(5, $exception->errorInfo[1] & 255);
        } finally {
            $writer->rollBack();
        }
        self::assertGreaterThan(1, $admissions);
        self::assertSame(0, $calls);
        self::assertLessThan(1_000_000_000, hrtime(true) - $started);
        self::assertSame(0, $this->connection->transactionLevel());
        self::assertFalse($this->connection->getPdo()->inTransaction());
    }

    #[DataProvider('admissionBounds')]
    public function testAdmittedBodyDoesNotRetryItsOwnContention(bool $claimed): void
    {
        $writer = $this->writer();
        $calls = 0;
        try {
            SqliteTransaction::run(static function () use ($writer, &$calls): void {
                $calls++;
                $writer->exec('UPDATE state SET value=1 WHERE id=2');
            }, admission: function (): void {
                $this->connection->update('UPDATE state SET id=id WHERE id=1');
            }, admissionDeadline: $claimed ? hrtime(true) + 30_000_000_000 : null);
            $this->fail('The body must keep its original contention failure.');
        } catch (PDOException $exception) {
            $this->assertSame(5, $exception->errorInfo[1] & 255);
        }
        $this->assertSame(1, $calls);
        $this->assertSame(0, $this->connection->transactionLevel());
        $this->assertFalse($this->connection->getPdo()->inTransaction());
    }

    private function separateApplicationConnection(): SQLiteConnection
    {
        $primary = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', [
            'driver' => 'sqlite',
        ]);
        Facade::getFacadeApplication()->make('config')->set('workflows.storage.connection', 'engine');
        DB::swap(new class($primary, $this->connection) {
            public function __construct(
                private SQLiteConnection $primary,
                private SQLiteConnection $storage
            ) {
            }

            public function connection(?string $name = null): SQLiteConnection
            {
                return $name === 'engine' ? $this->storage : $this->primary;
            }
        });

        return $primary;
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
