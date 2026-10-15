<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use OpenTelemetry\API\Trace\SpanKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ScheduleExecutionTracingTest extends TestCase
{
    public static function modes(): array
    {
        return [
            'manual traced' => ['manual', true],
            'manual disabled' => ['manual', false],
            'periodic traced' => ['scheduled', true],
            'periodic disabled' => ['scheduled', false],
        ];
    }

    #[DataProvider('modes')]
    public function testActualScheduleAdmissionAndColdWorkersKeepTheirOriginalCaller(string $kind, bool $enabled): void
    {
        $directory = sys_get_temp_dir() . '/dw-tracing-schedule-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = static function (string $mode) use ($directory, $kind, $enabled): array {
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
                'DW_TEST_TRACING_SCHEDULE' => $kind,
                'DW_TEST_TRACING_ENABLED' => $enabled ? 'true' : 'false',
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
            $workers = [$worker('work'), $worker('work'), $worker('work')];
            $finished = $worker('inspect');
            self::assertSame('completed', $finished['status']);
            self::assertSame([9007199254740993, null, '雪'], $finished['output']);
            self::assertSame(0, $finished['jobs']);
            self::assertSame(0, $finished['failed_jobs']);
            self::assertSame(0, $finished['repairs']);
            foreach (['run_id', 'carrier', 'execution_deadline', 'run_deadline'] as $field) {
                self::assertSame($admitted[$field], $finished[$field]);
            }
            self::assertSame(1, $finished['schedule']['fires_count']);
            self::assertSame($finished['schedule']['instance_id'], $finished['schedule']['latest_instance_id']);
            self::assertSame(1, array_count_values($finished['schedule']['history_types'])['ScheduleTriggered']);
            self::assertSame(1, array_count_values($finished['schedule']['history_types'])['ActivityCompleted']);
            self::assertSame(1, array_count_values($finished['schedule']['history_types'])['WorkflowCompleted']);
            self::assertCount(3, $finished['schedule']['tasks']);
            foreach ($finished['schedule']['tasks'] as $task) {
                self::assertSame('completed', $task['status']);
                self::assertSame(1, $task['attempt_count']);
                self::assertSame(0, $task['repair_count']);
            }
            foreach ([$admitted, ...$workers] as $checkpoint) {
                self::assertSame($checkpoint['schedule']['history_hashes'], array_slice(
                    $finished['schedule']['history_hashes'],
                    0,
                    count($checkpoint['schedule']['history_hashes'])
                ));
                foreach ($checkpoint['schedule']['capture_transaction_levels'] as $level) {
                    self::assertSame(0, $level);
                }
                foreach ($checkpoint['schedule']['boundaries'] as $boundary) {
                    self::assertSame(0, $boundary['transaction_level']);
                }
            }
            $triggers = array_values(array_filter(
                $admitted['spans'],
                static fn (array $span): bool => $span['name'] === 'durable.schedule.trigger'
            ));
            self::assertCount($enabled ? 1 : 0, $triggers);
            if ($enabled) {
                self::assertSame('00f067aa0ba902b7', $triggers[0]['parent_id']);
                self::assertSame($kind, $triggers[0]['fields']['durable.schedule.trigger_kind']);
                self::assertSame('triggered', $triggers[0]['fields']['durable.schedule.outcome']);
                self::assertSame($admitted['run_id'], $triggers[0]['fields']['workflow_run_id']);
            }
            $processing = array_values(array_filter(
                array_merge(...array_column($workers, 'spans')),
                static fn (array $span): bool => $span['kind'] === SpanKind::KIND_CONSUMER
            ));
            self::assertCount($enabled ? 3 : 0, $processing);
            foreach ($processing as $span) {
                self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $span['trace_id']);
                self::assertSame('00f067aa0ba902b7', $span['parent_id']);
                self::assertSame($admitted['run_id'], $span['fields']['workflow_run_id']);
            }
            self::assertCount(3, array_unique(array_column($workers, 'pid')));
        } finally {
            (new Filesystem())->deleteDirectory($directory);
        }
    }
}
