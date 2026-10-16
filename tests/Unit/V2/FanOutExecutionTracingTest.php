<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class FanOutExecutionTracingTest extends TestCase
{
    public static function modes(): array
    {
        return [
            'traced' => [true, true],
            'disabled' => [false, false],
            'legacy admission' => [false, true],
            'disabled workers' => [true, false],
        ];
    }

    #[DataProvider('modes')]
    public function testColdParallelMembersPreserveTheirOwnAttemptScopesAndRecordedResults(
        bool $admissionEnabled,
        bool $workerEnabled,
    ): void {
        $directory = sys_get_temp_dir() . '/dw-tracing-fan-out-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = static function (string $mode) use ($directory, $admissionEnabled, $workerEnabled): array {
            $autoloads = array_map(
                static fn (string $vendor): string => $vendor . '/autoload.php',
                array_keys(ClassLoader::getRegisteredLoaders()),
            );
            $process = new Process([
                PHP_BINARY, __DIR__ . '/../../Fixtures/V2/tracing-process.php', $directory, $mode, 'once',
                ...$autoloads,
            ], env: [
                'DW_TEST_TRACING_FAN_OUT' => 'true',
                'DW_TEST_TRACING_ENABLED' => ($mode === 'admit' ? $admissionEnabled : $workerEnabled) ? 'true' : 'false',
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
            $states = [$admitted];
            $workers = [];
            $deadline = microtime(true) + 30;
            do {
                $state = $worker('inspect');
                if (in_array($state['status'], ['completed', 'failed', 'cancelled', 'terminated'], true)) {
                    break;
                }
                if ($state['fan_out']['ready_jobs'] === 0) {
                    usleep(100_000);
                    continue;
                }
                $states[] = $workers[] = $worker('work');
            } while (microtime(true) < $deadline);
            $finished = $worker('inspect');
            self::assertDurableResult($admitted, $states, $workers, $finished);
            self::assertAttemptScopes(
                $admitted,
                $workers,
                $finished['fan_out']['attempts'],
                $admissionEnabled,
                $workerEnabled
            );
        } finally {
            (new Filesystem())->deleteDirectory($directory);
        }
    }

    private static function assertDurableResult(array $admitted, array $states, array $workers, array $finished): void
    {
        self::assertSame('completed', $finished['status'], json_encode([
            'run_id' => $finished['run_id'],
            'jobs' => $finished['jobs'],
            'history_types' => $finished['fan_out']['history_types'],
            'last_event' => end($finished['fan_out']['history_payloads']),
        ], JSON_THROW_ON_ERROR));
        self::assertSame(array_fill_keys(['ordinary', 'retry', 'local'], [9007199254740993, null, '雪']) + [
            'timer' => true,
        ], $finished['output']);
        self::assertSame(0, $finished['jobs']);
        self::assertSame(0, $finished['failed_jobs']);
        self::assertSame(0, $finished['repairs']);
        $fanOut = $finished['fan_out'];
        self::assertCount(3, $fanOut['activities']);
        self::assertCount(4, $fanOut['attempts']);
        $attemptCounts = array_column($fanOut['activities'], 'attempt_count');
        sort($attemptCounts);
        self::assertSame([1, 1, 2], $attemptCounts);
        self::assertSame(3, count(array_filter(
            $fanOut['history_types'],
            static fn (string $type): bool => $type === 'ActivityCompleted',
        )));
        self::assertSame(1, count(array_filter(
            $fanOut['history_types'],
            static fn (string $type): bool => $type === 'TimerFired',
        )));
        self::assertSame(1, count(array_filter(
            $fanOut['history_types'],
            static fn (string $type): bool => $type === 'WorkflowCompleted',
        )));
        $activitiesById = array_column($fanOut['activities'], null, 'id');
        foreach ($states as $state) {
            foreach (['run_id', 'carrier', 'execution_deadline', 'run_deadline'] as $field) {
                self::assertSame($admitted[$field], $state[$field]);
                self::assertSame($admitted[$field], $finished[$field]);
            }
            self::assertSame($state['fan_out']['history_hashes'], array_slice(
                $fanOut['history_hashes'],
                0,
                count($state['fan_out']['history_hashes']),
            ));
            foreach ($state['fan_out']['activities'] as $activity) {
                self::assertArrayHasKey($activity['id'], $activitiesById);
                self::assertSame($activity['retry_policy'], $activitiesById[$activity['id']]['retry_policy']);
            }
        }
        $retryActivity = array_values(array_filter(
            $fanOut['activities'],
            static fn (array $activity): bool => $activity['attempt_count'] === 2,
        ))[0];
        $retryAttempts = array_values(array_filter(
            $fanOut['attempts'],
            static fn (array $attempt): bool => $attempt['activity_execution_id'] === $retryActivity['id'],
        ));
        self::assertSame([1, 2], array_column($retryAttempts, 'attempt_number'));
        $tasksById = array_column($fanOut['tasks'], null, 'id');
        foreach ($retryAttempts as $attempt) {
            self::assertArrayHasKey($attempt['workflow_task_id'], $tasksById);
        }
        foreach ($fanOut['tasks'] as $task) {
            self::assertGreaterThanOrEqual(1, $task['attempt_count']);
            self::assertSame(0, $task['repair_count']);
        }
        self::assertSame(count($workers), count(array_unique(array_column($workers, 'pid'))));
        self::assertGreaterThan(2, count($workers));
        self::assertStringNotContainsString(
            'observability_context',
            json_encode($fanOut['history_payloads'], JSON_THROW_ON_ERROR),
        );
    }

    private static function assertAttemptScopes(
        array $admitted,
        array $workers,
        array $recordedAttempts,
        bool $admissionEnabled,
        bool $workerEnabled,
    ): void {
        $spans = array_merge(...array_column($workers, 'spans'));
        if (! $workerEnabled) {
            self::assertSame([], $spans);

            return;
        }
        $attempts = array_values(array_filter($spans, static fn (array $span): bool => in_array(
            $span['name'],
            ['durable.activity.attempt', 'durable.local_activity.attempt'],
            true,
        )));
        self::assertCount(4, $attempts);
        self::assertCount(1, array_filter(
            $attempts,
            static fn (array $span): bool => $span['status_code'] === StatusCode::STATUS_ERROR,
        ));
        self::assertCount(4, array_unique(array_column($attempts, 'span_id')));
        self::assertCount(4, array_unique(array_map(
            static fn (array $span): string => $span['fields']['activity_attempt_id'],
            $attempts,
        )));
        $durableAttempts = array_column($recordedAttempts, null, 'id');
        foreach ($workers as $state) {
            $workflowSpans = array_values(array_filter(
                $state['spans'],
                static fn (array $span): bool => $span['name'] === 'durable.workflow.task',
            ));
            $stateAttempts = array_filter($state['spans'], static fn (array $span): bool => in_array(
                $span['name'],
                ['durable.activity.attempt', 'durable.local_activity.attempt'],
                true,
            ));
            foreach ($stateAttempts as $span) {
                $fields = $span['fields'];
                self::assertSame($admitted['run_id'], $fields['workflow_run_id']);
                self::assertSame('cold-tracing', $fields['workflow_instance_id']);
                self::assertSame('0', $fields['namespace']);
                $recorded = $durableAttempts[$fields['activity_attempt_id']];
                self::assertSame($recorded['activity_execution_id'], $fields['activity_id']);
                self::assertSame($recorded['attempt_number'], $fields['activity_attempt_number']);
                if ($span['name'] === 'durable.local_activity.attempt') {
                    self::assertCount(1, $workflowSpans);
                    self::assertSame($workflowSpans[0]['span_id'], $span['parent_id']);
                    self::assertSame($workflowSpans[0]['fields']['task_id'], $fields['task_id']);
                } elseif ($admissionEnabled) {
                    self::assertSame($recorded['workflow_task_id'], $fields['task_id']);
                    self::assertSame('00f067aa0ba902b7', $span['parent_id']);
                } else {
                    self::assertSame($recorded['workflow_task_id'], $fields['task_id']);
                    self::assertSame('0000000000000000', $span['parent_id']);
                }
                if ($admissionEnabled) {
                    self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $span['trace_id']);
                } else {
                    self::assertNotSame('11111111111111111111111111111111', $span['trace_id']);
                }
            }
        }
    }
}
