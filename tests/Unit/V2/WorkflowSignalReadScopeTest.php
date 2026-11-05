<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Connection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowSignalReadScope;

final class WorkflowSignalReadScopeTest extends TestCase
{
    public static function entryConditions(): iterable
    {
        yield 'root MySQL' => ['mysql', 1, true];
        yield 'no transaction' => ['mysql', 0, false];
        yield 'caller transaction' => ['mysql', 2, false];
        yield 'other driver' => ['sqlite', 1, false];
    }

    #[DataProvider('entryConditions')]
    public function testOnlyTheOwnedMysqlRootTransactionOpensAScope(string $driver, int $depth, bool $allowed): void
    {
        $connection = $this->connection($driver);
        $run = $this->makeRun($connection, 'one');
        for ($i = 0; $i < $depth; $i++) {
            $connection->beginTransaction();
        }
        try {
            $this->assertFalse(WorkflowSignalReadScope::allowsConsistentRead($run, $connection));
            $result = WorkflowSignalReadScope::forLockedRun($run, function () use (
                $run,
                $connection,
                $allowed
            ): string {
                $this->assertSame($allowed, WorkflowSignalReadScope::allowsConsistentRead($run, $connection));
                return 'returned';
            });
            $this->assertSame('returned', $result);
            $this->assertFalse(WorkflowSignalReadScope::allowsConsistentRead($run, $connection));
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack(0);
            }
        }
    }

    public function testScopeRequiresTheSameRunConnectionAndPdo(): void
    {
        $connection = $this->connection();
        $other = $this->connection();
        $run = $this->makeRun($connection, 'one');
        $connection->beginTransaction();
        try {
            WorkflowSignalReadScope::forLockedRun($run, function () use ($connection, $other, $run): void {
                $this->assertTrue(WorkflowSignalReadScope::allowsConsistentRead($run, $connection));
                $this->assertFalse(
                    WorkflowSignalReadScope::allowsConsistentRead($this->makeRun($connection, 'two'), $connection)
                );
                $this->assertFalse(WorkflowSignalReadScope::allowsConsistentRead($run, $other));
                $pdo = $connection->getPdo();
                try {
                    $connection->setPdo(new PDO('sqlite::memory:'));
                    $connection->beginTransaction();
                    $this->assertFalse(WorkflowSignalReadScope::allowsConsistentRead($run, $connection));
                    $connection->rollBack();
                } finally {
                    $connection->setPdo($pdo);
                }
                // setPdo resets Laravel's depth even though the old PDO still
                // has its transaction. A stale transaction must stay refused.
                $this->assertFalse(WorkflowSignalReadScope::allowsConsistentRead($run, $connection));
            });
        } finally {
            if ($connection->getPdo()->inTransaction()) {
                $connection->getPdo()
                    ->rollBack();
            }
        }
    }

    public function testExceptionsClearTheScopeBeforeAnotherCallerCanUseTheConnection(): void
    {
        $connection = $this->connection();
        $run = $this->makeRun($connection, 'one');
        $connection->beginTransaction();
        try {
            try {
                WorkflowSignalReadScope::forLockedRun($run, function () use ($run, $connection): never {
                    $this->assertTrue(WorkflowSignalReadScope::allowsConsistentRead($run, $connection));
                    throw new RuntimeException('scope control');
                });
                $this->fail('The original exception was hidden.');
            } catch (RuntimeException $error) {
                $this->assertSame('scope control', $error->getMessage());
            }
            $this->assertFalse(WorkflowSignalReadScope::allowsConsistentRead($run, $connection));
        } finally {
            $connection->rollBack();
        }
    }

    public function testNestedFailureRestoresTheOuterRunScope(): void
    {
        $connection = $this->connection();
        $outer = $this->makeRun($connection, 'outer');
        $inner = $this->makeRun($connection, 'inner');
        $connection->beginTransaction();
        try {
            $result = WorkflowSignalReadScope::forLockedRun($outer, function () use ($connection, $outer, $inner): string {
                $this->assertTrue(WorkflowSignalReadScope::allowsConsistentRead($outer, $connection));
                try {
                    WorkflowSignalReadScope::forLockedRun($inner, function () use ($connection, $outer, $inner): never {
                        $this->assertTrue(WorkflowSignalReadScope::allowsConsistentRead($inner, $connection));
                        $this->assertFalse(WorkflowSignalReadScope::allowsConsistentRead($outer, $connection));
                        throw new RuntimeException('nested scope control');
                    });
                    $this->fail('The inner failure was hidden.');
                } catch (RuntimeException $error) {
                    $this->assertSame('nested scope control', $error->getMessage());
                }
                $this->assertTrue(WorkflowSignalReadScope::allowsConsistentRead($outer, $connection));
                $this->assertFalse(WorkflowSignalReadScope::allowsConsistentRead($inner, $connection));

                return 'outer restored';
            });
            $this->assertSame('outer restored', $result);
            $this->assertFalse(WorkflowSignalReadScope::allowsConsistentRead($outer, $connection));
            $this->assertFalse(WorkflowSignalReadScope::allowsConsistentRead($inner, $connection));
        } finally {
            $connection->rollBack();
        }
    }

    private function connection(string $driver = 'mysql'): Connection
    {
        // SQLite backs only scope/identity unit controls. The separate feature
        // suite exercises actual InnoDB locking with independent processes.
        return new Connection(new PDO('sqlite::memory:'), config: [
            'driver' => $driver,
        ]);
    }

    private function makeRun(Connection $connection, string $id): WorkflowRun
    {
        $run = new class() extends WorkflowRun {
            public Connection $scopeConnection;

            public function getConnection(): Connection
            {
                return $this->scopeConnection;
            }
        };
        $run->scopeConnection = $connection;
        $run->id = $id;
        return $run;
    }
}
