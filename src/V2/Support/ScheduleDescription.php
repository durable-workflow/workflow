<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use DateTimeInterface;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Enums\ScheduleStatus;

final class ScheduleDescription
{
    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $action
     */
    public function __construct(
        public readonly string $id,
        public readonly string $scheduleId,
        public readonly array $spec,
        public readonly array $action,
        public readonly ScheduleStatus $status,
        public readonly ScheduleOverlapPolicy $overlapPolicy,
        public readonly int $firesCount,
        public readonly int $failuresCount,
        public readonly ?int $remainingActions,
        public readonly ?DateTimeInterface $nextFireAt,
        public readonly ?DateTimeInterface $lastFiredAt,
        public readonly ?string $latestInstanceId,
        public readonly int $jitterSeconds,
        public readonly ?string $note,
        public readonly int $skippedTriggerCount = 0,
        public readonly ?string $lastSkipReason = null,
        public readonly ?DateTimeInterface $lastSkippedAt = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'schedule_id' => $this->scheduleId,
            'spec' => $this->spec,
            'action' => $this->action,
            'status' => $this->status->value,
            'overlap_policy' => $this->overlapPolicy->value,
            'fires_count' => $this->firesCount,
            'failures_count' => $this->failuresCount,
            'remaining_actions' => $this->remainingActions,
            'next_fire_at' => $this->nextFireAt?->format('Y-m-d\TH:i:s.uP'),
            'last_fired_at' => $this->lastFiredAt?->format('Y-m-d\TH:i:s.uP'),
            'latest_instance_id' => $this->latestInstanceId,
            'jitter_seconds' => $this->jitterSeconds,
            'note' => $this->note,
            'skipped_trigger_count' => $this->skippedTriggerCount,
            'last_skip_reason' => $this->lastSkipReason,
            'last_skipped_at' => $this->lastSkippedAt?->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}
