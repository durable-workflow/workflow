<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;

final class TaskRepair
{
    public static function repairRun(WorkflowRun $run, WorkflowRunSummary $summary): ?WorkflowTask
    {
        $task = self::recoverableTask($run);

        if ($task !== null) {
            return self::recoverExistingTask($task, $run);
        }

        return self::createMissingTask($run, $summary);
    }

    public static function recoverExistingTask(WorkflowTask $task, WorkflowRun $run): ?WorkflowTask
    {
        if (in_array($run->status, [
            RunStatus::Completed,
            RunStatus::Failed,
            RunStatus::Cancelled,
            RunStatus::Terminated,
        ], true)) {
            self::settleTerminalTask($task, $run);

            return null;
        }

        if ($task->status === TaskStatus::Ready) {
            if (! TaskRepairPolicy::readyTaskNeedsRedispatch($task)) {
                return null;
            }

            $task->forceFill([
                'repair_count' => $task->repair_count + 1,
                'last_error' => null,
            ])->save();

            return $task;
        }

        if ($task->status === TaskStatus::Leased) {
            if (! TaskRepairPolicy::leaseExpired($task)) {
                return null;
            }

            $task->forceFill([
                'status' => TaskStatus::Ready,
                'leased_at' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'repair_count' => $task->repair_count + 1,
                'last_error' => null,
            ])->save();

            return $task;
        }

        return null;
    }

    private static function recoverableTask(WorkflowRun $run): ?WorkflowTask
    {
        /** @var WorkflowTask|null $task */
        $task = $run->tasks
            ->filter(static fn (WorkflowTask $task): bool => TaskRepairPolicy::readyTaskNeedsRedispatch($task)
                || TaskRepairPolicy::leaseExpired($task))
            ->sort(static function (WorkflowTask $left, WorkflowTask $right): int {
                $leftAvailableAt = $left->available_at?->getTimestampMs() ?? PHP_INT_MAX;
                $rightAvailableAt = $right->available_at?->getTimestampMs() ?? PHP_INT_MAX;

                if ($leftAvailableAt !== $rightAvailableAt) {
                    return $leftAvailableAt <=> $rightAvailableAt;
                }

                $leftCreatedAt = $left->created_at?->getTimestampMs() ?? PHP_INT_MAX;
                $rightCreatedAt = $right->created_at?->getTimestampMs() ?? PHP_INT_MAX;

                if ($leftCreatedAt !== $rightCreatedAt) {
                    return $leftCreatedAt <=> $rightCreatedAt;
                }

                return $left->id <=> $right->id;
            })
            ->first();

        return $task;
    }

    private static function createMissingTask(WorkflowRun $run, WorkflowRunSummary $summary): ?WorkflowTask
    {
        if ($summary->wait_kind === 'activity') {
            /** @var ActivityExecution|null $execution */
            $execution = $run->activityExecutions
                ->first(static fn (ActivityExecution $execution): bool => in_array(
                    $execution->status,
                    [ActivityStatus::Pending, ActivityStatus::Running],
                    true,
                ));

            if ($execution instanceof ActivityExecution) {
                /** @var WorkflowTask $task */
                $task = WorkflowTask::query()->create([
                    'workflow_run_id' => $run->id,
                    'task_type' => TaskType::Activity->value,
                    'status' => TaskStatus::Ready->value,
                    'available_at' => now(),
                    'payload' => [
                        'activity_execution_id' => $execution->id,
                    ],
                    'connection' => $execution->connection ?? $run->connection,
                    'queue' => $execution->queue ?? $run->queue,
                    'compatibility' => $run->compatibility,
                    'repair_count' => 1,
                ]);

                return $task;
            }
        }

        if ($summary->wait_kind === 'timer') {
            /** @var WorkflowTimer|null $timer */
            $timer = $run->timers
                ->first(static fn (WorkflowTimer $timer): bool => $timer->status === TimerStatus::Pending);

            if ($timer instanceof WorkflowTimer) {
                /** @var WorkflowTask $task */
                $task = WorkflowTask::query()->create([
                    'workflow_run_id' => $run->id,
                    'task_type' => TaskType::Timer->value,
                    'status' => TaskStatus::Ready->value,
                    'available_at' => $timer->fire_at !== null && $timer->fire_at->isFuture()
                        ? $timer->fire_at
                        : now(),
                    'payload' => [
                        'timer_id' => $timer->id,
                    ],
                    'connection' => $run->connection,
                    'queue' => $run->queue,
                    'compatibility' => $run->compatibility,
                    'repair_count' => 1,
                ]);

                return $task;
            }
        }

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now(),
            'payload' => [],
            'connection' => $run->connection,
            'queue' => $run->queue,
            'compatibility' => $run->compatibility,
            'repair_count' => 1,
        ]);

        return $task;
    }

    private static function settleTerminalTask(WorkflowTask $task, WorkflowRun $run): void
    {
        $task->forceFill([
            'status' => $task->status === TaskStatus::Cancelled
                ? TaskStatus::Cancelled
                : match ($run->status) {
                    RunStatus::Failed => TaskStatus::Failed,
                    default => TaskStatus::Completed,
                },
            'leased_at' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
        ])->save();
    }
}
