<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;

final class LocalActivityRuntime
{
    public const EXECUTION_MODE = 'local';

    public const RETRY_REASON_FAILURE = 'failure';

    public const RETRY_REASON_TIMEOUT = 'timeout';

    public const RETRY_REASON_COLD_REPLAY = 'cold_replay';

    public static function isExecution(ActivityExecution $execution): bool
    {
        $options = is_array($execution->activity_options) ? $execution->activity_options : [];

        return ($options['execution_mode'] ?? null) === self::EXECUTION_MODE;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function eventPayload(array $payload = []): array
    {
        return [
            'execution_mode' => self::EXECUTION_MODE,
            'local_activity' => true,
            ...$payload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function workflowTaskPayload(ActivityExecution $execution, array $payload = []): array
    {
        return [
            'workflow_wait_kind' => 'local_activity',
            'open_wait_id' => sprintf('local-activity:%s', $execution->id),
            'resume_source_kind' => 'local_activity',
            'resume_source_id' => $execution->id,
            'activity_execution_id' => $execution->id,
            'activity_type' => $execution->activity_type ?? $execution->activity_class,
            'workflow_sequence' => $execution->sequence,
            'execution_mode' => self::EXECUTION_MODE,
            ...$payload,
        ];
    }

    public static function workflowTaskLeaseExpiresAt(): CarbonInterface
    {
        return WorkflowTaskLease::expiresAt();
    }

    public static function renewWorkflowTask(WorkflowTask $task): ?CarbonInterface
    {
        if ($task->task_type !== TaskType::Workflow || $task->status !== TaskStatus::Leased) {
            return null;
        }

        $leaseExpiresAt = self::workflowTaskLeaseExpiresAt();

        $task->forceFill([
            'lease_expires_at' => $leaseExpiresAt,
        ])->save();

        WorkflowRunSummary::query()
            ->whereKey($task->workflow_run_id)
            ->where('next_task_id', $task->id)
            ->update([
                'next_task_lease_expires_at' => $leaseExpiresAt,
            ]);

        return $leaseExpiresAt;
    }
}
