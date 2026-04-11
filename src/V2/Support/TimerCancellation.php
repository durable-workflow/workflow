<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;

final class TimerCancellation
{
    public static function record(
        WorkflowRun $run,
        WorkflowTimer $timer,
        WorkflowTask|string|null $task = null,
        WorkflowCommand|string|null $command = null,
        ?CarbonInterface $cancelledAt = null,
    ): WorkflowHistoryEvent {
        $run->loadMissing('historyEvents');

        $existing = $run->historyEvents
            ->first(
                static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::TimerCancelled
                && ($event->payload['timer_id'] ?? null) === $timer->id
            );

        if ($existing instanceof WorkflowHistoryEvent) {
            return $existing;
        }

        $scheduledPayload = self::scheduledPayload($run, $timer->id);
        $cancelledAt ??= now();

        $event = WorkflowHistoryEvent::record($run, HistoryEventType::TimerCancelled, array_filter([
            'timer_id' => $timer->id,
            'sequence' => self::intValue($scheduledPayload['sequence'] ?? null) ?? $timer->sequence,
            'delay_seconds' => self::intValue($scheduledPayload['delay_seconds'] ?? null) ?? $timer->delay_seconds,
            'fire_at' => self::stringValue($scheduledPayload['fire_at'] ?? null) ?? $timer->fire_at?->toJSON(),
            'timer_kind' => self::stringValue($scheduledPayload['timer_kind'] ?? null),
            'condition_wait_id' => self::stringValue($scheduledPayload['condition_wait_id'] ?? null),
            'condition_key' => self::stringValue($scheduledPayload['condition_key'] ?? null),
            'condition_definition_fingerprint' => self::stringValue(
                $scheduledPayload['condition_definition_fingerprint'] ?? null
            ),
            'cancelled_at' => $cancelledAt->toJSON(),
        ], static fn (mixed $value): bool => $value !== null), $task, $command);

        if ($run->relationLoaded('historyEvents')) {
            $run->historyEvents->push($event);
        }

        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    private static function scheduledPayload(WorkflowRun $run, string $timerId): array
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents
            ->filter(
                static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::TimerScheduled
                && ($event->payload['timer_id'] ?? null) === $timerId
            )
            ->sortByDesc('sequence')
            ->first();

        return is_array($event?->payload) ? $event->payload : [];
    }

    private static function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value)
            ? (int) $value
            : null;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
