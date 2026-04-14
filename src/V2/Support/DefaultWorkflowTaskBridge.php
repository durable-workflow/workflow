<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
use Workflow\Serializers\CodecRegistry;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\LifecycleEventDispatcher;
use Workflow\V2\Support\WorkerProtocolVersion;

final class DefaultWorkflowTaskBridge implements WorkflowTaskBridge
{
    private const TERMINAL_TYPES = ['complete_workflow', 'fail_workflow', 'continue_as_new'];

    private const NON_TERMINAL_TYPES = [
        'schedule_activity',
        'start_timer',
        'start_child_workflow',
        'record_side_effect',
        'record_version_marker',
        'upsert_search_attributes',
    ];

    public function __construct(
        private readonly WorkflowExecutor $executor,
    ) {
    }

    public function poll(?string $connection, ?string $queue, int $limit = 1, ?string $compatibility = null, ?string $namespace = null): array
    {
        // Use a 1-second ceiling on the availability cutoff so that tasks created
        // in the same request tick are reliably surfaced across all backends,
        // including SQLite where timestamp precision can vary.
        $availabilityCutoff = now()->addSecond();

        $query = ConfiguredV2Models::query('task_model', WorkflowTask::class)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->where(static function ($q) use ($availabilityCutoff) {
                $q->whereNull('available_at')
                    ->orWhere('available_at', '<=', $availabilityCutoff);
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

        if ($namespace !== null) {
            $query->where('namespace', $namespace);
        }

        $tasks = $query->get();

        return $tasks->map(static function (WorkflowTask $task) {
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
        })->values()
            ->all();
    }

    public function claimStatus(string $taskId, ?string $leaseOwner = null): array
    {
        return DB::transaction(static function () use ($taskId, $leaseOwner): array {
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
                return self::claimRejected($taskId, 'run_closed', "The workflow run is {$run->status->value}.");
            }

            TaskCompatibility::sync($task, $run);

            if (TaskBackendCapabilities::recordClaimFailureIfUnsupported($task) !== null) {
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return self::claimRejected(
                    $taskId,
                    'backend_unavailable',
                    'The backend does not support the required capabilities.',
                );
            }

            if (! TaskCompatibility::supported($task, $run)) {
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                $mismatch = TaskCompatibility::mismatchReason($task, $run);

                return self::claimRejected(
                    $taskId,
                    'compatibility_blocked',
                    $mismatch ?? 'The task compatibility marker does not match this worker.',
                );
            }

            $resolvedLeaseOwner = $leaseOwner ?? $taskId;
            $leaseExpiresAt = now()
                ->addMinutes(5);

            $task->forceFill([
                'status' => TaskStatus::Leased,
                'leased_at' => now(),
                'lease_owner' => $resolvedLeaseOwner,
                'lease_expires_at' => $leaseExpiresAt,
                'attempt_count' => $task->attempt_count + 1,
                'last_claim_failed_at' => null,
                'last_claim_error' => null,
            ])->save();

            RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

            return [
                'claimed' => true,
                'task_id' => $task->id,
                'workflow_run_id' => $run->id,
                'workflow_instance_id' => $run->workflow_instance_id,
                'workflow_type' => self::nonEmptyString($run->workflow_type),
                'workflow_class' => self::nonEmptyString($run->workflow_class),
                'payload_codec' => $run->payload_codec ?? CodecRegistry::defaultCodec(),
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
            'payload_codec' => $run->payload_codec ?? CodecRegistry::defaultCodec(),
            'arguments' => self::nonEmptyString($run->arguments),
            'run_status' => $run->status->value,
            'last_history_sequence' => (int) ($run->last_history_sequence ?? 0),
            'history_events' => $historyEvents->map(static fn (WorkflowHistoryEvent $event) => [
                'id' => $event->id,
                'sequence' => (int) $event->sequence,
                'event_type' => $event->event_type->value,
                'payload' => is_array($event->payload) ? $event->payload : [],
                'workflow_task_id' => self::nonEmptyString($event->workflow_task_id),
                'workflow_command_id' => self::nonEmptyString($event->workflow_command_id),
                'recorded_at' => $event->recorded_at?->toJSON(),
            ])->values()
                ->all(),
        ];
    }

    public function historyPayloadPaginated(string $taskId, int $afterSequence = 0, int $pageSize = 200): ?array
    {
        $pageSize = max(1, min($pageSize, WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE));

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
            ->where('sequence', '>', $afterSequence)
            ->orderBy('sequence')
            ->limit($pageSize + 1)
            ->get();

        $hasMore = $historyEvents->count() > $pageSize;

        if ($hasMore) {
            $historyEvents = $historyEvents->take($pageSize);
        }

        $lastEventSequence = $historyEvents->isNotEmpty()
            ? (int) $historyEvents->last()->sequence
            : null;

        return [
            'task_id' => $task->id,
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_type' => self::nonEmptyString($run->workflow_type),
            'workflow_class' => self::nonEmptyString($run->workflow_class),
            'payload_codec' => $run->payload_codec ?? CodecRegistry::defaultCodec(),
            'arguments' => self::nonEmptyString($run->arguments),
            'run_status' => $run->status->value,
            'last_history_sequence' => (int) ($run->last_history_sequence ?? 0),
            'after_sequence' => $afterSequence,
            'page_size' => $pageSize,
            'has_more' => $hasMore,
            'next_after_sequence' => $hasMore ? $lastEventSequence : null,
            'history_events' => $historyEvents->map(static fn (WorkflowHistoryEvent $event) => [
                'id' => $event->id,
                'sequence' => (int) $event->sequence,
                'event_type' => $event->event_type->value,
                'payload' => is_array($event->payload) ? $event->payload : [],
                'workflow_task_id' => self::nonEmptyString($event->workflow_task_id),
                'workflow_command_id' => self::nonEmptyString($event->workflow_command_id),
                'recorded_at' => $event->recorded_at?->toJSON(),
            ])->values()
                ->all(),
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
            DB::transaction(static function () use ($taskId, $throwable): void {
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
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));
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
        return DB::transaction(static function () use ($taskId, $failure): array {
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

            RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

            return [
                'recorded' => true,
                'task_id' => $taskId,
                'reason' => null,
            ];
        });
    }

    public function heartbeat(string $taskId): array
    {
        return DB::transaction(static function () use ($taskId): array {
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

            $leaseExpiresAt = now()
                ->addMinutes(5);

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
        $parsed = self::parseCommands($commands);

        if ($parsed === null) {
            return [
                'completed' => false,
                'task_id' => $taskId,
                'workflow_run_id' => null,
                'run_status' => null,
                'created_task_ids' => [],
                'reason' => 'invalid_commands',
            ];
        }

        return DB::transaction(function () use ($taskId, $parsed): array {
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
                    'created_task_ids' => [],
                    'reason' => $task === null ? 'task_not_found' : 'task_not_workflow',
                ];
            }

            if ($task->status !== TaskStatus::Leased) {
                return [
                    'completed' => false,
                    'task_id' => $taskId,
                    'workflow_run_id' => $task->workflow_run_id,
                    'run_status' => null,
                    'created_task_ids' => [],
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
                    'created_task_ids' => [],
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
                    'created_task_ids' => [],
                    'reason' => 'run_already_closed',
                ];
            }

            $sequence = ($run->last_history_sequence ?? 0) + 1;
            $createdTaskIds = [];

            foreach ($parsed['non_terminal'] as $command) {
                $sequence = $this->applyNonTerminalCommand($run, $task, $command, $sequence, $createdTaskIds);
            }

            $run->forceFill([
                'last_history_sequence' => $sequence - 1,
            ])->save();

            $terminal = $parsed['terminal'];

            if ($terminal !== null) {
                if ($terminal['type'] === 'continue_as_new') {
                    $this->applyContinueAsNew($run, $task, $sequence, $terminal, $createdTaskIds);
                } elseif ($terminal['type'] === 'complete_workflow') {
                    $this->applyWorkflowCompletion($run, $task, $terminal);
                } else {
                    $this->applyWorkflowFailure($run, $task, $terminal);
                }
            } else {
                $this->markRunWaiting($run, $task);
            }

            return [
                'completed' => true,
                'task_id' => $taskId,
                'workflow_run_id' => $run->id,
                'run_status' => $run->status->value,
                'created_task_ids' => $createdTaskIds,
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

        LifecycleEventDispatcher::workflowCompleted($run);

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
        $exceptionClass = is_string(
            $command['exception_class'] ?? null
        ) ? $command['exception_class'] : RuntimeException::class;
        $exceptionType = is_string($command['exception_type'] ?? null) ? $command['exception_type'] : null;

        $failureCategory = FailureFactory::classifyFromStrings('terminal', 'workflow_run', $exceptionClass, $message);
        $nonRetryable = (bool) ($command['non_retryable'] ?? FailureFactory::isNonRetryableFromStrings($exceptionClass));

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()->create([
            'workflow_run_id' => $run->id,
            'source_kind' => 'workflow_run',
            'source_id' => $run->id,
            'propagation_kind' => 'terminal',
            'failure_category' => $failureCategory->value,
            'non_retryable' => $nonRetryable,
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
            'failure_category' => $failureCategory->value,
            'non_retryable' => $nonRetryable,
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

        LifecycleEventDispatcher::workflowFailed($run, $exceptionClass, $message);
        LifecycleEventDispatcher::failureRecorded(
            $run,
            (string) $failure->id,
            'workflow_run',
            (string) $run->id,
            $exceptionClass,
            $message,
        );

        $this->dispatchParentResumeTasksForRun($run);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
    }

    private function markRunWaiting(WorkflowRun $run, WorkflowTask $task): void
    {
        $run->forceFill([
            'status' => RunStatus::Waiting,
            'last_progress_at' => now(),
        ])->save();

        $task->forceFill([
            'status' => TaskStatus::Completed,
            'lease_expires_at' => null,
        ])->save();

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
    }

    /**
     * Apply a single non-terminal command and return the next sequence number.
     *
     * @param array{type: string, ...} $command
     * @param list<string> $createdTaskIds
     */
    private function applyNonTerminalCommand(
        WorkflowRun $run,
        WorkflowTask $task,
        array $command,
        int $sequence,
        array &$createdTaskIds,
    ): int {
        return match ($command['type']) {
            'schedule_activity' => $this->applyScheduleActivity($run, $task, $command, $sequence, $createdTaskIds),
            'start_timer' => $this->applyStartTimer($run, $task, $command, $sequence, $createdTaskIds),
            'start_child_workflow' => $this->applyStartChildWorkflow($run, $task, $command, $sequence, $createdTaskIds),
            'record_side_effect' => $this->applyRecordSideEffect($run, $task, $command, $sequence),
            'record_version_marker' => $this->applyRecordVersionMarker($run, $task, $command, $sequence),
            'upsert_search_attributes' => $this->applyUpsertSearchAttributes($run, $task, $command, $sequence),
            default => $sequence,
        };
    }

    /**
     * @param array{type: string, activity_type: string, arguments?: string|null, connection?: string|null, queue?: string|null} $command
     * @param list<string> $createdTaskIds
     */
    private function applyScheduleActivity(
        WorkflowRun $run,
        WorkflowTask $task,
        array $command,
        int $sequence,
        array &$createdTaskIds,
    ): int {
        $activityType = $command['activity_type'];
        $arguments = $command['arguments'] ?? null;
        $connection = $command['connection'] ?? $run->connection;
        $queue = $command['queue'] ?? $run->queue;

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'activity_class' => $activityType,
            'activity_type' => $activityType,
            'status' => ActivityStatus::Pending->value,
            'attempt_count' => 0,
            'arguments' => $arguments,
            'connection' => $connection,
            'queue' => $queue,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => $execution->id,
            'activity_class' => $activityType,
            'activity_type' => $activityType,
            'sequence' => $sequence,
        ], $task);

        /** @var WorkflowTask $activityTask */
        $activityTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now(),
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
            'connection' => $connection,
            'queue' => $queue,
            'compatibility' => $run->compatibility,
        ]);

        $createdTaskIds[] = $activityTask->id;

        return $sequence + 1;
    }

    /**
     * @param array{type: string, delay_seconds: int} $command
     * @param list<string> $createdTaskIds
     */
    private function applyStartTimer(WorkflowRun $run, WorkflowTask $task, array $command, int $sequence, array &$createdTaskIds): int
    {
        $delaySeconds = max(0, (int) ($command['delay_seconds'] ?? 0));
        $fireAt = now()
            ->addSeconds($delaySeconds);

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'status' => TimerStatus::Pending->value,
            'delay_seconds' => $delaySeconds,
            'fire_at' => $fireAt,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::TimerScheduled, [
            'timer_id' => $timer->id,
            'sequence' => $sequence,
            'delay_seconds' => $delaySeconds,
            'fire_at' => $fireAt->toJSON(),
        ], $task);

        /** @var WorkflowTask $timerTask */
        $timerTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Timer->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => $fireAt,
            'payload' => [
                'timer_id' => $timer->id,
            ],
            'connection' => $run->connection,
            'queue' => $run->queue,
            'compatibility' => $run->compatibility,
        ]);

        $createdTaskIds[] = $timerTask->id;

        return $sequence + 1;
    }

    /**
     * @param array{type: string, workflow_type: string, arguments?: string|null, connection?: string|null, queue?: string|null} $command
     * @param list<string> $createdTaskIds
     */
    private function applyStartChildWorkflow(
        WorkflowRun $run,
        WorkflowTask $task,
        array $command,
        int $sequence,
        array &$createdTaskIds,
    ): int {
        $workflowType = $command['workflow_type'];
        $arguments = $command['arguments'] ?? null;
        $connection = $command['connection'] ?? $run->connection;
        $queue = $command['queue'] ?? $run->queue;
        $now = now();

        /** @var WorkflowInstance $childInstance */
        $childInstance = WorkflowInstance::query()->create([
            'workflow_class' => $workflowType,
            'workflow_type' => $workflowType,
            'reserved_at' => $now,
            'started_at' => $now,
            'run_count' => 1,
        ]);

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 1,
            'workflow_class' => $workflowType,
            'workflow_type' => $workflowType,
            'status' => RunStatus::Pending->value,
            'compatibility' => $run->compatibility ?? WorkerCompatibility::current(),
            'payload_codec' => $run->payload_codec ?? CodecRegistry::defaultCodec(),
            'arguments' => $arguments,
            'connection' => $connection,
            'queue' => $queue,
            'started_at' => $now,
            'last_progress_at' => $now,
            'last_history_sequence' => 0,
        ]);

        $childInstance->forceFill([
            'current_run_id' => $childRun->id,
        ])->save();

        $childCallId = (string) Str::ulid();

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()->create([
            'id' => $childCallId,
            'link_type' => 'child_workflow',
            'sequence' => $sequence,
            'parent_workflow_instance_id' => $run->workflow_instance_id,
            'parent_workflow_run_id' => $run->id,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'is_primary_parent' => true,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ChildWorkflowScheduled, [
            'sequence' => $sequence,
            'workflow_link_id' => $link->id,
            'child_call_id' => $childCallId,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'child_workflow_class' => $workflowType,
            'child_workflow_type' => $workflowType,
        ], $task);

        WorkflowHistoryEvent::record($run, HistoryEventType::ChildRunStarted, [
            'sequence' => $sequence,
            'workflow_link_id' => $link->id,
            'child_call_id' => $childCallId,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'child_workflow_class' => $workflowType,
            'child_workflow_type' => $workflowType,
            'child_run_number' => 1,
        ], $task);

        WorkflowHistoryEvent::record($childRun, HistoryEventType::WorkflowStarted, [
            'workflow_class' => $workflowType,
            'workflow_type' => $workflowType,
            'workflow_instance_id' => $childRun->workflow_instance_id,
            'workflow_run_id' => $childRun->id,
            'parent_workflow_instance_id' => $run->workflow_instance_id,
            'parent_workflow_run_id' => $run->id,
            'parent_sequence' => $sequence,
            'workflow_link_id' => $link->id,
            'child_call_id' => $childCallId,
        ]);

        /** @var WorkflowTask $childTask */
        $childTask = WorkflowTask::query()->create([
            'workflow_run_id' => $childRun->id,
            'namespace' => $childRun->namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => $now,
            'payload' => [],
            'connection' => $connection,
            'queue' => $queue,
            'compatibility' => $childRun->compatibility,
        ]);

        $createdTaskIds[] = $childTask->id;

        RunSummaryProjector::project(
            $childRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        return $sequence + 1;
    }

    /**
     * @param array{type: string, result: string} $command
     */
    private function applyRecordSideEffect(
        WorkflowRun $run,
        WorkflowTask $task,
        array $command,
        int $sequence,
    ): int {
        WorkflowHistoryEvent::record($run, HistoryEventType::SideEffectRecorded, [
            'sequence' => $sequence,
            'result' => $command['result'],
        ], $task);

        return $sequence + 1;
    }

    /**
     * @param array{type: string, change_id: string, version: int, min_supported: int, max_supported: int} $command
     */
    private function applyRecordVersionMarker(
        WorkflowRun $run,
        WorkflowTask $task,
        array $command,
        int $sequence,
    ): int {
        WorkflowHistoryEvent::record($run, HistoryEventType::VersionMarkerRecorded, [
            'sequence' => $sequence,
            'change_id' => $command['change_id'],
            'version' => $command['version'],
            'min_supported' => $command['min_supported'],
            'max_supported' => $command['max_supported'],
        ], $task);

        return $sequence + 1;
    }

    /**
     * @param array{type: string, attributes: array<string, scalar|null>} $command
     */
    private function applyUpsertSearchAttributes(
        WorkflowRun $run,
        WorkflowTask $task,
        array $command,
        int $sequence,
    ): int {
        $existing = is_array($run->search_attributes) ? $run->search_attributes : [];
        $merged = $existing;

        foreach ($command['attributes'] as $key => $value) {
            if ($value === null) {
                unset($merged[$key]);

                continue;
            }

            $merged[$key] = $value;
        }

        ksort($merged);

        $run->forceFill([
            'search_attributes' => $merged,
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::SearchAttributesUpserted, [
            'sequence' => $sequence,
            'attributes' => $command['attributes'],
            'merged' => $merged,
        ], $task);

        return $sequence + 1;
    }

    /**
     * @param array{type: string, arguments?: string|null, workflow_type?: string|null} $command
     * @param list<string> $createdTaskIds
     */
    private function applyContinueAsNew(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        array $command,
        array &$createdTaskIds = [],
    ): void {
        $now = now();
        $arguments = $command['arguments'] ?? $run->arguments;
        $workflowType = is_string($command['workflow_type'] ?? null) ? $command['workflow_type'] : $run->workflow_type;

        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()
            ->lockForUpdate()
            ->findOrFail($run->workflow_instance_id);

        /** @var WorkflowRun $continuedRun */
        $continuedRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $run->workflow_instance_id,
            'run_number' => $run->run_number + 1,
            'workflow_class' => $workflowType,
            'workflow_type' => $workflowType,
            'business_key' => $run->business_key,
            'visibility_labels' => $run->visibility_labels,
            'memo' => $run->memo,
            'search_attributes' => $run->search_attributes,
            'status' => RunStatus::Pending->value,
            'compatibility' => $run->compatibility,
            'payload_codec' => $run->payload_codec,
            'arguments' => $arguments,
            'connection' => $run->connection,
            'queue' => $run->queue,
            'started_at' => $now,
            'last_progress_at' => $now,
            'last_history_sequence' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $continuedRun->id,
            'run_count' => $continuedRun->run_number,
            'workflow_class' => $workflowType,
        ])->save();

        MessageStreamCursor::transferCursor($run, $continuedRun);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()->create([
            'link_type' => 'continue_as_new',
            'sequence' => $sequence,
            'parent_workflow_instance_id' => $run->workflow_instance_id,
            'parent_workflow_run_id' => $run->id,
            'child_workflow_instance_id' => $continuedRun->workflow_instance_id,
            'child_workflow_run_id' => $continuedRun->id,
            'is_primary_parent' => true,
        ]);

        $run->forceFill([
            'status' => RunStatus::Completed,
            'closed_reason' => 'continued',
            'closed_at' => $now,
            'last_progress_at' => $now,
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowContinuedAsNew, [
            'sequence' => $sequence,
            'continued_to_run_id' => $continuedRun->id,
            'continued_to_run_number' => $continuedRun->run_number,
            'workflow_link_id' => $link->id,
            'closed_reason' => 'continued',
        ], $task);

        WorkflowHistoryEvent::record($continuedRun, HistoryEventType::WorkflowStarted, [
            'workflow_class' => $workflowType,
            'workflow_type' => $workflowType,
            'workflow_instance_id' => $continuedRun->workflow_instance_id,
            'workflow_run_id' => $continuedRun->id,
            'continued_from_run_id' => $run->id,
            'workflow_link_id' => $link->id,
        ]);

        /** @var WorkflowTask $continuedTask */
        $continuedTask = WorkflowTask::query()->create([
            'workflow_run_id' => $continuedRun->id,
            'namespace' => $continuedRun->namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => $now,
            'payload' => [],
            'connection' => $continuedRun->connection,
            'queue' => $continuedRun->queue,
            'compatibility' => $continuedRun->compatibility,
        ]);

        $createdTaskIds[] = $continuedTask->id;

        $task->forceFill([
            'status' => TaskStatus::Completed,
            'lease_expires_at' => null,
        ])->save();

        LifecycleEventDispatcher::workflowStarted($continuedRun);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        RunSummaryProjector::project(
            $continuedRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
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
                'namespace' => $parentRun->namespace,
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
     * Parse the command list into non-terminal and terminal commands.
     *
     * Returns null when the command list is invalid: empty, contains multiple
     * terminal commands, or contains only unrecognized command types.
     *
     * @param list<array{type: string, ...}> $commands
     * @return array{non_terminal: list<array{type: string, ...}>, terminal: array{type: string, ...}|null}|null
     */
    private static function parseCommands(array $commands): ?array
    {
        $nonTerminal = [];
        $terminal = null;

        foreach ($commands as $command) {
            if (! is_array($command)) {
                return null;
            }

            $command = self::normalizeCommand($command);

            if ($command === null) {
                return null;
            }

            if (in_array($command['type'], self::TERMINAL_TYPES, true)) {
                if ($terminal !== null) {
                    return null; // Multiple terminal commands — reject.
                }
                $terminal = $command;
            } elseif (in_array($command['type'], self::NON_TERMINAL_TYPES, true)) {
                $nonTerminal[] = $command;
            }
        }

        if ($terminal === null && $nonTerminal === []) {
            return null; // No recognized commands at all.
        }

        return [
            'non_terminal' => $nonTerminal,
            'terminal' => $terminal,
        ];
    }

    /**
     * @param array<string, mixed> $command
     * @return array{type: string, ...}|null
     */
    private static function normalizeCommand(array $command): ?array
    {
        $type = is_string($command['type'] ?? null) ? $command['type'] : null;

        if ($type === null) {
            return null;
        }

        return match ($type) {
            'complete_workflow' => [
                'type' => $type,
                'result' => $command['result'] ?? null,
            ],
            'fail_workflow' => self::normalizeFailWorkflowCommand($command),
            'schedule_activity' => self::normalizeScheduleActivityCommand($command),
            'start_timer' => self::normalizeStartTimerCommand($command),
            'start_child_workflow' => self::normalizeStartChildWorkflowCommand($command),
            'continue_as_new' => self::normalizeContinueAsNewCommand($command),
            'record_side_effect' => self::normalizeRecordSideEffectCommand($command),
            'record_version_marker' => self::normalizeRecordVersionMarkerCommand($command),
            'upsert_search_attributes' => self::normalizeUpsertSearchAttributesCommand($command),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $command
     * @return array{type: string, message: string, exception_class?: string, exception_type?: string}|null
     */
    private static function normalizeFailWorkflowCommand(array $command): ?array
    {
        $message = self::normalizeRequiredString($command['message'] ?? null);

        if ($message === null) {
            return null;
        }

        return array_filter([
            'type' => 'fail_workflow',
            'message' => $message,
            'exception_class' => self::normalizeOptionalString($command['exception_class'] ?? null),
            'exception_type' => self::normalizeOptionalString($command['exception_type'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $command
     * @return array{type: string, activity_type: string, arguments?: string|null, connection?: string|null, queue?: string|null}|null
     */
    private static function normalizeScheduleActivityCommand(array $command): ?array
    {
        $activityType = self::normalizeRequiredString($command['activity_type'] ?? null);

        if ($activityType === null) {
            return null;
        }

        return array_filter([
            'type' => 'schedule_activity',
            'activity_type' => $activityType,
            'arguments' => self::normalizeNullableString($command['arguments'] ?? null),
            'connection' => self::normalizeOptionalString($command['connection'] ?? null),
            'queue' => self::normalizeOptionalString($command['queue'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $command
     * @return array{type: string, delay_seconds: int}|null
     */
    private static function normalizeStartTimerCommand(array $command): ?array
    {
        if (! is_int($command['delay_seconds'] ?? null) || (int) $command['delay_seconds'] < 0) {
            return null;
        }

        return [
            'type' => 'start_timer',
            'delay_seconds' => (int) $command['delay_seconds'],
        ];
    }

    /**
     * @param array<string, mixed> $command
     * @return array{type: string, workflow_type: string, arguments?: string|null, connection?: string|null, queue?: string|null}|null
     */
    private static function normalizeStartChildWorkflowCommand(array $command): ?array
    {
        $workflowType = self::normalizeRequiredString($command['workflow_type'] ?? null);

        if ($workflowType === null) {
            return null;
        }

        return array_filter([
            'type' => 'start_child_workflow',
            'workflow_type' => $workflowType,
            'arguments' => self::normalizeNullableString($command['arguments'] ?? null),
            'connection' => self::normalizeOptionalString($command['connection'] ?? null),
            'queue' => self::normalizeOptionalString($command['queue'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $command
     * @return array{type: string, arguments?: string|null, workflow_type?: string}|null
     */
    private static function normalizeContinueAsNewCommand(array $command): ?array
    {
        $workflowType = self::normalizeOptionalString($command['workflow_type'] ?? null);
        $arguments = self::normalizeNullableString($command['arguments'] ?? null);

        if (($command['workflow_type'] ?? null) !== null && $workflowType === null) {
            return null;
        }

        if (($command['arguments'] ?? null) !== null && $arguments === null) {
            return null;
        }

        return array_filter([
            'type' => 'continue_as_new',
            'arguments' => $arguments,
            'workflow_type' => $workflowType,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $command
     * @return array{type: string, result: string}|null
     */
    private static function normalizeRecordSideEffectCommand(array $command): ?array
    {
        $result = self::normalizeRequiredString($command['result'] ?? null);

        if ($result === null) {
            return null;
        }

        return [
            'type' => 'record_side_effect',
            'result' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $command
     * @return array{type: string, change_id: string, version: int, min_supported: int, max_supported: int}|null
     */
    private static function normalizeRecordVersionMarkerCommand(array $command): ?array
    {
        $changeId = self::normalizeRequiredString($command['change_id'] ?? null);

        if ($changeId === null) {
            return null;
        }

        if (! is_int($command['version'] ?? null)) {
            return null;
        }

        if (! is_int($command['min_supported'] ?? null)) {
            return null;
        }

        if (! is_int($command['max_supported'] ?? null)) {
            return null;
        }

        try {
            $versionCall = new VersionCall(
                $changeId,
                (int) $command['min_supported'],
                (int) $command['max_supported'],
            );
        } catch (LogicException) {
            return null;
        }

        $version = (int) $command['version'];

        if ($version < $versionCall->minSupported || $version > $versionCall->maxSupported) {
            return null;
        }

        return [
            'type' => 'record_version_marker',
            'change_id' => $versionCall->changeId,
            'version' => $version,
            'min_supported' => $versionCall->minSupported,
            'max_supported' => $versionCall->maxSupported,
        ];
    }

    /**
     * @param array<string, mixed> $command
     * @return array{type: string, attributes: array<string, scalar|null>}|null
     */
    private static function normalizeUpsertSearchAttributesCommand(array $command): ?array
    {
        if (! is_array($command['attributes'] ?? null)) {
            return null;
        }

        try {
            $call = new UpsertSearchAttributesCall($command['attributes']);
        } catch (LogicException) {
            return null;
        }

        return [
            'type' => 'upsert_search_attributes',
            'attributes' => $call->attributes,
        ];
    }

    private static function normalizeRequiredString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function normalizeOptionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::normalizeRequiredString($value);
    }

    private static function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        return $value;
    }

    /**
     * Claim a task if it is still in Ready status.
     * Used internally by execute() to avoid double-claiming.
     */
    private function claimIfReady(string $taskId): bool
    {
        return DB::transaction(static function () use ($taskId): bool {
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
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return false;
            }

            if (! TaskCompatibility::supported($task, $run)) {
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return false;
            }

            $task->forceFill([
                'status' => TaskStatus::Leased,
                'leased_at' => now(),
                'lease_owner' => $taskId,
                'lease_expires_at' => now()
                    ->addMinutes(5),
                'attempt_count' => $task->attempt_count + 1,
                'last_claim_failed_at' => null,
                'last_claim_error' => null,
            ])->save();

            RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

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
