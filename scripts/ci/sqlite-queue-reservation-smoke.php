<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Queue\DatabaseQueue;
use Workflow\Queue\SqliteDatabaseQueue;
use Workflow\Queue\SqliteAcknowledgingDatabaseQueue;

require getcwd() . '/vendor/autoload.php';
$app = require getcwd() . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
});
$path = tempnam(sys_get_temp_dir(), 'workflow-reservation-');
if ($path === false) {
    throw new RuntimeException('Cannot create isolated reservation database.');
}

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$database = null;
$writer = null;

try {
    config([
        'database.connections.workflow-reservation' => [
            'driver' => 'sqlite', 'database' => $path, 'prefix' => '',
        ],
        'queue.connections.workflow-reservation' => [
            'driver' => 'database', 'connection' => 'workflow-reservation',
            'table' => 'jobs', 'queue' => 'reservation', 'retry_after' => 60,
        ],
    ]);
    $queue = $app['queue']->connection('workflow-reservation');
    $expected = version_compare(Application::VERSION, '12.54.0', '>=')
        ? SqliteDatabaseQueue::class
        : (class_exists(SqliteAcknowledgingDatabaseQueue::class)
            ? SqliteAcknowledgingDatabaseQueue::class : DatabaseQueue::class);
    $assert($queue::class === $expected, 'The default connector selected an unexpected queue.');
    $database = $queue->getDatabase();
    $pdo = $database->getPdo();
    $pdo->exec('PRAGMA busy_timeout = 50');
    $pdo->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY, queue TEXT, payload TEXT, attempts INTEGER, reserved_at INTEGER, available_at INTEGER, created_at INTEGER)');
    $id = (int) $queue->pushRaw(json_encode([
        'uuid' => 'original-reservation', 'displayName' => 'ReservationSmoke',
        'job' => 'ReservationSmoke@fire', 'data' => [],
    ], JSON_THROW_ON_ERROR));
    $writer = new PDO('sqlite:' . $path);
    $writer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $writer->exec('PRAGMA busy_timeout = 50');
    $injected = false;
    $database->beforeExecuting(static function (string $sql) use ($writer, &$injected): void {
        if (! $injected && str_starts_with($sql, 'update "jobs" set "reserved_at"')) {
            $writer->beginTransaction();
            $writer->exec('UPDATE jobs SET available_at = available_at');
            $injected = true;
        }
    });
    $app['events']->listen(TransactionRolledBack::class, static function ($event) use (
        $database, $pdo, $writer, $assert,
    ): void {
        if ($event->connection === $database && $writer->inTransaction()) {
            $assert($database->transactionLevel() === 0 && ! $pdo->inTransaction(), 'Reservation did not fully roll back.');
            $writer->rollBack();
        }
    });

    $busy = false;
    try {
        $queue->pop();
    } catch (QueryException $exception) {
        $busy = (int) $exception->getPrevious()->errorInfo[1] === 5;
    }
    $assert($busy && $injected, 'The real SQLite reservation conflict was not observed.');
    $row = $database->table('jobs')->where('id', $id)->first();
    $assert($row !== null && (int) $row->attempts === 0 && $row->reserved_at === null, 'The failed reservation changed or deleted the unclaimed job.');
    $job = $queue->pop();
    $assert($job !== null && (int) $job->getJobId() === $id && $job->attempts() === 1, 'Reselection did not acquire the same original once.');
    $assert($queue->pop() === null, 'The reserved original was acquired twice.');
    $job->delete();
    echo json_encode(['laravel' => Application::VERSION, 'queue' => $queue::class,
        'sqlite_reservation_busy' => true, 'same_original_reselected' => true,
        'failed_reservation_attempts' => 0], JSON_THROW_ON_ERROR), "\n";
} finally {
    if ($writer instanceof PDO && $writer->inTransaction()) {
        $writer->rollBack();
    }
    $writer = null;
    $database?->disconnect();
    unlink($path);
}
