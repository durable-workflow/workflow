<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use DateTimeInterface;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Enums\ScheduleStatus;

final class ScheduleDescription
{
    public function __construct(
        public readonly string $id,
        public readonly string $scheduleId,
        public readonly string $workflowType,
        public readonly string $workflowClass,
        public readonly string $cronExpression,
        public readonly string $timezone,
        public readonly ScheduleStatus $status,
        public readonly ScheduleOverlapPolicy $overlapPolicy,
        public readonly int $totalRuns,
        public readonly ?int $remainingActions,
        public readonly ?DateTimeInterface $nextRunAt,
        public readonly ?DateTimeInterface $lastTriggeredAt,
        public readonly ?string $latestInstanceId,
        public readonly int $jitterSeconds,
        public readonly ?string $notes,
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
            'workflow_type' => $this->workflowType,
            'workflow_class' => $this->workflowClass,
            'cron_expression' => $this->cronExpression,
            'timezone' => $this->timezone,
            'status' => $this->status->value,
            'overlap_policy' => $this->overlapPolicy->value,
            'total_runs' => $this->totalRuns,
            'remaining_actions' => $this->remainingActions,
            'next_run_at' => $this->nextRunAt?->format('Y-m-d\TH:i:s.uP'),
            'last_triggered_at' => $this->lastTriggeredAt?->format('Y-m-d\TH:i:s.uP'),
            'latest_instance_id' => $this->latestInstanceId,
            'jitter_seconds' => $this->jitterSeconds,
            'notes' => $this->notes,
        ];
    }
}
