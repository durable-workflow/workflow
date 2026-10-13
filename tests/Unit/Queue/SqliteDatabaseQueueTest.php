<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Events\JobFailed;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workflow\Queue\SqliteDatabaseQueue;

class SqliteDatabaseQueueTest extends TestCase
{
    private string $path;

    private SQLiteConnection $connection;

    private DatabaseQueue $queue;

    private Dispatcher $events;

    private int $failureEvents;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'queue-reservation-');
        $pdo = new PDO('sqlite:' . $this->path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 50');
        $pdo->exec(
            'CREATE TABLE jobs (id INTEGER PRIMARY KEY, queue TEXT, payload TEXT, attempts INTEGER CHECK(attempts <= 255), reserved_at INTEGER, available_at INTEGER, created_at INTEGER)'
        );
        $pdo->exec('CREATE TABLE other_rows (id INTEGER PRIMARY KEY, value INTEGER)');
        $pdo->exec('INSERT INTO other_rows VALUES (1, 0)');
        $this->connection = new SQLiteConnection($pdo, $this->path);
        $container = new Container();
        $this->events = new Dispatcher($container);
        $container->instance(DispatcherContract::class, $this->events);
        $container->instance('events', $this->events);
        $this->connection->setEventDispatcher($this->events);
        $this->queue = new SqliteDatabaseQueue($this->connection, 'jobs', 'default', 60);
        $this->queue->setContainer($container);
        $this->queue->setConnectionName('sqlite');
        $this->failureEvents = ReservationOutcomeJob::$failed = ReservationOutcomeJob::$handled = 0;
        $this->events->listen(JobFailed::class, function (): void {
            $this->failureEvents++;
        });
    }

    protected function tearDown(): void
    {
        $this->connection->disconnect();
        unlink($this->path);
    }

    public function testRolledBackReservationPreservesTheSameJobForFreshAcquisition(): void
    {
        $id = $this->push();
        $writer = $this->writer();
        $this->inject($writer);
        $this->expectBusy();
        $row = $this->connection->table('jobs')
            ->where('id', $id)
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(0, $row->attempts);
        $this->assertNull($row->reserved_at);
        $this->assertSame(0, ReservationOutcomeJob::$failed);
        $this->assertSame(0, $this->failureEvents);
        $job = $this->queue->pop();
        $this->assertSame($id, $job->getJobId());
        $this->assertSame(1, $job->attempts());
        $this->assertNull($this->queue->pop());
    }

    public function testSustainedContentionNeverConsumesDurableAttempts(): void
    {
        $id = $this->push();
        $writer = $this->writer();
        $this->inject($writer, release: false);
        try {
            for ($i = 0; $i < 3; $i++) {
                $this->expectBusy();
            }
        } finally {
            if ($writer->inTransaction()) {
                $writer->rollBack();
            }
        }
        $this->assertSame(0, $this->connection->table('jobs')->where('id', $id)->value('attempts'));
        $this->assertSame(0, $this->failureEvents);
        $this->assertSame($id, $this->queue->pop()->getJobId());
    }

    public function testAnotherPhysicalConnectionWinsAfterRollback(): void
    {
        $id = $this->push();
        $writer = $this->writer();
        $this->inject($writer);
        $this->expectBusy();
        $other = new SQLiteConnection($this->writer(), $this->path);
        $queue = new DatabaseQueue($other, 'jobs', 'default', 60);
        $queue->setContainer($this->queue->getContainer());
        $job = $queue->pop();
        $this->assertNotNull($job);
        $this->assertSame($id, $job->getJobId());
        $this->assertNull($this->queue->pop());
        $this->assertSame(1, $this->connection->table('jobs')->where('id', $id)->value('attempts'));
        $other->disconnect();
    }

    public function testAttemptOverflowStillFailsAndDeletesTheInvalidJob(): void
    {
        $id = $this->push(255);
        try {
            $this->queue->pop();
            $this->fail('Expected the real SQLite CHECK violation.');
        } catch (QueryException $e) {
            $this->assertSame(19, $e->getPrevious()->errorInfo[1]);
        }
        $this->assertNull($this->connection->table('jobs')->where('id', $id)->first());
        $this->assertSame(1, ReservationOutcomeJob::$failed);
        $this->assertSame(1, $this->failureEvents);
    }

    public function testARealHandlerFailureStillFailsTheReservedJob(): void
    {
        $id = $this->push();
        $job = $this->queue->pop();
        try {
            $job->fire();
            $this->fail('Expected handler failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('real-handler-failure', $e->getMessage());
            $job->fail($e);
        }
        $this->assertNull($this->connection->table('jobs')->where('id', $id)->first());
        $this->assertSame(1, ReservationOutcomeJob::$handled);
        $this->assertSame(1, ReservationOutcomeJob::$failed);
        $this->assertSame(1, $this->failureEvents);
    }

    public function testMalformedPayloadStillFollowsTheFailedJobPath(): void
    {
        $id = $this->queue->pushRaw(json_encode([
            'uuid' => 'invalid-payload-original',
            'job' => 'MissingReservationHandler@fire',
            'data' => [],
        ], JSON_THROW_ON_ERROR));
        $job = $this->queue->pop();
        try {
            $job->fail(new RuntimeException('invalid-payload'));
        } catch (BindingResolutionException $e) {
            $this->assertStringContainsString('MissingReservationHandler', $e->getMessage());
        }
        $this->assertNull($this->connection->table('jobs')->where('id', $id)->first());
        $this->assertSame(1, $this->failureEvents);
    }

    public function testAChangedPhysicalConnectionDoesNotEnterReservationRecovery(): void
    {
        $id = $this->push();
        $writer = $this->writer();
        $this->inject($writer);
        $this->events->listen(TransactionRolledBack::class, function (): void {
            $this->connection->setPdo($this->writer());
        });
        $this->expectBusy();
        $this->assertNull($this->connection->table('jobs')->where('id', $id)->first());
        $this->assertSame(1, $this->failureEvents);
    }

    public function testBusyOnADifferentStatementDoesNotEnterReservationRecovery(): void
    {
        $id = $this->push();
        $writer = $this->writer();
        $injected = false;
        $this->connection->beforeExecuting(function (string $sql) use ($writer, &$injected): void {
            if ($injected || ! str_starts_with($sql, 'update "jobs" set "reserved_at"')) {
                return;
            }
            $injected = true;
            $writer->beginTransaction();
            $writer->exec('UPDATE other_rows SET value = value');
            $this->connection->table('other_rows')
                ->where('id', 1)
                ->update([
                    'value' => 1,
                ]);
        });
        $this->events->listen(TransactionRolledBack::class, static function () use ($writer): void {
            if ($writer->inTransaction()) {
                $writer->commit();
            }
        });
        $error = $this->expectBusy();
        $this->assertStringStartsWith('update "other_rows"', $error->getSql());
        $this->assertNull($this->connection->table('jobs')->where('id', $id)->first());
        $this->assertSame(1, $this->failureEvents);
    }

    public function testBusyOnTheSameStatementForAnotherJobIsNotReservationRecovery(): void
    {
        $id = $this->push();
        $otherId = $this->push();
        $writer = $this->writer();
        $injected = false;
        $this->connection->beforeExecuting(function (string $sql, array $bindings) use (
            $writer,
            &$injected,
            $otherId
        ): void {
            if ($injected || ! str_starts_with($sql, 'update "jobs" set "reserved_at"')) {
                return;
            }
            $injected = true;
            $writer->beginTransaction();
            $writer->exec('UPDATE jobs SET available_at = available_at');
            $this->connection->table('jobs')
                ->where('id', $otherId)
                ->update([
                    'reserved_at' => $bindings[0],
                    'attempts' => $bindings[1],
                ]);
        });
        $this->events->listen(TransactionRolledBack::class, static function () use ($writer): void {
            if ($writer->inTransaction()) {
                $writer->commit();
            }
        });
        $error = $this->expectBusy();
        $this->assertSame($otherId, $error->getBindings()[2]);
        $this->assertNull($this->connection->table('jobs')->where('id', $id)->first());
        $this->assertSame(0, $this->connection->table('jobs')->where('id', $otherId)->value('attempts'));
        $this->assertSame(1, $this->failureEvents);
    }

    public function testNestedTransactionDoesNotClaimAProvenRootRollback(): void
    {
        $id = $this->push();
        $writer = $this->writer();
        $this->inject($writer, release: false);
        $this->connection->beginTransaction();
        try {
            try {
                $this->queue->pop();
                $this->fail('Expected nested concurrency refusal.');
            } catch (DeadlockException) {
                $this->assertTrue($this->connection->getPdo()->inTransaction());
            }
        } finally {
            if ($writer->inTransaction()) {
                $writer->rollBack();
            }
            $this->connection->rollBack(0);
        }
        $this->assertSame(0, $this->connection->table('jobs')->where('id', $id)->value('attempts'));
        $this->assertSame(0, ReservationOutcomeJob::$handled);
    }

    public function testLostCommitAcknowledgmentIsNotRetriedAsFailedReservation(): void
    {
        $id = $this->push();
        $pdo = new ReservationAckPDO('sqlite:' . $this->path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->failCommit = true;
        $this->connection->setPdo($pdo);
        $updates = 0;
        $this->connection->beforeExecuting(static function (string $sql) use (&$updates): void {
            if (str_starts_with($sql, 'update "jobs" set "reserved_at"')) {
                $updates++;
            }
        });
        try {
            $this->queue->pop();
            $this->fail('Expected injected post-commit acknowledgment loss.');
        } catch (QueryException $e) {
            $this->assertSame('injected-commit-ack-loss', $e->getPrevious()->getMessage());
        }
        $this->assertSame(1, $updates);
        $this->assertSame(1, $this->failureEvents);
        $this->assertNull($this->connection->table('jobs')->where('id', $id)->first());
    }

    public function testLostRollbackAcknowledgmentCannotPreserveTheOriginalExceptionProof(): void
    {
        $id = $this->push();
        $pdo = new ReservationAckPDO('sqlite:' . $this->path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 50');
        $pdo->failRollback = true;
        $this->connection->setPdo($pdo);
        $writer = $this->writer();
        $this->inject($writer, release: false);
        try {
            try {
                $this->queue->pop();
                $this->fail('Expected injected rollback acknowledgment loss.');
            } catch (RuntimeException $e) {
                $this->assertSame('injected-rollback-ack-loss', $e->getMessage());
            }
        } finally {
            if ($writer->inTransaction()) {
                $writer->rollBack();
            }
            $this->connection->rollBack(0);
        }
        $this->assertSame(0, ReservationOutcomeJob::$handled);
        $this->assertSame(0, $this->connection->table('jobs')->where('id', $id)->value('attempts'));
    }

    private function push(int $attempts = 0): int
    {
        $id = $this->queue->pushRaw(json_encode([
            'uuid' => 'reservation-original',
            'job' => ReservationOutcomeJob::class . '@fire',
            'data' => [],
        ], JSON_THROW_ON_ERROR));
        $this->connection->table('jobs')
            ->where('id', $id)
            ->update([
                'attempts' => $attempts,
            ]);

        return (int) $id;
    }

    private function writer(): PDO
    {
        $writer = new PDO('sqlite:' . $this->path);
        $writer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $writer->exec('PRAGMA busy_timeout = 50');

        return $writer;
    }

    private function inject(PDO $writer, bool $release = true): void
    {
        $injected = false;
        $this->connection->beforeExecuting(static function (string $sql) use ($writer, &$injected): void {
            if ($injected || ! str_starts_with($sql, 'update "jobs" set "reserved_at"')) {
                return;
            }
            $writer->beginTransaction();
            $writer->exec('UPDATE jobs SET available_at = available_at');
            $injected = true;
        });
        if ($release) {
            $this->events->listen(TransactionRolledBack::class, function ($event) use ($writer): void {
                if ($event->connection === $this->connection && $writer->inTransaction()) {
                    $this->assertSame(0, $this->connection->transactionLevel());
                    $this->assertFalse($this->connection->getPdo()->inTransaction());
                    $writer->commit();
                }
            });
        }
    }

    private function expectBusy(): QueryException
    {
        try {
            $this->queue->pop();
        } catch (QueryException $e) {
            $this->assertSame(5, $e->getPrevious()->errorInfo[1]);

            return $e;
        }
        $this->fail('The original reservation must expose SQLite busy.');
    }
}

class ReservationAckPDO extends PDO
{
    public bool $failCommit = false;

    public bool $failRollback = false;

    public function commit(): bool
    {
        $result = parent::commit();
        if ($this->failCommit) {
            $this->failCommit = false;
            $previous = new \PDOException('injected-commit-ack-loss');
            $previous->errorInfo = ['HY000', 5, 'database is locked'];
            throw new QueryException(
                'default',
                'update "jobs" set "reserved_at" = ?, "attempts" = ? where "id" = ?',
                [],
                $previous
            );
        }

        return $result;
    }

    public function rollBack(): bool
    {
        $result = parent::rollBack();
        if ($this->failRollback) {
            $this->failRollback = false;
            throw new RuntimeException('injected-rollback-ack-loss');
        }

        return $result;
    }
}

class ReservationOutcomeJob
{
    public static int $failed = 0;

    public static int $handled = 0;

    public function fire($job, $data): void
    {
        static::$handled++;
        throw new RuntimeException('real-handler-failure');
    }

    public function failed($data, $exception, $uuid, $job): void
    {
        static::$failed++;
    }
}
