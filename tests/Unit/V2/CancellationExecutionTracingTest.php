<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CancellationExecutionTracingTest extends TestCase
{
    public static function modes(): iterable
    {
        foreach (['request', 'cancel', 'terminate'] as $command) {
            yield $command . ', traced' => [$command, true, true];
            yield $command . ', disabled' => [$command, false, false];
        }
        yield 'request, legacy admission' => ['request', true, false];
    }

    #[DataProvider('modes')]
    public function testCancellationKeepsOriginalContextAndUnwindsColdWorkerScopes(
        string $command,
        bool $enabled,
        bool $admissionEnabled,
    ): void {
        $directory = sys_get_temp_dir() . '/dw-tracing-cancellation-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = static function (string $mode, bool $duplicate = false) use (
            $directory,
            $command,
            $enabled,
            $admissionEnabled,
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
                'DW_TEST_TRACING_CANCELLATION' => $command,
                'DW_TEST_TRACING_ENABLED' => ($mode === 'admit' ? $admissionEnabled : $enabled) ? 'true' : 'false',
                'DW_TEST_TRACING_CANCEL_DUPLICATE' => $duplicate ? 'true' : 'false',
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
            $waiting = $worker('work');
            self::assertSame('waiting', $waiting['status']);
            self::assertSame(0, $waiting['jobs']);
            self::assertSame([], $waiting['cancellation']['activities']);
            $requested = $worker('cancel');
            self::assertTrue($requested['cancellation']['result']['accepted']);
            if ($command === 'request') {
                self::assertSame(
                    $enabled ? array_fill(
                        0,
                        1 + count($requested['spans']) - count($requested['execution_spans']),
                        0
                    ) : [],
                    $requested['cancellation']['capture_transaction_levels']
                );
                self::assertSame($enabled, $requested['cancellation']['request_carrier'] !== null);
                if ($enabled) {
                    self::assertSame('00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01', json_decode(
                        $requested['cancellation']['request_carrier'],
                        true,
                        flags: JSON_THROW_ON_ERROR
                    )['carrier']['traceparent']);
                }
                $duplicate = $worker('cancel', true);
                self::assertSame($requested['cancellation']['result'], $duplicate['cancellation']['result']);
                self::assertSame($requested['cancellation']['deadline'], $duplicate['cancellation']['deadline']);
                self::assertSame(
                    $requested['cancellation']['request_carrier'],
                    $duplicate['cancellation']['request_carrier']
                );
                self::assertSame(
                    $requested['cancellation']['history_hashes'],
                    $duplicate['cancellation']['history_hashes']
                );
            }
            $workers = [$waiting];
            for ($remaining = 6; $remaining > 0; --$remaining) {
                if ($worker('inspect')['jobs'] === 0) {
                    break;
                }
                $workers[] = $worker('work');
            }
            $finished = $worker('inspect');
            self::assertSame($command === 'terminate' ? 'terminated' : 'cancelled', $finished['status'], json_encode([
                'history_types' => $finished['cancellation']['history_types'],
                'tasks' => $finished['cancellation']['tasks'],
                'failed_jobs' => $finished['failed_jobs'],
            ], JSON_THROW_ON_ERROR));
            self::assertSame(0, $finished['jobs']);
            self::assertSame(0, $finished['failed_jobs']);
            self::assertSame(0, $finished['repairs']);
            foreach (['run_id', 'carrier', 'execution_deadline', 'run_deadline'] as $key) {
                self::assertSame($admitted[$key], $requested[$key]);
                self::assertSame($admitted[$key], $finished[$key]);
            }
            foreach ([$waiting, $requested] as $prefix) {
                self::assertSame($prefix['cancellation']['history_hashes'], array_slice(
                    $finished['cancellation']['history_hashes'],
                    0,
                    count($prefix['cancellation']['history_hashes'])
                ));
            }
            $originalCommands = array_column($waiting['cancellation']['commands'], null, 'id');
            $commands = array_column($finished['cancellation']['commands'], null, 'id');
            self::assertSame($originalCommands, array_intersect_key($commands, $originalCommands));
            self::assertCount(count($originalCommands) + 1, $commands);
            self::assertSame(
                $command === 'request' ? 'request_cancellation' : $command,
                $commands[$requested['cancellation']['result']['command_id']]['command_type']
            );
            $cooperative = $command === 'request';
            self::assertSame(
                $requested['cancellation']['request_carrier'],
                $finished['cancellation']['request_carrier']
            );
            self::assertSame($cooperative ? [
                'cleanup' => [9007199254740993, null, '雪'],
            ] : [], $finished['cancellation']['memo']);
            self::assertCount($cooperative ? 1 : 0, $finished['cancellation']['activities']);
            if ($cooperative) {
                self::assertSame('completed', $finished['cancellation']['activities'][0]['status']);
                self::assertSame(1, $finished['cancellation']['activities'][0]['attempt_count']);
                foreach ([
                    'CooperativeCancellationRequested',
                    'CooperativeCancellationDelivered',
                    'ActivityCompleted',
                    'MemoUpserted',
                ] as $type) {
                    self::assertSame(1, array_count_values($finished['cancellation']['history_types'])[$type] ?? 0);
                }
            }
            self::assertNotContains('WorkflowCompleted', $finished['cancellation']['history_types']);
            foreach ($finished['cancellation']['tasks'] as $task) {
                self::assertSame(1, $task['attempt_count']);
            }
            $spans = array_merge(...array_column($workers, 'execution_spans'));
            self::assertCount($enabled ? count($workers) : 0, $spans);
            $taskIds = array_column($finished['cancellation']['tasks'], 'id');
            foreach ($spans as $span) {
                if ($admissionEnabled) {
                    self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $span['trace_id']);
                    self::assertSame('00f067aa0ba902b7', $span['parent_id']);
                } else {
                    self::assertSame('0000000000000000', $span['parent_id']);
                    self::assertNotSame('11111111111111111111111111111111', $span['trace_id']);
                }
                self::assertSame($admitted['run_id'], $span['fields']['workflow_run_id']);
                self::assertContains($span['fields']['task_id'], $taskIds);
            }
            $linked = array_values(array_filter($spans, static fn (array $span): bool => $span['links'] !== []));
            self::assertCount($enabled && $cooperative ? 1 : 0, $linked);
            if ($linked !== []) {
                self::assertSame('durable.workflow.task', $linked[0]['name']);
                self::assertCount(1, $linked[0]['links']);
                self::assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $linked[0]['links'][0]['trace_id']);
                self::assertSame('bbbbbbbbbbbbbbbb', $linked[0]['links'][0]['span_id']);
                self::assertSame('request_cancellation', $linked[0]['links'][0]['fields']['command_type']);
                self::assertSame(
                    $requested['cancellation']['result']['command_id'],
                    $linked[0]['links'][0]['fields']['workflow_command_id']
                );
            }
            self::assertCount($enabled && $cooperative ? 1 : 0, array_filter(
                $spans,
                static fn (array $span): bool => $span['name'] === 'durable.activity.attempt'
            ));
            $records = array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory . '/activity.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            );
            self::assertCount($cooperative ? 1 : 0, array_filter(
                $records,
                static fn (array $record): bool => $record['message'] === 'callback'
            ));
            foreach ($records as $record) {
                if ($record['message'] === 'worker outside' || ! $enabled) {
                    self::assertArrayNotHasKey('workflow_run_id', $record['context']);
                    self::assertArrayNotHasKey('trace_id', $record['context']);
                } elseif (in_array($record['message'], ['cancellation cleanup', 'callback'], true)) {
                    self::assertSame($admitted['run_id'], $record['context']['workflow_run_id']);
                    if ($admissionEnabled) {
                        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $record['context']['trace_id']);
                    } else {
                        self::assertNotSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $record['context']['trace_id']);
                    }
                    self::assertContains($record['context']['task_id'], $taskIds);
                }
                if ($record['message'] === 'cancellation cleanup') {
                    self::assertSame(
                        $admissionEnabled && $enabled ? 'origin' : null,
                        $record['context']['approved_baggage']
                    );
                }
            }
            self::assertStringNotContainsString('observability_context', json_encode(
                $finished['cancellation']['history_payloads'],
                JSON_THROW_ON_ERROR
            ));
            self::assertSame(count($workers), count(array_unique(array_column($workers, 'pid'))));
        } finally {
            (new Filesystem())->deleteDirectory($directory);
        }
    }
}
