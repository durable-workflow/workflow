<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use Illuminate\Container\Container;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workflow\Queue\SqliteAcknowledgingDatabaseQueue;

final class SqliteDatabaseAcknowledgementTest extends TestCase
{
    private string $path;

    private SQLiteConnection $connection;

    private SqliteAcknowledgingDatabaseQueue $queue;

    private Dispatcher $events;

    private int $deleteStatements = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'queue-ack-');
        $pdo = $this->pdo();
        $pdo->exec(
            'CREATE TABLE jobs (id INTEGER PRIMARY KEY, queue TEXT, payload TEXT, attempts INTEGER, reserved_at INTEGER, available_at INTEGER, created_at INTEGER)'
        );
        $this->connection = new SQLiteConnection($pdo, $this->path);
        $container = new Container();
        $this->events = new Dispatcher($container);
        $this->connection->setEventDispatcher($this->events);
        $this->queue = new SqliteAcknowledgingDatabaseQueue($this->connection, 'jobs', 'original', 90);
        $this->queue->setContainer($container);
        AcknowledgementReceiptJob::$handled = 0;
        $this->connection->beforeExecuting(function (string $sql): void {
            if (str_starts_with(strtolower($sql), 'delete')) {
                $this->deleteStatements++;
            }
        });
    }

    protected function tearDown(): void
    {
        if ($this->connection->getRawPdo() instanceof PDO && $this->connection->getPdo()->inTransaction()) {
            $this->connection->getPdo()
                ->rollBack();
        }
        $this->connection->disconnect();
        unlink($this->path);
    }

    public function testRealRolledBackDeleteRecoversWithoutExecutingTheHandlerAgain(): void
    {
        $job = $this->job();
        $neighbor = $this->push();
        $writer = $this->blockDelete();
        $rollbacks = 0;
        $this->events->listen(TransactionRolledBack::class, function () use ($writer, &$rollbacks): void {
            $rollbacks++;
            self::assertFalse($this->connection->getPdo()->inTransaction());
            self::assertSame(0, $this->connection->transactionLevel());
            $writer->rollBack();
        });

        $job->fire();

        self::assertSame(1, AcknowledgementReceiptJob::$handled);
        self::assertSame(1, $rollbacks);
        self::assertSame(2, $this->deleteStatements);
        self::assertTrue($job->isDeleted());
        self::assertNull($this->connection->table('jobs')->find($job->getJobId()));
        self::assertSame(0, $this->connection->table('jobs')->find($neighbor)->attempts);
    }

    public function testSustainedBusyStopsAfterThreeStatementsWithTheOriginalReservationIntact(): void
    {
        $job = $this->job();
        $original = $this->connection->table('jobs')
            ->find($job->getJobId());
        $writer = $this->blockDelete();
        try {
            $job->fire();
            self::fail('Expected the original SQLite busy error.');
        } catch (QueryException $exception) {
            self::assertSame(5, $exception->getPrevious()->errorInfo[1] & 255);
        } finally {
            $writer->rollBack();
        }
        self::assertSame(3, $this->deleteStatements);
        self::assertSame(1, AcknowledgementReceiptJob::$handled);
        self::assertEquals($original, $this->connection->table('jobs')->find($job->getJobId()));
    }

    public function testAReclaimedReservationSurvivesTheOriginalAcknowledgementRetry(): void
    {
        $job = $this->job();
        $writer = $this->blockDelete();
        $this->events->listen(TransactionRolledBack::class, function () use ($writer, $job): void {
            $writer->rollBack();
            $this->connection->table('jobs')
                ->where('id', $job->getJobId())
                ->update([
                    'attempts' => 2,
                    'reserved_at' => time() + 1,
                ]);
        });
        $job->fire();
        self::assertSame(2, $this->deleteStatements);
        self::assertSame(2, $this->connection->table('jobs')->find($job->getJobId())->attempts);
        self::assertSame(1, AcknowledgementReceiptJob::$handled);
    }

    public function testChangedQueueAndReservationTimestampAreAlsoFenced(): void
    {
        foreach ([[
            'queue' => 'neighbor',
        ], [
            'reserved_at' => time() + 1,
        ]] as $change) {
            $job = $this->job();
            $this->connection->table('jobs')
                ->where('id', $job->getJobId())
                ->update($change);
            $job->delete();
            self::assertNotNull($this->connection->table('jobs')->find($job->getJobId()));
        }
    }

    public function testCommittedAcknowledgementCanBeRepeatedWithoutASecondHandler(): void
    {
        $job = $this->job();
        $job->fire();
        $job->delete();
        self::assertSame(1, AcknowledgementReceiptJob::$handled);
        self::assertNull($this->connection->table('jobs')->find($job->getJobId()));
    }

    public function testLostCommitReplyIsPropagatedWithoutAnotherDelete(): void
    {
        $job = $this->job();
        $this->events->listen(TransactionCommitted::class, static function (): void {
            throw new RuntimeException('commit-reply-unavailable');
        });
        try {
            $job->fire();
            self::fail('Expected the original commit uncertainty.');
        } catch (RuntimeException $exception) {
            self::assertSame('commit-reply-unavailable', $exception->getMessage());
        }
        self::assertSame(1, $this->deleteStatements);
        self::assertSame(1, AcknowledgementReceiptJob::$handled);
        self::assertNull($this->connection->table('jobs')->find($job->getJobId()));
    }

    public function testUncertainRollbackIsNotRetried(): void
    {
        $job = $this->job();
        $writer = $this->blockDelete();
        $this->events->listen(TransactionRolledBack::class, static function () use ($writer): void {
            $writer->rollBack();
            throw new RuntimeException('rollback-reply-unavailable');
        });
        try {
            $job->fire();
            self::fail('Expected the original rollback uncertainty.');
        } catch (RuntimeException $exception) {
            self::assertSame('rollback-reply-unavailable', $exception->getMessage());
        }
        self::assertSame(1, $this->deleteStatements);
        self::assertNotNull($this->connection->table('jobs')->find($job->getJobId()));
    }

    public function testChangedPhysicalConnectionIsNotRetried(): void
    {
        $job = $this->job();
        $writer = $this->blockDelete();
        $this->events->listen(TransactionRolledBack::class, function () use ($writer): void {
            $writer->rollBack();
            $this->connection->setPdo($this->pdo());
        });
        try {
            $job->delete();
            self::fail('Expected the original SQLite busy error.');
        } catch (QueryException $exception) {
            self::assertSame(5, $exception->getPrevious()->errorInfo[1] & 255);
        }
        self::assertSame(1, $this->deleteStatements);
        self::assertNotNull($this->connection->table('jobs')->find($job->getJobId()));
    }

    public function testNestedTransactionDoesNotEnterRootRollbackRecovery(): void
    {
        $job = $this->job();
        $writer = $this->blockDelete();
        $this->connection->beginTransaction();
        try {
            $job->delete();
            self::fail('Expected the nested transaction failure.');
        } catch (\Illuminate\Database\DeadlockException $exception) {
            self::assertInstanceOf(QueryException::class, $exception->getPrevious());
        } finally {
            $writer->rollBack();
            $this->connection->rollBack();
        }
        self::assertSame(1, $this->deleteStatements);
        self::assertNotNull($this->connection->table('jobs')->find($job->getJobId()));
    }

    public function testConstraintFailureIsNotClassifiedAsBusy(): void
    {
        $job = $this->job();
        $this->connection->getPdo()
            ->exec(
                "CREATE TRIGGER refuse_delete BEFORE DELETE ON jobs BEGIN SELECT RAISE(ABORT, 'delete-constraint'); END"
            );
        try {
            $job->delete();
            self::fail('Expected the real SQLite constraint failure.');
        } catch (QueryException $exception) {
            self::assertSame(19, $exception->getPrevious()->errorInfo[1] & 255);
        }
        self::assertSame(1, $this->deleteStatements);
        self::assertNotNull($this->connection->table('jobs')->find($job->getJobId()));
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 20');
        return $pdo;
    }

    private function push(): int
    {
        return $this->queue->pushRaw(json_encode([
            'job' => AcknowledgementReceiptJob::class . '@fire',
            'data' => [],
        ], JSON_THROW_ON_ERROR));
    }

    private function job(): \Illuminate\Queue\Jobs\DatabaseJob
    {
        $this->push();
        return $this->queue->pop();
    }

    private function blockDelete(): PDO
    {
        $writer = $this->pdo();
        $blocked = false;
        $this->connection->beforeExecuting(static function (string $sql) use ($writer, &$blocked): void {
            if (! $blocked && str_starts_with(strtolower($sql), 'delete')) {
                $blocked = true;
                $writer->beginTransaction();
                $writer->exec('UPDATE jobs SET attempts = attempts');
            }
        });
        return $writer;
    }
}

final class AcknowledgementReceiptJob
{
    public static int $handled = 0;

    public function fire($job): void
    {
        self::$handled++;
        $job->delete();
    }
}
