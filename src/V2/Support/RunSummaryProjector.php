<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Carbon;
use Throwable;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Workflow;

final class RunSummaryProjector
{
    /**
     * Projection schema version — bump when the derived-field set changes
     * (new columns, new computed values, changed derivation logic).
     *
     * Summaries with a NULL or lower version are eligible for schema-upgrade
     * rebuild via `workflow:v2:rebuild-projections --needs-rebuild`.
     */
    public const SCHEMA_VERSION = 2;

    public static function project(WorkflowRun $run): WorkflowRunSummary
    {
        $claimedTask = WorkflowTaskClaimProjectionContext::taskFor($run);

        if ($claimedTask instanceof WorkflowTask) {
            return self::projectWorkflowTaskClaim($run, $claimedTask);
        }

        $childResolution = ChildResolutionProjectionContext::projectionFor($run);

        if ($childResolution !== null) {
            return self::projectOpenWorkflowTask(
                $run,
                $childResolution['task'],
                [$childResolution['event']],
            );
        }

        $fastSummary = self::projectFreshWorkflowTaskRun($run);

        if ($fastSummary instanceof WorkflowRunSummary) {
            return $fastSummary;
        }

        $run->loadMissing(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents']);
        $run->loadMissing(['childLinks.childRun.instance.currentRun', 'childLinks.childRun.failures']);
        $currentRun = $run->instance === null
            ? null
            : CurrentRunResolver::forInstance($run->instance);

        $isTerminal = $run->status->isTerminal();
        $activities = RunActivityView::activitiesForRun($run);
        $timers = RunTimerView::timersForRun($run);

        $openActivity = $isTerminal
            ? null
            : collect($activities)
                ->first(static fn (array $activity): bool => self::isAuthoritativeOpenActivity($activity));
        $diagnosticActivity = $isTerminal ? null : self::diagnosticActivity($activities);
        $unsupportedActivity = $isTerminal ? null : self::unsupportedActivity($activities);
        $unsupportedTimer = $isTerminal ? null : self::unsupportedTimer($timers);

        $nextTask = $isTerminal
            ? null
            : self::nextOpenTask($run);

        $replayBlockedTask = $isTerminal
            ? null
            : self::replayBlockedTask($run);

        $openUpdateWait = $isTerminal || $openActivity !== null
            ? null
            : self::openUpdateWait($run);

        $openUpdateTask = $openUpdateWait === null
            ? null
            : self::openTaskById($run, self::nonEmptyString($openUpdateWait['task_id'] ?? null));

        $openSignalApplicationWait = $isTerminal
            || $openActivity !== null
            || $openUpdateWait !== null
            || $nextTask !== null
            ? null
            : self::openSignalApplicationWait($run);

        $openConditionWait = $isTerminal || $openActivity !== null || $openUpdateWait !== null || $openSignalApplicationWait !== null
            ? null
            : self::openConditionWait($run);

        $openTimer = $isTerminal || $openUpdateWait !== null
            ? null
            : collect($timers)
                ->first(
                    static fn (array $timer): bool => self::isAuthoritativeOpenTimer($timer)
                        && ! in_array($timer['timer_kind'] ?? null, ['condition_timeout', 'signal_timeout'], true)
                        && ($timer['id'] ?? null) !== ($openConditionWait['timer_id'] ?? null)
                );
        $diagnosticTimer = $isTerminal || $openUpdateWait !== null
            ? null
            : self::diagnosticTimer($timers, self::nonEmptyString($openConditionWait['timer_id'] ?? null));

        $openChildWait = $isTerminal || $openActivity !== null || $openUpdateWait !== null || $openSignalApplicationWait !== null || $openConditionWait !== null || $openTimer !== null || $nextTask !== null
            ? null
            : self::openChildWait($run);
        $diagnosticChildWait = (
            $isTerminal
            || $openActivity !== null
            || $openUpdateWait !== null
            || $openSignalApplicationWait !== null
            || $openConditionWait !== null
            || $openTimer !== null
            || $nextTask !== null
        )
            ? null
            : self::diagnosticChildWait($run);

        $pendingChildResolutionWait = (
            $isTerminal
            || $openActivity !== null
            || $openUpdateWait !== null
            || $openSignalApplicationWait !== null
            || $openConditionWait !== null
            || $openTimer !== null
            || $diagnosticChildWait !== null
        )
            ? null
            : self::pendingChildResolutionWait($run, $openChildWait !== null);

        $unsupportedChildWait = (
            $isTerminal
            || $openActivity !== null
            || $openUpdateWait !== null
            || $openSignalApplicationWait !== null
            || $openConditionWait !== null
            || $openTimer !== null
            || $diagnosticChildWait !== null
            || $openChildWait !== null
            || $pendingChildResolutionWait !== null
        )
            ? null
            : self::unsupportedChildWait($run);

        $openSignalWait = (
            $isTerminal
            || $openActivity !== null
            || $openUpdateWait !== null
            || $openSignalApplicationWait !== null
            || $openConditionWait !== null
            || $openTimer !== null
            || $diagnosticChildWait !== null
            || $openChildWait !== null
            || $pendingChildResolutionWait !== null
            || $unsupportedChildWait !== null
        )
            ? null
            : self::openSignalWait($run);

        $waitKind = null;
        $waitReason = null;
        $waitStartedAt = null;
        $waitDeadlineAt = null;
        $openWaitId = null;
        $resumeSourceKind = null;
        $resumeSourceId = null;

        if ($openActivity !== null) {
            $waitKind = 'activity';
            $waitReason = sprintf('Waiting for activity %s', self::activityType($openActivity));
            $waitStartedAt = self::timestamp($openActivity['started_at'] ?? null)
                ?? self::timestamp($openActivity['created_at'] ?? null);
            $openWaitId = sprintf('activity:%s', $openActivity['id']);
            $resumeSourceKind = 'activity_execution';
            $resumeSourceId = $openActivity['id'];
        } elseif ($openUpdateWait !== null) {
            $waitKind = 'update';
            $waitReason = sprintf('Waiting for update %s', $openUpdateWait['name']);
            $waitStartedAt = $openUpdateWait['opened_at'];
            $openWaitId = $openUpdateWait['id'];
            $resumeSourceKind = $openUpdateWait['resume_source_kind'];
            $resumeSourceId = $openUpdateWait['resume_source_id'];
        } elseif ($openSignalApplicationWait !== null) {
            $waitKind = 'signal';
            $waitReason = sprintf('Waiting to apply signal %s', $openSignalApplicationWait['name']);
            $waitStartedAt = $openSignalApplicationWait['opened_at'];
            $openWaitId = $openSignalApplicationWait['id'];
            $resumeSourceKind = $openSignalApplicationWait['resume_source_kind'];
            $resumeSourceId = $openSignalApplicationWait['resume_source_id'];
        } elseif ($openTimer !== null) {
            $waitKind = 'timer';
            $waitReason = 'Waiting for timer';
            $waitStartedAt = $openTimer['created_at'] ?? null;
            $waitDeadlineAt = $openTimer['fire_at'] ?? null;
            $openWaitId = sprintf('timer:%s', $openTimer['id']);
            $resumeSourceKind = 'timer';
            $resumeSourceId = $openTimer['id'];
        } elseif ($openConditionWait !== null) {
            $waitKind = 'condition';
            $conditionLabel = self::conditionLabel($openConditionWait);
            $waitReason = match (true) {
                self::timestamp($openConditionWait['timeout_fired_at'] ?? null) !== null => sprintf(
                    'Waiting to apply condition%s timeout',
                    $conditionLabel,
                ),
                $openConditionWait['timer_id'] === null => sprintf('Waiting for condition%s', $conditionLabel),
                default => sprintf('Waiting for condition%s or timeout', $conditionLabel),
            };
            $waitStartedAt = $openConditionWait['opened_at'];
            $waitDeadlineAt = $openConditionWait['deadline_at'];
            $openWaitId = $openConditionWait['id'];
            $resumeSourceKind = $openConditionWait['resume_source_kind'];
            $resumeSourceId = $openConditionWait['resume_source_id'];
        } elseif ($openSignalWait !== null) {
            $waitKind = 'signal';
            $waitReason = match (true) {
                self::timestamp($openSignalWait['timeout_fired_at'] ?? null) !== null => sprintf(
                    'Waiting to apply signal %s timeout',
                    $openSignalWait['name'],
                ),
                ($openSignalWait['timeout_seconds'] ?? null) === null => sprintf(
                    'Waiting for signal %s',
                    $openSignalWait['name'],
                ),
                default => sprintf('Waiting for signal %s or timeout', $openSignalWait['name']),
            };
            $waitStartedAt = $openSignalWait['opened_at'];
            $waitDeadlineAt = $openSignalWait['deadline_at'];
            $openWaitId = $openSignalWait['id'];
            $resumeSourceKind = $openSignalWait['resume_source_kind'];
            $resumeSourceId = $openSignalWait['resume_source_id'];
        } elseif ($nextTask !== null && $nextTask->task_type === TaskType::Workflow) {
            $waitKind = 'workflow-task';
            $waitReason = match (true) {
                self::taskWaitingForCompatibleWorker(
                    $nextTask,
                    $run
                ) => 'Workflow task waiting for a compatible worker',
                TaskRepairPolicy::dispatchFailed($nextTask) => 'Workflow task dispatch failed',
                TaskRepairPolicy::claimFailed($nextTask) => 'Workflow task claim failed',
                TaskRepairPolicy::leaseExpired($nextTask) => 'Workflow task lease expired',
                TaskRepairPolicy::dispatchOverdue($nextTask) => 'Workflow task ready but dispatch is overdue',
                $nextTask->status === TaskStatus::Leased => 'Workflow task leased to worker',
                default => 'Workflow task ready',
            };
            $waitStartedAt = $nextTask->leased_at ?? $nextTask->available_at;
            $waitDeadlineAt = $nextTask->lease_expires_at;
            $openWaitId = sprintf('workflow-task:%s', $nextTask->id);
            $resumeSourceKind = 'workflow_task';
            $resumeSourceId = $nextTask->id;
        } elseif ($pendingChildResolutionWait !== null) {
            $waitKind = 'child';
            $waitReason = sprintf('Waiting to apply child workflow %s result', $pendingChildResolutionWait['label']);
            $waitStartedAt = $pendingChildResolutionWait['resolved_at'] ?? $pendingChildResolutionWait['opened_at'];
            $openWaitId = $pendingChildResolutionWait['id'];
            $resumeSourceKind = $pendingChildResolutionWait['resume_source_kind'];
            $resumeSourceId = $pendingChildResolutionWait['resume_source_id'];
        } elseif ($openChildWait !== null) {
            $waitKind = 'child';
            $waitReason = sprintf('Waiting for child workflow %s', $openChildWait['label']);
            $waitStartedAt = $openChildWait['opened_at'];
            $openWaitId = $openChildWait['id'];
            $resumeSourceKind = $openChildWait['resume_source_kind'];
            $resumeSourceId = $openChildWait['resume_source_id'];
        }

        [$livenessState, $livenessReason] = self::liveness(
            $run,
            $isTerminal,
            $openActivity,
            $diagnosticActivity,
            $unsupportedActivity,
            $openUpdateWait,
            $openUpdateTask,
            $openSignalApplicationWait,
            $openConditionWait,
            $openTimer,
            $diagnosticTimer,
            $unsupportedTimer,
            $nextTask,
            $replayBlockedTask,
            $openChildWait,
            $diagnosticChildWait,
            $pendingChildResolutionWait,
            $unsupportedChildWait,
            $openSignalWait,
        );

        $statusBucket = $run->status->statusBucket();
        $historyBudget = HistoryBudget::forRun($run);
        $commandContract = RunCommandContract::forRun($run);
        $repairBlockedReason = RepairBlockedReason::forRun(
            $run,
            $currentRun?->id === $run->id,
            $livenessState,
            $replayBlockedTask !== null,
        );

        $durationMs = null;

        if ($run->started_at !== null && $run->closed_at !== null) {
            $durationMs = max(0, (int) $run->closed_at->diffInMilliseconds($run->started_at));
        }

        $taskProblem = self::taskProblemDetected(
            $run,
            $nextTask,
            $replayBlockedTask,
            $openUpdateWait,
            $openUpdateTask,
            $openSignalApplicationWait,
            $openConditionWait,
            $pendingChildResolutionWait,
            $waitKind,
            $livenessState,
        );
        $failureSnapshots = FailureSnapshots::forRun($run);
        /** @var list<string> $failureIds */
        $failureIds = array_values(array_filter(array_map(
            static fn (array $failure): mixed => $failure['id'] ?? null,
            $failureSnapshots,
        ), static fn (mixed $failureId): bool => is_string($failureId) && $failureId !== ''));

        $sortTimestamp = RunSummarySortKey::timestamp($run->started_at, $run->created_at, $run->updated_at);
        $summaryModel = self::summaryModel();
        $selectedNextTask = $openUpdateWait !== null ? $openUpdateTask : $nextTask;

        // Full projection and bounded repair share one durable per-event
        // failed-child application marker. Commit it with the authoritative
        // summary count so a later repair cannot count the same event twice.
        $summary = self::upsertFullSummary(
            $summaryModel,
            $run,
            [
                'workflow_instance_id' => $run->workflow_instance_id,
                'run_number' => $run->run_number,
                'is_current_run' => $currentRun?->id === $run->id,
                'engine_source' => self::engineSource($run),
                'projection_schema_version' => self::SCHEMA_VERSION,
                'class' => $run->workflow_class,
                'workflow_type' => $run->workflow_type,
                'namespace' => $run->namespace ?? $run->instance?->namespace,
                'business_key' => $run->business_key ?? $run->instance?->business_key,
                'visibility_labels' => $run->visibility_labels ?? $run->instance?->visibility_labels,
                'compatibility' => $run->compatibility,
                'declared_entry_mode' => $commandContract['entry_mode'],
                'declared_contract_source' => $commandContract['source'],
                'status' => $run->status->value,
                'status_bucket' => $statusBucket->value,
                'closed_reason' => $run->closed_reason,
                'connection' => $run->connection,
                'queue' => $run->queue,
                'started_at' => $run->started_at,
                'sort_timestamp' => $sortTimestamp,
                'sort_key' => RunSummarySortKey::key(
                    $run->started_at,
                    $run->created_at,
                    $run->updated_at,
                    $run->id,
                ),
                'closed_at' => $run->closed_at,
                'archived_at' => $run->archived_at,
                'archive_command_id' => $run->archive_command_id,
                'archive_reason' => $run->archive_reason,
                'duration_ms' => $durationMs,
                'wait_kind' => $waitKind,
                'wait_reason' => $waitReason,
                'wait_started_at' => $waitStartedAt,
                'wait_deadline_at' => $waitDeadlineAt,
                'open_wait_id' => $openWaitId,
                'resume_source_kind' => $resumeSourceKind,
                'resume_source_id' => $resumeSourceId,
                'next_task_at' => $selectedNextTask?->available_at,
                'liveness_state' => $livenessState,
                'liveness_reason' => $livenessReason,
                'next_task_id' => $selectedNextTask?->id,
                'next_task_type' => $selectedNextTask?->task_type->value,
                'next_task_status' => $selectedNextTask?->status->value,
                'next_task_lease_expires_at' => $selectedNextTask?->lease_expires_at,
                'repair_blocked_reason' => $repairBlockedReason,
                'repair_attention' => RepairBlockedReason::needsAttention($repairBlockedReason),
                'task_problem' => $taskProblem,
                'exception_count' => count($failureSnapshots),
                'history_event_count' => $historyBudget['history_event_count'],
                'history_size_bytes' => $historyBudget['history_size_bytes'],
                'history_fan_out' => $historyBudget['history_fan_out'],
                'continue_as_new_recommended' => $historyBudget['continue_as_new_recommended'],
                'history_budget_pressure' => $historyBudget['pressure'],
                'created_at' => $run->created_at,
                'updated_at' => $run->closed_at ?? $run->last_progress_at ?? $run->updated_at,
            ],
            $failureIds,
        );

        RunWaitProjector::project($run);
        RunTimelineProjector::project($run);
        RunTimerProjector::project($run);
        RunLineageProjector::project($run);

        return $summary;
    }

    /**
     * @param class-string<WorkflowRunSummary> $summaryModel
     * @param array<string, mixed> $values
     * @param list<string> $failureIds
     */
    private static function upsertFullSummary(
        string $summaryModel,
        WorkflowRun $run,
        array $values,
        array $failureIds,
    ): WorkflowRunSummary {
        return $run->getConnection()->transaction(static function () use (
            $summaryModel,
            $run,
            $values,
            $failureIds,
        ): WorkflowRunSummary {
            /** @var WorkflowRunSummary $summary */
            $summary = IdempotentProjectionUpsert::upsert(
                $summaryModel,
                ['id' => $run->id],
                $values,
            );

            ChildProjectionRepairStore::markSnapshotFailuresCounted($run, $failureIds);

            return $summary;
        });
    }

    /**
     * Project the state transition made by a successful workflow-task claim
     * without rebuilding history-derived views. Indexed repair rows identify
     * every deferred child outcome, while a child-resume payload from an older
     * release can still identify one resolution that needs bounded catch-up.
     * The canonical history budget is resolved through counters or its single
     * aggregate fallback in both cases.
     */
    private static function projectWorkflowTaskClaim(
        WorkflowRun $run,
        WorkflowTask $task,
    ): WorkflowRunSummary {
        $childResolutionEvents = WorkflowTaskClaimProjectionContext::childResolutionEventsFor($run);
        $knownEventIds = array_map(
            static fn (WorkflowHistoryEvent $event): string => (string) $event->getKey(),
            $childResolutionEvents,
        );
        $compatibilityEvent = self::claimChildResolutionEvent($run, $task, $knownEventIds);

        if (
            $compatibilityEvent instanceof WorkflowHistoryEvent
            && ! in_array((string) $compatibilityEvent->getKey(), $knownEventIds, true)
        ) {
            $childResolutionEvents[] = $compatibilityEvent;
        }

        usort(
            $childResolutionEvents,
            static fn (WorkflowHistoryEvent $left, WorkflowHistoryEvent $right): int =>
                (int) $left->sequence <=> (int) $right->sequence,
        );

        return self::projectOpenWorkflowTask(
            $run,
            $task,
            $childResolutionEvents,
        );
    }

    /**
     * Apply one open workflow task and the bounded child resolutions assigned
     * to it. Resolution effects are idempotent and are normally projected as
     * each event is recorded; the task-payload lookup preserves upgrade
     * compatibility for work queued by older releases.
     *
     * @param list<WorkflowHistoryEvent> $childResolutionEvents
     */
    private static function projectOpenWorkflowTask(
        WorkflowRun $run,
        WorkflowTask $task,
        array $childResolutionEvents,
    ): WorkflowRunSummary {
        if (
            $run->status->isTerminal()
            || $task->workflow_run_id !== $run->id
            || $task->task_type !== TaskType::Workflow
            || ! in_array($task->status, [TaskStatus::Ready, TaskStatus::Leased], true)
        ) {
            throw new \LogicException('Bounded workflow-task projection requires an open task on an open run.');
        }

        $historyBudget = HistoryBudget::forRunBounded($run);
        $summaryModel = self::summaryModel();

        /** @var WorkflowRunSummary|null $existingSummary */
        $existingSummary = $summaryModel::query()->find($run->id);
        $replayBlockedTask = self::claimReplayBlockedTask($run);
        $hasChildResolution = $childResolutionEvents !== [];
        $hasPendingChildResolution = $hasChildResolution
            || RunWaitProjector::hasPendingChildResolutionForTask($run, $task);
        [$taskLivenessState, $taskLivenessReason] = self::taskLiveness(
            $task,
            $run,
            self::claimTaskLabel($existingSummary, $task),
        );
        $projectTaskLiveness = $replayBlockedTask instanceof WorkflowTask
            || $hasChildResolution
            || self::claimOwnsProjectedLiveness($existingSummary)
            || $existingSummary?->liveness_state === 'timer_scheduled';
        $livenessState = $projectTaskLiveness
            ? $taskLivenessState
            : $existingSummary?->liveness_state;
        $livenessReason = $projectTaskLiveness
            ? $taskLivenessReason
            : $existingSummary?->liveness_reason;

        if ($replayBlockedTask instanceof WorkflowTask) {
            [$livenessState, $livenessReason] = self::replayBlockedLiveness($replayBlockedTask);
        } elseif ($existingSummary?->liveness_state === 'timer_scheduled') {
            $livenessState = 'timer_task_leased';
            $livenessReason = sprintf('Timer task %s is leased to a worker.', $task->id);
        }

        $isCurrentRun = $existingSummary instanceof WorkflowRunSummary
            ? (bool) $existingSummary->is_current_run
            : self::claimIsCurrentRun($run);
        $repairBlockedReason = $projectTaskLiveness || ! $existingSummary instanceof WorkflowRunSummary
            ? RepairBlockedReason::forRun(
                $run,
                $isCurrentRun,
                is_string($livenessState) ? $livenessState : null,
                $replayBlockedTask instanceof WorkflowTask,
            )
            : $existingSummary->repair_blocked_reason;
        $values = [
            'is_current_run' => $isCurrentRun,
            'liveness_state' => $livenessState,
            'liveness_reason' => $livenessReason,
            'next_task_at' => $task->available_at,
            'next_task_id' => $task->id,
            'next_task_type' => $task->task_type->value,
            'next_task_status' => $task->status->value,
            'next_task_lease_expires_at' => $task->lease_expires_at,
            'repair_blocked_reason' => $repairBlockedReason,
            'repair_attention' => RepairBlockedReason::needsAttention($repairBlockedReason),
            'task_problem' => $replayBlockedTask instanceof WorkflowTask
                || $hasPendingChildResolution
                || self::claimHasWorkflowTaskProblem($run)
                || ($existingSummary?->task_problem === true && $existingSummary->wait_kind === 'child'),
            'history_event_count' => $historyBudget['history_event_count'],
            'history_size_bytes' => $historyBudget['history_size_bytes'],
            'history_fan_out' => $historyBudget['history_fan_out'],
            'continue_as_new_recommended' => $historyBudget['continue_as_new_recommended'],
            'history_budget_pressure' => $historyBudget['pressure'],
            'updated_at' => $run->last_progress_at ?? $run->updated_at,
        ];
        $exceptionCount = self::claimExceptionCount(
            $run,
            $existingSummary,
            $childResolutionEvents,
        );
        $values['exception_count'] = $exceptionCount['count'];

        if (! $existingSummary instanceof WorkflowRunSummary) {
            $values = array_merge(self::claimSummaryIdentity($run), $values);
        }

        if (
            self::claimProjectsWorkflowTaskWait($task)
            || ! $existingSummary instanceof WorkflowRunSummary
            || $existingSummary->wait_kind === null
            || $existingSummary->wait_kind === 'workflow-task'
        ) {
            $values = array_merge($values, [
                'wait_kind' => 'workflow-task',
                'wait_reason' => $task->status === TaskStatus::Leased
                    ? 'Workflow task leased to worker'
                    : 'Workflow task ready',
                'wait_started_at' => $task->leased_at ?? $task->available_at,
                'wait_deadline_at' => $task->lease_expires_at,
                'open_wait_id' => sprintf('workflow-task:%s', $task->id),
                'resume_source_kind' => 'workflow_task',
                'resume_source_id' => $task->id,
            ]);
        }

        if ($exceptionCount['event_ids'] === []) {
            /** @var WorkflowRunSummary $summary */
            $summary = IdempotentProjectionUpsert::upsert(
                $summaryModel,
                ['id' => $run->id],
                $values,
            );
        } else {
            // Best-effort child projection may catch a later projection error.
            // Keep the count and its event marker in one transaction so neither
            // half can survive alone before the repair is retried.
            /** @var WorkflowRunSummary $summary */
            $summary = $run->getConnection()->transaction(static function () use (
                $summaryModel,
                $run,
                $values,
                $exceptionCount,
            ): WorkflowRunSummary {
                /** @var WorkflowRunSummary $summary */
                $summary = IdempotentProjectionUpsert::upsert(
                    $summaryModel,
                    ['id' => $run->id],
                    $values,
                );

                ChildProjectionRepairStore::markFailedChildrenCounted(
                    $run,
                    $exceptionCount['event_ids'],
                );

                return $summary;
            });
        }

        foreach ($childResolutionEvents as $childResolutionEvent) {
            RunWaitProjector::projectChildResolutionEvent($run, $task, $childResolutionEvent);
            RunTimelineProjector::projectChildResolutionEvent($run, $childResolutionEvent);
            RunLineageProjector::projectChildResolutionEvent($run, $childResolutionEvent);
        }

        RunWaitProjector::projectWorkflowTaskClaim(
            $run,
            $task,
            self::claimWaitId($existingSummary, $task),
        );

        return $summary;
    }

    /**
     * @param list<string> $knownEventIds
     */
    private static function claimChildResolutionEvent(
        WorkflowRun $run,
        WorkflowTask $task,
        array $knownEventIds = [],
    ): ?WorkflowHistoryEvent {
        $taskPayload = is_array($task->payload) ? $task->payload : [];

        if (($taskPayload['workflow_wait_kind'] ?? null) !== 'child') {
            return null;
        }

        $eventTypeValue = self::nonEmptyString($taskPayload['workflow_event_type'] ?? null);
        $eventType = $eventTypeValue === null ? null : HistoryEventType::tryFrom($eventTypeValue);

        if ($eventType === null || ! in_array($eventType, ChildRunHistory::resolutionEventTypes(), true)) {
            return null;
        }

        $eventId = self::nonEmptyString($taskPayload['workflow_history_event_id'] ?? null);

        if ($eventId !== null && in_array($eventId, $knownEventIds, true)) {
            return null;
        }

        $workflowSequence = self::intValue($taskPayload['workflow_sequence'] ?? null);
        $childCallId = self::nonEmptyString($taskPayload['child_call_id'] ?? null);
        $childRunId = self::nonEmptyString($taskPayload['child_workflow_run_id'] ?? null)
            ?? self::nonEmptyString($taskPayload['resume_source_id'] ?? null);
        $query = ConfiguredV2Models::query('history_event_model', WorkflowHistoryEvent::class)
            ->where('workflow_run_id', $run->id)
            ->where('event_type', $eventType->value);

        if ($eventId !== null) {
            $query->whereKey($eventId);
        } else {
            if ($workflowSequence === null) {
                return null;
            }

            $query->where('payload->sequence', $workflowSequence);

            if ($childCallId !== null) {
                $query->where('payload->child_call_id', $childCallId);
            }

            if ($childRunId !== null) {
                $query->where('payload->child_workflow_run_id', $childRunId);
            }
        }

        /** @var WorkflowHistoryEvent|null $event */
        $event = $query
            ->orderByDesc('sequence')
            ->first();

        if (! $event instanceof WorkflowHistoryEvent) {
            return null;
        }

        $eventPayload = is_array($event->payload) ? $event->payload : [];

        if (
            ($workflowSequence !== null && self::intValue($eventPayload['sequence'] ?? null) !== $workflowSequence)
            || ($childCallId !== null && self::nonEmptyString($eventPayload['child_call_id'] ?? null) !== $childCallId)
            || ($childRunId !== null && self::nonEmptyString(
                $eventPayload['child_workflow_run_id'] ?? null
            ) !== $childRunId)
        ) {
            return null;
        }

        return $event;
    }

    /**
     * @param list<WorkflowHistoryEvent> $childResolutionEvents
     * @return array{count: int, event_ids: list<string>}
     */
    private static function claimExceptionCount(
        WorkflowRun $run,
        ?WorkflowRunSummary $summary,
        array $childResolutionEvents,
    ): array {
        $count = (int) ($summary?->exception_count
            ?? ConfiguredV2Models::query('failure_model', WorkflowFailure::class)
                ->where('workflow_run_id', $run->id)
                ->count());

        $summaryHistoryCount = max(0, (int) ($summary?->history_event_count ?? 0));
        $applications = ChildProjectionRepairStore::failedChildCountApplications(
            $run,
            $childResolutionEvents,
        );
        $eventIds = [];

        foreach ($childResolutionEvents as $childResolutionEvent) {
            $eventPayload = is_array($childResolutionEvent->payload) ? $childResolutionEvent->payload : [];
            $failureId = self::nonEmptyString($eventPayload['failure_id'] ?? null);
            $eventId = (string) $childResolutionEvent->getKey();

            if (
                $failureId !== null
                && array_key_exists($eventId, $applications)
                && ! $applications[$eventId]
            ) {
                $count++;
                $eventIds[] = $eventId;
            } elseif (
                $failureId !== null
                && ! array_key_exists($eventId, $applications)
                && $summaryHistoryCount < (int) $childResolutionEvent->sequence
            ) {
                // Tasks created before durable repair identities still use the
                // summary history count as their one-event compatibility path.
                $count++;
            }
        }

        return [
            'count' => $count,
            'event_ids' => $eventIds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function claimSummaryIdentity(WorkflowRun $run): array
    {
        /** @var WorkflowInstance|null $instance */
        $instance = $run->instance()->first();
        $commandContract = self::freshRunCommandContract($run);
        $sortTimestamp = RunSummarySortKey::timestamp($run->started_at, $run->created_at, $run->updated_at);

        return [
            'workflow_instance_id' => $run->workflow_instance_id,
            'run_number' => $run->run_number,
            'engine_source' => self::engineSource($run),
            'projection_schema_version' => self::SCHEMA_VERSION,
            'class' => $run->workflow_class,
            'workflow_type' => $run->workflow_type,
            'namespace' => $run->namespace ?? $instance?->namespace,
            'business_key' => $run->business_key ?? $instance?->business_key,
            'visibility_labels' => $run->visibility_labels ?? $instance?->visibility_labels,
            'compatibility' => $run->compatibility,
            'declared_entry_mode' => $commandContract['entry_mode'],
            'declared_contract_source' => $commandContract['source'],
            'status' => $run->status->value,
            'status_bucket' => $run->status->statusBucket()->value,
            'closed_reason' => $run->closed_reason,
            'connection' => $run->connection,
            'queue' => $run->queue,
            'started_at' => $run->started_at,
            'sort_timestamp' => $sortTimestamp,
            'sort_key' => RunSummarySortKey::key(
                $run->started_at,
                $run->created_at,
                $run->updated_at,
                $run->id,
            ),
            'closed_at' => $run->closed_at,
            'archived_at' => $run->archived_at,
            'archive_command_id' => $run->archive_command_id,
            'archive_reason' => $run->archive_reason,
            'duration_ms' => null,
            'created_at' => $run->created_at,
        ];
    }

    private static function claimIsCurrentRun(WorkflowRun $run): bool
    {
        return ConfiguredV2Models::query('instance_model', WorkflowInstance::class)
            ->whereKey($run->workflow_instance_id)
            ->where('current_run_id', $run->id)
            ->exists();
    }

    private static function claimReplayBlockedTask(WorkflowRun $run): ?WorkflowTask
    {
        /** @var WorkflowTask|null $task */
        $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Failed->value)
            ->whereJsonContains('payload->replay_blocked', true)
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->limit(1)
            ->first();

        return $task;
    }

    private static function claimHasWorkflowTaskProblem(WorkflowRun $run): bool
    {
        $now = now();
        $redispatchCutoff = $now->copy()->subSeconds(TaskRepairPolicy::redispatchAfterSeconds());

        return ConfiguredV2Models::query('task_model', WorkflowTask::class)
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where(static function ($problem) use ($now, $redispatchCutoff): void {
                $problem->where('repair_count', '>', 0)
                    ->orWhereJsonContains('payload->replay_blocked', true)
                    ->orWhere(static function ($leased) use ($now): void {
                        $leased->where('status', TaskStatus::Leased->value)
                            ->whereNotNull('lease_expires_at')
                            ->where('lease_expires_at', '<=', $now);
                    })
                    ->orWhere(static function ($ready) use ($now, $redispatchCutoff): void {
                        $ready->where('status', TaskStatus::Ready->value)
                            ->where(static function ($readyProblem) use ($now, $redispatchCutoff): void {
                                $readyProblem
                                    ->where(static function ($claimFailed): void {
                                        $claimFailed->whereNotNull('last_claim_failed_at')
                                            ->whereNotNull('last_claim_error')
                                            ->where('last_claim_error', '!=', '');
                                    })
                                    ->orWhere(static function ($dispatchFailed): void {
                                        $dispatchFailed->whereNotNull('last_dispatch_attempt_at')
                                            ->whereNotNull('last_dispatch_error')
                                            ->where('last_dispatch_error', '!=', '')
                                            ->where(static function ($latestDispatch): void {
                                                $latestDispatch->whereNull('last_dispatched_at')
                                                    ->orWhereColumn(
                                                        'last_dispatch_attempt_at',
                                                        '>',
                                                        'last_dispatched_at',
                                                    );
                                            });
                                    })
                                    ->orWhere(static function ($dispatchOverdue) use (
                                        $now,
                                        $redispatchCutoff,
                                    ): void {
                                        $dispatchOverdue
                                            ->where(static function ($available) use ($now): void {
                                                $available->whereNull('available_at')
                                                    ->orWhere('available_at', '<=', $now);
                                            })
                                            ->where(static function ($dispatchHealthy): void {
                                                $dispatchHealthy->whereNull('last_dispatch_attempt_at')
                                                    ->orWhereNull('last_dispatch_error')
                                                    ->orWhere('last_dispatch_error', '')
                                                    ->orWhere(static function ($successfulDispatch): void {
                                                        $successfulDispatch->whereNotNull('last_dispatched_at')
                                                            ->whereColumn(
                                                                'last_dispatch_attempt_at',
                                                                '<=',
                                                                'last_dispatched_at',
                                                            );
                                                    });
                                            })
                                            ->where(static function ($claimHealthy): void {
                                                $claimHealthy->whereNull('last_claim_failed_at')
                                                    ->orWhereNull('last_claim_error')
                                                    ->orWhere('last_claim_error', '');
                                            })
                                            ->where(static function ($dispatch) use ($redispatchCutoff): void {
                                                $dispatch->where(static function ($sent) use ($redispatchCutoff): void {
                                                    $sent->whereNotNull('last_dispatched_at')
                                                        ->where('last_dispatched_at', '<=', $redispatchCutoff);
                                                })->orWhere(static function ($neverSent) use ($redispatchCutoff): void {
                                                    $neverSent->whereNull('last_dispatched_at')
                                                        ->where('created_at', '<=', $redispatchCutoff);
                                                });
                                            });
                                    });
                            });
                    });
            })
            ->exists();
    }

    private static function claimWaitId(
        ?WorkflowRunSummary $summary,
        WorkflowTask $task,
    ): ?string {
        $payloadWaitId = is_array($task->payload)
            ? self::nonEmptyString($task->payload['open_wait_id'] ?? null)
            : null;

        if ($payloadWaitId !== null) {
            return $payloadWaitId;
        }

        if ($summary?->next_task_id !== $task->id) {
            return null;
        }

        return self::nonEmptyString($summary->open_wait_id);
    }

    private static function claimOwnsProjectedLiveness(?WorkflowRunSummary $summary): bool
    {
        if (! $summary instanceof WorkflowRunSummary) {
            return true;
        }

        if ($summary->wait_kind === null || $summary->wait_kind === 'workflow-task') {
            return true;
        }

        return is_string($summary->liveness_state)
            && (
                str_starts_with($summary->liveness_state, 'workflow_task_')
                || $summary->liveness_state === 'repair_needed'
            );
    }

    private static function claimTaskLabel(?WorkflowRunSummary $summary, WorkflowTask $task): string
    {
        $taskPayload = is_array($task->payload) ? $task->payload : [];
        $payloadLabel = match (self::nonEmptyString($taskPayload['workflow_wait_kind'] ?? null)) {
            'update' => 'Update',
            'signal' => 'Signal',
            'condition' => 'Condition timeout',
            'timer' => 'Timer',
            default => null,
        };

        if ($payloadLabel !== null) {
            return $payloadLabel;
        }

        $livenessReason = $summary?->liveness_reason;

        if (! $summary instanceof WorkflowRunSummary || ! is_string($livenessReason)) {
            return 'Workflow';
        }

        $nextTaskId = $summary->next_task_id;
        $taskId = is_string($nextTaskId) && $nextTaskId !== ''
            ? $nextTaskId
            : (string) $task->id;
        $separator = sprintf(' task %s ', $taskId);
        $separatorPosition = strpos($livenessReason, $separator);

        if ($separatorPosition === false) {
            return 'Workflow';
        }

        $label = trim(substr($livenessReason, 0, $separatorPosition));

        return $label === '' ? 'Workflow' : $label;
    }

    private static function claimProjectsWorkflowTaskWait(WorkflowTask $task): bool
    {
        $taskPayload = is_array($task->payload) ? $task->payload : [];
        $waitKind = self::nonEmptyString($taskPayload['workflow_wait_kind'] ?? null);

        return $waitKind === null || $waitKind === 'child';
    }

    private static function projectFreshWorkflowTaskRun(WorkflowRun $run): ?WorkflowRunSummary
    {
        if ($run->status->isTerminal()) {
            return null;
        }

        if ((int) ($run->last_history_sequence ?? 0) !== 2) {
            return null;
        }

        $nextTask = self::freshStartWorkflowTask($run);

        if (! $nextTask instanceof WorkflowTask) {
            return null;
        }

        $run->loadMissing('instance');

        $waitReason = match (true) {
            self::taskWaitingForCompatibleWorker(
                $nextTask,
                $run
            ) => 'Workflow task waiting for a compatible worker',
            TaskRepairPolicy::dispatchFailed($nextTask) => 'Workflow task dispatch failed',
            TaskRepairPolicy::claimFailed($nextTask) => 'Workflow task claim failed',
            TaskRepairPolicy::leaseExpired($nextTask) => 'Workflow task lease expired',
            TaskRepairPolicy::dispatchOverdue($nextTask) => 'Workflow task ready but dispatch is overdue',
            $nextTask->status === TaskStatus::Leased => 'Workflow task leased to worker',
            default => 'Workflow task ready',
        };
        [$livenessState, $livenessReason] = self::taskLiveness($nextTask, $run, 'Workflow');
        $statusBucket = $run->status->statusBucket();
        $commandContract = self::freshRunCommandContract($run);
        $sortTimestamp = RunSummarySortKey::timestamp($run->started_at, $run->created_at, $run->updated_at);
        $historyEventCount = max(0, (int) ($run->last_history_sequence ?? 0));
        $summaryModel = self::summaryModel();

        /** @var WorkflowRunSummary $summary */
        $summary = IdempotentProjectionUpsert::upsert(
            $summaryModel,
            [
                'id' => $run->id,
            ],
            [
                'workflow_instance_id' => $run->workflow_instance_id,
                'run_number' => $run->run_number,
                'is_current_run' => $run->instance?->current_run_id === $run->id,
                'engine_source' => self::engineSource($run),
                'projection_schema_version' => self::SCHEMA_VERSION,
                'class' => $run->workflow_class,
                'workflow_type' => $run->workflow_type,
                'namespace' => $run->namespace ?? $run->instance?->namespace,
                'business_key' => $run->business_key ?? $run->instance?->business_key,
                'visibility_labels' => $run->visibility_labels ?? $run->instance?->visibility_labels,
                'compatibility' => $run->compatibility,
                'declared_entry_mode' => $commandContract['entry_mode'],
                'declared_contract_source' => $commandContract['source'],
                'status' => $run->status->value,
                'status_bucket' => $statusBucket->value,
                'closed_reason' => $run->closed_reason,
                'connection' => $run->connection,
                'queue' => $run->queue,
                'started_at' => $run->started_at,
                'sort_timestamp' => $sortTimestamp,
                'sort_key' => RunSummarySortKey::key(
                    $run->started_at,
                    $run->created_at,
                    $run->updated_at,
                    $run->id,
                ),
                'closed_at' => $run->closed_at,
                'archived_at' => $run->archived_at,
                'archive_command_id' => $run->archive_command_id,
                'archive_reason' => $run->archive_reason,
                'duration_ms' => null,
                'wait_kind' => 'workflow-task',
                'wait_reason' => $waitReason,
                'wait_started_at' => $nextTask->leased_at ?? $nextTask->available_at,
                'wait_deadline_at' => $nextTask->lease_expires_at,
                'open_wait_id' => sprintf('workflow-task:%s', $nextTask->id),
                'resume_source_kind' => 'workflow_task',
                'resume_source_id' => $nextTask->id,
                'next_task_at' => $nextTask->available_at,
                'liveness_state' => $livenessState,
                'liveness_reason' => $livenessReason,
                'next_task_id' => $nextTask->id,
                'next_task_type' => $nextTask->task_type->value,
                'next_task_status' => $nextTask->status->value,
                'next_task_lease_expires_at' => $nextTask->lease_expires_at,
                'repair_blocked_reason' => null,
                'repair_attention' => false,
                'task_problem' => false,
                'exception_count' => 0,
                'history_event_count' => $historyEventCount,
                'history_size_bytes' => 0,
                'history_fan_out' => 0,
                'continue_as_new_recommended' => false,
                'history_budget_pressure' => 'ok',
                'created_at' => $run->created_at,
                'updated_at' => $run->closed_at ?? $run->last_progress_at ?? $run->updated_at,
            ],
        );

        return $summary;
    }

    private static function freshStartWorkflowTask(WorkflowRun $run): ?WorkflowTask
    {
        if ($run->relationLoaded('tasks')) {
            /** @var \Illuminate\Database\Eloquent\Collection<int, WorkflowTask> $tasks */
            $tasks = $run->getRelation('tasks');
        } else {
            /** @var \Illuminate\Database\Eloquent\Collection<int, WorkflowTask> $tasks */
            $tasks = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                ->where('workflow_run_id', $run->id)
                ->limit(2)
                ->get();
        }

        if ($tasks->count() !== 1) {
            return null;
        }

        /** @var WorkflowTask $task */
        $task = $tasks->first();

        if (
            $task->task_type !== TaskType::Workflow
            || ! in_array($task->status, [TaskStatus::Ready, TaskStatus::Leased], true)
        ) {
            return null;
        }

        if (is_array($task->payload) && $task->payload !== []) {
            return null;
        }

        if (
            TaskRepairPolicy::leaseExpired($task)
            || TaskRepairPolicy::dispatchFailed($task)
            || TaskRepairPolicy::claimFailed($task)
            || TaskRepairPolicy::dispatchOverdue($task)
        ) {
            return null;
        }

        return $task;
    }

    /**
     * @return array{entry_mode: 'canonical'|null, source: string}
     */
    private static function freshRunCommandContract(WorkflowRun $run): array
    {
        if (! is_string($run->workflow_class)
            || $run->workflow_class === ''
            || ! class_exists($run->workflow_class)
            || ! is_subclass_of($run->workflow_class, Workflow::class)
        ) {
            return [
                'entry_mode' => null,
                'source' => RunCommandContract::SOURCE_UNAVAILABLE,
            ];
        }

        try {
            $contract = RunCommandContract::snapshot($run->workflow_class);

            return [
                'entry_mode' => $contract['entry_mode'],
                'source' => RunCommandContract::SOURCE_DURABLE_HISTORY,
            ];
        } catch (Throwable) {
            return [
                'entry_mode' => null,
                'source' => RunCommandContract::SOURCE_UNAVAILABLE,
            ];
        }
    }

    /**
     * @return class-string<WorkflowRunSummary>
     */
    private static function summaryModel(): string
    {
        /** @var class-string<WorkflowRunSummary> $model */
        $model = config('workflows.v2.run_summary_model', WorkflowRunSummary::class);

        return $model;
    }

    private static function engineSource(WorkflowRun $run): string
    {
        return $run->import_source === EmbeddedV2ImportContract::IMPORT_SOURCE
            ? EmbeddedV2ImportContract::ENGINE_SOURCE
            : 'v2';
    }

    private static function taskProblemDetected(
        WorkflowRun $run,
        ?WorkflowTask $nextTask,
        ?WorkflowTask $replayBlockedTask,
        ?array $openUpdateWait,
        ?WorkflowTask $openUpdateTask,
        ?array $openSignalApplicationWait,
        ?array $openConditionWait,
        ?array $pendingChildResolutionWait,
        ?string $waitKind,
        string $livenessState,
    ): bool {
        if ($replayBlockedTask !== null) {
            return true;
        }

        foreach ($run->tasks as $task) {
            if (self::workflowTaskProblemEvidence($task)) {
                return true;
            }
        }

        if ($openUpdateWait !== null && $openUpdateTask === null) {
            return true;
        }

        if ($openSignalApplicationWait !== null) {
            return true;
        }

        if ($pendingChildResolutionWait !== null) {
            return true;
        }

        if (
            $openConditionWait !== null
            && self::timestamp($openConditionWait['timeout_fired_at'] ?? null) !== null
            && $nextTask === null
        ) {
            return true;
        }

        return $livenessState === 'repair_needed' && $waitKind === null;
    }

    private static function workflowTaskProblemEvidence(WorkflowTask $task): bool
    {
        if ($task->task_type !== TaskType::Workflow) {
            return false;
        }

        if (($task->payload['replay_blocked'] ?? false) === true) {
            return true;
        }

        if ($task->repair_count > 0) {
            return true;
        }

        return TaskRepairPolicy::dispatchFailed($task)
            || TaskRepairPolicy::dispatchOverdue($task)
            || TaskRepairPolicy::claimFailed($task)
            || TaskRepairPolicy::leaseExpired($task);
    }

    private static function nextOpenTask(WorkflowRun $run): ?WorkflowTask
    {
        /** @var WorkflowTask|null $task */
        $task = $run->tasks
            ->filter(static fn (WorkflowTask $task): bool => in_array(
                $task->status,
                [TaskStatus::Ready, TaskStatus::Leased],
                true,
            ))
            ->sort(static function (WorkflowTask $left, WorkflowTask $right): int {
                $leftStatus = $left->status === TaskStatus::Leased ? 0 : 1;
                $rightStatus = $right->status === TaskStatus::Leased ? 0 : 1;

                if ($leftStatus !== $rightStatus) {
                    return $leftStatus <=> $rightStatus;
                }

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

    private static function replayBlockedTask(WorkflowRun $run): ?WorkflowTask
    {
        /** @var WorkflowTask|null $task */
        $task = $run->tasks
            ->filter(static fn (WorkflowTask $task): bool => $task->task_type === TaskType::Workflow
                && $task->status === TaskStatus::Failed
                && ($task->payload['replay_blocked'] ?? false) === true)
            ->sortByDesc(static fn (WorkflowTask $task): int => $task->updated_at?->getTimestampMs()
                ?? $task->created_at?->getTimestampMs()
                ?? 0)
            ->first();

        return $task;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function liveness(
        WorkflowRun $run,
        bool $isTerminal,
        ?array $openActivity,
        ?array $diagnosticActivity,
        ?array $unsupportedActivity,
        ?array $openUpdateWait,
        ?WorkflowTask $openUpdateTask,
        ?array $openSignalApplicationWait,
        ?array $openConditionWait,
        ?array $openTimer,
        ?array $diagnosticTimer,
        ?array $unsupportedTimer,
        ?WorkflowTask $nextTask,
        ?WorkflowTask $replayBlockedTask,
        ?array $openChildWait,
        ?array $diagnosticChildWait,
        ?array $pendingChildResolutionWait,
        ?array $unsupportedChildWait,
        ?array $openSignalWait,
    ): array {
        if ($isTerminal) {
            return ['closed', sprintf('Run closed as %s.', $run->closed_reason ?? $run->status->value)];
        }

        if ($replayBlockedTask !== null) {
            return self::replayBlockedLiveness($replayBlockedTask);
        }

        if ($nextTask !== null && self::hasActionableTransportFailure($nextTask)) {
            return self::taskLiveness($nextTask, $run, self::taskLabelFor($nextTask));
        }

        if ($diagnosticActivity !== null) {
            return [
                'workflow_replay_blocked',
                sprintf(
                    'Activity %s is visible only from an older mutable row without typed activity history. This row is diagnostic-only and does not satisfy the durable resume-path invariant.',
                    self::activityType($diagnosticActivity),
                ),
            ];
        }

        if ($openActivity !== null) {
            if ($nextTask !== null) {
                return self::taskLiveness($nextTask, $run, 'Activity');
            }

            if (($openActivity['status'] ?? null) === ActivityStatus::Running->value) {
                return [
                    'activity_running_without_task',
                    sprintf(
                        'Activity %s is already running without an open activity task. Repair is deferred to avoid duplicating in-flight work.',
                        $openActivity['id'],
                    ),
                ];
            }

            return [
                'repair_needed',
                sprintf(
                    'Activity %s is %s without an open activity task.',
                    $openActivity['id'],
                    $openActivity['status'] ?? ActivityStatus::Pending->value,
                ),
            ];
        }

        if ($openUpdateWait !== null) {
            if ($openUpdateTask !== null) {
                return self::taskLiveness($openUpdateTask, $run, 'Update');
            }

            return [
                'repair_needed',
                sprintf('Accepted update %s is open without an open workflow task.', $openUpdateWait['name']),
            ];
        }

        if ($openSignalApplicationWait !== null) {
            return [
                'repair_needed',
                sprintf(
                    'Accepted signal %s is received without an open workflow task.',
                    $openSignalApplicationWait['name'],
                ),
            ];
        }

        if ($openConditionWait !== null) {
            if ($openConditionWait['timer_id'] !== null) {
                if (self::timestamp($openConditionWait['timeout_fired_at'] ?? null) !== null) {
                    if ($nextTask !== null) {
                        return self::taskLiveness($nextTask, $run, 'Condition timeout');
                    }

                    return [
                        'repair_needed',
                        sprintf(
                            'Condition wait %s has a fired timeout without an open workflow task.',
                            $openConditionWait['id']
                        ),
                    ];
                }

                if ($nextTask !== null) {
                    if (
                        TaskRepairPolicy::leaseExpired($nextTask)
                        || TaskRepairPolicy::readyTaskNeedsRedispatch($nextTask)
                        || TaskRepairPolicy::claimFailed($nextTask)
                    ) {
                        return self::taskLiveness($nextTask, $run, 'Condition timeout');
                    }

                    return [
                        'waiting_for_condition',
                        sprintf(
                            'Waiting for condition-changing input or timeout at %s.',
                            $openConditionWait['deadline_at']?->toJSON() ?? 'an unknown time',
                        ),
                    ];
                }

                return [
                    'repair_needed',
                    sprintf('Condition wait %s is open without an open timeout task.', $openConditionWait['id']),
                ];
            }

            return ['waiting_for_condition', 'Waiting for a condition-changing durable input.'];
        }

        if ($openSignalWait !== null && $openSignalWait['timer_id'] !== null) {
            if (self::timestamp($openSignalWait['timeout_fired_at'] ?? null) !== null) {
                if ($nextTask !== null) {
                    return self::taskLiveness($nextTask, $run, 'Signal timeout');
                }

                return [
                    'repair_needed',
                    sprintf('Signal wait %s has a fired timeout without an open workflow task.', $openSignalWait['id']),
                ];
            }

            if ($nextTask !== null) {
                if (
                    TaskRepairPolicy::leaseExpired($nextTask)
                    || TaskRepairPolicy::readyTaskNeedsRedispatch($nextTask)
                    || TaskRepairPolicy::claimFailed($nextTask)
                ) {
                    return self::taskLiveness($nextTask, $run, 'Signal timeout');
                }

                return [
                    'waiting_for_signal',
                    sprintf(
                        'Waiting for signal %s or timeout at %s.',
                        $openSignalWait['name'],
                        $openSignalWait['deadline_at']?->toJSON() ?? 'an unknown time',
                    ),
                ];
            }

            return [
                'repair_needed',
                sprintf('Signal wait %s is open without an open timeout task.', $openSignalWait['id']),
            ];
        }

        if ($openTimer !== null) {
            if ($nextTask !== null) {
                if (
                    TaskRepairPolicy::leaseExpired($nextTask)
                    || TaskRepairPolicy::readyTaskNeedsRedispatch($nextTask)
                    || TaskRepairPolicy::claimFailed($nextTask)
                ) {
                    return self::taskLiveness($nextTask, $run, 'Timer');
                }

                return $nextTask->status === TaskStatus::Leased
                    ? ['timer_task_leased', sprintf('Timer task %s is leased to a worker.', $nextTask->id)]
                    : [
                        'timer_scheduled',
                        sprintf(
                            'Timer task %s is scheduled to fire at %s.',
                            $nextTask->id,
                            $openTimer['fire_at']?->toJSON()
                        ),
                    ];
            }

            return ['repair_needed', sprintf('Timer %s is pending without an open timer task.', $openTimer['id'])];
        }

        if ($diagnosticTimer !== null) {
            return [
                'workflow_replay_blocked',
                sprintf(
                    'Timer %s is visible only from an older mutable row without typed timer history. This row is diagnostic-only and does not satisfy the durable resume-path invariant.',
                    $diagnosticTimer['id'] ?? 'unknown',
                ),
            ];
        }

        if ($nextTask !== null) {
            return self::taskLiveness($nextTask, $run, 'Workflow');
        }

        if ($unsupportedActivity !== null) {
            return [
                'workflow_replay_blocked',
                sprintf(
                    'Activity %s has terminal mutable activity state without typed activity history. Run a compatible build or treat this older preview data as unsupported.',
                    self::activityType($unsupportedActivity),
                ),
            ];
        }

        if ($unsupportedTimer !== null) {
            return [
                'workflow_replay_blocked',
                sprintf(
                    'Timer %s has terminal mutable timer state without typed timer history. Run a compatible build or treat this older preview data as unsupported.',
                    $unsupportedTimer['id'] ?? 'unknown',
                ),
            ];
        }

        if ($pendingChildResolutionWait !== null) {
            return [
                'repair_needed',
                sprintf(
                    'Child workflow %s is resolved without an open workflow task.',
                    $pendingChildResolutionWait['label'],
                ),
            ];
        }

        if ($openChildWait !== null) {
            return ['waiting_for_child', sprintf('Waiting for child workflow %s.', $openChildWait['label'])];
        }

        if ($diagnosticChildWait !== null) {
            return [
                'workflow_replay_blocked',
                sprintf(
                    'Child workflow %s is visible only from an older mutable row or link without typed parent child history. This state is diagnostic-only and does not satisfy the durable resume-path invariant.',
                    $diagnosticChildWait['label'],
                ),
            ];
        }

        if ($unsupportedChildWait !== null) {
            return [
                'workflow_replay_blocked',
                sprintf(
                    'Child workflow %s has terminal mutable child state without typed parent child history. Run a compatible build or treat this older preview data as unsupported.',
                    $unsupportedChildWait['label'],
                ),
            ];
        }

        if ($openSignalWait !== null) {
            return ['waiting_for_signal', sprintf('Waiting for signal %s.', $openSignalWait['name'])];
        }

        return ['repair_needed', 'Run is non-terminal but has no durable next-resume source.'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function replayBlockedLiveness(WorkflowTask $task): array
    {
        if (($task->payload['replay_blocked_reason'] ?? null) === 'condition_wait_definition_mismatch') {
            $sequence = self::intValue($task->payload['replay_blocked_workflow_sequence'] ?? null);
            $recorded = self::nonEmptyString($task->payload['replay_blocked_recorded_condition_key'] ?? null)
                ?? 'none';
            $current = self::nonEmptyString($task->payload['replay_blocked_current_condition_key'] ?? null)
                ?? 'none';
            $recordedFingerprint = self::nonEmptyString(
                $task->payload['replay_blocked_recorded_condition_definition_fingerprint'] ?? null
            );
            $currentFingerprint = self::nonEmptyString(
                $task->payload['replay_blocked_current_condition_definition_fingerprint'] ?? null
            );
            $step = $sequence === null ? 'a keyed condition wait' : sprintf('workflow sequence %d', $sequence);

            if ($recorded === $current && $recordedFingerprint !== null && $currentFingerprint !== null) {
                return [
                    'workflow_replay_blocked',
                    sprintf(
                        'Workflow replay is blocked at %s because recorded condition predicate fingerprint [%s] does not match the current yielded fingerprint [%s]. Run this workflow on a compatible build, then repair it.',
                        $step,
                        $recordedFingerprint,
                        $currentFingerprint,
                    ),
                ];
            }

            return [
                'workflow_replay_blocked',
                sprintf(
                    'Workflow replay is blocked at %s because recorded condition key [%s] does not match the current yielded key [%s]. Run this workflow on a compatible build, then repair it.',
                    $step,
                    $recorded,
                    $current,
                ),
            ];
        }

        if (($task->payload['replay_blocked_reason'] ?? null) === 'history_shape_mismatch') {
            $sequence = self::intValue($task->payload['replay_blocked_workflow_sequence'] ?? null);
            $expected = self::nonEmptyString($task->payload['replay_blocked_expected_history_shape'] ?? null)
                ?? 'the current yielded step';
            $recorded = self::stringList($task->payload['replay_blocked_recorded_event_types'] ?? null);
            $step = $sequence === null ? 'a workflow step' : sprintf('workflow sequence %d', $sequence);

            return [
                'workflow_replay_blocked',
                sprintf(
                    'Workflow replay is blocked at %s because history recorded [%s] but the current workflow yielded %s. Run this workflow on a compatible build, then repair it.',
                    $step,
                    $recorded === [] ? 'unknown' : implode(', ', $recorded),
                    $expected,
                ),
            ];
        }

        return ['workflow_replay_blocked', trim($task->last_error ?? 'Workflow replay is blocked.')];
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     opened_at: \Carbon\CarbonInterface|null,
     *     signal_id: string|null,
     *     command_id: string|null,
     *     signal_wait_id: string|null,
     *     resume_source_kind: string,
     *     resume_source_id: string|null
     * }|null
     */
    private static function openSignalApplicationWait(WorkflowRun $run): ?array
    {
        $receivedSignals = array_values(array_filter(
            RunSignalView::forRun($run),
            static fn (array $signal): bool => ($signal['status'] ?? null) === 'received',
        ));

        if ($receivedSignals === []) {
            return null;
        }

        usort($receivedSignals, static function (array $left, array $right): int {
            $leftSequence = self::intValue($left['command_sequence'] ?? null) ?? PHP_INT_MAX;
            $rightSequence = self::intValue($right['command_sequence'] ?? null) ?? PHP_INT_MAX;

            if ($leftSequence !== $rightSequence) {
                return $leftSequence <=> $rightSequence;
            }

            $leftReceivedAt = self::timestamp($left['received_at'] ?? null)?->getTimestampMs() ?? PHP_INT_MAX;
            $rightReceivedAt = self::timestamp($right['received_at'] ?? null)?->getTimestampMs() ?? PHP_INT_MAX;

            if ($leftReceivedAt !== $rightReceivedAt) {
                return $leftReceivedAt <=> $rightReceivedAt;
            }

            return (string) ($left['id'] ?? $left['command_id'] ?? $left['signal_wait_id'] ?? '')
                <=> (string) ($right['id'] ?? $right['command_id'] ?? $right['signal_wait_id'] ?? '');
        });

        $signal = $receivedSignals[0];
        $signalId = self::nonEmptyString($signal['id'] ?? null);
        $commandId = self::nonEmptyString($signal['command_id'] ?? null);
        $signalWaitId = self::nonEmptyString($signal['signal_wait_id'] ?? null);
        $resumeSourceKind = $signalId === null ? 'workflow_command' : 'workflow_signal';
        $resumeSourceId = $signalId ?? $commandId;
        $waitIdentity = $signalId ?? $commandId ?? $signalWaitId ?? 'unknown';

        return [
            'id' => sprintf('signal-application:%s', $waitIdentity),
            'name' => self::nonEmptyString($signal['name'] ?? null) ?? 'signal',
            'opened_at' => self::timestamp($signal['received_at'] ?? null),
            'signal_id' => $signalId,
            'command_id' => $commandId,
            'signal_wait_id' => $signalWaitId,
            'resume_source_kind' => $resumeSourceKind,
            'resume_source_id' => $resumeSourceId,
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     opened_at: \Carbon\CarbonInterface|null,
     *     deadline_at: \Carbon\CarbonInterface|null,
     *     timer_id: string|null,
     *     timeout_fired_at: \Carbon\CarbonInterface|null,
     *     timeout_seconds: int|null,
     *     condition_key: string|null,
     *     resume_source_kind: string,
     *     resume_source_id: string|null
     * }|null
     */
    private static function openConditionWait(WorkflowRun $run): ?array
    {
        $openConditions = array_values(array_filter(
            ConditionWaits::forRun($run),
            static fn (array $wait): bool => $wait['status'] === 'open',
        ));

        if ($openConditions === []) {
            return null;
        }

        usort($openConditions, static function (array $left, array $right): int {
            $leftSequence = $left['sequence'] ?? PHP_INT_MIN;
            $rightSequence = $right['sequence'] ?? PHP_INT_MIN;

            if ($leftSequence !== $rightSequence) {
                return $leftSequence <=> $rightSequence;
            }

            $leftOpenedAt = $left['opened_at']?->getTimestampMs() ?? PHP_INT_MIN;
            $rightOpenedAt = $right['opened_at']?->getTimestampMs() ?? PHP_INT_MIN;

            if ($leftOpenedAt !== $rightOpenedAt) {
                return $leftOpenedAt <=> $rightOpenedAt;
            }

            return $left['condition_wait_id'] <=> $right['condition_wait_id'];
        });

        /** @var array{id: string, condition_wait_id: string, sequence: int|null, opened_at: \Carbon\CarbonInterface|null, deadline_at: \Carbon\CarbonInterface|null, timeout_fired_at: \Carbon\CarbonInterface|null, timer_id: string|null, timeout_seconds: int|null, condition_key: string|null, resume_source_kind: string, resume_source_id: string|null} $condition */
        $condition = end($openConditions);

        return [
            'id' => $condition['condition_wait_id'],
            'opened_at' => $condition['opened_at'],
            'deadline_at' => $condition['deadline_at'],
            'timer_id' => $condition['timer_id'],
            'timeout_fired_at' => self::timestamp($condition['timeout_fired_at'] ?? null),
            'timeout_seconds' => $condition['timeout_seconds'],
            'condition_key' => $condition['condition_key'] ?? null,
            'resume_source_kind' => $condition['resume_source_kind'],
            'resume_source_id' => $condition['resume_source_id'],
        ];
    }

    /**
     * @param array<string, mixed> $conditionWait
     */
    private static function conditionLabel(array $conditionWait): string
    {
        $conditionKey = self::nonEmptyString($conditionWait['condition_key'] ?? null);

        return $conditionKey === null
            ? ''
            : sprintf(' %s', $conditionKey);
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     opened_at: \Carbon\CarbonInterface,
     *     deadline_at: \Carbon\CarbonInterface|null,
     *     timeout_fired_at: \Carbon\CarbonInterface|null,
     *     timeout_seconds: int|null,
     *     timer_id: string|null,
     *     resume_source_kind: string,
     *     resume_source_id: string|null
     * }|null
     */
    private static function openSignalWait(WorkflowRun $run): ?array
    {
        $openSignals = array_values(array_filter(
            SignalWaits::forRun($run),
            static fn (array $wait): bool => $wait['status'] === 'open'
                || $wait['source_status'] === 'timed_out',
        ));

        if ($openSignals === []) {
            return null;
        }

        uasort($openSignals, static function (array $left, array $right): int {
            $leftSequence = $left['sequence'] ?? PHP_INT_MIN;
            $rightSequence = $right['sequence'] ?? PHP_INT_MIN;

            if ($leftSequence !== $rightSequence) {
                return $leftSequence <=> $rightSequence;
            }

            $leftOpenedAt = $left['opened_at']?->getTimestampMs() ?? PHP_INT_MIN;
            $rightOpenedAt = $right['opened_at']?->getTimestampMs() ?? PHP_INT_MIN;

            if ($leftOpenedAt !== $rightOpenedAt) {
                return $leftOpenedAt <=> $rightOpenedAt;
            }

            return $left['signal_wait_id'] <=> $right['signal_wait_id'];
        });

        /** @var array{id: string, name: string, opened_at: \Carbon\CarbonInterface} $signal */
        $signal = end($openSignals);
        $timerId = self::nonEmptyString($signal['timer_id'] ?? null);
        $timeoutFiredAt = self::timestamp($signal['timeout_fired_at'] ?? null);

        return [
            'id' => $signal['signal_wait_id'],
            'name' => $signal['signal_name'],
            'opened_at' => $signal['opened_at'],
            'deadline_at' => self::timestamp($signal['deadline_at'] ?? null),
            'timeout_fired_at' => $timeoutFiredAt,
            'timeout_seconds' => self::intValue($signal['timeout_seconds'] ?? null),
            'timer_id' => $timerId,
            'resume_source_kind' => $timerId === null ? 'signal' : 'timer',
            'resume_source_id' => $timerId,
        ];
    }

    /**
     * @param list<array<string, mixed>> $activities
     *
     * @return array<string, mixed>|null
     */
    private static function unsupportedActivity(array $activities): ?array
    {
        foreach ($activities as $activity) {
            if (($activity['history_unsupported_reason'] ?? null) === RunActivityView::UNSUPPORTED_TERMINAL_REASON) {
                return $activity;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $activities
     *
     * @return array<string, mixed>|null
     */
    private static function diagnosticActivity(array $activities): ?array
    {
        foreach ($activities as $activity) {
            if (self::hasMutableOpenFallbackAuthority($activity['history_authority'] ?? null)) {
                return $activity;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $timers
     *
     * @return array<string, mixed>|null
     */
    private static function unsupportedTimer(array $timers): ?array
    {
        foreach ($timers as $timer) {
            if (($timer['history_unsupported_reason'] ?? null) === RunTimerView::UNSUPPORTED_TERMINAL_REASON) {
                return $timer;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $timers
     *
     * @return array<string, mixed>|null
     */
    private static function diagnosticTimer(array $timers, ?string $ignoredTimerId = null): ?array
    {
        foreach ($timers as $timer) {
            if (($timer['id'] ?? null) === $ignoredTimerId) {
                continue;
            }

            if (self::hasMutableOpenFallbackAuthority($timer['history_authority'] ?? null)) {
                return $timer;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     opened_at: \Carbon\CarbonInterface|null,
     *     resolved_at: \Carbon\CarbonInterface|null,
     *     resume_source_kind: string,
     *     resume_source_id: string|null
     * }|null
     */
    private static function unsupportedChildWait(WorkflowRun $run): ?array
    {
        foreach (ChildRunHistory::knownSequences($run) as $sequence) {
            $snapshot = ChildRunHistory::waitSnapshotForSequence($run, $sequence);

            if (
                $snapshot === null
                || ($snapshot['history_unsupported_reason'] ?? null) !== ChildRunHistory::UNSUPPORTED_TERMINAL_REASON
            ) {
                continue;
            }

            return [
                'id' => sprintf('child:%s', $snapshot['child_call_id'] ?? $sequence),
                'label' => $snapshot['label'],
                'opened_at' => $snapshot['opened_at'],
                'resolved_at' => $snapshot['resolved_at'],
                'resume_source_kind' => 'child_workflow_run',
                'resume_source_id' => $snapshot['resume_source_id'],
            ];
        }

        return null;
    }

    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     opened_at: \Carbon\CarbonInterface|null
     * }|null
     */
    private static function diagnosticChildWait(WorkflowRun $run): ?array
    {
        foreach (ChildRunHistory::knownSequences($run) as $sequence) {
            $snapshot = ChildRunHistory::waitSnapshotForSequence($run, $sequence);

            if (
                $snapshot === null
                || $snapshot['status'] !== 'open'
                || ($snapshot['history_authority'] ?? null) !== ChildRunHistory::HISTORY_AUTHORITY_MUTABLE_OPEN_FALLBACK
            ) {
                continue;
            }

            return [
                'id' => sprintf('child:%s', $snapshot['child_call_id'] ?? $sequence),
                'label' => $snapshot['label'],
                'opened_at' => $snapshot['opened_at'],
            ];
        }

        return null;
    }

    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     opened_at: \Carbon\CarbonInterface,
     *     resume_source_kind: string,
     *     resume_source_id: string|null
     * }|null
     */
    private static function openChildWait(WorkflowRun $run): ?array
    {
        foreach (ChildRunHistory::knownSequences($run) as $sequence) {
            $snapshot = ChildRunHistory::waitSnapshotForSequence($run, $sequence);

            if (
                $snapshot === null
                || $snapshot['status'] !== 'open'
                || ($snapshot['history_authority'] ?? null) === ChildRunHistory::HISTORY_AUTHORITY_MUTABLE_OPEN_FALLBACK
            ) {
                continue;
            }

            return [
                'id' => sprintf('child:%s', $snapshot['child_call_id'] ?? $sequence),
                'label' => $snapshot['label'],
                'opened_at' => $snapshot['opened_at'],
                'resume_source_kind' => 'child_workflow_run',
                'resume_source_id' => $snapshot['resume_source_id'],
            ];
        }

        return null;
    }

    private static function isAuthoritativeOpenActivity(array $activity): bool
    {
        return in_array(
            $activity['status'] ?? null,
            [ActivityStatus::Pending->value, ActivityStatus::Running->value],
            true,
        ) && ! self::hasMutableOpenFallbackAuthority($activity['history_authority'] ?? null);
    }

    private static function isAuthoritativeOpenTimer(array $timer): bool
    {
        return ($timer['status'] ?? null) === 'pending'
            && ! self::hasMutableOpenFallbackAuthority($timer['history_authority'] ?? null);
    }

    private static function hasMutableOpenFallbackAuthority(mixed $historyAuthority): bool
    {
        return $historyAuthority === RunActivityView::HISTORY_AUTHORITY_MUTABLE_OPEN_FALLBACK
            || $historyAuthority === RunTimerView::HISTORY_AUTHORITY_MUTABLE_OPEN_FALLBACK
            || $historyAuthority === ChildRunHistory::HISTORY_AUTHORITY_MUTABLE_OPEN_FALLBACK;
    }

    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     opened_at: \Carbon\CarbonInterface|null,
     *     resolved_at: \Carbon\CarbonInterface|null,
     *     resume_source_kind: string,
     *     resume_source_id: string|null
     * }|null
     */
    private static function pendingChildResolutionWait(WorkflowRun $run, bool $hasOpenChildWait): ?array
    {
        foreach (ChildRunHistory::knownSequences($run) as $sequence) {
            $snapshot = ChildRunHistory::waitSnapshotForSequence($run, $sequence);

            if ($snapshot === null || $snapshot['status'] === 'open' || $snapshot['resolution_event'] === null) {
                continue;
            }

            if ($hasOpenChildWait && ($snapshot['source_status'] ?? null) === RunStatus::Completed->value) {
                continue;
            }

            return [
                'id' => sprintf('child:%s', $snapshot['child_call_id'] ?? $sequence),
                'label' => $snapshot['label'],
                'opened_at' => $snapshot['opened_at'],
                'resolved_at' => $snapshot['resolved_at'],
                'resume_source_kind' => 'child_workflow_run',
                'resume_source_id' => $snapshot['resume_source_id'],
            ];
        }

        return null;
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     opened_at: \Carbon\CarbonInterface|null,
     *     resume_source_kind: string,
     *     resume_source_id: string|null,
     *     task_id: string|null
     * }|null
     */
    private static function openUpdateWait(WorkflowRun $run): ?array
    {
        foreach (UpdateWaits::forRun($run) as $wait) {
            if (($wait['status'] ?? null) !== 'open') {
                continue;
            }

            $name = self::nonEmptyString($wait['target_name'] ?? null) ?? 'update';

            return [
                'id' => self::nonEmptyString($wait['id'] ?? null) ?? sprintf('update:%s', $name),
                'name' => $name,
                'opened_at' => self::timestamp($wait['opened_at'] ?? null),
                'resume_source_kind' => self::nonEmptyString($wait['resume_source_kind'] ?? null) ?? 'workflow_update',
                'resume_source_id' => self::nonEmptyString($wait['resume_source_id'] ?? null),
                'task_id' => self::nonEmptyString($wait['task_id'] ?? null),
            ];
        }

        return null;
    }

    private static function openTaskById(WorkflowRun $run, ?string $taskId): ?WorkflowTask
    {
        if ($taskId === null) {
            return null;
        }

        /** @var WorkflowTask|null $task */
        $task = $run->tasks
            ->first(static fn (WorkflowTask $task): bool => $task->id === $taskId
                && in_array($task->status, [TaskStatus::Ready, TaskStatus::Leased], true));

        return $task;
    }

    private static function hasActionableTransportFailure(WorkflowTask $task): bool
    {
        return TaskRepairPolicy::claimFailed($task)
            || TaskRepairPolicy::leaseExpired($task)
            || TaskRepairPolicy::dispatchFailed($task);
    }

    private static function taskLabelFor(WorkflowTask $task): string
    {
        return match ($task->task_type) {
            TaskType::Activity => 'Activity',
            TaskType::Timer => 'Timer',
            default => 'Workflow',
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function taskLiveness(WorkflowTask $task, WorkflowRun $run, string $label): array
    {
        if (self::taskWaitingForCompatibleWorker($task, $run)) {
            return [
                sprintf('%s_task_waiting_for_compatible_worker', $task->task_type->value),
                self::compatibleWorkerReason($task, $run, $label),
            ];
        }

        if (TaskRepairPolicy::leaseExpired($task)) {
            return [
                'repair_needed',
                sprintf(
                    '%s task %s lease expired at %s.',
                    $label,
                    $task->id,
                    $task->lease_expires_at?->toJSON() ?? 'an unknown time',
                ),
            ];
        }

        if (TaskRepairPolicy::dispatchFailed($task)) {
            return [
                'repair_needed',
                sprintf(
                    '%s task %s could not be dispatched at %s. %s',
                    $label,
                    $task->id,
                    $task->last_dispatch_attempt_at?->toJSON() ?? 'an unknown time',
                    trim($task->last_dispatch_error ?? 'The queue driver rejected the task.'),
                ),
            ];
        }

        if (TaskRepairPolicy::claimFailed($task)) {
            return [
                sprintf('%s_task_claim_failed', $task->task_type->value),
                sprintf(
                    '%s task %s could not be claimed by a worker at %s. %s',
                    $label,
                    $task->id,
                    $task->last_claim_failed_at?->toJSON() ?? 'an unknown time',
                    trim($task->last_claim_error ?? 'The worker backend capability check rejected the task.'),
                ),
            ];
        }

        if (TaskRepairPolicy::dispatchOverdue($task)) {
            $reference = $task->last_dispatched_at ?? $task->created_at;

            return [
                'repair_needed',
                sprintf(
                    '%s task %s is ready but has not been dispatched since %s.',
                    $label,
                    $task->id,
                    $reference?->toJSON() ?? 'an unknown time',
                ),
            ];
        }

        return $task->status === TaskStatus::Leased
            ? [
                sprintf('%s_task_leased', $task->task_type->value),
                sprintf('%s task %s is leased to a worker.', $label, $task->id),
            ]
            : [
                sprintf('%s_task_ready', $task->task_type->value),
                sprintf('%s task %s is ready to run.', $label, $task->id),
            ];
    }

    private static function taskWaitingForCompatibleWorker(WorkflowTask $task, WorkflowRun $run): bool
    {
        if (TaskCompatibility::supported($task, $run) || TaskCompatibility::supportedInFleet($task, $run)) {
            return false;
        }

        if (TaskRepairPolicy::leaseExpired($task)) {
            return true;
        }

        return $task->status === TaskStatus::Ready
            && ($task->available_at === null || ! $task->available_at->isFuture());
    }

    private static function compatibleWorkerReason(WorkflowTask $task, WorkflowRun $run, string $label): string
    {
        $reasons = array_values(array_unique(array_filter([
            TaskCompatibility::mismatchReason($task, $run),
            TaskCompatibility::fleetMismatchReason($task, $run),
        ])));
        $reason = $reasons === []
            ? 'Requires a compatible worker.'
            : implode(' ', $reasons);

        return match (true) {
            TaskRepairPolicy::leaseExpired($task) => sprintf(
                '%s task %s lease expired and is waiting for a compatible worker. %s',
                $label,
                $task->id,
                $reason,
            ),
            TaskRepairPolicy::dispatchFailed($task) => sprintf(
                '%s task %s could not be dispatched and is waiting for a compatible worker. %s',
                $label,
                $task->id,
                $reason,
            ),
            TaskRepairPolicy::dispatchOverdue($task) => sprintf(
                '%s task %s is ready but dispatch is overdue and is waiting for a compatible worker. %s',
                $label,
                $task->id,
                $reason,
            ),
            default => sprintf(
                '%s task %s is ready but waiting for a compatible worker. %s',
                $label,
                $task->id,
                $reason,
            ),
        };
    }

    /**
     * @param array<string, mixed> $activity
     */
    private static function activityType(array $activity): string
    {
        return is_string($activity['type'] ?? null) && $activity['type'] !== ''
            ? $activity['type']
            : (is_string($activity['class'] ?? null) && $activity['class'] !== ''
                ? $activity['class']
                : 'activity');
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

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && $item !== '',
        ));
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
}
