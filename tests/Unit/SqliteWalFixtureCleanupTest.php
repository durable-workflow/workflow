<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\TestCase;

final class SqliteWalFixtureCleanupTest extends TestCase
{
    public function testAnUnfinishedTransactionIsReportedAfterEveryConnectionIsClosed(): void
    {
        $database = $this->app->make('db');
        $connection = $database->connection();
        if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() === ':memory:') {
            $this->markTestSkipped('WAL fixture cleanup requires file-backed SQLite.');
        }
        $name = $connection->getName();
        $journal = (string) $connection->scalar('PRAGMA journal_mode');
        $this->useSqliteWalJournal();
        $cleanup = array_shift($this->beforeApplicationDestroyedCallbacks);
        config([
            'database.connections.wal_cleanup_peer' => config('database.connections.' . $name),
        ]);
        $peer = $database->connection('wal_cleanup_peer');
        $peer->table('migrations')
            ->count();
        $connection->beginTransaction();
        $connection->table('migrations')
            ->insert([
                'migration' => 'unfinished_wal_fixture',
                'batch' => 999,
            ]);
        $failure = null;
        $probe = null;
        $observedJournal = null;
        $remainingRows = null;
        $writerReady = false;

        try {
            try {
                $cleanup();
            } catch (ExpectationFailedException $error) {
                $failure = $error;
            }
            $primaryClosed = $connection->getRawPdo() === null;
            $peerClosed = $peer->getRawPdo() === null;
            $allClosed = $database->getConnections() === [];
            if ($primaryClosed && $peerClosed && $allClosed) {
                $probe = new PDO('sqlite:' . $connection->getDatabaseName(), options: [
                    PDO::ATTR_TIMEOUT => 0,
                ]);
                $observedJournal = $probe->query('PRAGMA journal_mode')
                    ->fetchColumn();
                $remainingRows = (int) $probe->query(
                    "SELECT COUNT(*) FROM migrations WHERE migration = 'unfinished_wal_fixture'"
                )
                    ->fetchColumn();
                $probe->exec('BEGIN EXCLUSIVE');
                $probe->exec('ROLLBACK');
                $writerReady = true;
            }
        } finally {
            $probe = null;
            foreach (array_keys($database->getConnections()) as $connectionName) {
                $database->purge($connectionName);
            }
            try {
                $database->connection($name)
                    ->statement('PRAGMA journal_mode=' . $journal);
            } finally {
                $database->purge($name);
            }
        }

        $this->assertInstanceOf(ExpectationFailedException::class, $failure);
        $this->assertTrue($primaryClosed);
        $this->assertTrue($peerClosed);
        $this->assertTrue($allClosed);
        $this->assertSame([
            $name => 1,
        ], $failure->getComparisonFailure()?->getActual());
        $this->assertSame($journal, $observedJournal);
        $this->assertSame(0, $remainingRows);
        $this->assertTrue($writerReady);
    }
}
