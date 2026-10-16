<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ScheduleExecutionTracingTest extends TestCase
{
    public static function bufferModes(): array
    {
        return [
            'buffered caller' => [true],
            'disabled buffer control' => [false],
        ];
    }

    #[DataProvider('bufferModes')]
    public function testBufferedCallerLinksOnlyItsCommittedColdDrain(bool $enabled): void
    {
        $directory = sys_get_temp_dir() . '/dw-tracing-buffer-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = static fn (string $mode): array => self::bufferWorker($directory, $mode, $enabled);
        try {
            $original = $worker('admit');
            $buffered = $worker('buffer');
            self::assertSame('buffered', $buffered['schedule']['buffer_result']);
            self::assertCount(1, $buffered['schedule']['buffered_actions']);
            $entry = $buffered['schedule']['buffered_actions'][0];
            if ($enabled) {
                self::assertSame(
                    '00-cccccccccccccccccccccccccccccccc-dddddddddddddddd-01',
                    $entry['observability_context']['carrier']['traceparent'] ?? null
                );
                self::assertSame('approved=buffer-only', $entry['observability_context']['carrier']['baggage']);
            } else {
                self::assertArrayNotHasKey('observability_context', $entry);
            }
            unset($entry['observability_context']);
            self::assertSame([$entry], $buffered['schedule']['public_buffered_actions']);

            $full = $worker('buffer-full');
            self::assertSame('buffer_full', $full['schedule']['buffer_result']);
            self::assertSame($buffered['schedule']['buffered_actions'], $full['schedule']['buffered_actions']);
            for ($workerIndex = 0; $workerIndex < 3; ++$workerIndex) {
                $worker('work');
            }
            $completed = $worker('inspect');
            self::assertSame('completed', $completed['status']);
            self::assertSame($original['run_id'], $completed['run_id']);
            self::assertSame($original['execution_deadline'], $completed['execution_deadline']);
            self::assertSame($original['run_deadline'], $completed['run_deadline']);

            $drained = $worker('drain');
            self::assertNotSame($original['run_id'], $drained['run_id']);
            self::assertNull($drained['schedule']['buffered_actions']);
            self::assertArrayNotHasKey('observability_context', $drained['schedule']['drain_results'][0]);
            if ($enabled) {
                $drainedCarrier = json_decode($drained['carrier'], true, flags: JSON_THROW_ON_ERROR);
                self::assertArrayNotHasKey('baggage', $drainedCarrier['carrier']);
            }
            $triggers = array_values(array_filter(
                $drained['spans'],
                static fn (array $span): bool => $span['name'] === 'durable.schedule.trigger'
            ));
            self::assertCount($enabled ? 1 : 0, $triggers);
            if ($enabled) {
                self::assertSame('11111111111111111111111111111111', $triggers[0]['trace_id']);
                self::assertSame('2222222222222222', $triggers[0]['parent_id']);
                self::assertSame([[
                    'trace_id' => 'cccccccccccccccccccccccccccccccc',
                    'span_id' => 'dddddddddddddddd',
                    'fields' => [
                        'durable.link.type' => 'buffered_schedule',
                    ],
                ]], $triggers[0]['links']);
            }
            for ($workerIndex = 0; $workerIndex < 3; ++$workerIndex) {
                $worker('work');
            }
            $finished = $worker('inspect');
            self::assertSame('completed', $finished['status']);
            self::assertSame([9007199254740993, null, '雪'], $finished['output']);
            self::assertSame($drained['carrier'], $finished['carrier']);
            self::assertSame($drained['execution_deadline'], $finished['execution_deadline']);
            self::assertSame($drained['run_deadline'], $finished['run_deadline']);
            self::assertSame(2, $finished['schedule']['fires_count']);
            self::assertCount(6, $finished['schedule']['tasks']);
            foreach ($finished['schedule']['tasks'] as $task) {
                self::assertSame('completed', $task['status']);
                self::assertSame(1, $task['attempt_count']);
                self::assertSame(0, $task['repair_count']);
            }
            foreach ([$original, $buffered, $full, $completed, $drained, $finished] as $checkpoint) {
                foreach ($checkpoint['schedule']['capture_transaction_levels'] as $level) {
                    self::assertSame(0, $level);
                }
                foreach ($checkpoint['schedule']['boundaries'] as $boundary) {
                    self::assertSame(0, $boundary['transaction_level']);
                }
            }
            self::assertSame(0, $finished['jobs']);
            self::assertSame(0, $finished['failed_jobs']);
            self::assertSame(0, $finished['repairs']);
        } finally {
            (new Filesystem())->deleteDirectory($directory);
        }
    }

    public static function missingBufferMetadata(): array
    {
        return [
            'legacy buffer' => ['legacy'],
            'malformed optional carrier' => ['malformed'],
        ];
    }

    #[DataProvider('missingBufferMetadata')]
    public function testLegacyOrMalformedBufferKeepsBusinessStateWithoutInventingACaller(string $metadata): void
    {
        $directory = sys_get_temp_dir() . '/dw-tracing-buffer-metadata-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = static fn (string $mode): array => self::bufferWorker($directory, $mode, true, $metadata);
        try {
            $worker('admit');
            $buffered = $worker('buffer');
            $changed = $worker('buffer-metadata');
            self::assertSame(
                $buffered['schedule']['public_buffered_actions'],
                $changed['schedule']['public_buffered_actions']
            );
            self::assertSame($buffered['schedule']['history_hashes'], $changed['schedule']['history_hashes']);
            for ($workerIndex = 0; $workerIndex < 3; ++$workerIndex) {
                $worker('work');
            }
            $drained = $worker('drain');
            $triggers = array_values(array_filter(
                $drained['spans'],
                static fn (array $span): bool => $span['name'] === 'durable.schedule.trigger'
            ));
            self::assertCount(1, $triggers);
            self::assertSame('2222222222222222', $triggers[0]['parent_id']);
            self::assertSame([], $triggers[0]['links']);
            self::assertNull($drained['schedule']['buffered_actions']);
            for ($workerIndex = 0; $workerIndex < 3; ++$workerIndex) {
                $worker('work');
            }
            $finished = $worker('inspect');
            self::assertSame('completed', $finished['status']);
            self::assertSame([9007199254740993, null, '雪'], $finished['output']);
            self::assertSame($drained['execution_deadline'], $finished['execution_deadline']);
            self::assertSame($drained['run_deadline'], $finished['run_deadline']);
            self::assertSame(0, $finished['repairs']);
            self::assertSame(0, $finished['failed_jobs']);
        } finally {
            (new Filesystem())->deleteDirectory($directory);
        }
    }

    public function testRolledBackDrainRetainsItsOriginalBufferedCallerUntilActualAdmission(): void
    {
        $directory = sys_get_temp_dir() . '/dw-tracing-buffer-rollback-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = static fn (string $mode): array => self::bufferWorker($directory, $mode, true);
        try {
            $worker('admit');
            $buffered = $worker('buffer');
            for ($workerIndex = 0; $workerIndex < 3; ++$workerIndex) {
                $worker('work');
            }
            $before = $worker('inspect');
            $failed = $worker('drain-fail');
            self::assertSame([[
                'schedule_id' => 'cold-schedule',
                'instance_id' => null,
                'error' => 'deliberate buffered admission rollback',
            ]], $failed['schedule']['drain_results']);
            self::assertSame(1, $failed['schedule']['failures_count']);
            self::assertSame(1, $failed['schedule']['fires_count']);
            self::assertSame(1, $failed['schedule']['run_count']);
            self::assertSame($buffered['schedule']['buffered_actions'], $failed['schedule']['buffered_actions']);
            foreach (['run_id', 'execution_deadline', 'run_deadline', 'carrier'] as $field) {
                self::assertSame($before[$field], $failed[$field]);
            }
            self::assertSame($before['schedule']['history_hashes'], $failed['schedule']['history_hashes']);
            self::assertSame($before['schedule']['tasks'], $failed['schedule']['tasks']);
            $triggers = array_values(array_filter(
                $failed['spans'],
                static fn (array $span): bool => $span['name'] === 'durable.schedule.trigger'
            ));
            self::assertCount(1, $triggers);
            self::assertSame([], $triggers[0]['links']);
            self::assertSame(StatusCode::STATUS_ERROR, $triggers[0]['status_code']);

            $drained = $worker('drain');
            $triggers = array_values(array_filter(
                $drained['spans'],
                static fn (array $span): bool => $span['name'] === 'durable.schedule.trigger'
            ));
            self::assertSame('cccccccccccccccccccccccccccccccc', $triggers[0]['links'][0]['trace_id']);
            self::assertNull($drained['schedule']['buffered_actions']);
            self::assertSame(2, $drained['schedule']['run_count']);
            for ($workerIndex = 0; $workerIndex < 3; ++$workerIndex) {
                $worker('work');
            }
            $finished = $worker('inspect');
            self::assertSame('completed', $finished['status']);
            self::assertSame([9007199254740993, null, '雪'], $finished['output']);
            self::assertSame(0, $finished['repairs']);
            self::assertSame(0, $finished['failed_jobs']);
        } finally {
            (new Filesystem())->deleteDirectory($directory);
        }
    }

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

    public function testAllBufferedCallersRemainPairedWithTheirOwnColdDrains(): void
    {
        $directory = sys_get_temp_dir() . '/dw-tracing-buffer-all-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = static fn (string $mode): array => self::bufferWorker($directory, $mode, true, 'valid', 'buffer_all');
        try {
            $original = $worker('admit');
            $first = $worker('buffer');
            $second = $worker('buffer-next');
            self::assertSame('buffered', $first['schedule']['buffer_result']);
            self::assertSame('buffered', $second['schedule']['buffer_result']);
            self::assertCount(2, $second['schedule']['buffered_actions']);
            self::assertSame($first['schedule']['buffered_actions'][0], $second['schedule']['buffered_actions'][0]);
            $runIds = [$original['run_id']];
            foreach (['cccccccccccccccccccccccccccccccc', 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'] as $caller) {
                for ($workerIndex = 0; $workerIndex < 3; ++$workerIndex) {
                    $worker('work');
                }
                $before = $worker('inspect');
                self::assertSame('completed', $before['status']);
                $drained = $worker('drain');
                $runIds[] = $drained['run_id'];
                $triggers = array_values(array_filter(
                    $drained['spans'],
                    static fn (array $span): bool => $span['name'] === 'durable.schedule.trigger'
                ));
                self::assertCount(1, $triggers);
                self::assertCount(1, $triggers[0]['links']);
                self::assertSame($caller, $triggers[0]['links'][0]['trace_id']);
                self::assertSame('2222222222222222', $triggers[0]['parent_id']);
                if ($caller === 'cccccccccccccccccccccccccccccccc') {
                    self::assertSame([$second['schedule']['buffered_actions'][1]],
                        $drained['schedule']['buffered_actions']
                    );
                } else {
                    self::assertNull($drained['schedule']['buffered_actions']);
                }
                foreach ($drained['schedule']['boundaries'] as $boundary) {
                    self::assertSame(0, $boundary['transaction_level']);
                }
            }
            for ($workerIndex = 0; $workerIndex < 3; ++$workerIndex) {
                $worker('work');
            }
            $finished = $worker('inspect');
            self::assertSame('completed', $finished['status']);
            self::assertSame([9007199254740993, null, '雪'], $finished['output']);
            self::assertSame($drained['execution_deadline'], $finished['execution_deadline']);
            self::assertSame($drained['run_deadline'], $finished['run_deadline']);
            self::assertSame(3, count(array_unique($runIds)));
            self::assertSame(3, $finished['schedule']['fires_count']);
            self::assertSame(3, $finished['schedule']['run_count']);
            self::assertCount(9, $finished['schedule']['tasks']);
            foreach ($finished['schedule']['tasks'] as $task) {
                self::assertSame('completed', $task['status']);
                self::assertSame(1, $task['attempt_count']);
                self::assertSame(0, $task['repair_count']);
            }
            self::assertSame(0, $finished['failed_jobs']);
            self::assertSame(0, $finished['repairs']);
        } finally {
            (new Filesystem())->deleteDirectory($directory);
        }
    }

    private static function bufferWorker(
        string $directory,
        string $mode,
        bool $enabled,
        string $metadata = 'valid',
        string $policy = 'buffer_one'
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
            'DW_TEST_TRACING_SCHEDULE' => 'buffered',
            'DW_TEST_TRACING_ENABLED' => $enabled ? 'true' : 'false',
            'DW_TEST_TRACING_BUFFER_METADATA' => $metadata,
            'DW_TEST_TRACING_BUFFER_POLICY' => $policy,
        ]);
        $process->setTimeout(15);
        $process->mustRun();
        self::assertSame('', $process->getErrorOutput());
        self::assertJson($process->getOutput(), substr($process->getOutput(), -1500));
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], $result['outside_context']);
        self::assertTrue($result['scope_restored']);

        return $result;
    }
}
