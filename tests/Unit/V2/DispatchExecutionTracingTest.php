<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use OpenTelemetry\API\Trace\SpanKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DispatchExecutionTracingTest extends TestCase
{
    public static function modes(): array
    {
        return [
            'ordinary' => ['ordinary', true, true],
            'retry' => ['retry', true, true],
            'timer and backoff' => ['timer', true, true],
            'disabled' => ['ordinary', false, false],
            'legacy admission' => ['ordinary', false, true],
        ];
    }

    #[DataProvider('modes')]
    public function testActualQueuedDispatchLinksColdProcessingWithoutChangingTheDurableParent(
        string $scenario,
        bool $admissionEnabled,
        bool $workerEnabled,
    ): void {
        $directory = sys_get_temp_dir() . '/dw-tracing-dispatch-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = static function (string $mode) use ($directory, $scenario, $admissionEnabled, $workerEnabled): array {
            $packageVendor = dirname(__DIR__, 3) . '/vendor';
            $otherVendors = array_filter(
                array_keys(ClassLoader::getRegisteredLoaders()),
                static fn (string $vendor): bool => $vendor !== $packageVendor
            );
            $process = new Process([
                PHP_BINARY, __DIR__ . '/../../Fixtures/V2/tracing-process.php', $directory, $mode,
                $scenario === 'retry' ? 'retry' : 'once',
                ...array_map(
                    static fn (string $vendor): string => $vendor . '/autoload.php',
                    [...$otherVendors, $packageVendor]
                ),
            ], env: [
                'DW_TEST_TRACING_DISPATCH' => 'true',
                'DW_TEST_TRACING_TIMINGS' => $scenario === 'timer' ? 'true' : 'false',
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
            $count = $scenario === 'timer' ? 6 : ($scenario === 'retry' ? 4 : 3);
            $workers = [];
            for ($index = 0; $index < $count; ++$index) {
                if ($scenario === 'timer' && ($index === 1 || $index === 4)) {
                    sleep(2);
                }
                $workers[] = $worker('work');
            }
            $finished = $worker('inspect');
            self::assertSame('completed', $finished['status']);
            self::assertSame([9007199254740993, null, '雪'], $finished['output']);
            self::assertSame($count, $finished['tasks']);
            self::assertSame(0, $finished['jobs']);
            self::assertSame(0, $finished['failed_jobs']);
            self::assertSame(0, $finished['repairs']);
            self::assertSame(1, $finished['dispatch']['activity_completions']);
            self::assertCount($count, array_unique(array_column($workers, 'pid')));
            foreach ([$admitted, ...$workers] as $phase) {
                foreach (['run_id', 'carrier', 'execution_deadline', 'run_deadline'] as $field) {
                    self::assertSame($admitted[$field], $phase[$field]);
                    self::assertSame($admitted[$field], $finished[$field]);
                }
                self::assertSame($phase['dispatch']['history_hashes'], array_slice(
                    $finished['dispatch']['history_hashes'],
                    0,
                    count($phase['dispatch']['history_hashes'])
                ));
            }
            $allSpans = array_merge(...array_column([$admitted, ...$workers], 'spans'));
            $dispatches = array_values(array_filter(
                $allSpans,
                static fn (array $span): bool => $span['name'] === 'durable.task.dispatch'
            ));
            self::assertCount(($admissionEnabled ? 1 : 0) + ($workerEnabled ? $count - 1 : 0), $dispatches);
            $dispatchByTask = [];
            foreach ($dispatches as $span) {
                self::assertSame(SpanKind::KIND_PRODUCER, $span['kind']);
                self::assertSame($admitted['run_id'], $span['fields']['workflow_run_id']);
                self::assertSame('0', $span['fields']['namespace']);
                self::assertSame('returned', $span['fields']['durable.dispatch.outcome']);
                $dispatchByTask[$span['fields']['task_id']] = $span;
            }
            foreach ([$admitted, ...$workers] as $index => $phase) {
                $enabled = $index === 0 ? $admissionEnabled : $workerEnabled;
                $producers = array_values(array_filter(
                    $phase['spans'],
                    static fn (array $span): bool => $span['kind'] === SpanKind::KIND_PRODUCER
                ));
                self::assertCount(2 * count($producers), $phase['dispatch']['boundaries']);
                foreach ($phase['dispatch']['boundaries'] as $boundary) {
                    self::assertSame(0, $boundary['transaction_level']);
                }
                foreach ($phase['dispatch']['queued'] as $queued) {
                    if (! $enabled) {
                        self::assertNull($queued['trace']);
                        continue;
                    }
                    $carrier = $queued['trace'];
                    self::assertSame('durable-workflow.queue-trace', $carrier['schema']);
                    self::assertSame(1, $carrier['version']);
                    self::assertSame($queued['task_id'], $carrier['task_id']);
                    self::assertLessThanOrEqual(4096, strlen(json_encode($carrier, JSON_THROW_ON_ERROR)));
                    self::assertArrayNotHasKey('baggage', $carrier['context']['carrier']);
                    $producer = $dispatchByTask[$queued['task_id']];
                    self::assertSame(
                        '00-' . $producer['trace_id'] . '-' . $producer['span_id'] . '-01',
                        $carrier['context']['carrier']['traceparent']
                    );
                }
                if ($producers !== []) {
                    $parent = array_values(array_filter($phase['spans'], static fn (array $span): bool =>
                        $span['kind'] === SpanKind::KIND_CONSUMER || $span['name'] === 'durable.workflow.admission'));
                    self::assertCount(1, $parent);
                    foreach ($producers as $producer) {
                        self::assertSame($parent[0]['span_id'], $producer['parent_id']);
                    }
                }
            }
            foreach ($workers as $index => $phase) {
                $consumers = array_values(array_filter(
                    $phase['spans'],
                    static fn (array $span): bool => $span['kind'] === SpanKind::KIND_CONSUMER
                ));
                self::assertCount($workerEnabled ? 1 : 0, $consumers);
                if (! $workerEnabled) {
                    continue;
                }
                $span = $consumers[0];
                self::assertSame($admissionEnabled ? '00f067aa0ba902b7' : '0000000000000000', $span['parent_id']);
                self::assertNotSame('11111111111111111111111111111111', $span['trace_id']);
                $delivered = $phase['dispatch']['delivered'];
                self::assertCount(1, $delivered);
                self::assertSame($span['fields']['task_id'], $delivered[0]['task_id']);
                $tracedDispatch = $index === 0 ? $admissionEnabled : $workerEnabled;
                self::assertCount($tracedDispatch ? 1 : 0, $span['links']);
                if ($tracedDispatch) {
                    $producer = $dispatchByTask[$span['fields']['task_id']];
                    self::assertSame($producer['trace_id'], $span['links'][0]['trace_id']);
                    self::assertSame($producer['span_id'], $span['links'][0]['span_id']);
                    self::assertSame($span['fields']['task_id'], $span['links'][0]['fields']['task_id']);
                }
            }
            $history = json_encode($finished['dispatch']['history_payloads'], JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('dispatchObservabilityContext', $history);
            self::assertStringNotContainsString('durable-workflow.queue-trace', $history);
            self::assertStringNotContainsString('must-not-propagate', json_encode($allSpans, JSON_THROW_ON_ERROR));
        } finally {
            (new Filesystem())->deleteDirectory($directory);
        }
    }
}
