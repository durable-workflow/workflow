<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workflow\V2\Support\SqliteSavepointRelease;

final class SqliteSavepointReleaseTest extends TestCase
{
    private SQLiteConnection $connection;

    private Dispatcher $events;

    protected function setUp(): void
    {
        $this->events = new Dispatcher();
        $this->events->listen(
            [TransactionCommitted::class, TransactionRolledBack::class],
            SqliteSavepointRelease::release(...)
        );
        $this->connection = $this->connection('primary');
    }

    public function testFinishedNestedWritesDoNotRetainSavepointsUntilRootCommit(): void
    {
        $callbacks = 0;
        SqliteSavepointRelease::transaction($this->connection, function (Connection $database) use (&$callbacks): void {
            for ($index = 0; $index < 128; ++$index) {
                $database->transaction(static function (Connection $nested) use (&$callbacks): void {
                    $nested->insert('INSERT INTO state (value) VALUES (1)');
                    $nested->afterCommit(static function () use (&$callbacks): void {
                        ++$callbacks;
                    });
                });
            }
            $this->assertSame(0, $callbacks);
            $this->assertSame(1, $database->transactionLevel());
            $this->assertTrue($database->getPdo()->inTransaction());
            $this->assertMissingSavepoint($database->getPdo(), 'trans2');
        });
        $this->assertSame(128, $callbacks);
        $this->assertSame(128, (int) $this->connection->selectOne('SELECT COUNT(*) AS count FROM state')->count);
        $this->assertSame(0, $this->connection->transactionLevel());
        $this->assertFalse($this->connection->getPdo()->inTransaction());
    }

