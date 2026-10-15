<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class TransitionExecutionTracingTest extends TestCase
{
    public static function transitions(): array
    {
        return [
            'child, tracing enabled' => ['child', true, true],
            'child, tracing disabled' => ['child', false, false],
            'continuation, tracing enabled' => ['continue', true, true],
            'continuation, tracing disabled' => ['continue', false, false],
            'legacy child, enabled later' => ['child', true, false],
            'legacy continuation, enabled later' => ['continue', true, false],
            'child, disabled workers' => ['child', false, true],
            'continuation, disabled workers' => ['continue', false, true],
        ];
    }

    #[DataProvider('transitions')]
    public function testColdSuccessorRetainsTheAdmissionCarrierWithoutChangingDurableExecution(
        string $transition,
        bool $enabled,
        bool $admissionEnabled,
    ): void {
        $directory = sys_get_temp_dir() . '/dw-tracing-transition-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = static function (string $mode) use ($directory, $transition, $enabled, $admissionEnabled): array {
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
                'DW_TEST_TRACING_TRANSITION' => $transition,
                'DW_TEST_TRACING_ENABLED' => ($mode === 'admit' ? $admissionEnabled : $enabled) ? 'true' : 'false',
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
            $scheduled = $worker('work');
            self::assertCount(2, $scheduled['runs']);
            foreach ($scheduled['runs'] as $run) {
                self::assertSame($admitted['carrier'], $run['carrier'], json_encode($run, JSON_THROW_ON_ERROR));
            }
            $workers = [$scheduled];
            for ($remaining = 8; $remaining > 0; --$remaining) {
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
            self::assertCount(2, $finished['runs']);
            foreach ($scheduled['runs'] as $index => $run) {
                foreach (['id', 'instance_id', 'carrier', 'execution_deadline', 'run_deadline'] as $key) {
                    self::assertSame($run[$key], $finished['runs'][$index][$key]);
                }
            }
            if ($transition === 'continue') {
                self::assertNotSame($admitted['run_id'], $finished['run_id']);
                self::assertSame($admitted['execution_deadline'], $finished['execution_deadline']);
                self::assertSame('completed', $finished['runs'][0]['status']);
                self::assertSame('continued', $finished['runs'][0]['closed_reason']);
                self::assertSame($finished['runs'][0]['instance_id'], $finished['runs'][1]['instance_id']);
            } else {
                self::assertSame($admitted['run_id'], $finished['run_id']);
                self::assertSame('completed', $finished['runs'][0]['status']);
                self::assertNotSame($finished['runs'][0]['instance_id'], $finished['runs'][1]['instance_id']);
            }
            self::assertSame(count($workers), count(array_unique(array_column($workers, 'pid'))));
            $spans = array_merge(...array_column($workers, 'spans'));
            if ($enabled) {
                $observedRuns = [];
                foreach ($spans as $span) {
                    if ($admissionEnabled) {
                        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $span['trace_id']);
                        self::assertSame('00f067aa0ba902b7', $span['parent_id']);
                    } else {
                        self::assertSame('0000000000000000', $span['parent_id']);
                        self::assertNotSame('11111111111111111111111111111111', $span['trace_id']);
                    }
                    $observedRuns[] = $span['fields']['workflow_run_id'];
                }
                foreach ($finished['runs'] as $run) {
                    self::assertContains($run['id'], $observedRuns);
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
