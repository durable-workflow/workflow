<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SignalStartExecutionTracingTest extends TestCase
{
    public static function modes(): array
    {
        return [
            'traced admission' => [true, true],
            'disabled control' => [false, false],
            'legacy admission, enabled later' => [false, true],
        ];
    }

    #[DataProvider('modes')]
    public function testSignalWithStartKeepsItsOriginalTraceAcrossAnotherCallerAndColdWorkers(
        bool $admissionEnabled,
        bool $workerEnabled,
    ): void {
        $directory = sys_get_temp_dir() . '/dw-tracing-signal-' . bin2hex(random_bytes(8));
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
                'DW_TEST_TRACING_SIGNAL_START' => 'true',
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
            self::assertSame('started_new', $admitted['signal_start_outcome']);
            self::assertSame($admissionEnabled, $admitted['carrier'] !== null);
            self::assertSame($admissionEnabled ? [0] : [], $admitted['capture_transaction_levels']);
            $existing = $worker('duplicate');
            self::assertSame('returned_existing_active', $existing['signal_start_outcome']);
            self::assertLessThanOrEqual($workerEnabled ? 1 : 0, count($existing['capture_transaction_levels']));
            foreach ($existing['capture_transaction_levels'] as $level) {
                self::assertSame(0, $level);
            }
            self::assertSame(2, $existing['signal_count']);
            self::assertSame(
                $admitted['history_hashes'],
                array_slice($existing['history_hashes'], 0, count($admitted['history_hashes']))
            );
            $workers = [];
            for ($remaining = 6; $remaining > 0; --$remaining) {
                $state = $worker('inspect');
                if ($state['jobs'] === 0) {
                    break;
                }
                $workers[] = $worker('work');
            }
            $finished = $worker('inspect');
            self::assertSame('completed', $finished['status']);
            self::assertSame([
                'signal' => [9007199254740993, null, '雪'],
                'activity' => [9007199254740993, null, '雪'],
            ], $finished['output']);
            self::assertSame(1, $finished['run_count']);
            self::assertSame(1, $finished['completion_count']);
            self::assertSame(0, $finished['repairs']);
            self::assertSame(0, $finished['jobs']);
            self::assertSame(0, $finished['failed_jobs']);
            foreach (['run_id', 'carrier', 'execution_deadline', 'run_deadline'] as $key) {
                self::assertSame($admitted[$key], $existing[$key]);
                self::assertSame($admitted[$key], $finished[$key]);
            }
            self::assertSame(
                $existing['history_hashes'],
                array_slice($finished['history_hashes'], 0, count($existing['history_hashes']))
            );
            self::assertSame(count($workers), count(array_unique(array_column($workers, 'pid'))));
            $spans = array_merge(...array_column($workers, 'spans'));
            self::assertCount($workerEnabled ? 3 : 0, $spans);
            foreach ($spans as $span) {
                self::assertSame($admitted['run_id'], $span['fields']['workflow_run_id']);
                if ($admissionEnabled) {
                    self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $span['trace_id']);
                    self::assertSame('00f067aa0ba902b7', $span['parent_id']);
                } else {
                    self::assertSame('0000000000000000', $span['parent_id']);
                    self::assertNotSame('11111111111111111111111111111111', $span['trace_id']);
                }
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
