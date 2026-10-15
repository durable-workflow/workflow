<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SignalLinkExecutionTracingTest extends TestCase
{
    private const FIRST_CALLER = '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01';

    private const SECOND_CALLER = '00-cccccccccccccccccccccccccccccccc-dddddddddddddddd-01';

    public static function modes(): array
    {
        return [
            'traced admission' => [true, true],
            'disabled control' => [false, false],
            'legacy admission' => [false, true],
        ];
    }

    #[DataProvider('modes')]
    public function testIndependentSignalCallersLinkOnlyTheirActualApplicationSegments(
        bool $admissionEnabled,
        bool $workerEnabled,
    ): void {
        $directory = sys_get_temp_dir() . '/dw-tracing-signal-links-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = static function (string $mode, string $step = 'first') use (
            $directory,
            $admissionEnabled,
            $workerEnabled
        ): array {
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
                'DW_TEST_TRACING_SIGNAL_LINKS' => 'true',
                'DW_TEST_TRACING_ENABLED' => ($mode === 'admit' ? $admissionEnabled : $workerEnabled) ? 'true' : 'false',
                'DW_TEST_TRACING_SIGNAL_STEP' => $step,
                'DW_TEST_TRACING_SIGNAL_CALLER' => $step === 'first' ? self::FIRST_CALLER : self::SECOND_CALLER,
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
            self::assertSame($admissionEnabled ? [0] : [], $admitted['signals']['capture_transaction_levels']);
            $early = $worker('signal');
            self::assertSame($workerEnabled ? [0] : [], $early['signals']['capture_transaction_levels']);
            self::assertSame($workerEnabled, $early['signals']['commands'][0]['carrier'] !== null);
            $waiting = $worker('work');
            self::assertSame(
                'waiting',
                $waiting['status'],
                json_encode(array_column($waiting['signals']['history_payloads'], 'message'), JSON_THROW_ON_ERROR)
            );
            self::assertSame(0, $waiting['jobs']);
            $late = $worker('signal', 'second');
            self::assertSame($workerEnabled ? [0] : [], $late['signals']['capture_transaction_levels']);
            $workers = [$waiting];
            for ($remaining = 6; $remaining > 0 && $worker('inspect')['jobs'] > 0; --$remaining) {
                $workers[] = $worker('work');
            }
            $finished = $worker('inspect');
            self::assertSame('completed', $finished['status']);
            self::assertSame([
                'first' => [9007199254740993, null, '雪'],
                'second' => [false, null, 'é'],
                'activity' => [9007199254740993, null, '雪'],
            ], $finished['output']);
            foreach (['run_id', 'carrier', 'execution_deadline', 'run_deadline'] as $key) {
                self::assertSame($admitted[$key], $finished[$key]);
            }
            foreach ([$admitted, $early, $waiting, $late] as $prefix) {
                self::assertSame($prefix['signals']['history_hashes'], array_slice(
                    $finished['signals']['history_hashes'],
                    0,
                    count($prefix['signals']['history_hashes'])
                ));
            }
            self::assertSame($early['signals']['commands'][0], $finished['signals']['commands'][0]);
            self::assertSame($late['signals']['commands'], $finished['signals']['commands']);
            self::assertSame(0, $finished['jobs']);
            self::assertSame(0, $finished['failed_jobs']);
            self::assertSame(0, $finished['repairs']);
            self::assertCount(4, $finished['signals']['tasks']);
            foreach ($finished['signals']['tasks'] as $task) {
                self::assertSame('completed', $task['status']);
                self::assertSame(1, $task['attempt_count']);
            }
            $spans = array_merge(...array_column($workers, 'spans'));
            self::assertCount($workerEnabled ? 4 : 0, $spans);
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
            if ($workerEnabled) {
                foreach ([self::FIRST_CALLER, self::SECOND_CALLER] as $index => $caller) {
                    $link = $spans[$index]['links'];
                    self::assertCount(1, $link);
                    self::assertSame(explode('-', $caller)[1], $link[0]['trace_id']);
                    self::assertSame(explode('-', $caller)[2], $link[0]['span_id']);
                    self::assertSame(
                        $finished['signals']['commands'][$index]['id'],
                        $link[0]['fields']['workflow_command_id']
                    );
                    self::assertSame('signal', $link[0]['fields']['command_type']);
                    self::assertSame($caller, json_decode(
                        $finished['signals']['commands'][$index]['carrier'],
                        true,
                        flags: JSON_THROW_ON_ERROR
                    )['carrier']['traceparent']);
                }
                self::assertSame([], $spans[2]['links']);
                self::assertSame([], $spans[3]['links']);
            } else {
                foreach ($finished['signals']['commands'] as $command) {
                    self::assertNull($command['carrier']);
                }
            }
            $records = array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory . '/activity.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            );
            $callbacks = array_filter($records, static fn (array $record): bool => $record['message'] === 'callback');
            self::assertCount(1, $callbacks);
            foreach ($records as $record) {
                if ($record['message'] === 'signal applied') {
                    self::assertSame($admissionEnabled ? 'origin' : null, $record['context']['approved_baggage']);
                }
                if ($record['message'] === 'worker outside' || ! $workerEnabled) {
                    self::assertArrayNotHasKey('trace_id', $record['context']);
                    self::assertArrayNotHasKey('workflow_run_id', $record['context']);
                }
            }
            self::assertStringNotContainsString('observability_context', json_encode(
                $finished['signals']['history_payloads'],
                JSON_THROW_ON_ERROR
            ));
            self::assertSame(count($workers), count(array_unique(array_column($workers, 'pid'))));
        } finally {
            (new Filesystem())->deleteDirectory($directory);
        }
    }
}
