<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class DefaultWorkflowTaskBridge implements WorkflowTaskBridge
{
    public function __construct(
        private readonly WorkflowExecutor $executor,
    ) {}

    public function poll(?string $connection, ?string $queue, int $limit = 1, ?string $compatibility = null): array
    {
        $query = ConfiguredV2Models::query('task_model', WorkflowTask::class)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->where(function ($q) {
                $q->whereNull('available_at')
                    ->orWhere('available_at', '<=', now());
            })
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit(max(1, min($limit, 100)));

        if ($connection !== null) {
            $query->where('connection', $connection);
        }

        if ($queue !== null) {
            $query->where('queue', $queue);
        }

        if ($compatibility !== null) {
            $query->where('compatibility', $compatibility);
        }

        $tasks = $query->get();

        return $tasks->map(function (WorkflowTask $task) {
            /** @var WorkflowRun|null $run */
            $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->find($task->workflow_run_id);

            return [
                'task_id' => $task->id,
                'workflow_run_id' => $task->workflow_run_id,
                'workflow_instance_id' => $run?->workflow_instance_id ?? '',
                'workflow_type' => self::nonEmptyString($run?->workflow_type),
                'workflow_class' => self::nonEmptyString($run?->workflow_class),
                'connection' => self::nonEmptyString($task->connection),
                'queue' => self::nonEmptyString($task->queue),
                'compatibility' => self::nonEmptyString($task->compatibility),
                'available_at' => $task->available_at?->toJSON(),
            ];
        })->values()->all();
    }

    public function claimStatus(string $taskId, ?string $leaseOwner = null): array
    {
        return DB::transaction(function () use ($taskId, $leaseOwner): array {
            /** @var WorkflowTask|null $task */
            $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                ->lockForUpdate()
                ->find($taskId);

            if ($task === null) {
                return self::claimRejected($taskId, 'task_not_found', 'The workflow task does not exist.');
            }

            if ($task->task_type !== TaskType::Workflow) {
                return self::claimRejected($taskId, 'task_not_workflow', 'The task is not a workflow task.');
            }

            if ($task->status !== TaskStatus::Ready) {
                return self::claimRejected(
                    $taskId,
                    'task_not_claimable',
                    "The task status is {$task->status->value} and cannot be claimed.",
                );
            }

            /** @var WorkflowRun|null $run */
            $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->lockForUpdate()
                ->find($task->workflow_run_id);

            if ($run === null) {
                return self::claimRejected($taskId, 'run_not_found', 'The workflow run does not exist.');
            }

            if ($run->status->isTerminal()) {
                return self::claimRejected(
                    $taskId,
                    'run_closed',
                    "The workflow run is {$run->status->value}.",
                );
            }

            TaskCompatibility::sync($task, $run);

            if (TaskBackendCapabilities::recordClaimFailureIfUnsupported($task) !== null) {
                RunSummaryProjector::project(
                    $run->fresh(['instance', 'tasks', 'activityExecutions', 'failures'])
                );

                return self::claimRejected(
                    $taskId,
                    'backend_unavailable',
                    'The backend does not support the required capabilities.',
                );
            }

            if (! TaskCompatibility::supported($task, $run)) {
                RunSummaryProjector::project(
                    $run->fresh(['instance', 'tasks', 'activityExecutions', 'failures'])
                );

                $mismatch = TaskCompatibility::mismatchReason($task, $run);

                return self::claimRejected(
                    $taskId,
                    'compatibility_blocked',
                    $mismatch ?? 'The task compatibility marker does not match this worker.',
                );
            }

            $resolvedLeaseOwner = $leaseOwner ?? $taskId;
            $leaseExpiresAt = now()->addMinutes(5);

            $task->forceFill([
                'status' => TaskStatus::Leased,
                'leased_at' => now(),
                'lease_owner' => $resolvedLeaseOwner,
                'lease_expires_at' => $leaseExpiresAt,
                'attempt_count' => $task->attempt_count + 1,
                'last_claim_failed_at' => null,
                'last_claim_error' => null,
            ])->save();

            RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'failures'])
            );

            return [
                'claimed' => true,
                'task_id' => $task->id,
                'workflow_run_id' => $run->id,
                'workflow_instance_id' => $run->workflow_instance_id,
                'workflow_type' => self::nonEmptyString($run->workflow_type),
                'workflow_class' => self::nonEmptyString($run->workflow_class),
                'payload_codec' => $run->payload_codec ?? config('workflows.serializer'),
                'connection' => self::nonEmptyString($task->connection),
                'queue' => self::nonEmptyString($task->queue),
                'compatibility' => self::nonEmptyString($task->compatibility),
                'lease_owner' => $resolvedLeaseOwner,
                'lease_expires_at' => $leaseExpiresAt->toJSON(),
                'reason' => null,
                'reason_detail' => null,
            ];
        });
    }

    public function claim(string $taskId, ?string $leaseOwner = null): ?array
    {
        $result = $this->claimStatus($taskId, $leaseOwner);

        if ($result['claimed'] !== true) {
            return null;
        }

        return [
            'task_id' => $result['task_id'],
            'workflow_run_id' => $result['workflow_run_id'],
            'workflow_instance_id' => $result['workflow_instance_id'],
            'workflow_type' => $result['workflow_type'],
            'workflow_class' => $result['workflow_class'],
            'payload_codec' => $result['payload_codec'],
            'connection' => $result['connection'],
            'queue' => $result['queue'],
            'compatibility' => $result['compatibility'],
            'lease_owner' => $result['lease_owner'],
            'lease_expires_at' => $result['lease_expires_at'],
        ];
    }

    public function historyPayload(string $taskId): ?array
    {
        /** @var WorkflowTask|null $task */
        $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
            ->find($taskId);

        if ($task === null || $task->task_type !== TaskType::Workflow) {
            return null;
        }

        /** @var WorkflowRun|null $run */
        $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
            ->find($task->workflow_run_id);

        if ($run === null) {
            return null;
        }

        $historyEvents = ConfiguredV2Models::query('history_event_model', WorkflowHistoryEvent::class)
            ->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->get();

        return [
            'task_id' => $task->id,
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_type' => self::nonEmptyString($run->workflow_type),
            'workflow_class' => self::nonEmptyString($run->workflow_class),
            'payload_codec' => $run->payload_codec ?? config('workflows.serializer'),
            'arguments' => self::nonEmptyString($run->arguments),
            'run_status' => $run->status->value,
            'last_history_sequence' => (int) ($run->last_history_sequence ?? 0),
            'history_events' => $historyEvents->map(fn (WorkflowHistoryEvent $event) => [
                'id' => $event->id,
                'sequence' => (int) $event->sequence,
                'event_type' => $event->event_type->value,
                'payload' => is_array($event->payload) ? $event->payload : [],
                'workflow_task_id' => self::nonEmptyString($event->workflow_task_id),
                'workflow_command_id' => self::nonEmptyString($event->workflow_command_id),
                'recorded_at' => $event->recorded_at?->toJSON(),
            ])->values()->all(),
        ];
    }

    public function execute(string $taskId): array
    {
        if (! $this->claimIfReady($taskId)) {
            return [
                'executed' => false,
                'task_id' => $taskId,
                'workflow_run_id' => null,
                'run_status' => null,
                'next_task_id' => null,
                'reason' => 'claim_failed',
            ];
        }

        $nextTask = null;

        try {
            $nextTask = DB::transaction(function () use ($taskId): ?WorkflowTask {
                /** @var WorkflowTask $task */
                $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                    ->lockForUpdate()
                    ->findOrFail($taskId);

                /** @var WorkflowRun $run */
                $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                    ->lockForUpdate()
                    ->findOrFail($task->workflow_run_id);

                if ($run->status->isTerminal()) {
                    $task->forceFill([
                        'status' => $task->status === TaskStatus::Cancelled
                            ? TaskStatus::Cancelled
                            : ($run->status === RunStatus::Failed ? TaskStatus::Failed : TaskStatus::Completed),
                        'lease_expires_at' => null,
                    ])->save();

                    RunSummaryProjector::project(
                        $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures'])
                    );

                    return null;
                }

                return $this->executor->run($run, $task);
            });
        } catch (Throwable $throwable) {
            DB::transaction(function () use ($taskId, $throwable): void {
                /** @var WorkflowTask|null $task */
                $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                    ->lockForUpdate()
                    ->find($taskId);

                if ($task === null) {
                    return;
                }

                $task->forceFill([
                    'status' => TaskStatus::Failed,
                    'last_error' => $throwable->getMessage(),
                    'lease_expires_at' => null,
                ])->save();

                /** @var WorkflowRun $run */
                $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                    ->findOrFail($task->workflow_run_id);
                RunSummaryProjector::project(
                    $run->fresh(['instance', 'tasks', 'activityExecutions', 'failures'])
                );
            });

            return [
                'executed' => false,
                'task_id' => $taskId,
                'workflow_run_id' => null,
                'run_status' => null,
                'next_task_id' => null,
                'reason' => 'execution_failed',
            ];
        }

        /** @var WorkflowRun|null $run */
        $run = null;

        /** @var WorkflowTask|null $task */
        $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)->find($taskId);
        if ($task !== null) {
            $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)->find($task->workflow_run_id);
        }

        return [
            'executed' => true,
            'task_id' => $taskId,
            'workflow_run_id' => $run?->id,
            'run_status' => $run?->status?->value,
            'next_task_id' => $nextTask?->id,
            'reason' => null,
        ];
    }

    public function fail(string $taskId, Throwable|array|string $failure): array
    {
        return DB::transaction(function () use ($taskId, $failure): array {
            /** @var WorkflowTask|null $task */
            $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                ->lockForUpdate()
                ->find($taskId);

            if ($task === null) {
                return [
                    'recorded' => false,
                    'task_id' => $taskId,
                    'reason' => 'task_not_found',
                ];
            }

            if ($task->task_type !== TaskType::Workflow) {
                return [
                    'recorded' => false,
                    'task_id' => $taskId,
                    'reason' => 'task_not_workflow',
                ];
            }

            if (! in_array($task->status, [TaskStatus::Ready, TaskStatus::Leased], true)) {
                return [
                    'recorded' => false,
                    'task_id' => $taskId,
                    'reason' => 'task_not_active',
                ];
            }

            $errorMessage = self::failureMessage($failure);

            $task->forceFill([
                'status' => TaskStatus::Failed,
                'last_error' => $errorMessage,
                'lease_expires_at' => null,
            ])->save();

            /** @var WorkflowRun $run */
            $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->findOrFail($task->workflow_run_id);

            RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'failures'])
            );

            return [
                'recorded' => true,
                'task_id' => $taskId,
                'reason' => null,
            ];
        });
    }

    public function heartbeat(string $taskId): array
    {
        return DB::transaction(function () use ($taskId): array {
            /** @var WorkflowTask|null $task */
            $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                ->lockForUpdate()
                ->find($taskId);

            if ($task === null) {
                return [
                    'renewed' => false,
                    'task_id' => $taskId,
                    'lease_expires_at' => null,
                    'run_status' => null,
                    'task_status' => null,
                    'reason' => 'task_not_found',
                ];
            }

            if ($task->task_type !== TaskType::Workflow || $task->status !== TaskStatus::Leased) {
                return [
                    'renewed' => false,
                    'task_id' => $taskId,
                    'lease_expires_at' => $task->lease_expires_at?->toJSON(),
                    'run_status' => null,
                    'task_status' => $task->status->value,
                    'reason' => $task->status !== TaskStatus::Leased ? 'task_not_leased' : 'task_not_workflow',
                ];
            }

            /** @var WorkflowRun|null $run */
            $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->lockForUpdate()
                ->find($task->workflow_run_id);

            if ($run !== null && $run->status->isTerminal()) {
                return [
                    'renewed' => false,
                    'task_id' => $taskId,
                    'lease_expires_at' => $task->lease_expires_at?->toJSON(),
                    'run_status' => $run->status->value,
                    'task_status' => $task->status->value,
                    'reason' => 'run_closed',
                ];
            }

            $leaseExpiresAt = now()->addMinutes(5);

            $task->forceFill([
                'lease_expires_at' => $leaseExpiresAt,
            ])->save();

            return [
                'renewed' => true,
                'task_id' => $taskId,
                'lease_expires_at' => $leaseExpiresAt->toJSON(),
                'run_status' => $run?->status?->value,
                'task_status' => $task->status->value,
                'reason' => null,
            ];
        });
    }

    public function complete(string $taskId, array $commands): array
    {
        $terminal = self::extractTerminalCommand($commands);

        if ($terminal === null) {
            return [
                'completed' => false,
                'task_id' => $taskId,
                'workflow_run_id' => null,
                'run_status' => null,
                'reason' => 'missing_terminal_command',
            ];
        }

        return DB::transaction(function () use ($taskId, $terminal): array {
            /** @var WorkflowTask|null $task */
            $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                ->lockForUpdate()
                ->find($taskId);

            if ($task === null || $task->task_type !== TaskType::Workflow) {
                return [
                    'completed' => false,
                    'task_id' => $taskId,
                    'workflow_run_id' => null,
                    'run_status' => null,
                    'reason' => $task === null ? 'task_not_found' : 'task_not_workflow',
                ];
            }

            if ($task->status !== TaskStatus::Leased) {
                return [
                    'completed' => false,
                    'task_id' => $taskId,
                    'workflow_run_id' => $task->workflow_run_id,
                    'run_status' => null,
                    'reason' => 'task_not_leased',
                ];
            }

            /** @var WorkflowRun|null $run */
            $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->lockForUpdate()
                ->find($task->workflow_run_id);

            if ($run === null) {
                return [
                    'completed' => false,
                    'task_id' => $taskId,
                    'workflow_run_id' => $task->workflow_run_id,
                    'run_status' => null,
                    'reason' => 'run_not_found',
                ];
            }

            if ($run->status->isTerminal()) {
                $task->forceFill([
                    'status' => $run->status === RunStatus::Failed ? TaskStatus::Failed : TaskStatus::Completed,
                    'lease_expires_at' => null,
                ])->save();

                return [
                    'completed' => false,
                    'task_id' => $taskId,
                    'workflow_run_id' => $run->id,
                    'run_status' => $run->status->value,
                    'reason' => 'run_already_closed',
                ];
            }

            if ($terminal['type'] === 'complete_workflow') {
                $this->applyWorkflowCompletion($run, $task, $terminal);
            } else {
                $this->applyWorkflowFailure($run, $task, $terminal);
            }

            return [
                'completed' => true,
                'task_id' => $taskId,
                'workflow_run_id' => $run->id,
                'run_status' => $run->status->value,
                'reason' => null,
            ];
        });
    }

    /**
     * @param array{type: string, result?: string|null} $command
     */
    private function applyWorkflowCompletion(WorkflowRun $run, WorkflowTask $task, array $command): void
    {
        $result = $command['result'] ?? null;

        $run->forceFill([
            'status' => RunStatus::Completed,
            'closed_reason' => 'completed',
            'output' => $result,
            'closed_at' => now(),
            'last_progress_at' => now(),
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowCompleted, [
            'output' => $result,
        ], $task);

        $task->forceFill([
            'status' => TaskStatus::Completed,
            'lease_expires_at' => null,
        ])->save();

        $this->dispatchParentResumeTasksForRun($run);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
    }

    /**
     * @param array{type: string, message: string, exception_class?: string, exception_type?: string} $command
     */
    private function applyWorkflowFailure(WorkflowRun $run, WorkflowTask $task, array $command): void
    {
        $message = is_string($command['message'] ?? null) ? $command['message'] : 'External workflow task failed';
        $exceptionClass = is_string($command['exception_class'] ?? null) ? $command['exception_class'] : RuntimeException::class;
        $exceptionType = is_string($command['exception_type'] ?? null) ? $command['exception_type'] : null;

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()->create([
            'workflow_run_id' => $run->id,
            'source_kind' => 'workflow_run',
            'source_id' => $run->id,
            'propagation_kind' => 'terminal',
            'handled' => false,
            'exception_class' => $exceptionClass,
            'message' => $message,
            'file' => '',
            'line' => 0,
            'trace_preview' => '',
        ]);

        $run->forceFill([
            'status' => RunStatus::Failed,
            'closed_reason' => 'failed',
            'closed_at' => now(),
            'last_progress_at' => now(),
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowFailed, [
            'failure_id' => $failure->id,
            'source_kind' => 'workflow_run',
            'source_id' => $run->id,
            'exception_type' => $exceptionType,
            'exception_class' => $exceptionClass,
            'message' => $message,
            'exception' => [
                'class' => $exceptionClass,
                'type' => $exceptionType,
                'message' => $message,
                'code' => 0,
                'file' => '',
                'line' => 0,
                'trace' => [],
                'properties' => [],
            ],
        ], $task);

        $task->forceFill([
            'status' => TaskStatus::Failed,
            'lease_expires_at' => null,
        ])->save();

        $this->dispatchParentResumeTasksForRun($run);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
    }

    /**
     * Dispatch parent resume tasks when a child run closes through the bridge.
     */
    private function dispatchParentResumeTasksForRun(WorkflowRun $childRun): void
    {
        $parentLinks = WorkflowLink::query()
            ->where('child_workflow_run_id', $childRun->id)
            ->where('link_type', 'child_workflow')
            ->get();

        foreach ($parentLinks as $parentLink) {
            if (! is_string($parentLink->parent_workflow_run_id) || $parentLink->parent_workflow_run_id === '') {
                continue;
            }

            /** @var WorkflowRun|null $parentRun */
            $parentRun = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->find($parentLink->parent_workflow_run_id);

            if ($parentRun === null || $parentRun->status->isTerminal()) {
                continue;
            }

            $existingTask = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                ->where('workflow_run_id', $parentRun->id)
                ->where('task_type', TaskType::Workflow->value)
                ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
                ->first();

            if ($existingTask !== null) {
                continue;
            }

            WorkflowTask::query()->create([
                'workflow_run_id' => $parentRun->id,
                'task_type' => TaskType::Workflow->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => now(),
                'payload' => [],
                'connection' => $parentRun->connection,
                'queue' => $parentRun->queue,
                'compatibility' => $parentRun->compatibility,
            ]);
        }
    }

    /**
     * Extract the single terminal command from the command list.
     *
     * @param list<array{type: string, ...}> $commands
     * @return array{type: string, ...}|null
     */
    private static function extractTerminalCommand(array $commands): ?array
    {
        $terminal = null;

        foreach ($commands as $command) {
            if (! is_array($command) || ! is_string($command['type'] ?? null)) {
                continue;
            }

            if (in_array($command['type'], ['complete_workflow', 'fail_workflow'], true)) {
                if ($terminal !== null) {
                    return null; // Multiple terminal commands — reject.
                }
                $terminal = $command;
            }
        }

        return $terminal;
    }

    /**
     * Claim a task if it is still in Ready status.
     * Used internally by execute() to avoid double-claiming.
     */
    private function claimIfReady(string $taskId): bool
    {
        return DB::transaction(function () use ($taskId): bool {
            /** @var WorkflowTask|null $task */
            $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                ->lockForUpdate()
                ->find($taskId);

            if ($task === null || $task->task_type !== TaskType::Workflow) {
                return false;
            }

            // Already leased by a prior claim() call — allow execute() to proceed.
            if ($task->status === TaskStatus::Leased) {
                return true;
            }

            if ($task->status !== TaskStatus::Ready) {
                return false;
            }

            /** @var WorkflowRun $run */
            $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->findOrFail($task->workflow_run_id);

            TaskCompatibility::sync($task, $run);

            if (TaskBackendCapabilities::recordClaimFailureIfUnsupported($task) !== null) {
                RunSummaryProjector::project(
                    $run->fresh(['instance', 'tasks', 'activityExecutions', 'failures'])
                );

                return false;
            }

            if (! TaskCompatibility::supported($task, $run)) {
                RunSummaryProjector::project(
                    $run->fresh(['instance', 'tasks', 'activityExecutions', 'failures'])
                );

                return false;
            }

            $task->forceFill([
                'status' => TaskStatus::Leased,
                'leased_at' => now(),
                'lease_owner' => $taskId,
                'lease_expires_at' => now()->addMinutes(5),
                'attempt_count' => $task->attempt_count + 1,
                'last_claim_failed_at' => null,
                'last_claim_error' => null,
            ])->save();

            RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'failures'])
            );

            return true;
        });
    }

    /**
     * @return array{
     *     claimed: bool,
     *     task_id: string,
     *     workflow_run_id: string|null,
     *     workflow_instance_id: string|null,
     *     workflow_type: string|null,
     *     workflow_class: string|null,
     *     payload_codec: string|null,
     *     connection: string|null,
     *     queue: string|null,
     *     compatibility: string|null,
     *     lease_owner: string|null,
     *     lease_expires_at: string|null,
     *     reason: string|null,
     *     reason_detail: string|null,
     * }
     */
    private static function claimRejected(string $taskId, string $reason, string $reasonDetail): array
    {
        return [
            'claimed' => false,
            'task_id' => $taskId,
            'workflow_run_id' => null,
            'workflow_instance_id' => null,
            'workflow_type' => null,
            'workflow_class' => null,
            'payload_codec' => null,
            'connection' => null,
            'queue' => null,
            'compatibility' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'reason' => $reason,
            'reason_detail' => $reasonDetail,
        ];
    }

    /**
     * @param Throwable|array<string, mixed>|string $failure
     */
    private static function failureMessage(Throwable|array|string $failure): string
    {
        if ($failure instanceof Throwable) {
            return $failure->getMessage();
        }

        if (is_string($failure)) {
            return $failure;
        }

        return is_string($failure['message'] ?? null)
            ? $failure['message']
            : 'External workflow task failed';
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
