<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Support\ConfiguredV2Models;

class WorkflowScheduleHistoryEvent extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $table = 'workflow_schedule_history_events';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'event_type' => HistoryEventType::class,
        'payload' => 'array',
        'recorded_at' => 'datetime',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('schedule_model', WorkflowSchedule::class),
            'workflow_schedule_id',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(
        WorkflowSchedule $schedule,
        HistoryEventType $eventType,
        array $payload = [],
    ): self {
        $sequence = ((int) ConfiguredV2Models::query('schedule_history_event_model', self::class)
            ->where('workflow_schedule_id', $schedule->id)
            ->max('sequence')) + 1;

        /** @var self $event */
        $event = ConfiguredV2Models::query('schedule_history_event_model', self::class)->create([
            'workflow_schedule_id' => $schedule->id,
            'schedule_id' => $schedule->schedule_id,
            'namespace' => $schedule->namespace,
            'sequence' => $sequence,
            'event_type' => $eventType->value,
            'payload' => self::snapshotPayload($schedule, $payload),
            'workflow_instance_id' => self::stringValue($payload['workflow_instance_id'] ?? null),
            'workflow_run_id' => self::stringValue($payload['workflow_run_id'] ?? null),
            'recorded_at' => now(),
        ]);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function snapshotPayload(WorkflowSchedule $schedule, array $payload): array
    {
        if (! array_key_exists('schedule', $payload)) {
            $payload['schedule'] = self::scheduleSnapshot($schedule);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function scheduleSnapshot(WorkflowSchedule $schedule): array
    {
        return array_filter([
            'id' => $schedule->id,
            'schedule_id' => $schedule->schedule_id,
            'namespace' => $schedule->namespace,
            'status' => $schedule->status?->value,
            'overlap_policy' => $schedule->overlap_policy,
            'next_fire_at' => self::timestamp($schedule->next_fire_at),
            'last_fired_at' => self::timestamp($schedule->last_fired_at),
            'paused_at' => self::timestamp($schedule->paused_at),
            'deleted_at' => self::timestamp($schedule->deleted_at),
            'last_skip_reason' => $schedule->last_skip_reason,
            'last_skipped_at' => self::timestamp($schedule->last_skipped_at),
            'fires_count' => (int) $schedule->fires_count,
            'skipped_trigger_count' => (int) ($schedule->skipped_trigger_count ?? 0),
            'latest_workflow_instance_id' => $schedule->latest_workflow_instance_id,
            'note' => $schedule->note,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function timestamp(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
