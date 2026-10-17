<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

final class RecoveryExecutionTracingTest extends TestCase
{
    private bool $tracing = true;

    public static function tracingModes(): array
    {
        return [
            'enabled tracing' => [true],
            'disabled tracing control' => [false],
        ];
    }

    #[DataProvider('tracingModes')]
    public function testWorkerCrashThenAuthoredTimeoutRecoversContextInAColdRetry(bool $tracing): void
    {
        $this->tracing = $tracing;
        $directory = sys_get_temp_dir() . '/dw-tracing-recovery-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $crashed = null;
        try {
            $admitted = $this->worker($directory, 'admit');
            $scheduled = $this->worker($directory, 'work');
            self::assertSame('waiting', $scheduled['status'], json_encode($scheduled, JSON_THROW_ON_ERROR));
            $crashed = $this->process($directory, 'work', true);
            $crashed->start();
            $marker = $directory . '/claimed.json';
            $limit = hrtime(true) + 5_000_000_000;
            while (! is_file($marker) && hrtime(true) < $limit && $crashed->isRunning()) {
                usleep(20_000);
            }
            self::assertFileExists($marker, substr($crashed->getErrorOutput() . $crashed->getOutput(), -4096));
            $firstFields = json_decode(file_get_contents($marker), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($crashed->getPid(), $firstFields['actor_pid']);
            $crashed->signal(9);
            try {
                $crashed->wait();
            } catch (ProcessSignaledException $error) {
                self::assertSame(9, $error->getSignal());
            }
            self::assertTrue($crashed->hasBeenSignaled());
            self::assertSame(9, $crashed->getTermSignal());
            $first = $this->worker($directory, 'inspect');
            self::assertSame('running', $first['recovery']['attempts'][0]['status']);
            self::assertSame($admitted['run_id'], $firstFields['workflow_run_id']);
            if ($tracing) {
                self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $firstFields['trace_id']);
            } else {
                self::assertSame([], $firstFields['tracing_context']);
                self::assertNull($admitted['carrier']);
            }
            self::assertSame(1, $firstFields['activity_attempt_number']);
            self::assertSame($firstFields['activity_attempt_id'], $first['recovery']['attempts'][0]['id']);
            self::assertSame($firstFields['task_id'], $first['recovery']['attempts'][0]['task_id']);
            $deadline = $this->timestamp($first['recovery']['activities'][0]['close_deadline_at']);
            $remaining = max(0.0, $deadline - microtime(true));
            self::assertLessThan(3.0, $remaining);
            usleep((int) ceil($remaining * 1_000_000) + 50_000);
            $repaired = $this->worker($directory, 'repair');
            self::assertSame(1, $repaired['recovery']['activities'][0]['attempt_count']);
            self::assertSame(1, $repaired['recovery']['repair_report']['activity_timeouts_enforced']);
            self::assertSame([], $repaired['recovery']['repair_report']['activity_timeout_failures']);
            $workers = [];
            for ($remainingWorkers = 6; $remainingWorkers > 0; --$remainingWorkers) {
                $state = $this->worker($directory, 'inspect');
                if ($state['jobs'] === 0) {
                    break;
                }
                $workers[] = $this->worker($directory, 'work');
            }
            $finished = $this->worker($directory, 'inspect');
            self::assertSame('completed', $finished['status']);
            self::assertSame([9007199254740993, null, '雪'], $finished['output']);
            self::assertSame(0, $finished['repairs'], json_encode($finished['recovery'], JSON_THROW_ON_ERROR));
            self::assertSame(0, $finished['jobs']);
            // The killed Laravel delivery exhausts the job's fixed transport retry budget.
            self::assertSame(1, $finished['failed_jobs'], json_encode($finished['recovery']['failed_deliveries']));
            self::assertStringStartsWith(
                'Illuminate\\Queue\\MaxAttemptsExceededException:',
                $finished['recovery']['failed_deliveries'][0]['exception']
            );
            self::assertStringContainsString(
                $firstFields['task_id'],
                $finished['recovery']['failed_deliveries'][0]['payload']
            );
            foreach (['run_id', 'carrier', 'execution_deadline', 'run_deadline'] as $key) {
                self::assertSame($admitted[$key], $finished[$key]);
            }
            self::assertCount(1, $finished['recovery']['activities']);
            $activity = $finished['recovery']['activities'][0];
            self::assertSame($firstFields['activity_id'], $activity['id']);
            self::assertSame(2, $activity['attempt_count']);
            self::assertSame('completed', $activity['status']);
            self::assertSame($first['recovery']['activities'][0]['retry_policy'], $activity['retry_policy']);
            self::assertSame(
                $first['recovery']['activities'][0]['schedule_to_close_deadline_at'],
                $activity['schedule_to_close_deadline_at']
            );
            self::assertCount(2, $finished['recovery']['attempts']);
            $attempts = $finished['recovery']['attempts'];
            self::assertSame($firstFields['activity_attempt_id'], $attempts[0]['id']);
            self::assertSame('failed', $attempts[0]['status']);
            self::assertSame('completed', $attempts[1]['status']);
            self::assertSame(2, $attempts[1]['number']);
            self::assertNotSame($attempts[0]['id'], $attempts[1]['id']);
            self::assertGreaterThan(
                250,
                $this->timestamp($first['recovery']['attempts'][0]['lease_expires_at'])
                    - $this->timestamp($first['recovery']['attempts'][0]['started_at'])
            );
            $spans = [];
            foreach ($workers as $worker) {
                self::assertSame([], $worker['outside_context']);
                self::assertTrue($worker['scope_restored']);
                foreach ($worker['execution_spans'] as $span) {
                    if ($span['name'] === 'durable.activity.attempt') {
                        $spans[] = $span;
                    }
                }
            }
            self::assertCount($tracing ? 1 : 0, $spans);
            if ($tracing) {
                self::assertSame($firstFields['activity_id'], $spans[0]['fields']['activity_id']);
                self::assertSame($attempts[1]['id'], $spans[0]['fields']['activity_attempt_id']);
                self::assertSame($firstFields['trace_id'], $spans[0]['trace_id']);
                self::assertSame('00f067aa0ba902b7', $spans[0]['parent_id']);
                self::assertNotSame($firstFields['span_id'], $spans[0]['span_id']);
            }
            self::assertSame(
                $first['recovery']['history_hashes'],
                array_slice($finished['recovery']['history_hashes'], 0, count($first['recovery']['history_hashes']))
            );
            self::assertSame(1, array_count_values($finished['recovery']['history_types'])['ActivityRetryScheduled']);
            self::assertSame(1, array_count_values($finished['recovery']['history_types'])['ActivityCompleted']);
            self::assertSame(1, array_count_values($finished['recovery']['history_types'])['WorkflowCompleted']);
            $records = array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory . '/activity.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            );
            $callbacks = array_values(array_filter(
                $records,
                static fn (array $record): bool => $record['message'] === 'callback'
            ));
            self::assertCount(2, $callbacks);
            if ($tracing) {
                self::assertSame($firstFields['activity_attempt_id'], $callbacks[0]['context']['activity_attempt_id']);
                self::assertSame($attempts[1]['id'], $callbacks[1]['context']['activity_attempt_id']);
                self::assertSame($firstFields['trace_id'], $callbacks[1]['context']['trace_id']);
                self::assertSame($spans[0]['span_id'], $callbacks[1]['context']['span_id']);
            } else {
                foreach ($callbacks as $callback) {
                    self::assertArrayNotHasKey('workflow_run_id', $callback['context']);
                    self::assertArrayNotHasKey('trace_id', $callback['context']);
                }
            }
        } finally {
            $crashed?->stop(0, 9);
            (new Filesystem())->deleteDirectory($directory);
        }
    }

    private function worker(string $directory, string $mode): array
    {
        $process = $this->process($directory, $mode);
        $process->mustRun();
        self::assertSame('', $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], $result['outside_context']);
        self::assertTrue($result['scope_restored']);

        return $result;
    }

    private function process(string $directory, string $mode, bool $crash = false): Process
    {
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
            'DW_TEST_TRACING_RECOVERY' => 'true',
            'DW_TEST_TRACING_ENABLED' => $this->tracing ? 'true' : 'false',
            'DW_TEST_TRACING_CRASH_FIRST_ATTEMPT' => $crash ? 'true' : 'false',
        ]);
        $process->setTimeout(15);

        return $process;
    }

    private function timestamp(string $value): float
    {
        return (float) (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('U.u');
    }
}
