<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Collection;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\RunActivityView;

final class ActivityAttemptLeaseViewTest extends TestCase
{
    public static function terminalCases(): array
    {
        return [
            'lease retry' => [HistoryEventType::ActivityRetryScheduled, 'lease_expired', 'expired', 'lease_expired'],
            'exhausted lease' => [HistoryEventType::ActivityFailed, 'lease_expired', 'expired', 'lease_expired'],
            'deregistered retry' => [
                HistoryEventType::ActivityRetryScheduled,
                'worker_deregistered',
                'expired',
                'worker_deregistered',
            ],
            'exhausted deregistration' => [
                HistoryEventType::ActivityFailed,
                'worker_deregistered',
                'expired',
                'worker_deregistered',
            ],
            'ordinary retry' => [HistoryEventType::ActivityRetryScheduled, null, 'failed', 'attempt_closed'],
            'ordinary failure' => [HistoryEventType::ActivityFailed, null, 'failed', 'attempt_closed'],
            'unknown reason' => [HistoryEventType::ActivityFailed, 'unknown_failure', 'failed', 'attempt_closed'],
            'authored timeout' => [HistoryEventType::ActivityTimedOut, null, 'expired', 'attempt_expired'],
        ];
    }

    #[DataProvider('terminalCases')]
    public function testColdHistoryKeepsTheOriginalAttemptOutcome(
        HistoryEventType $eventType,
        ?string $reason,
        string $expectedStatus,
        string $expectedStopReason,
    ): void {
        $run = $this->createRun($eventType, $reason);
        $before = $this->attributes($run);
        $rows = RunActivityView::activitiesForRun($run, decodePayloads: false);

        self::assertCount(1, $rows);
        self::assertSame('completed', $rows[0]['status']);
        self::assertSame('typed_history', $rows[0]['history_authority']);
        self::assertCount(2, $rows[0]['attempts']);
        [$original, $replacement] = $rows[0]['attempts'];
        self::assertSame('original-attempt', $original['id']);
        self::assertSame('original-task', $original['task_id']);
        self::assertSame('original-worker', $original['lease_owner']);
        self::assertSame(1, $original['attempt_number']);
        self::assertSame($expectedStatus, $original['status']);
        self::assertSame($expectedStopReason, $original['stop_reason']);
        self::assertSame(
            $expectedStatus === 'expired' && $reason !== null ? $reason : null,
            $original['failure_reason'] ?? null,
        );
        self::assertSame('2026-10-22T06:14:54.510928Z', $original['closed_at']->toJSON());
        self::assertNull($original['lease_expires_at']);
        self::assertFalse($original['can_continue']);
        self::assertFalse($original['cancel_requested']);
        self::assertSame('replacement-attempt', $replacement['id']);
        self::assertSame('completed', $replacement['status']);
        self::assertSame('attempt_closed', $replacement['stop_reason']);
        self::assertNull($replacement['failure_reason'] ?? null);
        self::assertFalse($replacement['can_continue']);
        self::assertSame($before, $this->attributes($run));
    }

    public function testProjectionOnlyReadDoesNotInventALeaseLossReason(): void
    {
        $run = $this->createRun(HistoryEventType::ActivityRetryScheduled, 'lease_expired');
        $run->activityExecutions[0]->attempts[0]->status = ActivityAttemptStatus::Expired;
        $before = $this->attributes($run);
        $attempt = RunActivityView::activitiesForRun($run, false, false)[0]['attempts'][0];

        self::assertSame('expired', $attempt['status']);
        self::assertSame('attempt_expired', $attempt['stop_reason']);
        self::assertNull($attempt['failure_reason'] ?? null);
        self::assertFalse($attempt['can_continue']);
        self::assertSame($before, $this->attributes($run));
    }

    public function testLeaseReasonSurvivesMissingMutableAttemptRows(): void
    {
        $run = $this->createRun(HistoryEventType::ActivityRetryScheduled, 'lease_expired');
        $run->activityExecutions[0]->setRelation('attempts', new Collection());
        $rows = RunActivityView::activitiesForRun($run, decodePayloads: false);

        self::assertCount(2, $rows[0]['attempts']);
        self::assertSame('expired', $rows[0]['attempts'][0]['status']);
        self::assertSame('lease_expired', $rows[0]['attempts'][0]['stop_reason']);
        self::assertSame('lease_expired', $rows[0]['attempts'][0]['failure_reason']);
        self::assertFalse($rows[0]['attempts'][0]['can_continue']);
        self::assertCount(0, $run->activityExecutions[0]->attempts);
    }

    private function createRun(HistoryEventType $eventType, ?string $reason): WorkflowRun
    {
        $original = new ActivityAttempt([
            'id' => 'original-attempt',
            'activity_execution_id' => 'activity',
            'workflow_task_id' => 'original-task',
            'attempt_number' => 1,
            'status' => 'failed',
            'lease_owner' => 'original-worker',
            'started_at' => '2026-10-22 06:14:49.510928',
            'closed_at' => '2026-10-22 06:14:54.510928',
        ]);
        $replacement = new ActivityAttempt([
            'id' => 'replacement-attempt',
            'activity_execution_id' => 'activity',
            'workflow_task_id' => 'replacement-task',
            'attempt_number' => 2,
            'status' => 'completed',
        ]);
        $execution = new ActivityExecution([
            'id' => 'activity',
            'status' => 'completed',
            'current_attempt_id' => 'replacement-attempt',
            'attempt_count' => 2,
        ]);
        $execution->setRelation('attempts', new Collection([$original, $replacement]));
        $event = new WorkflowHistoryEvent([
            'sequence' => 1,
            'event_type' => $eventType,
            'workflow_task_id' => 'original-task',
            'recorded_at' => '2026-10-22 06:14:54.510928',
            'payload' => array_filter([
                'activity_execution_id' => 'activity',
                'activity_attempt_id' => 'original-attempt',
                'attempt_number' => 1,
                'failure_reason' => $reason,
                'activity' => [
                    'id' => 'activity',
                    'status' => 'pending',
                    'attempt_count' => 1,
                ],
                'activity_attempt' => [
                    'id' => 'original-attempt',
                    'lease_owner' => 'original-worker',
                ],
            ], static fn (mixed $value): bool => $value !== null),
        ]);
        $completed = new WorkflowHistoryEvent([
            'sequence' => 2,
            'event_type' => HistoryEventType::ActivityCompleted,
            'workflow_task_id' => 'replacement-task',
            'recorded_at' => '2026-10-22 06:15:00.123456',
            'payload' => [
                'activity_execution_id' => 'activity',
                'activity_attempt_id' => 'replacement-attempt',
                'attempt_number' => 2,
                'activity' => [
                    'id' => 'activity',
                    'status' => 'completed',
                    'attempt_count' => 2,
                ],
            ],
        ]);
        $run = new WorkflowRun([
            'id' => 'run',
            'namespace' => 'lease-observation',
        ]);
        $run->setRelation('activityExecutions', new Collection([$execution]));
        $run->setRelation('historyEvents', new Collection([$event, $completed]));

        return $run;
    }

    private function attributes(WorkflowRun $run): array
    {
        return [
            $run->getAttributes(),
            $run->activityExecutions->map(static fn ($row): array => [
                $row->getAttributes(),
                $row->attempts->map(static fn ($attempt): array => $attempt->getAttributes())
                    ->all(),
            ])->all(),
            $run->historyEvents->map(static fn ($row): array => $row->getAttributes())
                ->all(),
        ];
    }
}
