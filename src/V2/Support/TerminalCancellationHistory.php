<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

/**
 * History lookups shared by one terminal command while it holds the run lock.
 * The instance must not survive that transaction or be reused for another run.
 */
final class TerminalCancellationHistory
{
    /**
     * @var array<string, WorkflowHistoryEvent>
     */
    private array $cancelledTimers = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $scheduledTimers = [];

    /**
     * @var array<string, int>
     */
    private array $scheduledSequences = [];

    /**
     * @var array<string, true>
     */
    private array $cancelledActivities = [];

    private function __construct(
        private readonly string $workflowRunId
    ) {
    }

    public static function forRun(WorkflowRun $run): self
    {
        $history = new self($run->id);
        $run->loadMissing('historyEvents');

        foreach ($run->historyEvents as $event) {
            $type = $event->event_type;
            if (! in_array($type, [
                HistoryEventType::TimerScheduled,
                HistoryEventType::TimerCancelled,
                HistoryEventType::ActivityCancelled,
            ], true)) {
                continue;
            }

            $payload = $event->payload;
            if (! is_array($payload)) {
                continue;
            }

            if ($type === HistoryEventType::ActivityCancelled) {
                $activityId = $payload['activity_execution_id'] ?? null;
                if (is_string($activityId)) {
                    $history->cancelledActivities[$activityId] = true;
                }

                continue;
            }

            $timerId = $payload['timer_id'] ?? null;
            if (! is_string($timerId)) {
                continue;
            }

            if ($type === HistoryEventType::TimerCancelled) {
                $history->cancelledTimers[$timerId] ??= $event;
            } elseif (! isset($history->scheduledSequences[$timerId])
                || $event->sequence > $history->scheduledSequences[$timerId]) {
                $history->scheduledSequences[$timerId] = $event->sequence;
                $history->scheduledTimers[$timerId] = $payload;
            }
        }

        return $history;
    }

    public function assertRun(WorkflowRun $run): void
    {
        if ($run->id !== $this->workflowRunId) {
            throw new LogicException('terminal_cancellation_history_run_mismatch');
        }
    }

    public function cancelledTimer(string $timerId): ?WorkflowHistoryEvent
    {
        return $this->cancelledTimers[$timerId] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function scheduledTimer(string $timerId): array
    {
        return $this->scheduledTimers[$timerId] ?? [];
    }

    public function rememberTimer(string $timerId, WorkflowHistoryEvent $event): void
    {
        $this->cancelledTimers[$timerId] = $event;
    }

    public function activityCancelled(string $activityId): bool
    {
        return isset($this->cancelledActivities[$activityId]);
    }

    public function rememberActivity(string $activityId): void
    {
        $this->cancelledActivities[$activityId] = true;
    }
}
