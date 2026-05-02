<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\ChildCallStatus;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\ParentClosePolicy;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;

final class DefaultWorkflowTaskBridge implements WorkflowTaskBridge
{
    public const POLL_BATCH_CAP = 100;

    public const AVAILABILITY_CEILING_SECONDS = 1;

    public const WORKFLOW_TASK_LEASE_SECONDS = 300;

    /**
     * Relations the history-projection role consumes for a workflow-bridge
     * projection. Hydrated by {@see self::projectRun()} before the role is
     * invoked so the role implementation always sees an up-to-date graph,
     * regardless of which call path queued the projection.
     *
     * @var list<string>
     */
    private const PROJECTION_RUN_RELATIONS = [
        'instance',
        'tasks',
        'activityExecutions',
        'failures',
    ];

    /**
     * Projection relations for call sites that also need to see active timer
     * rows on the run (e.g. the post-execute terminal-status projection that
     * cancels still-armed timers).
     *
     * @var list<string>
     */
    private const PROJECTION_RUN_RELATIONS_WITH_TIMERS = [
        'instance',
        'tasks',
        'activityExecutions',
        'timers',
        'failures',
    ];

    /**
     * Projection relations for call sites that also need the full history
     * stream (terminal-write paths and command-application paths that close
     * out a workflow turn).
     *
     * @var list<string>
     */
    private const PROJECTION_RUN_RELATIONS_WITH_HISTORY = [
        'instance',
        'tasks',
        'activityExecutions',
        'timers',
        'failures',
        'historyEvents',
    ];

    /**
     * Projection relations for the parent-resume rebuild path, which also
     * needs the parent's child-link graph hydrated so the projection sees
     * the same shape the parent will read on its next workflow task.
     *
     * @var list<string>
     */
    private const PROJECTION_RUN_RELATIONS_WITH_CHILDREN = [
        'instance',
        'tasks',
        'activityExecutions',
        'timers',
        'failures',
        'historyEvents',
        'childLinks.childRun.instance.currentRun',
        'childLinks.childRun.failures',
        'childLinks.childRun.historyEvents',
    ];

    private const TERMINAL_TYPES = ['complete_workflow', 'fail_workflow', 'continue_as_new'];

    private const NON_TERMINAL_TYPES = [
        'schedule_activity',
        'start_timer',
        'start_child_workflow',
        'complete_update',
        'fail_update',
        'record_side_effect',
        'record_version_marker',
        'upsert_search_attributes',
        'open_condition_wait',
    ];

    public function __construct(
        private readonly WorkflowExecutor $executor,
    ) {
    }

    public function poll(
        ?string $connection,
        ?string $queue,
        int $limit = 1,
        ?string $compatibility = null,
        ?string $namespace = null,
        array $workflowTypes = []
    ): array {
        $requestedWorkflowTypes = self::nonEmptyStrings($workflowTypes);

        if ($workflowTypes !== [] && $requestedWorkflowTypes === []) {
            return [];
        }

        // The availability ceiling is a deliberate cross-backend tolerance so tasks
        // created in the same request tick are reliably surfaced on backends with
        // sub-second timestamp drift (notably SQLite). It is part of the matching
        // contract; tightening it would silently de-list freshly-available tasks.
        $availabilityCutoff = now()
            ->addSeconds(self::AVAILABILITY_CEILING_SECONDS);

        $query = ConfiguredV2Models::query('task_model', WorkflowTask::class)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->where(static function ($q) use ($availabilityCutoff) {
                $q->whereNull('available_at')
                    ->orWhere('available_at', '<=', $availabilityCutoff);
            })
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit(max(1, min($limit, self::POLL_BATCH_CAP)));

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

        if ($requestedWorkflowTypes !== []) {
            $query->whereIn(
                'workflow_run_id',
                ConfiguredV2Models::query('run_model', WorkflowRun::class)
                    ->select('id')
                    ->whereIn('workflow_type', $requestedWorkflowTypes),
            );
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
                self::projectRun($run, self::PROJECTION_RUN_RELATIONS);

                return self::claimRejected(
                    $taskId,
                    'backend_unavailable',
                    'The backend does not support the required capabilities.',
                );
            }

            if (! TaskCompatibility::supported($task, $run)) {
                self::projectRun($run, self::PROJECTION_RUN_RELATIONS);

                $mismatch = TaskCompatibility::mismatchReason($task, $run);

                return self::claimRejected(
                    $taskId,
                    'compatibility_blocked',
                    $mismatch ?? 'The task compatibility marker does not match this worker.',
                );
            }

            $resolvedLeaseOwner = $leaseOwner ?? $taskId;
            $leaseExpiresAt = now()
                ->addSeconds(self::WORKFLOW_TASK_LEASE_SECONDS);

            $task->forceFill([
                'status' => TaskStatus::Leased,
                'leased_at' => now(),
                'lease_owner' => $resolvedLeaseOwner,
                'lease_expires_at' => $leaseExpiresAt,
                'attempt_count' => $task->attempt_count + 1,
                'last_claim_failed_at' => null,
                'last_claim_error' => null,
            ])->save();

            self::projectRun($run, self::PROJECTION_RUN_RELATIONS);

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

    public function historyPayloadPaginated(
        string $taskId,
        int $afterSequence = 0,
        int $pageSize = WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE,
    ): ?array {
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
            ? (int) $historyEvents->last()
->sequence
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

                    self::projectRun($run, self::PROJECTION_RUN_RELATIONS_WITH_TIMERS);

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
                self::projectRun($run, self::PROJECTION_RUN_RELATIONS);
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

    public function fail(string $taskId, Throwable|array|string $failure, ?string $codec = null): array
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
                    'next_task_id' => null,
                ];
            }

            if ($task->task_type !== TaskType::Workflow) {
                return [
                    'recorded' => false,
                    'task_id' => $taskId,
                    'reason' => 'task_not_workflow',
                    'next_task_id' => null,
                ];
            }

