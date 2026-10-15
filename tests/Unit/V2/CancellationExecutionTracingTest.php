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
            yield $command . ', traced' => [$command, true];
            yield $command . ', disabled' => [$command, false];
        }
    }

    #[DataProvider('modes')]
    public function testCancellationKeepsOriginalContextAndUnwindsColdWorkerScopes(string $command, bool $enabled): void
    {
        $directory = sys_get_temp_dir() . '/dw-tracing-cancellation-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = static function (string $mode) use ($directory, $command, $enabled): array {
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
                'DW_TEST_TRACING_ENABLED' => $enabled ? 'true' : 'false',
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
            self::assertSame($enabled, $admitted['carrier'] !== null);
            $waiting = $worker('work');
            self::assertSame('waiting', $waiting['status']);
            self::assertSame(0, $waiting['jobs']);
            self::assertSame([], $waiting['cancellation']['activities']);
            $requested = $worker('cancel');
            self::assertTrue($requested['cancellation']['result']['accepted']);
            if ($command === 'request') {
                $duplicate = $worker('cancel');
                self::assertSame($requested['cancellation']['result'], $duplicate['cancellation']['result']);
                self::assertSame($requested['cancellation']['deadline'], $duplicate['cancellation']['deadline']);
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
            self::assertSame($command === 'terminate' ? 'terminated' : 'cancelled', $finished['status']);
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
            $spans = array_merge(...array_column($workers, 'spans'));
            self::assertCount($enabled ? count($workers) : 0, $spans);
            $taskIds = array_column($finished['cancellation']['tasks'], 'id');
            foreach ($spans as $span) {
                self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $span['trace_id']);
                self::assertSame('00f067aa0ba902b7', $span['parent_id']);
                self::assertSame($admitted['run_id'], $span['fields']['workflow_run_id']);
                self::assertContains($span['fields']['task_id'], $taskIds);
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
                    self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $record['context']['trace_id']);
                    self::assertContains($record['context']['task_id'], $taskIds);
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
