<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use OpenTelemetry\API\Trace\SpanKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SignalStartExecutionTracingTest extends TestCase
{
    public static function modes(): array
    {
        return [
            'traced admission' => [true, true, false],
            'disabled control' => [false, false, false],
            'legacy admission, enabled later' => [false, true, false],
            'no application parent' => [true, true, true],
        ];
    }

    #[DataProvider('modes')]
    public function testSignalWithStartKeepsItsOriginalTraceAcrossAnotherCallerAndColdWorkers(
        bool $admissionEnabled,
        bool $workerEnabled,
        bool $withoutParent,
    ): void {
        $directory = sys_get_temp_dir() . '/dw-tracing-signal-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = static function (string $mode) use (
            $directory,
            $admissionEnabled,
            $workerEnabled,
            $withoutParent
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
                'DW_TEST_TRACING_SIGNAL_START' => 'true',
                'DW_TEST_TRACING_SIGNAL_START_ADMISSION' => 'true',
                'DW_TEST_TRACING_ADMISSION_NO_PARENT' => $withoutParent ? 'true' : 'false',
                'DW_TEST_TRACING_ENABLED' => ($mode === 'admit' ? $admissionEnabled : $workerEnabled) ? 'true' : 'false',
            ]);
            $process->setTimeout(15);
            $process->mustRun();
            self::assertSame('', $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame([], $result['outside_context']);
            self::assertTrue($result['scope_restored']);
            self::assertTrue($result['admission']['context_preserved']);

            return $result;
        };
        try {
            $admitted = $worker('admit');
            self::assertSame('started_new', $admitted['signal_start_outcome']);
            $originalHasParent = $admissionEnabled && ! $withoutParent;
            self::assertSame($originalHasParent, $admitted['carrier'] !== null);
            self::assertSame($originalHasParent, $admitted['signal_commands'][0]['carrier'] !== null);
            self::assertSame(
                $admissionEnabled ? array_fill(
                    0,
                    1 + count($admitted['spans']) - count($admitted['execution_spans']),
                    0
                ) : [],
                $admitted['capture_transaction_levels']
            );
            $existing = $worker('duplicate');
            self::assertSame('returned_existing_active', $existing['signal_start_outcome']);
            self::assertLessThanOrEqual(
                $workerEnabled ? 1 + count($existing['spans']) - count($existing['execution_spans']) : 0,
                count($existing['capture_transaction_levels'])
            );
            foreach ($existing['capture_transaction_levels'] as $level) {
                self::assertSame(0, $level);
            }
            self::assertSame(2, $existing['signal_count']);
            self::assertSame($admitted['signal_commands'][0], $existing['signal_commands'][0]);
            self::assertSame($workerEnabled && ! $withoutParent, $existing['signal_commands'][1]['carrier'] !== null);
            self::assertSame(
                $admitted['history_hashes'],
                array_slice($existing['history_hashes'], 0, count($admitted['history_hashes']))
            );
            $rejected = $worker('reject-signal-start');
            self::assertSame('rejected', $rejected['signal_start_result']['status']);
            self::assertSame('unknown_signal', $rejected['signal_start_result']['rejection_reason']);
            self::assertNull($rejected['signal_start_result']['start_command_id']);
            self::assertSame(2, $rejected['signal_count']);
            self::assertSame($existing['signal_commands'], array_slice($rejected['signal_commands'], 0, 2));
            self::assertCount(3, $rejected['signal_commands']);
            self::assertNull($rejected['signal_commands'][2]['carrier']);
            self::assertSame($existing['history_hashes'], array_slice(
                $rejected['history_hashes'],
                0,
                count($existing['history_hashes'])
            ));
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
            self::assertSame($rejected['signal_commands'], $finished['signal_commands']);
            foreach (['run_id', 'carrier', 'execution_deadline', 'run_deadline'] as $key) {
                self::assertSame($admitted[$key], $existing[$key]);
                self::assertSame($admitted[$key], $rejected[$key]);
                self::assertSame($admitted[$key], $finished[$key]);
            }
            self::assertSame(
                $existing['history_hashes'],
                array_slice($finished['history_hashes'], 0, count($existing['history_hashes']))
            );
            self::assertSame(count($workers), count(array_unique(array_column($workers, 'pid'))));
            $spans = array_merge(...array_column($workers, 'execution_spans'));
            self::assertCount($workerEnabled ? 3 : 0, $spans);
            foreach ($spans as $span) {
                self::assertSame($admitted['run_id'], $span['fields']['workflow_run_id']);
                if ($originalHasParent) {
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
            foreach ([$admitted, $existing, $rejected] as $index => $attempt) {
                $enabled = $index === 0 ? $admissionEnabled : $workerEnabled;
                self::assertSame(
                    $enabled ? array_fill(0, 1 + count($attempt['spans']) - count($attempt['execution_spans']), 0) : [],
                    $attempt['capture_transaction_levels']
                );
                self::assertCount($enabled ? 1 : 0, $attempt['execution_spans']);
                if (! $enabled) {
                    self::assertSame([], $attempt['admission']['boundaries']);
                    continue;
                }
                self::assertSame([
                    [
                        'event' => 'start',
                        'transaction_level' => 0,
                    ],
                    [
                        'event' => 'end',
                        'transaction_level' => 0,
                    ],
                ], $attempt['admission']['boundaries']);
                $span = $attempt['execution_spans'][0];
                $receipt = $attempt['signal_start_result'];
                self::assertSame('durable.workflow.admission', $span['name']);
                self::assertSame(SpanKind::KIND_INTERNAL, $span['kind']);
                self::assertSame($admitted['run_id'], $span['fields']['workflow_run_id']);
                self::assertSame('0', $span['fields']['namespace']);
                self::assertSame($receipt['command_id'], $span['fields']['workflow_command_id']);
                self::assertSame('signal', $span['fields']['durable.command.type']);
                self::assertSame($receipt['status'], $span['fields']['durable.command.status']);
                self::assertSame($receipt['outcome'], $span['fields']['durable.command.outcome']);
                if ($index === 2) {
                    self::assertSame('unknown_signal', $span['fields']['durable.command.rejection_reason']);
                    foreach (['command_id', 'status', 'outcome'] as $field) {
                        self::assertArrayNotHasKey('durable.start.' . $field, $span['fields']);
                    }
                } else {
                    foreach (['command_id', 'status', 'outcome'] as $field) {
                        self::assertSame($receipt['start_' . $field], $span['fields']['durable.start.' . $field]);
                    }
                }
                self::assertSame($withoutParent ? '0000000000000000'
                    : ($index === 0 ? '00f067aa0ba902b7' : 'bbbbbbbbbbbbbbbb'), $span['parent_id']);
                if (! $withoutParent) {
                    self::assertSame($index === 0 ? '4bf92f3577b34da6a3ce929d0e0e4736'
                        : 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $span['trace_id']);
                }
                self::assertStringNotContainsString(
                    'refused-admission-private',
                    json_encode($span, JSON_THROW_ON_ERROR)
                );
                foreach ($spans as $processing) {
                    self::assertNotSame($span['span_id'], $processing['parent_id']);
                    foreach ($processing['links'] as $link) {
                        self::assertNotSame($span['span_id'], $link['span_id']);
                    }
                }
            }
            foreach ([$admitted['signal_commands'][0], $existing['signal_commands'][1]] as $index => $command) {
                if ($command['carrier'] !== null) {
                    $carrier = json_decode($command['carrier'], true, flags: JSON_THROW_ON_ERROR)['carrier'];
                    self::assertSame($index === 0
                        ? '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'
                        : '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01', $carrier['traceparent']);
                }
            }
        } finally {
            (new Filesystem())->deleteDirectory($directory);
        }
    }
}
