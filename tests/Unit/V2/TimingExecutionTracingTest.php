<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class TimingExecutionTracingTest extends TestCase
{
    public static function modes(): array
    {
        return [
            'traced' => [true, true],
            'disabled' => [false, false],
            'legacy admission' => [false, true],
        ];
    }

    #[DataProvider('modes')]
    public function testActualTimerAndRetryWorkersSeparateStoredWaitingFromProcessing(
        bool $admissionEnabled,
        bool $workerEnabled,
    ): void {
        $directory = sys_get_temp_dir() . '/dw-tracing-timings-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = static function (string $mode) use ($directory, $admissionEnabled, $workerEnabled): array {
            $packageVendor = dirname(__DIR__, 3) . '/vendor';
            $otherVendors = array_filter(
                array_keys(ClassLoader::getRegisteredLoaders()),
                static fn (string $vendor): bool => $vendor !== $packageVendor
            );
            $process = new Process([
                PHP_BINARY, __DIR__ . '/../../Fixtures/V2/tracing-process.php', $directory, $mode, 'once',
                ...array_map(
                    static fn (string $vendor): string => $vendor . '/autoload.php',
                    [...$otherVendors, $packageVendor]
                ),
            ], env: [
                'DW_TEST_TRACING_TIMINGS' => 'true',
                'DW_TEST_TRACING_ENABLED' => ($mode === 'admit' ? $admissionEnabled : $workerEnabled) ? 'true' : 'false',
            ]);
            $process->setTimeout(15);
            $process->mustRun();
            self::assertSame('', $process->getErrorOutput());
            self::assertJson($process->getOutput(), substr($process->getOutput(), -1500));
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame([], $result['outside_context']);
            self::assertTrue($result['scope_restored']);

            return $result;
        };
        try {
            $admitted = $worker('admit');
            $workers = [$worker('work')];
            self::assertSame('waiting', $workers[0]['status']);
            $scheduled = $workers[0]['timings']['timer'];
            sleep(2);
            $workers[] = $worker('work');
            $workers[] = $worker('work');
            $workers[] = $worker('work');
            self::assertSame('failed', $workers[3]['timings']['attempts'][0]['status']);
            $retryPolicy = $workers[3]['timings']['policy'];
            sleep(2);
            $workers[] = $worker('work');
            $workers[] = $worker('work');
            $finished = $worker('inspect');
            self::assertSame(
                'completed',
                $finished['status'],
                json_encode($finished['timings']['history_types'], JSON_THROW_ON_ERROR)
            );
            self::assertSame([9007199254740993, null, '雪'], $finished['output']);
            self::assertSame(0, $finished['jobs']);
            self::assertSame(0, $finished['failed_jobs']);
            self::assertSame(0, $finished['repairs']);
            self::assertSame(1, $finished['timings']['completion_count']);
            self::assertSame($retryPolicy, $finished['timings']['policy']);
            self::assertSame([1], $retryPolicy['backoff_seconds']);
            self::assertSame(2, $retryPolicy['max_attempts']);
            self::assertSame(['failed', 'completed'], array_column($finished['timings']['attempts'], 'status'));
            self::assertSame([1, 2], array_column($finished['timings']['attempts'], 'attempt_number'));
            foreach (['run_id', 'carrier', 'execution_deadline', 'run_deadline'] as $field) {
                self::assertSame($admitted[$field], $finished[$field]);
            }
            foreach (['id', 'fire_at', 'delay_seconds', 'created_at'] as $field) {
                self::assertSame($scheduled[$field], $finished['timings']['timer'][$field]);
            }
            self::assertSame('fired', $finished['timings']['timer']['status']);
            foreach ($workers as $prefix) {
                self::assertSame($prefix['timings']['history_hashes'], array_slice(
                    $finished['timings']['history_hashes'],
                    0,
                    count($prefix['timings']['history_hashes'])
                ));
            }
            $tasks = array_column($finished['timings']['tasks'], null, 'id');
            self::assertCount(6, $tasks);
            foreach ($tasks as $task) {
                self::assertSame('completed', $task['status']);
                self::assertSame($task['payload']['retry_after_attempt'] ?? 0, $task['attempt_count'] - 1);
            }
            $spans = array_merge(...array_column($workers, 'execution_spans'));
            self::assertCount($workerEnabled ? 6 : 0, $spans);
            foreach ($spans as $span) {
                self::assertSame($admitted['run_id'], $span['fields']['workflow_run_id']);
                if ($admissionEnabled) {
                    self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $span['trace_id']);
                    self::assertSame('00f067aa0ba902b7', $span['parent_id']);
                } else {
                    self::assertSame('0000000000000000', $span['parent_id']);
                    self::assertNotSame('11111111111111111111111111111111', $span['trace_id']);
                }
                $task = $tasks[$span['fields']['task_id']];
                $seconds = self::seconds($task['available_at'], $task['leased_at']);
                self::assertEqualsWithDelta($seconds, $span['fields']['durable.task.queue_wait_seconds'], 0.000001);
                if (isset($task['payload']['retry_backoff_seconds'])) {
                    self::assertSame(1, $span['fields']['durable.task.retry_backoff_seconds']);
                    self::assertSame(2, $span['fields']['activity_attempt_number']);
                } else {
                    self::assertArrayNotHasKey('durable.task.retry_backoff_seconds', $span['fields']);
                }
                if ($task['type'] === 'timer') {
                    self::assertSame('durable.timer.task', $span['name']);
                    self::assertSame($scheduled['id'], $span['fields']['timer_id']);
                    self::assertSame(1, $span['fields']['durable.timer.requested_wait_seconds']);
                    self::assertEqualsWithDelta(
                        self::seconds($scheduled['fire_at'], $task['leased_at']),
                        $span['fields']['durable.timer.lateness_seconds'],
                        0.000001
                    );
                } else {
                    self::assertArrayNotHasKey('durable.timer.requested_wait_seconds', $span['fields']);
                }
            }
            $records = array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory . '/activity.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            );
            self::assertCount(
                2,
                array_filter($records, static fn (array $record): bool => $record['message'] === 'callback')
            );
            foreach ($records as $record) {
                foreach (array_keys($record['context']) as $key) {
                    self::assertStringNotContainsString('queue_wait_seconds', $key);
                    self::assertStringNotContainsString('retry_backoff_seconds', $key);
                }
                if ($record['message'] === 'worker outside' || ! $workerEnabled) {
                    self::assertArrayNotHasKey('trace_id', $record['context']);
                }
            }
            self::assertStringNotContainsString(
                'durable.task.queue_wait_seconds',
                json_encode($finished['timings']['history_payloads'], JSON_THROW_ON_ERROR)
            );
            self::assertCount(6, array_unique(array_column($workers, 'pid')));
        } finally {
            (new Filesystem())->deleteDirectory($directory);
        }
    }

    private static function seconds(string $start, string $end): float
    {
        $from = new DateTimeImmutable($start, new DateTimeZone('UTC'));
        $to = new DateTimeImmutable($end, new DateTimeZone('UTC'));

        return ($to->getTimestamp() - $from->getTimestamp())
            + ((int) $to->format('u') - (int) $from->format('u')) / 1_000_000;
    }
}
