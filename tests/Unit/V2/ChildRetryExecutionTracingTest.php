<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Filesystem\Filesystem;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ChildRetryExecutionTracingTest extends TestCase
{
    public static function modes(): iterable
    {
        foreach (['work', 'bridge-fail'] as $failure) {
            yield $failure . ', traced' => [$failure, true, true];
            yield $failure . ', disabled' => [$failure, false, false];
            yield $failure . ', legacy admission' => [$failure, false, true];
            yield $failure . ', disabled workers' => [$failure, true, false];
        }
    }

    #[DataProvider('modes')]
    public function testColdChildRetryKeepsItsAdmissionContextAndOriginalDurablePolicy(
        string $failure,
        bool $admissionEnabled,
        bool $workerEnabled,
    ): void {
        $directory = sys_get_temp_dir() . '/dw-tracing-child-retry-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = static function (string $mode, bool $fail = false) use (
            $directory,
            $admissionEnabled,
            $workerEnabled,
        ): array {
            $autoloads = array_map(
                static fn (string $vendor): string => $vendor . '/autoload.php',
                array_keys(ClassLoader::getRegisteredLoaders())
            );
            $process = new Process([
                PHP_BINARY, __DIR__ . '/../../Fixtures/V2/tracing-process.php', $directory, $mode, 'once',
                ...$autoloads,
            ], env: [
                'DW_TEST_TRACING_CHILD_RETRY' => 'true',
                'DW_TEST_TRACING_FAIL_CHILD' => $fail ? 'true' : 'false',
                'DW_TEST_TRACING_ENABLED' => ($mode === 'admit' ? $admissionEnabled : $workerEnabled) ? 'true' : 'false',
            ]);
            $process->setTimeout(15);
            $process->mustRun();
            self::assertSame('', $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame([], $result['outside_context']);
            self::assertTrue($result['scope_restored']);

            return $result;
        };
        try {
            $admitted = $worker('admit');
            self::assertSame($admissionEnabled, $admitted['carrier'] !== null);
            self::assertCount(2, $admitted['runs']);
            $originalChild = $admitted['runs'][1];
            $failed = $worker($failure, true);
            self::assertCount(3, $failed['runs']);
            $retry = $failed['runs'][2];
            self::assertSame('failed', $failed['runs'][1]['status']);
            self::assertSame('pending', $retry['status']);
            self::assertSame($originalChild['instance_id'], $retry['instance_id']);
            self::assertSame(2, $retry['number']);
            self::assertSame($originalChild['execution_deadline'], $retry['execution_deadline']);
            $retryTask = array_values(array_filter(
                $failed['task_rows'],
                static fn (array $row): bool => $row['run_id'] === $retry['id']
            ))[0];
            $zone = new DateTimeZone('UTC');
            $started = new DateTimeImmutable($retry['started_at'], $zone);
            $available = new DateTimeImmutable($retryTask['available_at'], $zone);
            self::assertSame($started->modify('+1 second')->format('U.u'), $available->format('U.u'));
            self::assertSame($started->modify('+20 seconds')->format('U.u'), (
                new DateTimeImmutable($retry['run_deadline'], $zone)
            )->format('U.u'));
            // Wait for the real queue's second-resolution wakeup, without advancing stored clocks.
            $wakeup = ceil((float) $available->format('U.u'));
            self::assertLessThan(5, $wakeup - microtime(true));
            while (microtime(true) < $wakeup) {
                usleep(10_000);
            }
            $workers = [$failed];
            for ($remaining = 10; $remaining > 0; --$remaining) {
                $state = $worker('inspect');
                if ($state['jobs'] === 0) {
                    break;
                }
                $workers[] = $worker('work');
            }
            $finished = $worker('inspect');
            self::assertSame('completed', $finished['status']);
            self::assertSame([9007199254740993, null, '雪'], $finished['output']);
            self::assertSame(0, $finished['jobs']);
            self::assertSame(0, $finished['failed_jobs']);
            self::assertSame(0, $finished['repairs']);
            self::assertSame(1, $finished['activity_count']);
            self::assertSame(1, $finished['completion_count']);
            self::assertSame(6, $finished['tasks']);
            self::assertCount(3, $finished['runs']);
            self::assertSame(['completed', 'failed', 'completed'], array_column($finished['runs'], 'status'));
            self::assertCount(2, $finished['links']);
            self::assertCount(1, array_filter(
                $finished['task_rows'],
                static fn (array $task): bool => $task['status'] === 'failed'
            ));
            self::assertCount(5, array_filter(
                $finished['task_rows'],
                static fn (array $task): bool => $task['status'] === 'completed'
            ));
            self::assertSame($admitted['policy'], $failed['policy']);
            self::assertSame($admitted['policy'], $finished['policy']);
            self::assertSame([
                'snapshot_version' => 1,
                'max_attempts' => 2,
                'backoff_seconds' => [1],
                'non_retryable_error_types' => [],
            ], $finished['policy']);
            foreach ($admitted['runs'] as $index => $run) {
                foreach (['id', 'instance_id', 'carrier', 'execution_deadline', 'run_deadline', 'started_at'] as $key) {
                    self::assertSame($run[$key], $failed['runs'][$index][$key]);
                }
                self::assertSame(
                    $run['history_hashes'],
                    array_slice($failed['runs'][$index]['history_hashes'], 0, count($run['history_hashes']))
                );
            }
            foreach ($failed['runs'] as $index => $run) {
                foreach (['id', 'instance_id', 'execution_deadline', 'run_deadline', 'started_at'] as $key) {
                    self::assertSame($run[$key], $finished['runs'][$index][$key]);
                }
                self::assertSame($run['history_hashes'], array_slice(
                    $finished['runs'][$index]['history_hashes'],
                    0,
                    count($run['history_hashes'])
                ));
            }
            self::assertSame($failed['runs'][1]['history_hashes'], $finished['runs'][1]['history_hashes']);
            foreach ($finished['task_rows'] as $task) {
                self::assertSame(1, $task['attempt_count']);
                self::assertGreaterThanOrEqual(
                    new DateTimeImmutable($task['available_at'], $zone),
                    new DateTimeImmutable($task['leased_at'], $zone)
                );
            }
            self::assertSame(count($workers), count(array_unique(array_column($workers, 'pid'))));
            foreach ($finished['runs'] as $run) {
                self::assertSame($admitted['carrier'], $run['carrier'], $run['id'] . ' generation ' . $run['number']);
            }
            $spans = array_merge(...array_column($workers, 'execution_spans'));
            if ($workerEnabled) {
                $observed = [];
                foreach ($spans as $span) {
                    $observed[] = $span['fields']['workflow_run_id'];
                    if ($admissionEnabled) {
                        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $span['trace_id']);
                        self::assertSame('00f067aa0ba902b7', $span['parent_id']);
                    } else {
                        self::assertSame('0000000000000000', $span['parent_id']);
                        self::assertNotSame('11111111111111111111111111111111', $span['trace_id']);
                    }
                }
                self::assertContains($retry['id'], $observed);
                self::assertContains($admitted['run_id'], $observed);
                if ($failure === 'work') {
                    $failureSpan = array_values(array_filter(
                        $failed['execution_spans'],
                        static fn (array $span): bool => $span['fields']['workflow_run_id'] === $originalChild['id']
                    ));
                    self::assertCount(1, $failureSpan);
                    self::assertSame(StatusCode::STATUS_ERROR, $failureSpan[0]['status_code']);
                }
            } else {
                self::assertSame([], $spans);
            }
            self::assertStringNotContainsString(
                'observability_context',
                json_encode($finished['history_payloads'], JSON_THROW_ON_ERROR)
            );
        } finally {
            (new Filesystem())->deleteDirectory($directory);
        }
    }
}
