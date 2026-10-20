<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\TracingQueueErrorDiagnostics;
use Throwable;

final class LargeFanOutExecutionTracingTest extends TestCase
{
    public static function modes(): array
    {
        return [
            'embedded traced' => [true],
            'embedded disabled' => [false],
        ];
    }

    #[DataProvider('modes')]
    public function testConcurrentWorkersCompleteTheFanOutAndExposeMissingOutcomeContexts(bool $enabled): void
    {
        $directory = sys_get_temp_dir() . '/dw-tracing-large-fan-out-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $batch = [];
        try {
            $admitted = self::read(self::process($directory, 'admit', $enabled));
            self::assertTrue($admitted['large_fan_out']['matching_role']['queue_wake_enabled']);
            self::assertSame('in_worker', $admitted['large_fan_out']['matching_role']['shape']);
            $deadline = microtime(true) + 90;
            $initial = self::read(self::process($directory, 'work', $enabled, $deadline));
            self::assertCount(64, $initial['large_fan_out']['activities']);
            self::assertSame(64, $initial['large_fan_out']['ready_jobs']);
            $spans = $initial['spans'];
            $queueErrors = $initial['large_fan_out']['queue_errors'];
            $reportedQueueErrors = $initial['large_fan_out']['reported_queue_errors'];
            $queueDiagnosticsTruncated = $initial['large_fan_out']['queue_diagnostics_truncated'];
            $prefixes = [$admitted['large_fan_out']['history_hashes'], $initial['large_fan_out']['history_hashes']];
            $pids = [];
            $queries = $initial['large_fan_out']['task_outcome_queries'];
            for ($index = 0; $index < 4; ++$index) {
                $batch[] = $process = self::process($directory, 'work-drain', $enabled, $deadline);
                $process->start();
            }
            self::assertCount(4, array_filter($batch, static fn (Process $process): bool => $process->isRunning()));
            self::observeCompletion($directory, $admitted['run_id'], $deadline);
            file_put_contents($directory . '/stop-large-workers', 'stop');
            foreach ($batch as $process) {
                $result = self::read($process);
                $spans = array_merge($spans, $result['spans']);
                $queueErrors = array_merge($queueErrors, $result['large_fan_out']['queue_errors']);
                $reportedQueueErrors = array_merge(
                    $reportedQueueErrors,
                    $result['large_fan_out']['reported_queue_errors']
                );
                $queueDiagnosticsTruncated = $queueDiagnosticsTruncated
                    || $result['large_fan_out']['queue_diagnostics_truncated'];
                $prefixes[] = $result['large_fan_out']['history_hashes'];
                $pids[] = $result['pid'];
                $queries += $result['large_fan_out']['task_outcome_queries'];
            }
            $finished = self::read(self::process($directory, 'inspect', $enabled, $deadline));
            $lastEvent = end($finished['large_fan_out']['history_payloads']);
            $diagnostic = json_encode([
                'run_id' => $finished['run_id'],
                'jobs' => $finished['jobs'],
                'status' => $finished['status'],
                'failed_jobs' => $finished['failed_jobs'],
                'ready_jobs' => $finished['large_fan_out']['ready_jobs'],
                'reserved_jobs' => $finished['large_fan_out']['reserved_jobs'],
                'delayed_jobs' => $finished['large_fan_out']['delayed_jobs'],
                'queue_attempts' => $finished['large_fan_out']['queue_attempts'],
                'queue_errors' => $queueErrors,
                'reported_queue_errors' => $reportedQueueErrors,
                'remaining_deliveries' => $finished['large_fan_out']['remaining_deliveries'],
                'delivery_errors' => $finished['large_fan_out']['delivery_errors'],
                'repairs' => $finished['repairs'],
                'task_states' => array_count_values(array_column($finished['large_fan_out']['tasks'], 'status')),
                'activity_states' => array_count_values(
                    array_column($finished['large_fan_out']['activities'], 'status')
                ),
                'history_counts' => array_count_values($finished['large_fan_out']['history_types']),
                'last_event' => is_array($lastEvent) ? array_intersect_key(
                    $lastEvent,
                    array_fill_keys(['exception_class', 'message', 'reason'], true)
                ) : $lastEvent,
            ], JSON_THROW_ON_ERROR);
            self::assertLessThan($deadline, microtime(true), 'Original 90-second observer elapsed. ' . $diagnostic);
            self::assertSame('completed', $finished['status'], $diagnostic);
            self::assertSame(array_map(static fn (int $member): array => [
                'member' => $member,
                'value' => [9007199254740993, null, '雪'],
            ], range(0, 63)), $finished['output']);
            foreach (['run_id', 'carrier', 'execution_deadline', 'run_deadline'] as $field) {
                self::assertSame($admitted[$field], $finished[$field]);
            }
            $observed = $finished['large_fan_out'];
            self::assertCount(64, $observed['activities']);
            self::assertCount(64, $observed['attempts']);
            self::assertSame([1], array_values(array_unique(array_column($observed['attempts'], 'attempt_number'))));
            self::assertCount(count($pids), array_unique($pids));
            self::assertCount(4, glob($directory . '/ready-worker-*'));
            foreach ($prefixes as $prefix) {
                self::assertSame($prefix, array_slice($observed['history_hashes'], 0, count($prefix)));
            }
            $records = array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory . '/activity.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            );
            $callbacks = array_values(array_filter($records, static fn (array $record): bool =>
                $record['message'] === 'large callback'));
            self::assertCount(64, $callbacks);
            foreach ($callbacks as $callback) {
                self::assertContains($callback['context']['actor_pid'], $pids);
            }
            $members = array_map(static fn (array $record): int => $record['context']['member'], $callbacks);
            sort($members);
            self::assertSame(range(0, 63), $members);
            self::assertTraceWindow($spans, $observed, $enabled, $queries);
            self::assertFalse($queueDiagnosticsTruncated, $diagnostic);
            self::assertSupportedQueueRecovery($finished, $observed, $queueErrors, $reportedQueueErrors, $diagnostic);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                $exception->getMessage() . ' Read-only failure snapshot: ' . self::failureSnapshot($directory),
                previous: $exception,
            );
        } finally {
            foreach ($batch as $process) {
                $process->stop(1, 9);
            }
            (new Filesystem())->deleteDirectory($directory);
        }
    }

    private static function assertSupportedQueueRecovery(
        array $finished,
        array $observed,
        array $queueErrors,
        array $reportedErrors,
        string $diagnostic,
    ): void {
        foreach ($queueErrors as $error) {
            self::assertQueuePressureError($error, false, $diagnostic);
        }
        foreach ($reportedErrors as $error) {
            self::assertQueuePressureError($error, true, $diagnostic);
        }
        self::assertCount($finished['failed_jobs'], $observed['delivery_errors'], $diagnostic);
        self::assertCount($finished['jobs'], $observed['remaining_deliveries'], $diagnostic);
        $tasks = array_column($observed['tasks'], null, 'id');
        $errors = array_column($queueErrors, null, 'delivery_id');
        foreach ($observed['delivery_errors'] as $failed) {
            self::assertRecoveredTask($failed, $tasks, $finished['run_id'], $diagnostic);
            self::assertArrayHasKey($failed['delivery_id'], $errors, $diagnostic);
            self::assertSame('update', $errors[$failed['delivery_id']]['operation'], $diagnostic);
            self::assertStringContainsString('SqliteTransaction::forTask', implode("\n", $failed['stage_frames']));
        }
        foreach ($observed['remaining_deliveries'] as $remaining) {
            self::assertRecoveredTask($remaining, $tasks, $finished['run_id'], $diagnostic);
            $fence = [
                (int) $remaining['id'], $remaining['queue'],
                (int) $remaining['reserved_at'], $remaining['attempts'],
            ];
            $refusals = array_filter($reportedErrors, static fn (array $error): bool =>
                $error['operation'] === 'delete' && $error['ack_fence'] === $fence);
            self::assertNotEmpty($refusals, $diagnostic);
            self::assertGreaterThan(time(), $remaining['reserved_at'] + $observed['retry_after_seconds'], $diagnostic);
        }
    }

    private static function assertQueuePressureError(array $error, bool $reported, string $diagnostic): void
    {
        self::assertIsInt($error['native_code'], $diagnostic);
        self::assertContains($error['native_code'] & 255, [5, 6], $diagnostic);
        self::assertTrue(in_array($error['operation'], ['update', 'delete'], true)
            || ($reported && TracingQueueErrorDiagnostics::isFailedJobLogContention($error)), $diagnostic);
        self::assertSame(0, $error['transaction_level'], $diagnostic);
        self::assertFalse($error['pdo_in_transaction'], $diagnostic);
    }

    private static function assertRecoveredTask(array $delivery, array $tasks, string $runId, string $diagnostic): void
    {
        self::assertIsString($delivery['task_id'], $diagnostic);
        self::assertArrayHasKey($delivery['task_id'], $tasks, $diagnostic);
        $task = $tasks[$delivery['task_id']];
        self::assertSame($runId, $task['workflow_run_id'], $diagnostic);
        self::assertSame('0', $task['namespace'], $diagnostic);
        self::assertSame('default', $task['queue'], $diagnostic);
        self::assertSame('completed', $task['status'], $diagnostic);
        self::assertSame(1, $task['attempt_count'], $diagnostic);
    }

    private static function process(string $directory, string $mode, bool $enabled, ?float $deadline = null): Process
    {
        $process = new Process([
            PHP_BINARY, __DIR__ . '/../../Fixtures/V2/tracing-process.php', $directory, $mode, 'once',
            ...array_map(
                static fn (string $vendor): string => $vendor . '/autoload.php',
                array_keys(ClassLoader::getRegisteredLoaders())
            ),
        ], env: [
            'DW_TEST_TRACING_LARGE_FAN_OUT' => 'true',
            // Disabling this role requires a separate matching process.
            'DW_TEST_TRACING_LARGE_FAN_OUT_QUEUE_WAKE' => 'true',
            'DW_TEST_TRACING_CONTINUOUS_WORKERS' => 'true',
            'DW_TEST_TRACING_ENABLED' => $enabled ? 'true' : 'false',
        ]);
        $process->setTimeout($deadline === null ? 20 : max(0.001, $deadline - microtime(true)));
        return $process;
    }

    private static function observeCompletion(string $directory, string $runId, float $deadline): void
    {
        $database = new PDO('sqlite:' . $directory . '/database.sqlite');
        $database->exec('PRAGMA busy_timeout=50');
        do {
            $query = null;
            try {
                // A statement whose execution returned SQLITE_BUSY cannot be reused safely by PDO SQLite.
                $query = $database->prepare('SELECT status FROM workflow_runs WHERE id = ?');
                $query->execute([$runId]);
                $status = $query->fetchColumn();
                $query->closeCursor();
                if ($status === 'completed') {
                    return;
                }
            } catch (PDOException $exception) {
                $code = $exception->errorInfo[1] ?? null;
                if (! is_int($code) || ! in_array($code & 255, [5, 6], true)) {
                    throw $exception;
                }
            } finally {
                $query = null;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);
    }

    private static function failureSnapshot(string $directory): string
    {
        try {
            $database = new PDO('sqlite:file:' . $directory . '/database.sqlite?mode=ro');
            $database->exec('PRAGMA busy_timeout=25');
            $database->exec('PRAGMA query_only=ON');
            $snapshot = [];
            foreach (['workflow_runs', 'workflow_tasks', 'activity_executions'] as $table) {
                $snapshot[$table] = $database->query('SELECT status, COUNT(*) AS count FROM ' . $table
                    . ' GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);
            }
            $snapshot['jobs'] = (int) $database->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
            $snapshot['failed_jobs'] = (int) $database->query('SELECT COUNT(*) FROM failed_jobs')->fetchColumn();

            return json_encode($snapshot, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            return json_encode([
                'unavailable' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
    }

    private static function read(Process $process): array
    {
        if (! $process->isStarted()) {
            $process->mustRun();
        } else {
            $process->wait();
            self::assertTrue($process->isSuccessful(), substr($process->getErrorOutput(), -4096));
        }
        self::assertSame('', $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], $result['outside_context']);
        self::assertTrue($result['scope_restored']);
        return $result;
    }

    private static function assertTraceWindow(array $spans, array $observed, bool $enabled, int $queries): void
    {
        if (! $enabled) {
            self::assertSame([], $spans);
            self::assertSame([], $observed['outcome_contexts']);
            self::assertSame(0, $queries);
            return;
        }
        $callbacks = array_values(array_filter($spans, static fn (array $span): bool =>
            $span['name'] === 'durable.activity.attempt'));
        self::assertCount(64, $callbacks);
        $attempts = array_column($observed['attempts'], null, 'id');
        foreach ($callbacks as $span) {
            self::assertArrayHasKey($span['fields']['activity_attempt_id'], $attempts);
            self::assertSame(
                $attempts[$span['fields']['activity_attempt_id']]['workflow_task_id'],
                $span['fields']['task_id']
            );
        }
        $events = array_column($observed['outcome_events'], null, 'id');
        $completion = array_values(array_filter($events, static fn (array $event): bool =>
            $event['type'] === 'WorkflowCompleted'))[0];
        $parent = array_values(array_filter($spans, static fn (array $span): bool =>
            $span['name'] === 'durable.workflow.task' && $span['fields']['task_id'] === $completion['task_id']))[0];
        $links = array_values(array_filter($parent['links'], static fn (array $link): bool =>
            ($link['fields']['durable.link.type'] ?? null) === 'task_outcome'));
        self::assertCount(32, $links);
        foreach ($links as $link) {
            $event = $events[$link['fields']['history_event_id']];
            self::assertSame('ActivityCompleted', $event['type']);
            $callback = array_values(array_filter($callbacks, static fn (array $span): bool =>
                $span['fields']['task_id'] === $event['task_id']))[0];
            self::assertSame($callback['trace_id'], $link['trace_id']);
            self::assertSame($callback['span_id'], $link['span_id']);
        }
        self::assertArrayHasKey('durable.links.outcome_context_unavailable', $parent['fields']);
        self::assertTrue($parent['fields']['durable.links.outcome_context_unavailable']);
    }
}
