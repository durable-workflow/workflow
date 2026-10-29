<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Casts\Json;
use LogicException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowCommand;
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

    /**
     * @param array<string, mixed> $commandAttributes
     * @param array<string, mixed> $commandCasts
     * @param array<string, mixed>|null $commandSnapshot
     */
    private function __construct(
        private readonly string $workflowRunId,
        private readonly WorkflowCommand $command,
        private readonly array $commandAttributes,
        private readonly array $commandCasts,
        private readonly ?array $commandSnapshot,
    ) {
    }

    public static function forRun(WorkflowRun $run, WorkflowCommand $command, WorkflowHistoryEvent $requested): self
    {
        $history = new self(
            $run->id,
            $command,
            $command->getAttributes(),
            $command->getCasts(),
            self::reusableCommandSnapshot($command, $requested),
        );
        $events = $run->relationLoaded('historyEvents')
            ? $run->historyEvents
            : $run->historyEvents()
                ->get();

        foreach ($events as $event) {
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
                // Cancellation derives timer metadata from this event, while
                // recording its own command/task snapshots. Retaining every
                // original snapshot keeps a second large object graph alive.
                $history->scheduledTimers[$timerId] = array_diff_key($payload, [
                    'task' => true,
                    'command' => true,
                ]);
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

    /**
     * @return array<string, mixed>
     */
    public function commandPayload(WorkflowCommand|string|null $command): array
    {
        // Reuse only the already recorded snapshot of this unchanged ordinary
        // command. Configured models and changed attributes/casts keep their
        // normal snapshot hooks on every event.
        return $this->commandSnapshot !== null && $command instanceof WorkflowCommand && $command === $this->command
            && (! class_exists(Json::class) || HistoryPayloadJson::usesDefaultDecoder())
            && $command->getAttributes() === $this->commandAttributes
            && $command->getCasts() === $this->commandCasts
            ? [
                'command' => $this->commandSnapshot,
            ]
            : [];
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

    /**
     * @return array<string, mixed>|null
     */
    private static function reusableCommandSnapshot(
        WorkflowCommand $command,
        WorkflowHistoryEvent $requested,
    ): ?array {
        $snapshot = $requested->payload['command'] ?? null;
        // Identical raw attributes cannot establish an unchanged value for a
        // stateful custom cast. Keep its normal per-event snapshot hooks.
        if ($command::class !== WorkflowCommand::class
            || $requested::class !== WorkflowHistoryEvent::class
            || $command->getCasts() !== (new WorkflowCommand())->getCasts()
            || (class_exists(Json::class) && ! HistoryPayloadJson::usesDefaultDecoder())) {
            return null;
        }

        // Requested-event observers may mutate the command or the returned
        // event payload. Validate once against the live ordinary command.
        return is_array($snapshot) && ($snapshot['id'] ?? null) === $command->id
            && WorkflowHistoryEvent::matchesCommandSnapshot($command, $snapshot)
            ? $snapshot : null;
    }
}
