<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Carbon;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class UpdateWaits
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forRun(WorkflowRun $run): array
    {
        $run->loadMissing([
            'commands',
            'historyEvents',
            'updates.command',
            'updates.failure',
            'tasks',
        ]);

        $rows = [];

        foreach (RunUpdateView::forRun($run) as $update) {
            $sourceStatus = self::stringValue($update['status'] ?? null);

            if (! in_array($sourceStatus, ['accepted', 'completed', 'failed'], true)) {
                continue;
            }

            $updateId = self::stringValue($update['id'] ?? null);
            $commandId = self::stringValue($update['command_id'] ?? null);
            $waitId = self::waitId($updateId, $commandId);

            if ($waitId === null) {
                continue;
            }

            $task = $sourceStatus === 'accepted'
                ? self::preferredWorkflowTask($run)
                : null;
            $taskBacked = self::isOpenTask($task);
            $updateName = self::stringValue($update['name'] ?? null) ?? 'update';
            $status = $sourceStatus === 'accepted'
                ? 'open'
                : 'resolved';

            $rows[] = [
                'id' => $waitId,
                'update_id' => $updateId,
                'kind' => 'update',
                'sequence' => self::intValue($update['workflow_sequence'] ?? null),
                'status' => $status,
                'source_status' => $sourceStatus,
                'summary' => match ($sourceStatus) {
                    'accepted' => sprintf('Waiting for update %s.', $updateName),
                    'failed' => sprintf('Update %s failed.', $updateName),
                    default => sprintf('Update %s completed.', $updateName),
                },
                'opened_at' => self::timestamp($update['accepted_at'] ?? null),
                'deadline_at' => null,
                'resolved_at' => self::timestamp($update['closed_at'] ?? null)
                    ?? self::timestamp($update['applied_at'] ?? null),
                'target_name' => $updateName,
                'target_type' => 'update',
                'task_backed' => $taskBacked,
                'external_only' => false,
                'resume_source_kind' => 'workflow_update',
                'resume_source_id' => $updateId ?? $commandId,
                'task_id' => $task?->id,
                'task_type' => $task?->task_type?->value,
                'task_status' => $task?->status?->value,
                'command_id' => $commandId,
                'command_sequence' => self::intValue($update['command_sequence'] ?? null),
                'command_status' => 'accepted',
                'command_outcome' => self::stringValue($update['outcome'] ?? null),
            ];
        }

        return $rows;
    }

    private static function waitId(?string $updateId, ?string $commandId): ?string
    {
        if ($updateId !== null) {
            return sprintf('update:%s', $updateId);
        }

        if ($commandId !== null) {
            return sprintf('update-command:%s', $commandId);
        }

        return null;
    }

    private static function preferredWorkflowTask(WorkflowRun $run): ?WorkflowTask
    {
        /** @var WorkflowTask|null $task */
        $task = $run->tasks
            ->filter(static fn (WorkflowTask $task): bool => $task->task_type === TaskType::Workflow)
            ->sort(static function (WorkflowTask $left, WorkflowTask $right): int {
                $leftPriority = self::taskPriority($left);
                $rightPriority = self::taskPriority($right);

                if ($leftPriority !== $rightPriority) {
                    return $leftPriority <=> $rightPriority;
                }

                $leftUpdatedAt = $left->updated_at?->getTimestampMs() ?? PHP_INT_MIN;
                $rightUpdatedAt = $right->updated_at?->getTimestampMs() ?? PHP_INT_MIN;

                if ($leftUpdatedAt !== $rightUpdatedAt) {
                    return $rightUpdatedAt <=> $leftUpdatedAt;
                }

                $leftCreatedAt = $left->created_at?->getTimestampMs() ?? PHP_INT_MIN;
                $rightCreatedAt = $right->created_at?->getTimestampMs() ?? PHP_INT_MIN;

                if ($leftCreatedAt !== $rightCreatedAt) {
                    return $rightCreatedAt <=> $leftCreatedAt;
                }

                return $right->id <=> $left->id;
            })
            ->first();

        return $task;
    }

    private static function taskPriority(WorkflowTask $task): int
    {
        return match ($task->status) {
            TaskStatus::Leased => 0,
            TaskStatus::Ready => 1,
            TaskStatus::Completed => 2,
            TaskStatus::Failed => 3,
            TaskStatus::Cancelled => 4,
        };
    }

    private static function isOpenTask(?WorkflowTask $task): bool
    {
        if (! $task instanceof WorkflowTask) {
            return false;
        }

        return in_array($task->status, [TaskStatus::Ready, TaskStatus::Leased], true);
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
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

    private static function timestamp(mixed $value): ?\Carbon\CarbonInterface
    {
        if ($value instanceof \Carbon\CarbonInterface) {
            return $value;
        }

        return is_string($value) && $value !== ''
            ? Carbon::parse($value)
            : null;
    }
}
