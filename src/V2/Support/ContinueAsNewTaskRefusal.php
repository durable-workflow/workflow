<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\PromotedBuildUnavailableException;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/** @internal */
final class ContinueAsNewTaskRefusal
{
    public const KEY = 'continue_as_new_refusal';

    /**
     * @return array{task_id: string, status: string, available_at: ?string, workflow_task_attempt: int, recorded_at: string, reason: string}|null
     */
    public static function forRun(WorkflowRun $run): ?array
    {
        /** @var class-string<WorkflowTask> $model */
        $model = ConfiguredV2Models::resolve('task_model', WorkflowTask::class);
        $task = (new $model())->setConnection($run->getConnectionName())
            ->newQuery()
            ->setEagerLoads([])
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn(
                'status',
                [
                    TaskStatus::Ready->value,
                    TaskStatus::Leased->value,
                    TaskStatus::Failed->value,
                    TaskStatus::Cancelled->value,
                ]
            )
            ->where('payload->' . self::KEY . '->reason', PromotedBuildUnavailableException::REASON)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id', 'status', 'available_at', 'attempt_count', 'payload']);
        $refusal = $task?->payload[self::KEY] ?? null;
        if ($task === null || ! is_array($refusal)
            || ($refusal['workflow_task_attempt'] ?? null) !== (int) $task->attempt_count
            || ! is_string($refusal['recorded_at'] ?? null)) {
            return null;
        }

        $available = $task->status === TaskStatus::Ready && ! $run->status->isTerminal()
            ? $task->available_at
            : null;
        if ($available !== null && (($run->execution_deadline_at !== null && $available->gte(
            $run->execution_deadline_at
        ))
            || ($run->run_deadline_at !== null && $available->gte($run->run_deadline_at)))) {
            $available = null;
        }

        return [
            'task_id' => $task->id,
            'status' => $task->status->value,
            'available_at' => $available?->toIso8601String(),
            'workflow_task_attempt' => (int) $task->attempt_count,
            'recorded_at' => $refusal['recorded_at'],
            'reason' => PromotedBuildUnavailableException::REASON,
        ];
    }
}
