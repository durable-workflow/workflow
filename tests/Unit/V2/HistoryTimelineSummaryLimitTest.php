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

final class HistoryTimelineSummaryLimitTest extends TestCase
{
    public function testRejectedOutcomeSummaryIsBoundedWithoutChangingDiagnosticEvidence(): void
    {
        $payload = [
            'activity_execution_id' => 'original-activity',
            'activity_attempt_id' => 'original-attempt',
            'attempt_number' => 1,
            'outcome' => 'completed',
            'reason' => str_repeat('界🦀', 10_000),
        ];
        $event = $this->event(HistoryEventType::ActivityOutcomeRejected, $payload);
        $entry = HistoryTimeline::fromHistory($this->timelineRun($event))[0];

        $this->assertLessThanOrEqual(65_535, strlen($entry['summary']));
        $this->assertTrue(mb_check_encoding($entry['summary'], 'UTF-8'));
        $this->assertStringContainsString('[truncated]', $entry['summary']);
        $this->assertSame('original-activity', $entry['activity_execution_id']);
        $this->assertSame('original-attempt', $entry['activity']['attempt_id']);
        $this->assertSame(1, $entry['activity']['attempt_count']);
        $this->assertSame($payload, $event->payload);
        $this->assertSame($entry, HistoryTimeline::fromActivityOutcomeRejection($event));
    }

    public function testMalformedRejectedOutcomeFieldsDoNotBreakTimelineInspection(): void
    {
        $payload = [
            'activity_execution_id' => [],
            'activity_attempt_id' => [],
            'attempt_number' => [],
            'outcome' => [],
            'reason' => [],
        ];
        $event = $this->event(HistoryEventType::ActivityOutcomeRejected, $payload);
        $entry = HistoryTimeline::fromHistory($this->timelineRun($event))[0];

        $this->assertSame('Rejected unknown outcome from activity attempt unknown: unknown.', $entry['summary']);
        $this->assertNull($entry['source_id']);
        $this->assertNull($entry['activity_execution_id']);
        $this->assertNull($entry['activity']['id']);
        $this->assertNull($entry['activity']['attempt_id']);
        $this->assertNull($entry['activity']['attempt_count']);
        $this->assertSame($payload, $event->payload);
    }

    #[DataProvider('failureEvents')]
    public function testLargeSummariesFitStorageWithoutChangingFailureEvidence(
        HistoryEventType $type,
        bool $lostAuthority,
    ): void {
        $message = str_repeat('界🦀', 10_000);
        $payload = [
            'message' => $message,
            'exception_type' => 'OriginalFailureType',
            'non_retryable' => true,
            'error' => $message,
            'local_recovery' => [
                'callback_stop_state' => $lostAuthority ? 'unknown' : 'stopped',
            ],
        ];
        $event = $this->event($type, $payload);
        $entry = HistoryTimeline::fromHistory($this->timelineRun($event))[0];

        $this->assertLessThanOrEqual(65_535, strlen($entry['summary']));
        $this->assertTrue(mb_check_encoding($entry['summary'], 'UTF-8'));
        $this->assertStringContainsString('[truncated]', $entry['summary']);
        $this->assertSame($message, $entry['failure']['message']);
        $this->assertSame('OriginalFailureType', $entry['failure']['exception_type']);
        $this->assertTrue($entry['failure']['non_retryable']);
        $this->assertSame($payload, $event->payload);

        if ($lostAuthority) {
            $this->assertStringEndsWith(
                'The previous attempt lost authority. Callback stop is unknown.',
                $entry['summary'],
            );
        }
    }

    /**
     * @return iterable<string, array{HistoryEventType, bool}>
     */
    public static function failureEvents(): iterable
    {
        foreach ([
            HistoryEventType::ActivityFailed,
            HistoryEventType::ActivityTimedOut,
            HistoryEventType::ChildRunFailed,
            HistoryEventType::UpdateCompleted,
            HistoryEventType::WorkflowFailed,
            HistoryEventType::WorkflowTimedOut,
            HistoryEventType::FailureHandled,
            HistoryEventType::ParentClosePolicyFailed,
        ] as $type) {
            yield $type->value => [$type, false];
            yield $type->value . ' with recovery suffix' => [$type, true];
        }
    }

    #[DataProvider('boundaryMessages')]
    public function testOnlySummariesOverTheByteBoundaryChange(string $message): void
    {
        $entry = HistoryTimeline::fromHistory($this->timelineRun($this->event(HistoryEventType::WorkflowFailed, [
            'message' => $message,
        ])))[0];
        $originalSummary = 'Workflow failed: ' . $message . '.';

        if (strlen($originalSummary) <= 65_535) {
            $this->assertSame($originalSummary, $entry['summary']);
        } else {
            $this->assertLessThanOrEqual(65_535, strlen($entry['summary']));
            $this->assertStringContainsString('[truncated]', $entry['summary']);
        }

        $this->assertSame($message, $entry['failure']['message']);
        $this->assertTrue(mb_check_encoding($entry['summary'], 'UTF-8'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function boundaryMessages(): iterable
    {
        yield 'short' => ['ordinary failure'];
        yield 'one byte below' => [str_repeat('a', 65_516)];
        yield 'exactly full' => [str_repeat('a', 65_517)];
        yield 'one byte over' => [str_repeat('a', 65_518)];
        yield 'three byte boundary' => [str_repeat('界', 21_845)];
        yield 'four byte boundary' => [str_repeat('🦀', 16_384)];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(HistoryEventType $type, array $payload): WorkflowHistoryEvent
    {
        return (new WorkflowHistoryEvent())->forceFill([
            'id' => 'summary-event',
            'sequence' => 1,
            'event_type' => $type,
            'payload' => $payload,
        ]);
    }

    private function timelineRun(WorkflowHistoryEvent $event): WorkflowRun
    {
        $run = new WorkflowRun();

        foreach (['commands', 'tasks', 'activityExecutions', 'timers', 'failures'] as $relation) {
            $run->setRelation($relation, new Collection());
        }

        return $run->setRelation('historyEvents', new Collection([$event]));
    }
}
