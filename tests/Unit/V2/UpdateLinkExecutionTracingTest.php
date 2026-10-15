<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class UpdateLinkExecutionTracingTest extends TestCase
{
    private const FIRST_CALLER = '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01';

    private const FAILED_CALLER = '00-cccccccccccccccccccccccccccccccc-dddddddddddddddd-01';

    private const DUPLICATE_CALLER = '00-eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee-ffffffffffffffff-01';

    public static function modes(): array
    {
        return [
            'traced' => [true, true],
            'disabled' => [false, false],
            'legacy admission' => [false, true],
        ];
    }

    #[DataProvider('modes')]
    public function testUpdateCallersLinkOnlyAcceptedProcessingAndKeepOriginalDecisions(
        bool $admissionEnabled,
        bool $workerEnabled,
    ): void {
        $directory = sys_get_temp_dir() . '/dw-tracing-update-links-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = static function (string $mode, string $step = 'first', bool $duplicate = false) use (
            $directory,
            $admissionEnabled,
            $workerEnabled,
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
                'DW_TEST_TRACING_UPDATE_LINKS' => 'true',
                'DW_TEST_TRACING_ENABLED' => ($mode === 'admit' ? $admissionEnabled : $workerEnabled) ? 'true' : 'false',
                'DW_TEST_TRACING_UPDATE_STEP' => $step,
                'DW_TEST_TRACING_UPDATE_CALLER' => $duplicate ? self::DUPLICATE_CALLER
                    : ($step === 'first' ? self::FIRST_CALLER : self::FAILED_CALLER),
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
            self::assertSame(0, $workers[0]['jobs']);
            $accepted = $worker('update');
            self::assertTrue($accepted['updates']['submitted']['accepted']);
            self::assertSame($workerEnabled ? [0] : [], $accepted['updates']['capture_transaction_levels']);
            $duplicate = $worker('update', duplicate: true);
            self::assertSame($accepted['updates']['submitted'], $duplicate['updates']['submitted']);
            self::assertSame($accepted['updates']['commands'], $duplicate['updates']['commands']);
            self::assertSame($accepted['updates']['history_hashes'], $duplicate['updates']['history_hashes']);
            $workers[] = $worker('work');
            self::assertSame('completed', $workers[1]['updates']['rows'][0]['status']);
            self::assertSame([9007199254740993, null, '雪'], $workers[1]['updates']['rows'][0]['result']);
            $failed = $worker('update', 'failure');
            self::assertTrue($failed['updates']['submitted']['accepted']);
            $workers[] = $worker('work');
            self::assertSame('failed', $workers[2]['updates']['rows'][1]['status']);
            self::assertSame('deliberate update failure', $workers[2]['updates']['rows'][1]['failure_message']);
            $workers[] = $worker('work');
            $refused = $worker('update', 'invalid');
            self::assertFalse($refused['updates']['submitted']['accepted']);
            self::assertNull($refused['updates']['commands'][2]['carrier']);
            $signalled = $worker('signal');
            for ($remaining = 6; $remaining > 0 && $worker('inspect')['jobs'] > 0; --$remaining) {
                $workers[] = $worker('work');
            }
            $finished = $worker('inspect');
            self::assertSame('completed', $finished['status'], json_encode([
                'history' => $finished['updates']['history_types'],
                'tasks' => $finished['updates']['tasks'],
                'failed_jobs' => $finished['failed_jobs'],
            ], JSON_THROW_ON_ERROR));
            self::assertSame([
                'updates' => [[9007199254740993, null, '雪']],
                'activity' => [9007199254740993, null, '雪'],
            ], $finished['output']);
            foreach (['run_id', 'carrier', 'execution_deadline', 'run_deadline'] as $field) {
                self::assertSame($admitted[$field], $finished[$field]);
            }
            foreach ([$admitted, $accepted, $failed, $refused, $signalled] as $prefix) {
                self::assertSame($prefix['updates']['history_hashes'], array_slice(
                    $finished['updates']['history_hashes'],
                    0,
                    count($prefix['updates']['history_hashes'])
                ));
            }
            self::assertSame(
                $accepted['updates']['commands'][0]['immutable'],
                $finished['updates']['commands'][0]['immutable']
            );
            self::assertSame(
                $failed['updates']['commands'][1]['immutable'],
                $finished['updates']['commands'][1]['immutable']
            );
            self::assertSame(0, $finished['jobs']);
            self::assertSame(0, $finished['failed_jobs']);
            self::assertSame(0, $finished['repairs']);
            self::assertCount(7, $finished['updates']['tasks']);
            foreach ($finished['updates']['tasks'] as $task) {
                self::assertSame('completed', $task['status']);
                self::assertSame(1, $task['attempt_count']);
            }
            $spans = array_merge(...array_column($workers, 'spans'));
            self::assertCount($workerEnabled ? 7 : 0, $spans);
            foreach ($spans as $index => $span) {
                self::assertSame($admitted['run_id'], $span['fields']['workflow_run_id']);
                if ($admissionEnabled) {
                    self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $span['trace_id']);
                    self::assertSame('00f067aa0ba902b7', $span['parent_id']);
                } else {
                    self::assertSame('0000000000000000', $span['parent_id']);
                    self::assertNotSame('11111111111111111111111111111111', $span['trace_id']);
                }
                if ($index === 1 || $index === 2) {
                    self::assertCount(1, $span['links']);
                    $caller = $index === 1 ? self::FIRST_CALLER : self::FAILED_CALLER;
                    self::assertSame(explode('-', $caller)[1], $span['links'][0]['trace_id']);
                    self::assertSame(explode('-', $caller)[2], $span['links'][0]['span_id']);
                    self::assertSame('update', $span['links'][0]['fields']['command_type']);
                    self::assertSame(
                        $finished['updates']['commands'][$index - 1]['id'],
                        $span['links'][0]['fields']['workflow_command_id']
                    );
                } elseif ($index === 4) {
                    self::assertCount(1, $span['links']);
                    self::assertSame('signal', $span['links'][0]['fields']['command_type']);
                    self::assertSame('77777777777777777777777777777777', $span['links'][0]['trace_id']);
                    self::assertSame('8888888888888888', $span['links'][0]['span_id']);
                    self::assertSame(
                        $finished['updates']['signal_command_id'],
                        $span['links'][0]['fields']['workflow_command_id']
                    );
                } else {
                    self::assertSame([], $span['links']);
                }
            }
            foreach (array_slice($finished['updates']['commands'], 0, 2) as $index => $command) {
                if ($workerEnabled) {
                    self::assertSame($index === 0 ? self::FIRST_CALLER : self::FAILED_CALLER, json_decode(
                        $command['carrier'],
                        true,
                        flags: JSON_THROW_ON_ERROR
                    )['carrier']['traceparent']);
                } else {
                    self::assertNull($command['carrier']);
                }
            }
            $records = array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory . '/activity.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            );
            self::assertCount(
                1,
                array_filter($records, static fn (array $record): bool => $record['message'] === 'callback')
            );
            foreach ($records as $record) {
                if ($record['message'] === 'update handler') {
                    self::assertSame(
                        $admissionEnabled && $workerEnabled ? 'origin' : null,
                        $record['context']['approved_baggage']
                    );
                }
                if ($record['message'] === 'worker outside' || ! $workerEnabled) {
                    self::assertArrayNotHasKey('trace_id', $record['context']);
                    self::assertArrayNotHasKey('workflow_run_id', $record['context']);
                }
            }
            self::assertStringNotContainsString(
                'observability_context',
                json_encode($finished['updates']['history_payloads'], JSON_THROW_ON_ERROR)
            );
            self::assertSame(count($workers), count(array_unique(array_column($workers, 'pid'))));
        } finally {
            (new Filesystem())->deleteDirectory($directory);
        }
    }
}
