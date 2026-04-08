<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;

final class ActivitySnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function fromExecution(ActivityExecution $execution): array
    {
        return array_filter([
            'id' => $execution->id,
            'sequence' => $execution->sequence,
            'type' => $execution->activity_type,
            'class' => $execution->activity_class,
            'status' => $execution->status?->value,
            'attempt_count' => $execution->attempt_count,
            'connection' => $execution->connection,
            'queue' => $execution->queue,
            'last_heartbeat_at' => self::timestamp($execution->last_heartbeat_at),
            'created_at' => self::timestamp($execution->created_at),
            'started_at' => self::timestamp($execution->started_at),
            'closed_at' => self::timestamp($execution->closed_at),
            'arguments' => self::stringValue($execution->arguments),
            'result' => self::stringValue($execution->result),
            'exception' => self::stringValue($execution->exception),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function fromEvent(WorkflowHistoryEvent $event): ?array
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($event->payload) ? $event->payload : [];
        $snapshot = is_array($payload['activity'] ?? null) ? $payload['activity'] : [];
        $activityId = self::stringValue($snapshot['id'] ?? null)
            ?? self::stringValue($payload['activity_execution_id'] ?? null);

        if (
            $activityId === null
            && $snapshot === []
            && ! array_key_exists('activity_type', $payload)
            && ! array_key_exists('activity_class', $payload)
        ) {
            return null;
        }

        $merged = self::merge([
            'id' => $activityId,
            'sequence' => self::intValue($payload['sequence'] ?? null),
            'type' => self::stringValue($payload['activity_type'] ?? null),
            'class' => self::stringValue($payload['activity_class'] ?? null),
            'result' => self::stringValue($payload['result'] ?? null),
            'created_at' => $event->event_type === HistoryEventType::ActivityScheduled
                ? self::timestamp($event->recorded_at)
                : null,
            'closed_at' => in_array($event->event_type, [
                HistoryEventType::ActivityCompleted,
                HistoryEventType::ActivityFailed,
            ], true)
                ? self::timestamp($event->recorded_at)
                : null,
        ], self::sanitizeSnapshot($snapshot));

        $merged['status'] = self::stringValue($merged['status'] ?? null)
            ?? self::statusForEvent($event->event_type);

        return $merged;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public static function merge(array $state, array $snapshot): array
    {
        foreach ($snapshot as $key => $value) {
            if ($value === null) {
                continue;
            }

            $state[$key] = $value;
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private static function sanitizeSnapshot(array $snapshot): array
    {
        return array_filter([
            'id' => self::stringValue($snapshot['id'] ?? null),
            'sequence' => self::intValue($snapshot['sequence'] ?? null),
            'type' => self::stringValue($snapshot['type'] ?? null),
            'class' => self::stringValue($snapshot['class'] ?? null),
            'status' => self::stringValue($snapshot['status'] ?? null),
            'attempt_count' => self::intValue($snapshot['attempt_count'] ?? null),
            'connection' => self::stringValue($snapshot['connection'] ?? null),
            'queue' => self::stringValue($snapshot['queue'] ?? null),
            'last_heartbeat_at' => self::stringValue($snapshot['last_heartbeat_at'] ?? null),
            'created_at' => self::stringValue($snapshot['created_at'] ?? null),
            'started_at' => self::stringValue($snapshot['started_at'] ?? null),
            'closed_at' => self::stringValue($snapshot['closed_at'] ?? null),
            'arguments' => self::stringValue($snapshot['arguments'] ?? null),
            'result' => self::stringValue($snapshot['result'] ?? null),
            'exception' => self::stringValue($snapshot['exception'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function statusForEvent(HistoryEventType $eventType): ?string
    {
        return match ($eventType) {
            HistoryEventType::ActivityScheduled => 'pending',
            HistoryEventType::ActivityCompleted => 'completed',
            HistoryEventType::ActivityFailed => 'failed',
            default => null,
        };
    }

    private static function timestamp(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toJSON();
        }

        return self::stringValue($value);
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function intValue(mixed $value): ?int
    {
        return is_int($value)
            ? $value
            : null;
    }
}
