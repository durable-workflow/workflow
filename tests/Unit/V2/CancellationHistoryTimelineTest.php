<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Collection;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\HistoryTimeline;

final class CancellationHistoryTimelineTest extends TestCase
{
    #[DataProvider('historyEventTypes')]
    public function testEverySupportedHistoryEventCanBeInspectedWithSparseMetadata(HistoryEventType $type): void
    {
        $entry = $this->entry($type, []);

        $this->assertSame($type->value, $entry['type']);
        $this->assertNotSame('', $entry['summary']);
    }

    /**
     * @return iterable<string, array{HistoryEventType}>
     */
    public static function historyEventTypes(): iterable
    {
        foreach (HistoryEventType::cases() as $type) {
            yield $type->value => [$type];
        }
    }

    #[DataProvider('receiptTiming')]
    public function testStopReceiptExplainsTheOriginalAttemptAndBudgetWithoutGrantingAuthority(bool $late): void
    {
        $payload = [
            'sequence' => 4,
            'activity_execution_id' => 'activity-1',
            'activity_attempt_id' => 'original-attempt',
            'cancellation_history_event_id' => 'cancel-event',
            'request_id' => 'child-request',
            'root_request_id' => 'root-request',
            'cleanup_deadline_at' => '2026-10-02T00:00:30.000000Z',
            'callback_state' => 'stopped',
            'evidence_source' => 'activity_worker',
            'acknowledged_at' => $late ? '2026-10-02T00:00:31.000000Z' : '2026-10-02T00:00:10.000000Z',
            'received_after_deadline' => $late,
        ];
        $entry = $this->entry(HistoryEventType::ActivityCancellationAcknowledged, $payload);

        $this->assertSame('activity', $entry['kind']);
        $this->assertSame('activity_execution', $entry['source_kind']);
        $this->assertSame('activity-1', $entry['source_id']);
        $this->assertSame('original-attempt', $entry['activity']['attempt_id']);
        $this->assertSame('cancelled', $entry['activity_status']);
        $this->assertSame('activity', $entry['task']['type']);
        $this->assertSame('cancelled', $entry['task']['status']);
        $this->assertSame(array_diff_key($payload, [
            'sequence' => true,
            'activity_execution_id' => true,
        ]), $entry['cancellation_acknowledgement']);
        $this->assertSame(
            'Worker reported stopped callback for activity' . ($late ? ' after the cleanup deadline' : '') . '.',
            $entry['summary'],
        );
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function receiptTiming(): iterable
    {
        yield 'before original deadline' => [false];
        yield 'after original deadline' => [true];
    }

    public function testLocalStopReceiptDoesNotDescribeItsWorkflowClaimAsACancelledActivityTask(): void
    {
        $entry = $this->entry(HistoryEventType::ActivityCancellationAcknowledged, [
            'activity_execution_id' => 'local-activity-1',
            'activity_attempt_id' => 'original-local-attempt',
            'local_activity' => true,
            'execution_mode' => 'local',
            'evidence_source' => 'workflow_worker',
            'task' => [
                'id' => 'task-1',
                'type' => 'workflow',
                'status' => 'leased',
                'attempt_count' => 1,
            ],
        ]);

        $this->assertSame('cancelled', $entry['activity_status']);
        $this->assertSame('workflow', $entry['task']['type']);
        $this->assertSame('leased', $entry['task']['status']);
        $this->assertSame(1, $entry['task']['attempt_count']);
    }

    #[DataProvider('localActivityEvents')]
    public function testLocalActivityHistoryCannotInventAClosedOrdinaryActivityTask(HistoryEventType $type): void
    {
        $entry = $this->entry($type, [
            'activity_execution_id' => 'local-activity-1',
            'execution_mode' => 'local',
            'task' => [
                'id' => 'task-1',
                'type' => 'workflow',
                'status' => 'leased',
                'attempt_count' => 1,
            ],
        ]);

        $this->assertSame('workflow', $entry['task']['type']);
        $this->assertSame('leased', $entry['task']['status']);
    }

    /**
     * @return iterable<string, array{HistoryEventType}>
     */
    public static function localActivityEvents(): iterable
    {
        foreach ([HistoryEventType::ActivityScheduled, HistoryEventType::ActivityStarted,
            HistoryEventType::ActivityHeartbeatRecorded, HistoryEventType::ActivityRetryScheduled,
            HistoryEventType::ActivityCompleted, HistoryEventType::ActivityFailed,
            HistoryEventType::ActivityCancelled, HistoryEventType::ActivityTimedOut,
            HistoryEventType::ActivityCancellationAcknowledged] as $type) {
            yield $type->value => [$type];
        }
    }

    #[DataProvider('localActivityEvents')]
    public function testARepairedWorkflowClaimCannotChangeTheLocalActivityAttemptCount(HistoryEventType $type): void
    {
        $expected = $type === HistoryEventType::ActivityScheduled ? 0 : 2;
        $entry = $this->entry($type, [
            'activity_execution_id' => 'local-activity-1',
            'execution_mode' => 'local',
            'activity' => [
                'id' => 'local-activity-1',
                'attempt_count' => $expected,
            ],
            'task' => [
                'id' => 'task-1',
                'type' => 'workflow',
                'status' => 'leased',
                'attempt_count' => 5,
            ],
        ]);

        $this->assertSame($expected, $entry['activity']['attempt_count']);
        $this->assertSame(5, $entry['task']['attempt_count']);
    }

    public function testSparseLocalHistoryUsesTheActivityAttemptNumberInsteadOfTheWorkflowClaimCount(): void
    {
        $entry = $this->entry(HistoryEventType::ActivityStarted, [
            'activity_execution_id' => 'local-activity-1',
            'local_activity' => true,
            'attempt_number' => 3,
            'task' => [
                'id' => 'task-1',
                'type' => 'workflow',
                'status' => 'leased',
                'attempt_count' => 7,
            ],
        ]);

        $this->assertSame(3, $entry['activity']['attempt_count']);
        $this->assertSame(7, $entry['task']['attempt_count']);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function entry(HistoryEventType $type, array $payload): array
    {
        $run = new WorkflowRun();
        foreach (['commands', 'tasks', 'activityExecutions', 'timers', 'failures'] as $relation) {
            $run->setRelation($relation, new Collection());
        }
        $event = new WorkflowHistoryEvent();
        $event->forceFill([
            'id' => 'event-1',
            'sequence' => 1,
            'workflow_task_id' => 'task-1',
            'event_type' => $type,
            'payload' => $payload,
        ]);
        $run->setRelation('historyEvents', new Collection([$event]));

        return HistoryTimeline::fromHistory($run)[0];
    }
}