    public function testRootRollbackStillUndoesAllFinishedInnerWritesAndCallbacks(): void
    {
        $callbacks = 0;
        try {
            SqliteSavepointRelease::transaction($this->connection, static function (Connection $database) use (
                &$callbacks
            ): void {
                $database->transaction(static function (Connection $nested) use (&$callbacks): void {
                    $nested->insert('INSERT INTO state (value) VALUES (1)');
                    $nested->afterCommit(static function () use (&$callbacks): void {
                        ++$callbacks;
                    });
                });
                throw new RuntimeException('root rollback');
            });
            $this->fail('The root failure must propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame('root rollback', $error->getMessage());
        }
        $this->assertSame(0, $callbacks);
        $this->assertSame(0, (int) $this->connection->selectOne('SELECT COUNT(*) AS count FROM state')->count);

        // Failure must also restore ordinary application transaction behavior.
        $this->connection->beginTransaction();
        $this->connection->transaction(
            static fn (Connection $nested): bool => $nested->insert('INSERT INTO state (value) VALUES (2)')
        );
        $this->connection->getPdo()
            ->exec('ROLLBACK TO SAVEPOINT trans2');
        $this->assertSame(0, (int) $this->connection->selectOne('SELECT COUNT(*) AS count FROM state')->count);
        $this->connection->rollBack();
    }

    public function testCaughtInnerRollbackReleasesOnlyItsFinishedSavepoint(): void
    {
        SqliteSavepointRelease::transaction($this->connection, function (Connection $database): void {
            try {
                $database->transaction(static function (Connection $nested): void {
                    $nested->insert('INSERT INTO state (value) VALUES (1)');
                    throw new RuntimeException('inner rollback');
                });
            } catch (RuntimeException $error) {
                $this->assertSame('inner rollback', $error->getMessage());
            }
            $this->assertMissingSavepoint($database->getPdo(), 'trans2');
            $database->transaction(
                static fn (Connection $nested): bool => $nested->insert('INSERT INTO state (value) VALUES (2)')
            );
            $this->assertMissingSavepoint($database->getPdo(), 'trans2');
        });
        $this->assertSame([2], $this->connection->table('state')->pluck('value')->all());
    }

    public function testApplicationSavepointsAndOuterRollbackRemainOwnedByTheApplication(): void
    {
        $database = $this->connection;
        $database->beginTransaction();
        $database->transaction(
            static fn (Connection $nested): bool => $nested->insert('INSERT INTO state (value) VALUES (1)')
        );
        SqliteSavepointRelease::transaction($database, static function (Connection $workflow): void {
            $workflow->transaction(
                static fn (Connection $nested): bool => $nested->insert('INSERT INTO state (value) VALUES (2)')
            );
        });
        $this->assertSame(1, $database->transactionLevel());
        $this->assertSame(2, (int) $database->selectOne('SELECT COUNT(*) AS count FROM state')->count);
        // This is the older application savepoint with the same Laravel name.
        $database->getPdo()
            ->exec('ROLLBACK TO SAVEPOINT trans2');
        $this->assertSame(0, (int) $database->selectOne('SELECT COUNT(*) AS count FROM state')->count);
        $database->rollBack();
    }

    public function testNestedScopesRestoreTheEnclosingScopeAndIgnoreOtherConnections(): void
    {
        $secondary = $this->connection('secondary');
        SqliteSavepointRelease::transaction($this->connection, function (Connection $database) use ($secondary): void {
            SqliteSavepointRelease::transaction($database, static function (Connection $inner): void {
                $inner->transaction(
                    static fn (Connection $nested): bool => $nested->insert('INSERT INTO state (value) VALUES (1)')
                );
            });
            $database->transaction(
                static fn (Connection $nested): bool => $nested->insert('INSERT INTO state (value) VALUES (2)')
            );
            $this->assertMissingSavepoint($database->getPdo(), 'trans2');
            $secondary->beginTransaction();
            $secondary->transaction(
                static fn (Connection $nested): bool => $nested->insert('INSERT INTO state (value) VALUES (3)')
            );
            $secondary->getPdo()
                ->exec('ROLLBACK TO SAVEPOINT trans2');
            $this->assertSame(0, (int) $secondary->selectOne('SELECT COUNT(*) AS count FROM state')->count);
            $secondary->rollBack();
        });
        $this->assertSame([1, 2], $this->connection->table('state')->pluck('value')->all());
    }

    public function testOtherDriversUseTheirExistingTransactionPath(): void
    {
        $database = $this->createMock(Connection::class);
        $operation = static fn (): int => 42;
        $database->expects($this->once())
            ->method('getDriverName')
            ->willReturn('pgsql');
        $database->expects($this->never())
            ->method('getPdo');
        $database->expects($this->once())
            ->method('transaction')
            ->with($operation, 3)
            ->willReturn(42);
        $this->assertSame(42, SqliteSavepointRelease::transaction($database, $operation, 3));
    }

    public function testAfterCommitApplicationTransactionsAreOutsideTheWorkflowScope(): void
    {
        $called = false;
        SqliteSavepointRelease::transaction($this->connection, function (Connection $database) use (&$called): void {
            $database->insert('INSERT INTO state (value) VALUES (1)');
            $database->afterCommit(function () use ($database, &$called): void {
                $called = true;
                $database->beginTransaction();
                $database->transaction(
                    static fn (Connection $nested): bool => $nested->insert('INSERT INTO state (value) VALUES (2)')
                );
                $database->getPdo()
                    ->exec('ROLLBACK TO SAVEPOINT trans2');
                $this->assertSame([1], $database->table('state')->pluck('value')->all());
                $database->rollBack();
            });
        });
        $this->assertTrue($called);
        $this->assertSame([1], $this->connection->table('state')->pluck('value')->all());
    }

    private function connection(string $name): SQLiteConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE state (value INTEGER NOT NULL)');
        $database = new SQLiteConnection($pdo, ':memory:', '', [
            'driver' => 'sqlite',
            'name' => $name,
        ]);
        $database->setEventDispatcher($this->events);
        $database->setTransactionManager(new DatabaseTransactionsManager());

        return $database;
    }

    private function assertMissingSavepoint(PDO $pdo, string $name): void
    {
        try {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $name);
            $this->fail('Finished nested savepoint is still retained.');
        } catch (PDOException $error) {
            $this->assertStringContainsString('no such savepoint', $error->getMessage());
        }
    }
}
