<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use DateTimeImmutable;

/**
 * The schedule fire that started a run, read from the run's own
 * ScheduleTriggered history event.
 *
 * occurrenceTime is the time the scheduler evaluated: the jittered fire time
 * for a tick, or the enumerated time for a backfill. It is not the run's actual
 * start time. It is null for a fire recorded without an occurrence, such as a
 * manual trigger.
 *
 * @api Stable v2 workflow authoring API (durable-workflow/.github#243).
 */
final class ScheduleOrigin
{
    public function __construct(
        public readonly string $scheduleId,
        public readonly ?DateTimeImmutable $occurrenceTime,
        public readonly ?int $triggerNumber,
    ) {
    }

    /**
     * The origin from a ScheduleTriggered payload, or null when it names no schedule.
     *
     * @param array<string, mixed>|mixed $payload
     */
    public static function fromPayload(mixed $payload): ?self
    {
        if (! is_array($payload)) {
            return null;
        }

        $scheduleId = $payload['schedule_id'] ?? null;

        if (! is_string($scheduleId) || $scheduleId === '') {
            return null;
        }

        $occurrence = $payload['occurrence_time'] ?? null;
        $trigger = $payload['trigger_number'] ?? null;

        return new self(
            $scheduleId,
            is_string($occurrence) ? self::occurrence($occurrence) : null,
            is_int($trigger) && $trigger > 0 ? $trigger : null,
        );
    }

    private static function occurrence(string $value): ?DateTimeImmutable
    {
        // Recorded as Y-m-d\TH:i:s.uP; any explicit offset is accepted, a
        // time without one is not guessed.
        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $value) !== 1) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
