<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use OpenTelemetry\API\Trace\SpanKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AdmissionExecutionTracingTest extends TestCase
{
    public static function modes(): array
    {
        return [
            'traced caller' => [true, false],
            'disabled control' => [false, false],
            'caller without parent' => [true, true],
        ];
    }

    #[DataProvider('modes')]
    public function testOrdinaryAdmissionDecisionsPreserveTheCallerAndOriginalDurableParent(
        bool $enabled,
        bool $withoutParent,
    ): void {
        $directory = sys_get_temp_dir() . '/dw-tracing-admission-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = static function (string $mode) use ($directory, $enabled, $withoutParent): array {
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
                'DW_TEST_TRACING_ADMISSION' => 'true',
                'DW_TEST_TRACING_ADMISSION_NO_PARENT' => $withoutParent ? 'true' : 'false',
                'DW_TEST_TRACING_ENABLED' => $enabled ? 'true' : 'false',
            ]);
            $process->setTimeout(15);
            $process->mustRun();
            self::assertSame('', $process->getErrorOutput());
            self::assertJson($process->getOutput(), substr($process->getOutput(), -1500));
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame([], $result['outside_context']);
            self::assertTrue($result['scope_restored']);
            self::assertTrue($result['admission']['context_preserved']);

            return $result;
        };
        try {
            $admitted = $worker('admit');
            $duplicate = $worker('duplicate');
            $rejected = $worker('reject');
            $workers = [$worker('work'), $worker('work'), $worker('work')];
            $finished = $worker('inspect');
            self::assertSame('completed', $finished['status']);
            self::assertSame([9007199254740993, null, '雪'], $finished['output']);
            self::assertSame(1, $finished['admission']['run_count']);
            self::assertSame(3, $finished['tasks']);
            self::assertSame(0, $finished['jobs']);
            self::assertSame(0, $finished['failed_jobs']);
            self::assertSame(0, $finished['repairs']);
            foreach ([$duplicate, $rejected, $finished] as $observation) {
                foreach (['run_id', 'carrier', 'execution_deadline', 'run_deadline'] as $field) {
                    self::assertSame($admitted[$field], $observation[$field]);
                }
                self::assertSame($admitted['admission']['history_hashes'], array_slice(
                    $observation['admission']['history_hashes'],
                    0,
                    count($admitted['admission']['history_hashes'])
                ));
            }
            foreach ([$admitted, $duplicate, $rejected] as $index => $attempt) {
                self::assertSame(
                    $enabled ? array_fill(0, 1 + count($attempt['spans']) - count($attempt['execution_spans']), 0) : [],
                    $attempt['admission']['capture_transaction_levels']
                );
                self::assertCount($enabled ? 1 : 0, $attempt['execution_spans']);
                self::assertSame(
                    ['started_new', 'returned_existing_active', 'rejected_duplicate'][$index],
                    $attempt['admission']['result']['outcome']
                );
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
                self::assertSame('durable.workflow.admission', $span['name']);
                self::assertSame(SpanKind::KIND_INTERNAL, $span['kind']);
                self::assertSame($admitted['run_id'], $span['fields']['workflow_run_id']);
                self::assertSame('cold-tracing', $span['fields']['workflow_instance_id']);
                self::assertSame('0', $span['fields']['namespace']);
                self::assertSame($attempt['admission']['result']['command_id'], $span['fields']['workflow_command_id']);
                self::assertSame(
                    $attempt['admission']['result']['outcome'],
                    $span['fields']['durable.command.outcome']
                );
                self::assertSame($index === 2 ? 'rejected' : 'accepted', $span['fields']['durable.command.status']);
                if ($index === 2) {
                    self::assertSame('instance_already_started', $span['fields']['durable.command.rejection_reason']);
                }
                if ($withoutParent) {
                    self::assertSame('0000000000000000', $span['parent_id']);
                } else {
                    self::assertSame($index === 0 ? '4bf92f3577b34da6a3ce929d0e0e4736'
                        : 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $span['trace_id']);
                    self::assertSame($index === 0 ? '00f067aa0ba902b7' : 'bbbbbbbbbbbbbbbb', $span['parent_id']);
                }
            }
            self::assertCount(1, $admitted['admission']['callbacks']);
            $callback = $admitted['admission']['callbacks'][0];
            self::assertSame(0, $callback['transaction_level']);
            self::assertSame('caller-scope', $callback['foreign']);
            if ($enabled) {
                self::assertSame($admitted['execution_spans'][0]['span_id'], $callback['span_id']);
                self::assertSame('cold-tracing', $callback['fields']['workflow_instance_id']);
            } else {
                self::assertSame([], $callback['fields']);
            }
            self::assertSame([], $duplicate['admission']['callbacks']);
            self::assertSame([], $rejected['admission']['callbacks']);
            if ($enabled && ! $withoutParent) {
                $carrier = json_decode($admitted['carrier'], true, flags: JSON_THROW_ON_ERROR)['carrier'];
                self::assertSame('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', $carrier['traceparent']);
                self::assertSame('approved=origin', $carrier['baggage']);
            } else {
                self::assertNull($admitted['carrier']);
            }
            foreach ($workers as $workerResult) {
                self::assertCount($enabled ? 1 : 0, $workerResult['execution_spans']);
                if ($enabled) {
                    $span = $workerResult['execution_spans'][0];
                    self::assertSame($withoutParent ? '0000000000000000' : '00f067aa0ba902b7', $span['parent_id']);
                    self::assertNotSame($admitted['execution_spans'][0]['span_id'], $span['parent_id']);
                }
            }
            $records = array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory . '/activity.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            );
            $callbacks = array_values(
                array_filter($records, static fn (array $record): bool => $record['message'] === 'admission callback')
            );
            self::assertCount(1, $callbacks);
            self::assertSame('application-request', $callbacks[0]['context']['request_id']);
            self::assertStringNotContainsString(
                'durable.command.outcome',
                json_encode($finished['admission']['history_payloads'], JSON_THROW_ON_ERROR)
            );
            self::assertCount(3, array_unique(array_column($workers, 'pid')));
        } finally {
            (new Filesystem())->deleteDirectory($directory);
        }
    }
}
