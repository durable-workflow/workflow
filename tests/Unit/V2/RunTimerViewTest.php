<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Collection;
use Orchestra\Testbench\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\RunTimerView;

final class RunTimerViewTest extends TestCase
{
    public function testTypedHistoryWinsOverAConflictingTerminalTimerRow(): void
    {
        $run = $this->runWithTimers(
            [
                $this->event(HistoryEventType::TimerScheduled, 1, [
                    'timer_id' => 'timer-1',
                    'sequence' => '7',
                    'delay_seconds' => '30',
                    'fire_at' => '2026-01-01T00:00:30Z',
                ]),
                $this->event(HistoryEventType::TimerFired, 2, [
                    'timer_id' => 'timer-1',
                    'fired_at' => '2026-01-01T00:00:31Z',
                ]),
            ],
            [$this->timer('timer-1', TimerStatus::Cancelled, 7)],
        );

        $timer = RunTimerView::timerById($run, 'timer-1');

        $this->assertNotNull($timer);
        $this->assertSame('fired', $timer['status']);
        $this->assertSame('fired', $timer['source_status']);
        $this->assertSame('cancelled', $timer['row_status']);
        $this->assertSame(7, $timer['sequence']);
        $this->assertSame(30, $timer['delay_seconds']);
        $this->assertSame(RunTimerView::HISTORY_AUTHORITY_TYPED, $timer['history_authority']);
        $this->assertFalse($timer['diagnostic_only']);
        $this->assertSame(['TimerScheduled', 'TimerFired'], $timer['history_event_types']);
        $this->assertSame('2026-01-01T00:00:31+00:00', $timer['fired_at']?->toIso8601String());
    }

    public function testRowOnlyTerminalTimerCannotClaimHistoryAuthority(): void
    {
        $run = $this->runWithTimers([], [
            $this->timer('pending', TimerStatus::Pending, 2),
            $this->timer('cancelled', TimerStatus::Cancelled, 1),
        ]);

        $timers = RunTimerView::timersForRun($run);

        $this->assertSame(['cancelled', 'pending'], array_column($timers, 'id'));
        $this->assertSame('unsupported', $timers[0]['status']);
        $this->assertSame('cancelled', $timers[0]['source_status']);
        $this->assertSame(RunTimerView::HISTORY_AUTHORITY_UNSUPPORTED_TERMINAL, $timers[0]['history_authority']);
        $this->assertSame(RunTimerView::UNSUPPORTED_TERMINAL_REASON, $timers[0]['history_unsupported_reason']);
        $this->assertTrue($timers[0]['diagnostic_only']);
        $this->assertNull($timers[0]['fired_at']);
        $this->assertSame('pending', $timers[1]['status']);
        $this->assertSame(RunTimerView::HISTORY_AUTHORITY_MUTABLE_OPEN_FALLBACK, $timers[1]['history_authority']);
        $this->assertTrue($timers[1]['diagnostic_only']);
    }

    public function testConditionTimeoutIsExcludedOnlyWhenRequested(): void
    {
        $run = $this->runWithTimers([
            $this->event(HistoryEventType::TimerScheduled, 1, []),
            $this->event(HistoryEventType::TimerScheduled, 2, [
                'timer_id' => 'condition-timeout',
                'sequence' => 4,
                'timer_kind' => 'condition_timeout',
                'condition_wait_id' => 'wait-1',
            ]),
            $this->event(HistoryEventType::TimerCancelled, 3, [
                'timer_id' => 'condition-timeout',
                'cancelled_at' => '2026-01-01T00:00:04Z',
            ]),
        ]);

        $this->assertCount(1, RunTimerView::timersForRun($run));
        $this->assertSame('cancelled', RunTimerView::timerForSequence($run, 4)['status']);
        $this->assertNull(RunTimerView::timerForSequence($run, 4, includeConditionTimeout: false));
        $this->assertNull(RunTimerView::timerForSequence($run, 5));
        $this->assertNull(RunTimerView::timerById($run, 'missing'));
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     * @param list<WorkflowTimer> $timers
     */
    private function runWithTimers(array $events, array $timers = []): WorkflowRun
    {
        $run = new WorkflowRun();
        $run->setRelation('historyEvents', new Collection($events));
        $run->setRelation('timers', new Collection($timers));

        return $run;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(HistoryEventType $type, int $sequence, array $payload): WorkflowHistoryEvent
    {
        $event = new WorkflowHistoryEvent();
        $event->forceFill([
            'sequence' => $sequence,
            'event_type' => $type->value,
            'payload' => $payload,
            'recorded_at' => '2026-01-01T00:00:00Z',
        ]);

        return $event;
    }

    private function timer(string $id, TimerStatus $status, int $sequence): WorkflowTimer
    {
        $timer = new WorkflowTimer();
        $timer->forceFill([
            'id' => $id,
            'sequence' => $sequence,
            'status' => $status->value,
            'delay_seconds' => 5,
            'fire_at' => '2026-01-01T00:00:05Z',
            'fired_at' => '2026-01-01T00:00:06Z',
            'created_at' => '2026-01-01T00:00:00Z',
        ]);

        return $timer;
    }
}
