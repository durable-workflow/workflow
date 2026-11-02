<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\TaskTraceTimings;

final class TaskTraceTimingsTest extends TestCase
{
    public function testOriginalMicrosecondObservationsRemainSeparateFromRequestedBackoffAndAuthority(): void
    {
        $task = self::task([
            'available_at' => '2026-10-15 10:00:10.123450',
            'leased_at' => '2026-10-15 10:00:10.373451',
            'payload' => json_encode([
                'retry_after_attempt_id' => 'attempt-1',
                'retry_after_attempt' => 1,
                'retry_backoff_seconds' => 7,
            ], JSON_THROW_ON_ERROR),
        ]);
        $before = $task->getRawOriginal();
        self::assertSame([
            'durable.task.queue_wait_seconds' => 0.250001,
            'durable.task.retry_backoff_seconds' => 7,
        ], TaskTraceTimings::forTask($task));
        self::assertSame($before, $task->getRawOriginal());
        self::assertSame([], $task->getRelations());

        $task->task_type = 'timer';
        $task->payload = [
            'timer_id' => 'timer-1',
        ];
        $timer = new WorkflowTimer();
        $timer->setRawAttributes([
            'id' => 'timer-1',
            'workflow_run_id' => 'run-1',
            'delay_seconds' => 7,
            'fire_at' => '2026-10-15 10:00:09.373450',
        ], true);
        self::assertSame([
            'durable.task.queue_wait_seconds' => 0.250001,
            'durable.timer.requested_wait_seconds' => 7,
            'durable.timer.lateness_seconds' => 1.000001,
        ], TaskTraceTimings::forTask($task, $timer));
        $timer->workflow_run_id = 'foreign';
        self::assertSame([
            'durable.task.queue_wait_seconds' => 0.250001,
        ], TaskTraceTimings::forTask($task, $timer));
    }

    public static function invalidDates(): array
    {
        return [
            'missing eligibility' => [null, '2026-10-15 10:00:10'],
            'missing claim' => ['2026-10-15 10:00:10', null],
            'invalid' => ['not-a-date', '2026-10-15 10:00:10'],
            'calendar normalization' => ['2026-02-30 10:00:10', '2026-03-03 10:00:10'],
            'time normalization' => ['2026-10-15 25:00:00', '2026-10-16 02:00:00'],
            'negative clock observation' => ['2026-10-15 10:00:11', '2026-10-15 10:00:10'],
        ];
    }

    #[DataProvider('invalidDates')]
    public function testMissingInvalidAndNegativeTimesProduceNoFabricatedQueueWait(
        ?string $available,
        ?string $leased
    ): void {
        self::assertSame([], TaskTraceTimings::forTask(self::task([
            'available_at' => $available,
            'leased_at' => $leased,
        ])));
    }

    public function testStoredSecondAndFractionalPrecisionAndZeroBackoffRemainValid(): void
    {
        foreach (['2026-10-15 10:00:10', '2026-10-15 10:00:10.1', '2026-10-15 10:00:10.12345'] as $time) {
            $task = self::task([
                'available_at' => $time,
                'leased_at' => $time,
            ]);
            self::assertSame([
                'durable.task.queue_wait_seconds' => 0.0,
            ], TaskTraceTimings::forTask($task));
        }
        $task->payload = [
            'retry_after_attempt_id' => 'attempt-1',
            'retry_after_attempt' => 1,
            'retry_backoff_seconds' => 0,
        ];
        self::assertSame(0, TaskTraceTimings::forTask($task)['durable.task.retry_backoff_seconds']);
        foreach ([-1, '7', false, null, 0.5] as $backoff) {
            $task->payload = [
                'retry_after_attempt_id' => 'attempt-1',
                'retry_after_attempt' => 1,
                'retry_backoff_seconds' => $backoff,
            ];
            self::assertArrayNotHasKey('durable.task.retry_backoff_seconds', TaskTraceTimings::forTask($task));
        }
    }

    public function testThrowingOptionalDateAndPayloadCastsCannotInterruptBusinessMetadata(): void
    {
        $task = new class() extends WorkflowTask {
            public function getAttribute($key): mixed
            {
                if (in_array($key, ['available_at', 'leased_at', 'payload'], true)) {
                    throw new RuntimeException('private-optional-cast');
                }

                return parent::getAttribute($key);
            }
        };
        $task->setRawAttributes([
            'id' => 'task-1',
            'task_type' => 'activity',
            'workflow_run_id' => 'run-1',
        ], true);
        $before = $task->getRawOriginal();
        self::assertSame([], TaskTraceTimings::forTask($task));
        self::assertSame($before, $task->getRawOriginal());
    }

    private static function task(array $attributes): WorkflowTask
    {
        $task = new WorkflowTask();
        $task->setRawAttributes(array_replace([
            'id' => 'task-1',
            'workflow_run_id' => 'run-1',
            'task_type' => 'activity',
            'available_at' => null,
            'leased_at' => null,
            'payload' => '{}',
        ], $attributes), true);

        return $task;
    }
}
