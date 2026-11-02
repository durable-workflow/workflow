<?php

declare(strict_types=1);

namespace Tests\Support;

use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowTask;

/** Constant-space observation of actual task maintenance transitions. */
final class ContinuationTaskMaintenance
{
    /**
     * @var array{ready_redispatches: int, lease_recoveries: int, other_repairs: int}
     */
    private array $counts = [
        'ready_redispatches' => 0,
        'lease_recoveries' => 0,
        'other_repairs' => 0,
    ];

    public function observe(WorkflowTask $task): void
    {
        if (! $task->wasChanged('repair_count')) {
            return;
        }
        $change = (int) $task->repair_count - (int) $task->getRawOriginal('repair_count');
        $before = $task->getRawOriginal('status');
        $ready = $task->status === TaskStatus::Ready;
        $healthy = in_array($task->getRawOriginal('last_dispatch_error'), [null, ''], true)
            && in_array($task->getRawOriginal('last_claim_error'), [null, ''], true);
        $kind = match (true) {
            $change > 0 && $before === TaskStatus::Ready->value && $ready && $healthy
                && (int) $task->getRawOriginal('attempt_count') === 0 => 'ready_redispatches',
            $change > 0 && $before === TaskStatus::Leased->value && $ready => 'lease_recoveries',
            default => 'other_repairs',
        };
        $this->counts[$kind] += abs($change);
    }

    /**
     * @return array{ready_redispatches: int, lease_recoveries: int, other_repairs: int}
     */
    public function snapshot(): array
    {
        return $this->counts;
    }
}