            if (! in_array($task->status, [TaskStatus::Ready, TaskStatus::Leased], true)) {
                return [
                    'recorded' => false,
                    'task_id' => $taskId,
                    'reason' => 'task_not_active',
                    'next_task_id' => null,
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

            self::projectRun($run, self::PROJECTION_RUN_RELATIONS);

            return [
                'recorded' => true,
                'task_id' => $taskId,
                'reason' => null,
                'next_task_id' => null,
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
                    'run_closed_reason' => null,
                    'run_closed_at' => null,
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
                    'run_closed_reason' => null,
                    'run_closed_at' => null,
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
                    'run_closed_reason' => $run->closed_reason,
                    'run_closed_at' => $run->closed_at?->toJSON(),
                    'task_status' => $task->status->value,
                    'reason' => 'run_closed',
                ];
            }

            $leaseExpiresAt = now()
                ->addSeconds(self::WORKFLOW_TASK_LEASE_SECONDS);

            $task->forceFill([
                'lease_expires_at' => $leaseExpiresAt,
            ])->save();

            return [
                'renewed' => true,
                'task_id' => $taskId,
                'lease_expires_at' => $leaseExpiresAt->toJSON(),
                'run_status' => $run?->status?->value,
                'run_closed_reason' => $run?->closed_reason,
                'run_closed_at' => $run?->closed_at?->toJSON(),
                'task_status' => $task->status->value,
                'reason' => null,
            ];
        });
    }

    public function status(string $taskId): array
    {
        /** @var WorkflowTask|null $task */
        $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
            ->find($taskId);

        if ($task === null) {
            return [
                'task_id' => $taskId,
                'task_status' => null,
                'run_status' => null,
                'run_closed_reason' => null,
                'run_closed_at' => null,
                'workflow_run_id' => null,
                'workflow_instance_id' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'lease_expired' => false,
                'attempt_count' => null,
                'reason' => 'task_not_found',
            ];
        }

        if ($task->task_type !== TaskType::Workflow) {
            return [
                'task_id' => $taskId,
                'task_status' => $task->status?->value,
                'run_status' => null,
                'run_closed_reason' => null,
                'run_closed_at' => null,
                'workflow_run_id' => $task->workflow_run_id,
                'workflow_instance_id' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'lease_expired' => false,
                'attempt_count' => null,
                'reason' => 'task_not_workflow',
            ];
        }

        $leaseOwner = is_string($task->lease_owner) && trim($task->lease_owner) !== ''
            ? trim($task->lease_owner)
            : null;
        $leaseExpiresAt = $task->lease_expires_at;
        $leaseExpired = $leaseExpiresAt !== null && $leaseExpiresAt->lte(now());

        /** @var WorkflowRun|null $run */
        $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
            ->find($task->workflow_run_id);

        $attemptCount = is_int($task->attempt_count) && $task->attempt_count > 0
            ? (int) $task->attempt_count
            : null;

        return [
            'task_id' => $taskId,
            'task_status' => $task->status?->value,
            'run_status' => $run?->status?->value,
            'run_closed_reason' => $run?->closed_reason,
            'run_closed_at' => $run?->closed_at?->toJSON(),
            'workflow_run_id' => $task->workflow_run_id,
            'workflow_instance_id' => $run?->workflow_instance_id,
            'lease_owner' => $leaseOwner,
            'lease_expires_at' => $leaseExpiresAt?->toJSON(),
            'lease_expired' => $leaseExpired,
            'attempt_count' => $attemptCount,
            'reason' => null,
        ];
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
            $invalidUpdateCommands = $this->validateUpdateCommands($run, $task, $parsed['non_terminal']);

            if ($invalidUpdateCommands !== null) {
                return [
                    'completed' => false,
                    'task_id' => $taskId,
                    'workflow_run_id' => $run->id,
                    'run_status' => $run->status->value,
                    'created_task_ids' => [],
                    'reason' => $invalidUpdateCommands,
                ];
            }

            $this->recordSatisfiedConditionWaitForSignalResume($run, $task, $parsed['non_terminal']);

            foreach ($parsed['non_terminal'] as $command) {
                $sequence = $this->applyNonTerminalCommand($run, $task, $command, $sequence, $createdTaskIds);
            }

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

        self::projectRun($run, self::PROJECTION_RUN_RELATIONS_WITH_HISTORY);
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
        $nonRetryable = (bool) ($command['non_retryable'] ?? FailureFactory::isNonRetryableFromStrings(
            $exceptionClass
        ));

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

        self::projectRun($run, self::PROJECTION_RUN_RELATIONS_WITH_HISTORY);
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

        self::projectRun($run, self::PROJECTION_RUN_RELATIONS_WITH_HISTORY);
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
            'complete_update' => $this->applyCompleteUpdate($run, $task, $command, $sequence),
            'fail_update' => $this->applyFailUpdate($run, $task, $command, $sequence),
            'record_side_effect' => $this->applyRecordSideEffect($run, $task, $command, $sequence),
            'record_version_marker' => $this->applyRecordVersionMarker($run, $task, $command, $sequence),
            'upsert_search_attributes' => $this->applyUpsertSearchAttributes($run, $task, $command, $sequence),
            'open_condition_wait' => $this->applyOpenConditionWait($run, $task, $command, $sequence, $createdTaskIds),
            default => $sequence,
        };
    }

    /**
     * @param list<array{type: string, ...}> $commands
     */
    private function validateUpdateCommands(WorkflowRun $run, WorkflowTask $task, array $commands): ?string
    {
        $taskPayload = is_array($task->payload) ? $task->payload : [];
        $taskUpdateId = self::nonEmptyString($taskPayload['workflow_update_id'] ?? null);
        $seen = [];

        foreach ($commands as $command) {
            if (! in_array($command['type'] ?? null, ['complete_update', 'fail_update'], true)) {
                continue;
            }

            $updateId = self::nonEmptyString($command['update_id'] ?? null);

            if ($updateId === null || isset($seen[$updateId])) {
                return 'invalid_commands';
            }

            if ($taskUpdateId !== null && $taskUpdateId !== $updateId) {
                return 'invalid_commands';
            }

            /** @var WorkflowUpdate|null $update */
            $update = ConfiguredV2Models::query('update_model', WorkflowUpdate::class)
                ->lockForUpdate()
                ->whereKey($updateId)
                ->where('workflow_run_id', $run->id)
                ->first();

            if (! $update instanceof WorkflowUpdate || $update->status !== UpdateStatus::Accepted) {
                return 'invalid_commands';
            }

            $seen[$updateId] = true;
        }

        return null;
    }

    /**
     * External SDKs resolve condition waits by replaying signal history, then
     * either re-opening the wait or advancing to the next command. When a signal
     * resume advances, make that resolution explicit in history for replay and
     * Waterline instead of leaving only SignalReceived as an implicit cue.
     *
     * @param list<array{type: string, ...}> $nonTerminalCommands
     */
    private function recordSatisfiedConditionWaitForSignalResume(
        WorkflowRun $run,
        WorkflowTask $task,
        array $nonTerminalCommands,
    ): void {
        $taskPayload = is_array($task->payload) ? $task->payload : [];

        if (($taskPayload['resume_source_kind'] ?? null) !== 'workflow_signal') {
            return;
        }

        foreach ($nonTerminalCommands as $command) {
            if (($command['type'] ?? null) === 'open_condition_wait') {
                return;
            }
        }

        $wait = $this->latestOpenConditionWait($run);

        if ($wait === null) {
            return;
        }

        WorkflowHistoryEvent::record($run, HistoryEventType::ConditionWaitSatisfied, array_filter([
            'condition_wait_id' => $wait['condition_wait_id'],
            'condition_key' => $wait['condition_key'],
            'condition_definition_fingerprint' => $wait['condition_definition_fingerprint'],
            'sequence' => $wait['sequence'],
            'timer_id' => $wait['timer_id'],
            'timeout_seconds' => $wait['timeout_seconds'],
            'workflow_signal_id' => self::nonEmptyString($taskPayload['workflow_signal_id'] ?? null),
            'signal_name' => self::nonEmptyString($taskPayload['signal_name'] ?? null),
            'signal_wait_id' => self::nonEmptyString($taskPayload['signal_wait_id'] ?? null),
        ], static fn (mixed $value): bool => $value !== null), $task);

        $this->cancelOpenConditionTimer($run, $task, $wait);
    }

    /**
     * @return array{
     *     condition_wait_id: string,
     *     condition_key: string|null,
     *     condition_definition_fingerprint: string|null,
     *     sequence: int|null,
     *     status: string,
     *     source_status: string,
     *     timeout_seconds: int|null,
     *     timer_id: string|null
     * }|null
     */
    private function latestOpenConditionWait(WorkflowRun $run): ?array
    {
        $open = array_values(array_filter(
            ConditionWaits::forRun($run),
            static fn (array $wait): bool => ($wait['status'] ?? null) === 'open'
                && ($wait['source_status'] ?? null) !== 'timeout_fired'
                && self::nonEmptyString($wait['condition_wait_id'] ?? null) !== null,
        ));

        if ($open === []) {
            return null;
        }

        usort(
            $open,
            static fn (array $left, array $right): int => ((int) ($left['sequence'] ?? 0))
                <=> ((int) ($right['sequence'] ?? 0)),
        );

        /** @var array{
         *     condition_wait_id: string,
         *     condition_key: string|null,
         *     condition_definition_fingerprint: string|null,
         *     sequence: int|null,
         *     status: string,
         *     source_status: string,
         *     timeout_seconds: int|null,
         *     timer_id: string|null
         * } $wait
         */
        $wait = end($open);

        return $wait;
    }

    /**
     * @param array{timer_id: string|null} $wait
     */
    private function cancelOpenConditionTimer(WorkflowRun $run, WorkflowTask $task, array $wait): void
    {
        $timerId = self::nonEmptyString($wait['timer_id'] ?? null);

        if ($timerId === null) {
            return;
        }

        /** @var WorkflowTimer|null $timer */
        $timer = ConfiguredV2Models::query('timer_model', WorkflowTimer::class)
            ->whereKey($timerId)
            ->where('workflow_run_id', $run->id)
            ->first();

        if (! $timer instanceof WorkflowTimer || $timer->status !== TimerStatus::Pending) {
            return;
        }

        $timer->forceFill([
            'status' => TimerStatus::Cancelled,
        ])->save();

        TimerCancellation::record($run, $timer, $task);

        ConfiguredV2Models::query('task_model', WorkflowTask::class)
            ->where('workflow_run_id', $run->id)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->where('payload->timer_id', $timer->id)
            ->get()
            ->each(static function (WorkflowTask $timerTask): void {
                $timerTask->forceFill([
                    'status' => TaskStatus::Cancelled,
                    'lease_expires_at' => null,
                    'last_error' => null,
                ])->save();
            });
    }

    /**
     * @param array{type: string, update_id: string, result?: string|null} $command
     */
    private function applyCompleteUpdate(
        WorkflowRun $run,
        WorkflowTask $task,
        array $command,
        int $sequence,
    ): int {
        /** @var WorkflowUpdate $update */
        $update = ConfiguredV2Models::query('update_model', WorkflowUpdate::class)
            ->lockForUpdate()
            ->whereKey($command['update_id'])
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();

        /** @var WorkflowCommand|null $workflowCommand */
        $workflowCommand = $update->workflow_command_id === null
            ? null
            : ConfiguredV2Models::query('command_model', WorkflowCommand::class)
                ->whereKey($update->workflow_command_id)
                ->first();

        $result = $command['result'] ?? null;
        $now = now();

        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateApplied, [
            'workflow_command_id' => $workflowCommand?->id,
            'update_id' => $update->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'update_name' => $update->update_name,
            'arguments' => $update->arguments,
            'sequence' => $sequence,
        ], $task, $workflowCommand);

        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateCompleted, [
            'workflow_command_id' => $workflowCommand?->id,
            'update_id' => $update->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'update_name' => $update->update_name,
            'sequence' => $sequence,
            'result' => $result,
        ], $task, $workflowCommand);

        $update->forceFill([
            'workflow_sequence' => $sequence,
            'status' => UpdateStatus::Completed->value,
            'outcome' => CommandOutcome::UpdateCompleted->value,
            'result' => $result,
            'applied_at' => $now,
            'closed_at' => $now,
        ])->save();

        if ($workflowCommand instanceof WorkflowCommand) {
            $workflowCommand->forceFill([
                'outcome' => CommandOutcome::UpdateCompleted->value,
                'applied_at' => $now,
            ])->save();

            if ($workflowCommand->message_sequence !== null) {
                MessageStreamCursor::advanceCursor($run, (int) $workflowCommand->message_sequence, $task);
            }
        }

        return $sequence + 1;
    }

    /**
     * @param array{
     *     type: string,
     *     update_id: string,
     *     message: string,
     *     exception_class?: string,
     *     exception_type?: string,
     *     non_retryable?: bool
     * } $command
     */
    private function applyFailUpdate(WorkflowRun $run, WorkflowTask $task, array $command, int $sequence): int
    {
        /** @var WorkflowUpdate $update */
        $update = ConfiguredV2Models::query('update_model', WorkflowUpdate::class)
            ->lockForUpdate()
            ->whereKey($command['update_id'])
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();

        /** @var WorkflowCommand|null $workflowCommand */
        $workflowCommand = $update->workflow_command_id === null
            ? null
            : ConfiguredV2Models::query('command_model', WorkflowCommand::class)
                ->whereKey($update->workflow_command_id)
                ->first();

        $message = self::normalizeRequiredString($command['message'] ?? null) ?? 'External update failed';
        $exceptionClass = self::normalizeOptionalString($command['exception_class'] ?? null) ?? RuntimeException::class;
        $exceptionType = self::normalizeOptionalString($command['exception_type'] ?? null);
        $failureCategory = FailureFactory::classifyFromStrings('update', 'workflow_command', $exceptionClass, $message);
        $nonRetryable = (bool) ($command['non_retryable'] ?? FailureFactory::isNonRetryableFromStrings(
            $exceptionClass
        ));

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()->create([
            'workflow_run_id' => $run->id,
            'source_kind' => $workflowCommand instanceof WorkflowCommand ? 'workflow_command' : 'workflow_update',
            'source_id' => $workflowCommand?->id ?? $update->id,
            'propagation_kind' => 'update',
            'failure_category' => $failureCategory->value,
            'non_retryable' => $nonRetryable,
            'handled' => false,
            'exception_class' => $exceptionClass,
            'message' => $message,
            'file' => '',
            'line' => 0,
            'trace_preview' => '',
        ]);

        $exceptionPayload = [
            'class' => $exceptionClass,
            'type' => $exceptionType,
            'message' => $message,
            'code' => 0,
            'file' => '',
            'line' => 0,
            'trace' => [],
            'properties' => [],
        ];

        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateCompleted, [
            'workflow_command_id' => $workflowCommand?->id,
            'update_id' => $update->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'update_name' => $update->update_name,
            'sequence' => $sequence,
            'failure_id' => $failure->id,
            'failure_category' => $failureCategory->value,
            'non_retryable' => $nonRetryable,
            'exception_type' => $exceptionType,
            'exception_class' => $exceptionClass,
            'message' => $message,
            'code' => 0,
            'exception' => $exceptionPayload,
        ], $task, $workflowCommand);

        $now = now();

        $update->forceFill([
            'workflow_sequence' => $sequence,
            'status' => UpdateStatus::Failed->value,
            'outcome' => CommandOutcome::UpdateFailed->value,
            'failure_id' => $failure->id,
            'failure_message' => $message,
            'applied_at' => $now,
            'closed_at' => $now,
        ])->save();

        if ($workflowCommand instanceof WorkflowCommand) {
            $workflowCommand->forceFill([
                'outcome' => CommandOutcome::UpdateFailed->value,
                'applied_at' => $now,
            ])->save();

            if ($workflowCommand->message_sequence !== null) {
                MessageStreamCursor::advanceCursor($run, (int) $workflowCommand->message_sequence, $task);
            }
        }

        return $sequence + 1;
    }

    /**
     * @param array<string, mixed> $command
     */
    private static function activityOptionsFromCommand(array $command): ?ActivityOptions
    {
        $retryPolicy = is_array($command['retry_policy'] ?? null) ? $command['retry_policy'] : [];
        $hasOptions = $retryPolicy !== []
            || array_key_exists('start_to_close_timeout', $command)
            || array_key_exists('schedule_to_start_timeout', $command)
            || array_key_exists('schedule_to_close_timeout', $command)
            || array_key_exists('heartbeat_timeout', $command);

        if (! $hasOptions) {
            return null;
        }

        return new ActivityOptions(
            connection: self::nonEmptyString($command['connection'] ?? null),
            queue: self::nonEmptyString($command['queue'] ?? null),
            maxAttempts: is_int($retryPolicy['max_attempts'] ?? null) ? (int) $retryPolicy['max_attempts'] : null,
            backoff: is_array($retryPolicy['backoff_seconds'] ?? null) ? $retryPolicy['backoff_seconds'] : null,
            startToCloseTimeout: is_int($command['start_to_close_timeout'] ?? null)
                ? (int) $command['start_to_close_timeout']
                : null,
            scheduleToStartTimeout: is_int($command['schedule_to_start_timeout'] ?? null)
                ? (int) $command['schedule_to_start_timeout']
                : null,
            scheduleToCloseTimeout: is_int($command['schedule_to_close_timeout'] ?? null)
                ? (int) $command['schedule_to_close_timeout']
                : null,
            heartbeatTimeout: is_int($command['heartbeat_timeout'] ?? null)
                ? (int) $command['heartbeat_timeout']
                : null,
            nonRetryableErrorTypes: is_array($retryPolicy['non_retryable_error_types'] ?? null)
                ? $retryPolicy['non_retryable_error_types']
                : [],
        );
    }

    /**
     * @param array{
     *     type: string,
     *     activity_type: string,
     *     arguments?: string|null,
     *     connection?: string|null,
     *     queue?: string|null,
     *     retry_policy?: array<string, mixed>,
     *     start_to_close_timeout?: int,
     *     schedule_to_start_timeout?: int,
     *     schedule_to_close_timeout?: int,
     *     heartbeat_timeout?: int
     * } $command
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
        $options = self::activityOptionsFromCommand($command);
        $scheduleDeadlineAt = $options?->scheduleToStartTimeout !== null
            ? now()
                ->addSeconds($options->scheduleToStartTimeout)
            : null;
        $scheduleToCloseDeadlineAt = $options?->scheduleToCloseTimeout !== null
            ? now()
                ->addSeconds($options->scheduleToCloseTimeout)
            : null;
        $retryPolicy = ActivityRetryPolicy::snapshotExternal(
            is_array($command['retry_policy'] ?? null) ? $command['retry_policy'] : null,
            $options,
        );

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
            'retry_policy' => $retryPolicy,
            'activity_options' => $options?->toSnapshot(),
            'schedule_deadline_at' => $scheduleDeadlineAt,
            'schedule_to_close_deadline_at' => $scheduleToCloseDeadlineAt,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => $execution->id,
            'activity_class' => $activityType,
            'activity_type' => $activityType,
            'sequence' => $sequence,
            'activity' => ActivitySnapshot::fromExecution($execution),
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
    private function applyStartTimer(
        WorkflowRun $run,
        WorkflowTask $task,
        array $command,
        int $sequence,
        array &$createdTaskIds
    ): int {
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
     * @param array{
     *     type: string,
     *     condition_key?: string|null,
     *     condition_definition_fingerprint?: string|null,
     *     timeout_seconds?: int|null
     * } $command
     * @param list<string> $createdTaskIds
     */
    private function applyOpenConditionWait(
        WorkflowRun $run,
        WorkflowTask $task,
        array $command,
        int $sequence,
        array &$createdTaskIds,
    ): int {
        $conditionKey = self::nonEmptyString($command['condition_key'] ?? null);
        $conditionDefinitionFingerprint = self::nonEmptyString(
            $command['condition_definition_fingerprint'] ?? null,
        );
        $timeoutSeconds = is_int($command['timeout_seconds'] ?? null) && $command['timeout_seconds'] >= 0
            ? (int) $command['timeout_seconds']
            : null;

        $waitId = (string) Str::ulid();

        WorkflowHistoryEvent::record($run, HistoryEventType::ConditionWaitOpened, array_filter([
            'condition_wait_id' => $waitId,
            'condition_key' => $conditionKey,
            'condition_definition_fingerprint' => $conditionDefinitionFingerprint,
            'sequence' => $sequence,
            'timeout_seconds' => $timeoutSeconds,
        ], static fn (mixed $value): bool => $value !== null), $task);

        if ($timeoutSeconds !== null && $timeoutSeconds > 0) {
            $fireAt = now()
                ->addSeconds($timeoutSeconds);

            /** @var WorkflowTimer $timer */
            $timer = WorkflowTimer::query()->create([
                'workflow_run_id' => $run->id,
                'sequence' => $sequence,
                'status' => TimerStatus::Pending->value,
                'delay_seconds' => $timeoutSeconds,
                'fire_at' => $fireAt,
            ]);

            WorkflowHistoryEvent::record($run, HistoryEventType::TimerScheduled, array_filter([
                'timer_id' => $timer->id,
                'sequence' => $sequence,
                'delay_seconds' => $timer->delay_seconds,
                'fire_at' => $timer->fire_at?->toJSON(),
                'timer_kind' => 'condition_timeout',
                'condition_wait_id' => $waitId,
                'condition_key' => $conditionKey,
                'condition_definition_fingerprint' => $conditionDefinitionFingerprint,
            ], static fn (mixed $value): bool => $value !== null), $task);

            /** @var WorkflowTask $timerTask */
            $timerTask = WorkflowTask::query()->create([
                'workflow_run_id' => $run->id,
                'namespace' => $run->namespace,
                'task_type' => TaskType::Timer->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => $fireAt,
                'payload' => array_filter([
                    'timer_id' => $timer->id,
                    'condition_wait_id' => $waitId,
                    'condition_key' => $conditionKey,
                    'condition_definition_fingerprint' => $conditionDefinitionFingerprint,
                ], static fn (mixed $value): bool => $value !== null),
                'connection' => $run->connection,
                'queue' => $run->queue,
                'compatibility' => $run->compatibility,
            ]);

            $createdTaskIds[] = $timerTask->id;
        }

        return $sequence + 1;
    }

    /**
     * @param array{
     *     type: string,
     *     workflow_type: string,
     *     arguments?: string|null,
     *     connection?: string|null,
     *     queue?: string|null,
     *     parent_close_policy?: string|null,
     *     retry_policy?: array<string, mixed>,
     *     execution_timeout_seconds?: int,
     *     run_timeout_seconds?: int
     * } $command
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
        $executionTimeoutSeconds = is_int($command['execution_timeout_seconds'] ?? null)
            ? (int) $command['execution_timeout_seconds']
            : null;
        $runTimeoutSeconds = is_int($command['run_timeout_seconds'] ?? null)
            ? (int) $command['run_timeout_seconds']
            : null;
        $executionDeadlineAt = $executionTimeoutSeconds !== null
            ? $now->copy()
                ->addSeconds($executionTimeoutSeconds)
            : null;
        $runDeadlineAt = $runTimeoutSeconds !== null
            ? $now->copy()
                ->addSeconds($runTimeoutSeconds)
            : null;
        $retryPolicy = ChildWorkflowRetryPolicy::snapshotExternal(
            is_array($command['retry_policy'] ?? null) ? $command['retry_policy'] : null,
        );
        $timeoutPolicy = ChildWorkflowRetryPolicy::timeoutSnapshot($executionTimeoutSeconds, $runTimeoutSeconds);

        /** @var WorkflowInstance $childInstance */
        $childInstance = WorkflowInstance::query()->create([
            'workflow_class' => $workflowType,
            'workflow_type' => $workflowType,
            'namespace' => $run->namespace,
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
            'namespace' => $run->namespace,
            'status' => RunStatus::Pending->value,
            'compatibility' => $run->compatibility ?? WorkerCompatibility::current(),
            'payload_codec' => $run->payload_codec ?? CodecRegistry::defaultCodec(),
            'arguments' => $arguments,
            'run_timeout_seconds' => $runTimeoutSeconds,
            'execution_deadline_at' => $executionDeadlineAt,
            'run_deadline_at' => $runDeadlineAt,
            'connection' => $connection,
            'queue' => $queue,
            'started_at' => $now,
            'last_progress_at' => $now,
            'last_history_sequence' => 0,
        ]);

        $this->inheritTypedVisibilityMetadata($run, $childRun);

        $childInstance->forceFill([
            'current_run_id' => $childRun->id,
        ])->save();

        $childCallId = (string) Str::ulid();

        $parentClosePolicy = $command['parent_close_policy'] ?? ParentClosePolicy::Abandon->value;

        WorkflowChildCall::query()->create([
            'parent_workflow_run_id' => $run->id,
            'parent_workflow_instance_id' => $run->workflow_instance_id,
            'sequence' => $sequence,
            'child_workflow_type' => $workflowType,
            'child_workflow_class' => $workflowType,
            'parent_close_policy' => $parentClosePolicy,
            'connection' => $connection,
            'queue' => $queue,
            'compatibility' => $childRun->compatibility,
            'retry_policy' => $retryPolicy,
            'timeout_policy' => $timeoutPolicy,
            'cancellation_propagation' => false,
            'status' => ChildCallStatus::Started,
            'scheduled_at' => $now,
            'started_at' => $now,
            'arguments' => $arguments === null ? null : [
                'payload' => $arguments,
            ],
            'metadata' => [
                'child_call_id' => $childCallId,
                'attempt_count' => 1,
            ],
            'resolved_child_instance_id' => $childInstance->id,
            'resolved_child_run_id' => $childRun->id,
        ]);

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
            'parent_close_policy' => $parentClosePolicy,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ChildWorkflowScheduled, [
            'sequence' => $sequence,
            'workflow_link_id' => $link->id,
            'child_call_id' => $childCallId,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'child_workflow_class' => $workflowType,
            'child_workflow_type' => $workflowType,
            'parent_close_policy' => $parentClosePolicy,
            'retry_policy' => $retryPolicy,
            'timeout_policy' => $timeoutPolicy,
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
            'parent_close_policy' => $parentClosePolicy,
            'retry_policy' => $retryPolicy,
            'timeout_policy' => $timeoutPolicy,
            'execution_timeout_seconds' => $executionTimeoutSeconds,
            'run_timeout_seconds' => $runTimeoutSeconds,
            'execution_deadline_at' => $executionDeadlineAt?->toIso8601String(),
            'run_deadline_at' => $runDeadlineAt?->toIso8601String(),
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
            'retry_policy' => $retryPolicy,
            'timeout_policy' => $timeoutPolicy,
            'execution_timeout_seconds' => $executionTimeoutSeconds,
            'run_timeout_seconds' => $runTimeoutSeconds,
            'execution_deadline_at' => $executionDeadlineAt?->toIso8601String(),
            'run_deadline_at' => $runDeadlineAt?->toIso8601String(),
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

        self::projectRun($childRun, self::PROJECTION_RUN_RELATIONS_WITH_HISTORY);

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
        $call = new UpsertSearchAttributesCall($command['attributes']);
        $existing = $run->typedSearchAttributes();
        $merged = $existing;

        foreach ($call->attributes as $key => $value) {
            if ($value === null) {
                unset($merged[$key]);

                continue;
            }

            $merged[$key] = $value;
        }

        ksort($merged);
        app(SearchAttributeUpsertService::class)->upsert($run, $call, $sequence);
        $run->unsetRelation('searchAttributes');

        WorkflowHistoryEvent::record($run, HistoryEventType::SearchAttributesUpserted, [
            'sequence' => $sequence,
            'attributes' => $call->attributes,
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
        $queue = is_string($command['queue'] ?? null) ? $command['queue'] : $run->queue;

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
            'namespace' => $run->namespace,
            'business_key' => $run->business_key,
            'visibility_labels' => $run->visibility_labels,
            'status' => RunStatus::Pending->value,
            'compatibility' => $run->compatibility,
            'payload_codec' => $run->payload_codec,
            'arguments' => $arguments,
            'connection' => $run->connection,
            'queue' => $queue,
            'started_at' => $now,
            'last_progress_at' => $now,
            'last_history_sequence' => 0,
        ]);

        $this->inheritTypedVisibilityMetadata($run, $continuedRun);

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

        self::projectRun($run, self::PROJECTION_RUN_RELATIONS_WITH_HISTORY);

        self::projectRun($continuedRun, self::PROJECTION_RUN_RELATIONS_WITH_HISTORY);
    }

    private function startChildRetryIfAvailable(
        WorkflowRun $parentRun,
        int $sequence,
        WorkflowRun $failedChildRun,
    ): ?WorkflowTask {
        if (ChildRunHistory::resolvedStatus(null, $failedChildRun) !== RunStatus::Failed) {
            return null;
        }

        $parentRun->loadMissing(
            ['historyEvents', 'childLinks.childRun.instance.currentRun', 'childLinks.childRun.failures']
        );

        $parentLink = ChildRunHistory::latestLinkForSequence($parentRun, $sequence);

        if ($parentLink?->child_workflow_run_id !== $failedChildRun->id) {
            return null;
        }

        $scheduledEvent = ChildRunHistory::scheduledEventForSequence($parentRun, $sequence);
        $retryPolicy = is_array($scheduledEvent?->payload['retry_policy'] ?? null)
            ? $scheduledEvent->payload['retry_policy']
            : null;

        if ($retryPolicy === null) {
            /** @var WorkflowChildCall|null $childCall */
            $childCall = WorkflowChildCall::query()
                ->where('parent_workflow_run_id', $parentRun->id)
                ->where('sequence', $sequence)
                ->first();

            $retryPolicy = is_array($childCall?->retry_policy) ? $childCall->retry_policy : null;
        }

        if ($retryPolicy === null) {
            return null;
        }

        $attemptCount = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRun->id)
            ->where('sequence', $sequence)
            ->where('link_type', 'child_workflow')
            ->count();

        if ($attemptCount >= ChildWorkflowRetryPolicy::maxAttempts($retryPolicy)) {
            return null;
        }

        if (ChildWorkflowRetryPolicy::isNonRetryableFailure($retryPolicy, $failedChildRun)) {
            return null;
        }

        $timeoutPolicy = is_array($scheduledEvent?->payload['timeout_policy'] ?? null)
            ? $scheduledEvent->payload['timeout_policy']
            : null;
        $executionTimeoutSeconds = is_int($timeoutPolicy['execution_timeout_seconds'] ?? null)
            ? (int) $timeoutPolicy['execution_timeout_seconds']
            : null;
        $runTimeoutSeconds = is_int($timeoutPolicy['run_timeout_seconds'] ?? null)
            ? (int) $timeoutPolicy['run_timeout_seconds']
            : null;

        $now = now();
        $backoffSeconds = ChildWorkflowRetryPolicy::backoffSeconds($retryPolicy, $attemptCount);
        $availableAt = $now->copy()
            ->addSeconds($backoffSeconds);

        /** @var WorkflowInstance|null $childInstance */
        $childInstance = WorkflowInstance::query()
            ->lockForUpdate()
            ->find($failedChildRun->workflow_instance_id);

        if (! $childInstance instanceof WorkflowInstance) {
            return null;
        }

        $nextRunNumber = ((int) WorkflowRun::query()
            ->where('workflow_instance_id', $failedChildRun->workflow_instance_id)
            ->max('run_number')) + 1;

        $executionDeadlineAt = $failedChildRun->execution_deadline_at;
        if ($executionDeadlineAt === null && $executionTimeoutSeconds !== null) {
            $executionDeadlineAt = $now->copy()
                ->addSeconds($executionTimeoutSeconds);
        }

        $runDeadlineAt = $runTimeoutSeconds !== null ? $now->copy()
            ->addSeconds($runTimeoutSeconds) : null;

        /** @var WorkflowRun $retryRun */
        $retryRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $failedChildRun->workflow_instance_id,
            'run_number' => $nextRunNumber,
            'workflow_class' => $failedChildRun->workflow_class,
            'workflow_type' => $failedChildRun->workflow_type,
            'namespace' => $failedChildRun->namespace,
            'business_key' => $failedChildRun->business_key,
            'visibility_labels' => $failedChildRun->visibility_labels,
            'status' => RunStatus::Pending->value,
            'compatibility' => $failedChildRun->compatibility ?? WorkerCompatibility::current(),
            'payload_codec' => $failedChildRun->payload_codec ?? CodecRegistry::defaultCodec(),
            'arguments' => $failedChildRun->arguments,
            'run_timeout_seconds' => $runTimeoutSeconds,
            'execution_deadline_at' => $executionDeadlineAt,
            'run_deadline_at' => $runDeadlineAt,
            'connection' => $failedChildRun->connection,
            'queue' => $failedChildRun->queue,
            'started_at' => $now,
            'last_progress_at' => $now,
            'last_history_sequence' => 0,
        ]);

        $this->inheritTypedVisibilityMetadata($failedChildRun, $retryRun);

        $childInstance->forceFill([
            'current_run_id' => $retryRun->id,
            'run_count' => max((int) $childInstance->run_count, $nextRunNumber),
        ])->save();

        $childCallId = ChildRunHistory::childCallIdForSequence($parentRun, $sequence) ?? (string) Str::ulid();
        $parallelMetadataPath = ChildRunHistory::parallelGroupPathForSequence($parentRun, $sequence);
        $parallelMetadata = ParallelChildGroup::payloadForPath($parallelMetadataPath);
        $parentClosePolicy = $parentLink?->parent_close_policy ?? ParentClosePolicy::Abandon->value;

        /** @var WorkflowLink $retryLink */
        $retryLink = WorkflowLink::query()->create([
            'link_type' => 'child_workflow',
            'sequence' => $sequence,
            'parent_workflow_instance_id' => $parentRun->workflow_instance_id,
            'parent_workflow_run_id' => $parentRun->id,
            'child_workflow_instance_id' => $retryRun->workflow_instance_id,
            'child_workflow_run_id' => $retryRun->id,
            'is_primary_parent' => true,
            'parallel_group_path' => $parallelMetadataPath === [] ? null : $parallelMetadataPath,
            'parent_close_policy' => $parentClosePolicy,
        ]);

        WorkflowHistoryEvent::record($parentRun, HistoryEventType::ChildRunStarted, array_filter(array_merge([
            'sequence' => $sequence,
            'workflow_link_id' => $retryLink->id,
            'child_call_id' => $childCallId,
            'child_workflow_instance_id' => $retryRun->workflow_instance_id,
            'child_workflow_run_id' => $retryRun->id,
            'child_workflow_class' => $retryRun->workflow_class,
            'child_workflow_type' => $retryRun->workflow_type,
            'child_run_number' => $retryRun->run_number,
            'parent_close_policy' => $parentClosePolicy,
            'retry_attempt' => $attemptCount + 1,
            'retry_of_child_workflow_run_id' => $failedChildRun->id,
            'retry_backoff_seconds' => $backoffSeconds,
            'retry_policy' => $retryPolicy,
            'timeout_policy' => $timeoutPolicy,
            'execution_timeout_seconds' => $executionTimeoutSeconds,
            'run_timeout_seconds' => $runTimeoutSeconds,
            'execution_deadline_at' => $executionDeadlineAt?->toIso8601String(),
            'run_deadline_at' => $runDeadlineAt?->toIso8601String(),
        ], $parallelMetadata), static fn (mixed $value): bool => $value !== null));

        WorkflowHistoryEvent::record($retryRun, HistoryEventType::WorkflowStarted, [
            'workflow_class' => $retryRun->workflow_class,
            'workflow_type' => $retryRun->workflow_type,
            'workflow_instance_id' => $retryRun->workflow_instance_id,
            'workflow_run_id' => $retryRun->id,
            'parent_workflow_instance_id' => $parentRun->workflow_instance_id,
            'parent_workflow_run_id' => $parentRun->id,
            'parent_sequence' => $sequence,
            'workflow_link_id' => $retryLink->id,
            'child_call_id' => $childCallId,
            'retry_attempt' => $attemptCount + 1,
            'retry_of_child_workflow_run_id' => $failedChildRun->id,
            'retry_policy' => $retryPolicy,
            'timeout_policy' => $timeoutPolicy,
            'execution_timeout_seconds' => $executionTimeoutSeconds,
            'run_timeout_seconds' => $runTimeoutSeconds,
            'execution_deadline_at' => $executionDeadlineAt?->toIso8601String(),
            'run_deadline_at' => $runDeadlineAt?->toIso8601String(),
        ]);

        /** @var WorkflowTask $retryTask */
        $retryTask = WorkflowTask::query()->create([
            'workflow_run_id' => $retryRun->id,
            'namespace' => $retryRun->namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => $availableAt,
            'payload' => [],
            'connection' => $retryRun->connection,
            'queue' => $retryRun->queue,
            'compatibility' => $retryRun->compatibility,
        ]);

        /** @var WorkflowChildCall|null $childCall */
        $childCall = WorkflowChildCall::query()
            ->where('parent_workflow_run_id', $parentRun->id)
            ->where('sequence', $sequence)
            ->first();

        if ($childCall instanceof WorkflowChildCall) {
            $childCall->forceFill([
                'resolved_child_instance_id' => $retryRun->workflow_instance_id,
                'resolved_child_run_id' => $retryRun->id,
                'status' => ChildCallStatus::Started,
                'started_at' => $now,
                'metadata' => array_merge(is_array($childCall->metadata) ? $childCall->metadata : [], [
                    'child_call_id' => $childCallId,
                    'attempt_count' => $attemptCount + 1,
                    'last_retry_of_child_workflow_run_id' => $failedChildRun->id,
                    'last_retry_backoff_seconds' => $backoffSeconds,
                ]),
            ])->save();
        }

        TaskDispatcher::dispatch($retryTask);

        self::projectRun($retryRun, self::PROJECTION_RUN_RELATIONS_WITH_HISTORY);

        return $retryTask;
    }

    /**
     * Dispatch parent resume tasks when a child run closes through the bridge.
     */
    private function dispatchParentResumeTasksForRun(WorkflowRun $childRun): void
    {
        $childRun->unsetRelation('historyEvents');
        $childRun->unsetRelation('failures');
        $childRun->load(['historyEvents', 'failures']);

        $parentLinks = WorkflowLink::query()
            ->where('child_workflow_run_id', $childRun->id)
            ->where('link_type', 'child_workflow')
            ->lockForUpdate()
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

            $sequence = is_int($parentLink->sequence) ? $parentLink->sequence : null;
            $parentTaskPayload = [];

            if ($sequence !== null) {
                $parentRun->loadMissing([
                    'historyEvents',
                    'childLinks.childRun.instance.currentRun',
                    'childLinks.childRun.failures',
                    'childLinks.childRun.historyEvents',
                ]);

                if ($this->startChildRetryIfAvailable($parentRun, $sequence, $childRun) !== null) {
                    continue;
                }

                try {
                    WorkflowStepHistory::assertCompatible(
                        $parentRun,
                        $sequence,
                        WorkflowStepHistory::CHILD_WORKFLOW,
                    );
                    WorkflowStepHistory::assertTypedHistoryRecorded(
                        $parentRun,
                        $sequence,
                        WorkflowStepHistory::CHILD_WORKFLOW,
                    );
                } catch (HistoryEventShapeMismatchException) {
                    self::projectRun($parentRun, self::PROJECTION_RUN_RELATIONS_WITH_CHILDREN);

                    continue;
                }

                $resolutionEvent = $this->recordChildResolution($parentRun, null, $sequence, $childRun);
                $parentTaskPayload = WorkflowTaskPayload::forChildResolution($resolutionEvent);
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
                'payload' => $parentTaskPayload,
                'connection' => $parentRun->connection,
                'queue' => $parentRun->queue,
                'compatibility' => $parentRun->compatibility,
            ]);
        }
    }

    private function recordChildResolution(
        WorkflowRun $run,
        ?WorkflowTask $task,
        int $sequence,
        WorkflowRun $childRun,
    ): WorkflowHistoryEvent {
        $link = ChildRunHistory::latestLinkForSequence($run, $sequence);
        $eventType = match (ChildRunHistory::resolvedStatus(null, $childRun)) {
            RunStatus::Completed => HistoryEventType::ChildRunCompleted,
            RunStatus::Cancelled => HistoryEventType::ChildRunCancelled,
            RunStatus::Terminated => HistoryEventType::ChildRunTerminated,
            default => HistoryEventType::ChildRunFailed,
        };

        $alreadyRecorded = $run->historyEvents->contains(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === $eventType
                && ($event->payload['sequence'] ?? null) === $sequence
                && ($event->payload['child_workflow_run_id'] ?? null) === $childRun->id
        );

        if ($alreadyRecorded) {
            /** @var WorkflowHistoryEvent $event */
            $event = $run->historyEvents->first(
                static fn (WorkflowHistoryEvent $event): bool => $event->event_type === $eventType
                    && ($event->payload['sequence'] ?? null) === $sequence
                    && ($event->payload['child_workflow_run_id'] ?? null) === $childRun->id
            );

            return $event;
        }

        $childTerminalEvent = $childRun->historyEvents
            ->filter(
                static fn (WorkflowHistoryEvent $event): bool => in_array($event->event_type, [
                    HistoryEventType::WorkflowCompleted,
                    HistoryEventType::WorkflowFailed,
                    HistoryEventType::WorkflowCancelled,
                    HistoryEventType::WorkflowTerminated,
                ], true)
            )
            ->sortByDesc('sequence')
            ->first();
        $failure = $childRun->failures->first();
        $parallelMetadataPath = ChildRunHistory::parallelGroupPathForSequence($run, $sequence);
        $parallelMetadata = ParallelChildGroup::payloadForPath($parallelMetadataPath);

        return WorkflowHistoryEvent::record($run, $eventType, array_filter([
            'sequence' => $sequence,
            'workflow_link_id' => $link?->id,
            'child_call_id' => ChildRunHistory::childCallIdForSequence($run, $sequence),
            'child_workflow_instance_id' => $childRun->workflow_instance_id,
            'child_workflow_run_id' => $childRun->id,
            'child_workflow_class' => $childRun->workflow_class,
            'child_workflow_type' => $childRun->workflow_type,
            'child_run_number' => $childRun->run_number,
            'child_status' => $childRun->status->value,
            'closed_reason' => $childRun->closed_reason,
            'closed_at' => $childRun->closed_at?->toJSON(),
            'output' => $childTerminalEvent?->event_type === HistoryEventType::WorkflowCompleted
                ? $childTerminalEvent->payload['output'] ?? $childRun->output
                : null,
            'failure_id' => $failure?->id,
            'failure_category' => match ($eventType) {
                HistoryEventType::ChildRunFailed => $failure?->failure_category ?? FailureCategory::ChildWorkflow->value,
                HistoryEventType::ChildRunCancelled => $failure?->failure_category ?? FailureCategory::Cancelled->value,
                HistoryEventType::ChildRunTerminated => $failure?->failure_category ?? FailureCategory::Terminated->value,
                default => null,
            },
            'exception' => $childTerminalEvent?->event_type === HistoryEventType::WorkflowFailed
                ? $childTerminalEvent->payload['exception'] ?? null
                : null,
            'exception_type' => $childTerminalEvent?->event_type === HistoryEventType::WorkflowFailed
                ? $childTerminalEvent->payload['exception_type'] ?? null
                : null,
            'exception_class' => $childTerminalEvent?->event_type === HistoryEventType::WorkflowFailed
                ? $childTerminalEvent->payload['exception_class'] ?? $failure?->exception_class
                : $failure?->exception_class,
            'message' => $childTerminalEvent?->event_type === HistoryEventType::WorkflowFailed
                ? $childTerminalEvent->payload['message'] ?? $failure?->message
                : $failure?->message,
            'code' => $childTerminalEvent?->event_type === HistoryEventType::WorkflowFailed
                ? $childTerminalEvent->payload['code'] ?? null
                : null,
            ...($parallelMetadata ?? []),
        ], static fn ($value): bool => $value !== null), $task);
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
            'complete_update' => self::normalizeCompleteUpdateCommand($command),
            'fail_update' => self::normalizeFailUpdateCommand($command),
            'record_side_effect' => self::normalizeRecordSideEffectCommand($command),
            'record_version_marker' => self::normalizeRecordVersionMarkerCommand($command),
            'upsert_search_attributes' => self::normalizeUpsertSearchAttributesCommand($command),
            'open_condition_wait' => self::normalizeOpenConditionWaitCommand($command),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $command
     * @return array{
     *     type: string,
     *     condition_key?: string,
     *     condition_definition_fingerprint?: string,
     *     timeout_seconds?: int
     * }
     */
    private static function normalizeOpenConditionWaitCommand(array $command): array
    {
        $timeoutSeconds = $command['timeout_seconds'] ?? null;

        return array_filter([
            'type' => 'open_condition_wait',
            'condition_key' => self::normalizeOptionalString($command['condition_key'] ?? null),
            'condition_definition_fingerprint' => self::normalizeOptionalString(
                $command['condition_definition_fingerprint'] ?? null,
            ),
            'timeout_seconds' => is_int($timeoutSeconds) && $timeoutSeconds >= 0 ? $timeoutSeconds : null,
        ], static fn (mixed $value): bool => $value !== null);
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
     * @return array{
     *     type: string,
     *     activity_type: string,
     *     arguments?: string|null,
     *     connection?: string|null,
     *     queue?: string|null,
     *     retry_policy?: array<string, mixed>,
     *     start_to_close_timeout?: int,
     *     schedule_to_start_timeout?: int,
     *     schedule_to_close_timeout?: int,
     *     heartbeat_timeout?: int
     * }|null
     */
    private static function normalizeScheduleActivityCommand(array $command): ?array
    {
        $activityType = self::normalizeRequiredString($command['activity_type'] ?? null);
        $retryPolicy = self::normalizeActivityRetryPolicy($command['retry_policy'] ?? null);
        $startToCloseTimeout = self::normalizePositiveInt($command['start_to_close_timeout'] ?? null);
        $scheduleToStartTimeout = self::normalizePositiveInt($command['schedule_to_start_timeout'] ?? null);
        $scheduleToCloseTimeout = self::normalizePositiveInt($command['schedule_to_close_timeout'] ?? null);
        $heartbeatTimeout = self::normalizePositiveInt($command['heartbeat_timeout'] ?? null);

        if ($activityType === null) {
            return null;
        }

        if (($command['retry_policy'] ?? null) !== null && $retryPolicy === null) {
            return null;
        }

        foreach (
            [
                'start_to_close_timeout' => $startToCloseTimeout,
                'schedule_to_start_timeout' => $scheduleToStartTimeout,
                'schedule_to_close_timeout' => $scheduleToCloseTimeout,
                'heartbeat_timeout' => $heartbeatTimeout,
            ] as $field => $value
        ) {
            if (($command[$field] ?? null) !== null && $value === null) {
                return null;
            }
        }

        return array_filter([
            'type' => 'schedule_activity',
            'activity_type' => $activityType,
            'arguments' => self::normalizeNullableString($command['arguments'] ?? null),
            'connection' => self::normalizeOptionalString($command['connection'] ?? null),
            'queue' => self::normalizeOptionalString($command['queue'] ?? null),
            'retry_policy' => $retryPolicy,
            'start_to_close_timeout' => $startToCloseTimeout,
            'schedule_to_start_timeout' => $scheduleToStartTimeout,
            'schedule_to_close_timeout' => $scheduleToCloseTimeout,
            'heartbeat_timeout' => $heartbeatTimeout,
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
     * @return array{
     *     type: string,
     *     workflow_type: string,
     *     arguments?: string|null,
     *     connection?: string|null,
     *     queue?: string|null,
     *     parent_close_policy?: string|null,
     *     retry_policy?: array<string, mixed>,
     *     execution_timeout_seconds?: int,
     *     run_timeout_seconds?: int
     * }|null
     */
    private static function normalizeStartChildWorkflowCommand(array $command): ?array
    {
        $workflowType = self::normalizeRequiredString($command['workflow_type'] ?? null);
        $retryPolicy = self::normalizeActivityRetryPolicy($command['retry_policy'] ?? null);
        $executionTimeoutSeconds = self::normalizePositiveInt($command['execution_timeout_seconds'] ?? null);
        $runTimeoutSeconds = self::normalizePositiveInt($command['run_timeout_seconds'] ?? null);

        if ($workflowType === null) {
            return null;
        }

        if (($command['retry_policy'] ?? null) !== null && $retryPolicy === null) {
            return null;
        }

        foreach (
            [
                'execution_timeout_seconds' => $executionTimeoutSeconds,
                'run_timeout_seconds' => $runTimeoutSeconds,
            ] as $field => $value
        ) {
            if (($command[$field] ?? null) !== null && $value === null) {
                return null;
            }
        }

        return array_filter([
            'type' => 'start_child_workflow',
            'workflow_type' => $workflowType,
            'arguments' => self::normalizeNullableString($command['arguments'] ?? null),
            'connection' => self::normalizeOptionalString($command['connection'] ?? null),
            'queue' => self::normalizeOptionalString($command['queue'] ?? null),
            'parent_close_policy' => self::normalizeOptionalString($command['parent_close_policy'] ?? null),
            'retry_policy' => $retryPolicy,
            'execution_timeout_seconds' => $executionTimeoutSeconds,
            'run_timeout_seconds' => $runTimeoutSeconds,
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
        $queue = self::normalizeOptionalString($command['queue'] ?? null);

        if (($command['workflow_type'] ?? null) !== null && $workflowType === null) {
            return null;
        }

        if (($command['arguments'] ?? null) !== null && $arguments === null) {
            return null;
        }

        if (($command['queue'] ?? null) !== null && $queue === null) {
            return null;
        }

        return array_filter([
            'type' => 'continue_as_new',
            'arguments' => $arguments,
            'workflow_type' => $workflowType,
            'queue' => $queue,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $command
     * @return array{type: string, update_id: string, result?: string|null}|null
     */
    private static function normalizeCompleteUpdateCommand(array $command): ?array
    {
        $updateId = self::normalizeRequiredString($command['update_id'] ?? null);

        if ($updateId === null) {
            return null;
        }

        return [
            'type' => 'complete_update',
            'update_id' => $updateId,
            'result' => $command['result'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $command
     * @return array{
     *     type: string,
     *     update_id: string,
     *     message: string,
     *     exception_class?: string,
     *     exception_type?: string,
     *     non_retryable?: bool
     * }|null
     */
    private static function normalizeFailUpdateCommand(array $command): ?array
    {
        $updateId = self::normalizeRequiredString($command['update_id'] ?? null);
        $message = self::normalizeRequiredString($command['message'] ?? null);

        if ($updateId === null || $message === null) {
            return null;
        }

        return array_filter([
            'type' => 'fail_update',
            'update_id' => $updateId,
            'message' => $message,
            'exception_class' => self::normalizeOptionalString($command['exception_class'] ?? null),
            'exception_type' => self::normalizeOptionalString($command['exception_type'] ?? null),
            'non_retryable' => is_bool($command['non_retryable'] ?? null) ? $command['non_retryable'] : null,
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

    private static function normalizePositiveInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) || $value < 1) {
            return null;
        }

        return $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function normalizeActivityRetryPolicy(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            return null;
        }

        $policy = [];

        if (array_key_exists('max_attempts', $value)) {
            if (! is_int($value['max_attempts']) || $value['max_attempts'] < 1) {
                return null;
            }

            $policy['max_attempts'] = $value['max_attempts'];
        }

        if (array_key_exists('backoff_seconds', $value)) {
            if (! is_array($value['backoff_seconds'])) {
                return null;
            }

            $backoff = [];
            foreach (array_values($value['backoff_seconds']) as $seconds) {
                if (! is_int($seconds) || $seconds < 0) {
                    return null;
                }

                $backoff[] = $seconds;
            }

            $policy['backoff_seconds'] = $backoff;
        }

        if (array_key_exists('non_retryable_error_types', $value)) {
            if (! is_array($value['non_retryable_error_types'])) {
                return null;
            }

            $types = [];
            foreach (array_values($value['non_retryable_error_types']) as $type) {
                if (! is_string($type) || trim($type) === '') {
                    return null;
                }

                $types[] = trim($type);
            }

            $policy['non_retryable_error_types'] = array_values(array_unique($types));
        }

        return $policy === [] ? null : $policy;
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
                self::projectRun($run, self::PROJECTION_RUN_RELATIONS);

                return false;
            }

            if (! TaskCompatibility::supported($task, $run)) {
                self::projectRun($run, self::PROJECTION_RUN_RELATIONS);

                return false;
            }

            $task->forceFill([
                'status' => TaskStatus::Leased,
                'leased_at' => now(),
                'lease_owner' => $taskId,
                'lease_expires_at' => now()
                    ->addSeconds(self::WORKFLOW_TASK_LEASE_SECONDS),
                'attempt_count' => $task->attempt_count + 1,
                'last_claim_failed_at' => null,
                'last_claim_error' => null,
            ])->save();

            self::projectRun($run, self::PROJECTION_RUN_RELATIONS);

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

    /**
     * @return list<string>
     */
    private static function nonEmptyStrings(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): ?string => self::nonEmptyString($value),
            $values,
        )));
    }

    /**
     * Hydrate the relations the projection needs and dispatch into the
     * {@see HistoryProjectionRole} contract. Every projection emitted by the
     * workflow bridge flows through this helper so the role contract is the
     * single entry point — no call site reaches into `RunSummaryProjector`
     * directly, and the relation-hydration shape lives in one of the
     * `PROJECTION_RUN_RELATIONS*` constants rather than at each call site.
     *
     * @param list<string> $with
     */
    private static function projectRun(WorkflowRun $run, array $with = []): void
    {
        if ($with !== []) {
            $run = $run->fresh($with) ?? $run;
        }

        self::historyProjectionRole()->projectRun($run);
    }

    private static function historyProjectionRole(): HistoryProjectionRole
    {
        /** @var HistoryProjectionRole $role */
        $role = app(HistoryProjectionRole::class);

        return $role;
    }

    private function inheritTypedVisibilityMetadata(WorkflowRun $sourceRun, WorkflowRun $targetRun): void
    {
        app(MemoUpsertService::class)->inheritFromParent($sourceRun, $targetRun, 1);
        app(SearchAttributeUpsertService::class)->inheritFromParent($sourceRun, $targetRun, 1);

        $targetRun->unsetRelation('memos');
        $targetRun->unsetRelation('searchAttributes');
    }
}
