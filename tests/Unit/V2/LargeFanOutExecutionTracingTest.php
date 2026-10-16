<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

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
            $initial = self::read(self::process($directory, 'work', $enabled));
            self::assertCount(64, $initial['large_fan_out']['activities']);
            self::assertSame(64, $initial['large_fan_out']['ready_jobs']);
            $spans = $initial['spans'];
            $prefixes = [$admitted['large_fan_out']['history_hashes'], $initial['large_fan_out']['history_hashes']];
            $pids = [];
            $queries = $initial['large_fan_out']['task_outcome_queries'];
            for ($index = 0; $index < 4; ++$index) {
                $batch[] = $process = self::process($directory, 'work-drain', $enabled);
                $process->start();
            }
            self::assertCount(4, array_filter($batch, static fn (Process $process): bool => $process->isRunning()));
            foreach ($batch as $process) {
                $result = self::read($process);
                $spans = array_merge($spans, $result['spans']);
                $prefixes[] = $result['large_fan_out']['history_hashes'];
                $pids[] = $result['pid'];
                $queries += $result['large_fan_out']['task_outcome_queries'];
            }
            $finished = self::read(self::process($directory, 'inspect', $enabled));
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
            foreach (['jobs', 'failed_jobs'] as $field) {
                self::assertSame(0, $finished[$field], $field . ': ' . $diagnostic);
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
        } finally {
            foreach ($batch as $process) {
                $process->stop(1, 9);
            }
            (new Filesystem())->deleteDirectory($directory);
        }
    }

    private static function process(string $directory, string $mode, bool $enabled): Process
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
        $process->setTimeout($mode === 'work-drain' ? 60 : 20);
        return $process;
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
