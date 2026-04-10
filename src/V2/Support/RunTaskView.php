<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Collection;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class RunTaskView
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forRun(WorkflowRun $run): array
    {
        $run->loadMissing(['tasks', 'activityExecutions', 'timers', 'historyEvents']);

        /** @var Collection<string, array<string, mixed>> $activities */
        $activities = collect(RunActivityView::activitiesForRun($run))
            ->filter(static fn (array $activity): bool => is_string($activity['id'] ?? null))
            ->keyBy(static fn (array $activity): string => $activity['id']);
        /** @var Collection<string, array<string, mixed>> $timers */
        $timers = collect(RunTimerView::timersForRun($run))
            ->filter(static fn (array $timer): bool => is_string($timer['id'] ?? null))
            ->keyBy(static fn (array $timer): string => $timer['id']);

        $taskRows = $run->tasks
            ->sort(static function (WorkflowTask $left, WorkflowTask $right): int {
                $leftOpen = in_array($left->status, [TaskStatus::Ready, TaskStatus::Leased], true) ? 0 : 1;
                $rightOpen = in_array($right->status, [TaskStatus::Ready, TaskStatus::Leased], true) ? 0 : 1;

                if ($leftOpen !== $rightOpen) {
                    return $leftOpen <=> $rightOpen;
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
            ->map(
                static function (WorkflowTask $task) use ($activities, $run, $timers): array {
                    $activityExecutionId = self::stringValue($task->payload['activity_execution_id'] ?? null);
                    $timerId = self::stringValue($task->payload['timer_id'] ?? null);
                    $conditionWaitId = self::stringValue($task->payload['condition_wait_id'] ?? null);
                    $conditionKey = self::stringValue($task->payload['condition_key'] ?? null);
                    $workflowWaitKind = self::stringValue($task->payload['workflow_wait_kind'] ?? null);
                    $workflowResumeSourceKind = self::stringValue($task->payload['resume_source_kind'] ?? null);
                    $workflowResumeSourceId = self::stringValue($task->payload['resume_source_id'] ?? null);
                    $replayBlocked = ($task->payload['replay_blocked'] ?? false) === true;
                    $compatibility = TaskCompatibility::resolve($task, $run);

                    /** @var array<string, mixed>|null $activity */
                    $activity = $activityExecutionId === null ? null : $activities->get($activityExecutionId);
                    /** @var array<string, mixed>|null $timer */
                    $timer = $timerId === null ? null : $timers->get($timerId);

                    return [
                        'id' => $task->id,
                        'type' => $task->task_type->value,
                        'status' => $task->status->value,
                        'transport_state' => self::transportState($task),
                        'task_missing' => false,
                        'synthetic' => false,
                        'expected_task_id' => null,
                        'summary' => self::summaryFor($task, $activity, $timer, $compatibility),
                        'compatibility' => $compatibility,
                        'compatibility_supported' => WorkerCompatibility::supports($compatibility),
                        'compatibility_reason' => WorkerCompatibility::mismatchReason($compatibility),
                        'compatibility_supported_in_fleet' => TaskCompatibility::supportedInFleet($task, $run),
                        'compatibility_fleet_reason' => TaskCompatibility::fleetMismatchReason($task, $run),
                        'dispatch_failed' => TaskRepairPolicy::dispatchFailed($task),
                        'dispatch_overdue' => TaskRepairPolicy::dispatchOverdue($task),
                        'claim_failed' => TaskRepairPolicy::claimFailed($task),
                        'is_open' => in_array($task->status, [TaskStatus::Ready, TaskStatus::Leased], true),
                        'available_at' => $task->available_at,
                        'last_dispatch_attempt_at' => $task->last_dispatch_attempt_at,
                        'leased_at' => $task->leased_at,
                        'last_dispatched_at' => $task->last_dispatched_at,
                        'last_dispatch_error' => $task->last_dispatch_error,
                        'last_claim_failed_at' => $task->last_claim_failed_at,
                        'last_claim_error' => $task->last_claim_error,
                        'repair_available_at' => $task->repair_available_at,
                        'repair_backoff_seconds' => TaskRepairPolicy::failureBackoffSeconds($task),
                        'lease_expired' => TaskRepairPolicy::leaseExpired($task),
                        'lease_owner' => $task->lease_owner,
                        'lease_expires_at' => $task->lease_expires_at,
                        'attempt_count' => $task->attempt_count,
                        'repair_count' => $task->repair_count,
                        'last_error' => $task->last_error,
                        'connection' => $task->connection,
                        'queue' => $task->queue,
                        'activity_execution_id' => $activityExecutionId,
                        'activity_type' => self::stringValue($activity['type'] ?? null),
                        'activity_class' => self::stringValue($activity['class'] ?? null),
                        'retry_of_task_id' => self::stringValue($task->payload['retry_of_task_id'] ?? null),
                        'retry_after_attempt_id' => self::stringValue($task->payload['retry_after_attempt_id'] ?? null),
                        'retry_after_attempt' => self::intValue($task->payload['retry_after_attempt'] ?? null),
                        'retry_backoff_seconds' => self::intValue($task->payload['retry_backoff_seconds'] ?? null),
                        'retry_max_attempts' => self::intValue($task->payload['max_attempts'] ?? null),
                        'retry_policy' => is_array($task->payload['retry_policy'] ?? null) ? $task->payload['retry_policy'] : null,
                        'timer_id' => $timerId,
                        'timer_sequence' => self::intValue($timer['sequence'] ?? null),
                        'timer_fire_at' => $timer['fire_at'] ?? null,
                        'condition_wait_id' => $conditionWaitId,
                        'condition_key' => $conditionKey,
                        'workflow_wait_kind' => $workflowWaitKind,
                        'workflow_open_wait_id' => self::stringValue($task->payload['open_wait_id'] ?? null),
                        'workflow_resume_source_kind' => $workflowResumeSourceKind,
                        'workflow_resume_source_id' => $workflowResumeSourceId,
                        'workflow_update_id' => self::stringValue($task->payload['workflow_update_id'] ?? null),
                        'workflow_signal_id' => self::stringValue($task->payload['workflow_signal_id'] ?? null),
                        'workflow_command_id' => self::stringValue($task->payload['workflow_command_id'] ?? null),
                        'replay_blocked' => $replayBlocked,
                        'replay_blocked_reason' => $replayBlocked
                            ? self::stringValue($task->payload['replay_blocked_reason'] ?? null)
                            : null,
                        'replay_blocked_workflow_sequence' => $replayBlocked
                            ? self::intValue($task->payload['replay_blocked_workflow_sequence'] ?? null)
                            : null,
                        'replay_blocked_condition_wait_id' => $replayBlocked
                            ? self::stringValue($task->payload['replay_blocked_condition_wait_id'] ?? null)
                            : null,
                        'replay_blocked_recorded_condition_key' => $replayBlocked
                            ? self::stringValue($task->payload['replay_blocked_recorded_condition_key'] ?? null)
                            : null,
                        'replay_blocked_current_condition_key' => $replayBlocked
                            ? self::stringValue($task->payload['replay_blocked_current_condition_key'] ?? null)
                            : null,
                        'created_at' => $task->created_at,
                        'updated_at' => $task->updated_at,
                    ];
                }
            )
            ->values()
            ->all();

        $taskRows = array_merge($taskRows, self::missingTransportRows($run, $activities, $timers));

        usort($taskRows, [self::class, 'sortTaskRows']);

        return array_values($taskRows);
    }

    /**
     * @param Collection<string, array<string, mixed>> $activities
     * @param Collection<string, array<string, mixed>> $timers
     * @return list<array<string, mixed>>
     */
    private static function missingTransportRows(WorkflowRun $run, Collection $activities, Collection $timers): array
    {
        $rows = [];

        foreach (RunWaitView::forRun($run) as $wait) {
            if (($wait['status'] ?? null) !== 'open' || ($wait['task_backed'] ?? false) === true) {
                continue;
            }

            $kind = self::stringValue($wait['kind'] ?? null);

            if (
                $kind === 'activity'
                && self::stringValue($wait['source_status'] ?? null) === ActivityStatus::Pending->value
            ) {
                $activityId = self::stringValue($wait['resume_source_id'] ?? null);

                if ($activityId === null) {
                    continue;
                }

                /** @var array<string, mixed>|null $activity */
                $activity = $activities->get($activityId);
                $rows[] = self::missingActivityTaskRow($run, $activity ?? ['id' => $activityId], $wait);

                continue;
            }

            if ($kind === 'timer' && self::stringValue($wait['source_status'] ?? null) === 'pending') {
                $timerId = self::stringValue($wait['resume_source_id'] ?? null);

                if ($timerId === null) {
                    continue;
                }

                /** @var array<string, mixed>|null $timer */
                $timer = $timers->get($timerId);
                $rows[] = self::missingTimerTaskRow($run, $timer ?? ['id' => $timerId], $wait, false);

                continue;
            }

            if (
                $kind === 'condition'
                && self::stringValue($wait['resume_source_kind'] ?? null) === 'timer'
                && self::stringValue($wait['resume_source_id'] ?? null) !== null
            ) {
                $timerId = self::stringValue($wait['resume_source_id'] ?? null);

                /** @var array<string, mixed>|null $timer */
                $timer = $timerId === null ? null : $timers->get($timerId);
                $rows[] = self::missingTimerTaskRow($run, $timer ?? ['id' => $timerId], $wait, true);

                continue;
            }

            if ($kind === 'update') {
                $rows[] = self::missingWorkflowTaskRow(
                    $run,
                    'update',
                    self::stringValue($wait['id'] ?? null) ?? 'update',
                    self::stringValue($wait['target_name'] ?? null) ?? 'update',
                    self::timestamp($wait['opened_at'] ?? null),
                    self::stringValue($wait['resume_source_kind'] ?? null) ?? 'workflow_update',
                    self::stringValue($wait['resume_source_id'] ?? null),
                    self::stringValue($wait['update_id'] ?? null),
                    null,
                    self::stringValue($wait['command_id'] ?? null),
                );
            }
        }

        if (! self::hasOpenWorkflowTask($run)) {
            foreach (RunSignalView::forRun($run) as $signal) {
                if (self::stringValue($signal['status'] ?? null) !== 'received') {
                    continue;
                }

                $signalId = self::stringValue($signal['id'] ?? null);
                $commandId = self::stringValue($signal['command_id'] ?? null);
                $identity = $signalId ?? $commandId;

                if ($identity === null) {
                    continue;
                }

                $rows[] = self::missingWorkflowTaskRow(
                    $run,
                    'signal',
                    sprintf('signal-application:%s', $identity),
                    self::stringValue($signal['name'] ?? null) ?? 'signal',
                    self::timestamp($signal['received_at'] ?? null),
                    $signalId === null ? 'workflow_command' : 'workflow_signal',
                    $signalId ?? $commandId,
                    null,
                    $signalId,
                    $commandId,
                );
            }
        }

        $deduped = [];

        foreach ($rows as $row) {
            $id = self::stringValue($row['id'] ?? null);

            if ($id === null) {
                continue;
            }

            $deduped[$id] = $row;
        }

        return array_values($deduped);
    }

    /**
     * @param array<string, mixed> $activity
     * @param array<string, mixed> $wait
     * @return array<string, mixed>
     */
    private static function missingActivityTaskRow(WorkflowRun $run, array $activity, array $wait): array
    {
        $activityId = self::stringValue($activity['id'] ?? null)
            ?? self::stringValue($wait['resume_source_id'] ?? null)
            ?? 'activity';
        $activityType = self::stringValue($activity['type'] ?? null)
            ?? self::stringValue($activity['class'] ?? null)
            ?? 'activity';
        $retryPayload = self::latestActivityRetryPayload($run, $activityId);
        $retryAfterAttempt = self::intValue($retryPayload['retry_after_attempt'] ?? null);
        $availableAt = self::timestamp($retryPayload['retry_available_at'] ?? null)
            ?? self::timestamp($wait['opened_at'] ?? null);

        $row = self::missingTaskBase(
            $run,
            sprintf('missing:activity:%s', $activityId),
            TaskType::Activity->value,
            self::stringValue($activity['connection'] ?? null) ?? $run->connection,
            self::stringValue($activity['queue'] ?? null) ?? $run->queue,
            $availableAt,
        );

        $row['summary'] = $retryAfterAttempt === null
            ? sprintf('Activity task missing for %s.', $activityType)
            : sprintf('Activity retry %d task missing for %s.', $retryAfterAttempt + 1, $activityType);
        $row['expected_task_id'] = self::stringValue($retryPayload['retry_task_id'] ?? null);
        $row['activity_execution_id'] = $activityId;
        $row['activity_type'] = self::stringValue($activity['type'] ?? null);
        $row['activity_class'] = self::stringValue($activity['class'] ?? null);
        $row['retry_of_task_id'] = self::stringValue($retryPayload['retry_of_task_id'] ?? null);
        $row['retry_after_attempt_id'] = self::stringValue($retryPayload['retry_after_attempt_id'] ?? null);
        $row['retry_after_attempt'] = $retryAfterAttempt;
        $row['retry_backoff_seconds'] = self::intValue($retryPayload['retry_backoff_seconds'] ?? null);
        $row['retry_max_attempts'] = self::intValue($retryPayload['max_attempts'] ?? null);
        $row['retry_policy'] = is_array($retryPayload['retry_policy'] ?? null)
            ? $retryPayload['retry_policy']
            : (is_array($activity['retry_policy'] ?? null) ? $activity['retry_policy'] : null);
        $row['attempt_count'] = $retryAfterAttempt ?? self::intValue($activity['attempt_count'] ?? null) ?? 0;

        return $row;
    }

    /**
     * @param array<string, mixed> $timer
     * @param array<string, mixed> $wait
     * @return array<string, mixed>
     */
    private static function missingTimerTaskRow(WorkflowRun $run, array $timer, array $wait, bool $conditionTimeout): array
    {
        $timerId = self::stringValue($timer['id'] ?? null)
            ?? self::stringValue($wait['resume_source_id'] ?? null)
            ?? 'timer';
        $availableAt = self::timestamp($wait['deadline_at'] ?? null)
            ?? self::timestamp($timer['fire_at'] ?? null);
        $conditionKey = self::stringValue($wait['condition_key'] ?? null)
            ?? self::stringValue($timer['condition_key'] ?? null);
        $conditionWaitId = self::stringValue($wait['condition_wait_id'] ?? null)
            ?? self::stringValue($timer['condition_wait_id'] ?? null);

        $row = self::missingTaskBase(
            $run,
            sprintf('missing:timer:%s', $timerId),
            TaskType::Timer->value,
            $run->connection,
            $run->queue,
            $availableAt,
        );

        $row['summary'] = $conditionTimeout
            ? sprintf(
                'Condition timeout task missing%s.',
                $conditionKey === null ? '' : sprintf(' for %s', $conditionKey),
            )
            : 'Timer task missing.';
        $row['timer_id'] = $timerId;
        $row['timer_sequence'] = self::intValue($timer['sequence'] ?? null)
            ?? self::intValue($wait['sequence'] ?? null);
        $row['timer_fire_at'] = $availableAt;
        $row['condition_wait_id'] = $conditionWaitId;
        $row['condition_key'] = $conditionKey;

        return $row;
    }

    private static function missingWorkflowTaskRow(
        WorkflowRun $run,
        string $waitKind,
        string $openWaitId,
        string $targetName,
        ?\Carbon\CarbonInterface $openedAt,
        string $resumeSourceKind,
        ?string $resumeSourceId,
        ?string $updateId,
        ?string $signalId,
        ?string $commandId,
    ): array {
        $row = self::missingTaskBase(
            $run,
            sprintf('missing:workflow:%s', $openWaitId),
            TaskType::Workflow->value,
            $run->connection,
            $run->queue,
            $openedAt,
        );

        $row['summary'] = sprintf('Workflow task missing for accepted %s %s.', $waitKind, $targetName);
        $row['workflow_wait_kind'] = $waitKind;
        $row['workflow_open_wait_id'] = $openWaitId;
        $row['workflow_resume_source_kind'] = $resumeSourceKind;
        $row['workflow_resume_source_id'] = $resumeSourceId;
        $row['workflow_update_id'] = $updateId;
        $row['workflow_signal_id'] = $signalId;
        $row['workflow_command_id'] = $commandId;

        return $row;
    }

    private static function missingTaskBase(
        WorkflowRun $run,
        string $id,
        string $type,
        ?string $connection,
        ?string $queue,
        ?\Carbon\CarbonInterface $availableAt,
    ): array {
        $compatibility = self::stringValue($run->compatibility);

        return [
            'id' => $id,
            'type' => $type,
            'status' => 'missing',
            'transport_state' => 'missing',
            'task_missing' => true,
            'synthetic' => true,
            'expected_task_id' => null,
            'summary' => 'Durable task transport is missing.',
            'compatibility' => $compatibility,
            'compatibility_supported' => WorkerCompatibility::supports($compatibility),
            'compatibility_reason' => WorkerCompatibility::mismatchReason($compatibility),
            'compatibility_supported_in_fleet' => WorkerCompatibilityFleet::supports($compatibility, $connection, $queue),
            'compatibility_fleet_reason' => WorkerCompatibilityFleet::mismatchReason($compatibility, $connection, $queue),
            'dispatch_failed' => false,
            'dispatch_overdue' => false,
            'claim_failed' => false,
            'is_open' => false,
            'available_at' => $availableAt,
            'last_dispatch_attempt_at' => null,
            'leased_at' => null,
            'last_dispatched_at' => null,
            'last_dispatch_error' => null,
            'last_claim_failed_at' => null,
            'last_claim_error' => null,
            'repair_available_at' => null,
            'repair_backoff_seconds' => 0,
            'lease_expired' => false,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'attempt_count' => 0,
            'repair_count' => 0,
            'last_error' => null,
            'connection' => $connection,
            'queue' => $queue,
            'activity_execution_id' => null,
            'activity_type' => null,
            'activity_class' => null,
            'retry_of_task_id' => null,
            'retry_after_attempt_id' => null,
            'retry_after_attempt' => null,
            'retry_backoff_seconds' => null,
            'retry_max_attempts' => null,
            'retry_policy' => null,
            'timer_id' => null,
            'timer_sequence' => null,
            'timer_fire_at' => null,
            'condition_wait_id' => null,
            'condition_key' => null,
            'workflow_wait_kind' => null,
            'workflow_open_wait_id' => null,
            'workflow_resume_source_kind' => null,
            'workflow_resume_source_id' => null,
            'workflow_update_id' => null,
            'workflow_signal_id' => null,
            'workflow_command_id' => null,
            'replay_blocked' => false,
            'replay_blocked_reason' => null,
            'replay_blocked_workflow_sequence' => null,
            'replay_blocked_condition_wait_id' => null,
            'replay_blocked_recorded_condition_key' => null,
            'replay_blocked_current_condition_key' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    private static function summaryFor(
        WorkflowTask $task,
        ?array $activity,
        ?array $timer,
        ?string $compatibility,
    ): string {
        if (($task->payload['replay_blocked'] ?? false) === true) {
            $reason = self::stringValue($task->payload['replay_blocked_reason'] ?? null);

            return $reason === 'condition_wait_definition_mismatch'
                ? 'Workflow replay blocked by condition wait definition drift.'
                : 'Workflow replay blocked.';
        }

        if (self::taskWaitingForCompatibleWorker($task, $compatibility)) {
            return match ($task->task_type) {
                TaskType::Workflow => match (true) {
                    TaskRepairPolicy::leaseExpired(
                        $task
                    ) => 'Workflow task lease expired and is waiting for a compatible worker.',
                    TaskRepairPolicy::dispatchFailed(
                        $task
                    ) => 'Workflow task dispatch failed and is waiting for a compatible worker.',
                    TaskRepairPolicy::dispatchOverdue(
                        $task
                    ) => 'Workflow task is waiting for a compatible worker; dispatch is overdue.',
                    default => 'Workflow task is waiting for a compatible worker.',
                },
                TaskType::Activity => sprintf(
                    TaskRepairPolicy::leaseExpired($task)
                        ? 'Activity task lease expired and is waiting for a compatible worker for %s.'
                        : (TaskRepairPolicy::dispatchFailed($task)
                            ? 'Activity task dispatch failed and is waiting for a compatible worker for %s.'
                            : (TaskRepairPolicy::dispatchOverdue($task)
                            ? 'Activity task is waiting for a compatible worker for %s; dispatch is overdue.'
                            : 'Activity task is waiting for a compatible worker for %s.')),
                    self::activityLabel($activity),
                ),
                TaskType::Timer => sprintf(
                    TaskRepairPolicy::leaseExpired($task)
                        ? '%s lease expired and is waiting for a compatible worker.'
                        : (TaskRepairPolicy::dispatchFailed($task)
                            ? '%s dispatch failed and is waiting for a compatible worker.'
                            : (TaskRepairPolicy::dispatchOverdue($task)
                            ? '%s is waiting for a compatible worker; dispatch is overdue.'
                            : '%s is waiting for a compatible worker.')),
                    ucfirst(self::timerLabel($task, $timer) . ' task'),
                ),
            };
        }

        if (TaskRepairPolicy::leaseExpired($task)) {
            return match ($task->task_type) {
                TaskType::Workflow => 'Workflow task lease expired; waiting for recovery.',
                TaskType::Activity => sprintf(
                    'Activity task lease expired for %s; waiting for recovery.',
                    $activity?->activity_type ?? $activity?->activity_class ?? 'activity',
                ),
                TaskType::Timer => sprintf(
                    '%s lease expired; waiting for recovery.',
                    ucfirst(self::timerLabel($task, $timer) . ' task'),
                ),
            };
        }

        if (TaskRepairPolicy::dispatchFailed($task)) {
            if ($task->repair_available_at !== null && $task->repair_available_at->isFuture()) {
                return match ($task->task_type) {
                    TaskType::Workflow => sprintf(
                        'Workflow task dispatch failed; next repair is available at %s.',
                        $task->repair_available_at->toJSON(),
                    ),
                    TaskType::Activity => sprintf(
                        'Activity task dispatch failed for %s; next repair is available at %s.',
                        $activity?->activity_type ?? $activity?->activity_class ?? 'activity',
                        $task->repair_available_at->toJSON(),
                    ),
                    TaskType::Timer => sprintf(
                        '%s dispatch failed; next repair is available at %s.',
                        ucfirst(self::timerLabel($task, $timer) . ' task'),
                        $task->repair_available_at->toJSON(),
                    ),
                };
            }

            return match ($task->task_type) {
                TaskType::Workflow => 'Workflow task dispatch failed; waiting for recovery.',
                TaskType::Activity => sprintf(
                    'Activity task dispatch failed for %s; waiting for recovery.',
                    $activity?->activity_type ?? $activity?->activity_class ?? 'activity',
                ),
                TaskType::Timer => sprintf(
                    '%s dispatch failed; waiting for recovery.',
                    ucfirst(self::timerLabel($task, $timer) . ' task'),
                ),
            };
        }

        if (TaskRepairPolicy::claimFailed($task)) {
            if ($task->repair_available_at !== null && $task->repair_available_at->isFuture()) {
                return match ($task->task_type) {
                    TaskType::Workflow => sprintf(
                        'Workflow task claim failed; next repair is available at %s.',
                        $task->repair_available_at->toJSON(),
                    ),
                    TaskType::Activity => sprintf(
                        'Activity task claim failed for %s; next repair is available at %s.',
                        $activity?->activity_type ?? $activity?->activity_class ?? 'activity',
                        $task->repair_available_at->toJSON(),
                    ),
                    TaskType::Timer => sprintf(
                        '%s claim failed; next repair is available at %s.',
                        ucfirst(self::timerLabel($task, $timer) . ' task'),
                        $task->repair_available_at->toJSON(),
                    ),
                };
            }

            return match ($task->task_type) {
                TaskType::Workflow => 'Workflow task claim failed; worker backend capability is unsupported.',
                TaskType::Activity => sprintf(
                    'Activity task claim failed for %s; worker backend capability is unsupported.',
                    $activity?->activity_type ?? $activity?->activity_class ?? 'activity',
                ),
                TaskType::Timer => sprintf(
                    '%s claim failed; worker backend capability is unsupported.',
                    ucfirst(self::timerLabel($task, $timer) . ' task'),
                ),
            };
        }

        if (TaskRepairPolicy::dispatchOverdue($task)) {
            return match ($task->task_type) {
                TaskType::Workflow => 'Workflow task is ready but dispatch is overdue.',
                TaskType::Activity => sprintf(
                    'Activity task is ready but dispatch is overdue for %s.',
                    $activity?->activity_type ?? $activity?->activity_class ?? 'activity',
                ),
                TaskType::Timer => sprintf(
                    '%s is ready but dispatch is overdue.',
                    ucfirst(self::timerLabel($task, $timer) . ' task'),
                ),
            };
        }

        return match ($task->task_type) {
            TaskType::Workflow => match ($task->status) {
                TaskStatus::Ready => match (self::stringValue($task->payload['workflow_wait_kind'] ?? null)) {
                    'update' => 'Workflow task ready to apply accepted update.',
                    'signal' => 'Workflow task ready to apply accepted signal.',
                    default => 'Workflow task ready to resume the selected run.',
                },
                TaskStatus::Leased => match (self::stringValue($task->payload['workflow_wait_kind'] ?? null)) {
                    'update' => 'Workflow task leased to apply accepted update.',
                    'signal' => 'Workflow task leased to apply accepted signal.',
                    default => 'Workflow task leased to a worker.',
                },
                TaskStatus::Completed => 'Workflow task completed.',
                TaskStatus::Cancelled => 'Workflow task cancelled.',
                TaskStatus::Failed => 'Workflow task failed.',
            },
            TaskType::Activity => self::activitySummary($task, $activity),
            TaskType::Timer => self::timerSummary($task, $timer),
        };
    }

    private static function activitySummary(WorkflowTask $task, ?array $activity): string
    {
        $label = self::activityLabel($activity);
        $retryAfterAttempt = self::intValue($task->payload['retry_after_attempt'] ?? null);

        if ($retryAfterAttempt !== null) {
            $retryNumber = $retryAfterAttempt + 1;

            return match ($task->status) {
                TaskStatus::Ready => $task->available_at !== null && $task->available_at->isFuture()
                    ? sprintf('Activity retry %d for %s scheduled for %s.', $retryNumber, $label, $task->available_at->toJSON())
                    : sprintf('Activity retry %d ready for %s.', $retryNumber, $label),
                TaskStatus::Leased => sprintf('Activity retry %d leased for %s.', $retryNumber, $label),
                TaskStatus::Completed => sprintf('Activity retry %d completed for %s.', $retryNumber, $label),
                TaskStatus::Cancelled => sprintf('Activity retry %d cancelled for %s.', $retryNumber, $label),
                TaskStatus::Failed => sprintf('Activity retry %d failed for %s.', $retryNumber, $label),
            };
        }

        return match ($task->status) {
            TaskStatus::Ready => sprintf('Activity task ready for %s.', $label),
            TaskStatus::Leased => sprintf('Activity task leased for %s.', $label),
            TaskStatus::Completed => sprintf('Activity task completed for %s.', $label),
            TaskStatus::Cancelled => sprintf('Activity task cancelled for %s.', $label),
            TaskStatus::Failed => sprintf('Activity task failed for %s.', $label),
        };
    }

    private static function timerSummary(WorkflowTask $task, ?array $timer): string
    {
        $label = self::timerLabel($task, $timer);

        return match ($task->status) {
            TaskStatus::Ready => sprintf('%s task ready.', ucfirst($label)),
            TaskStatus::Leased => sprintf('%s task leased to a worker.', ucfirst($label)),
            TaskStatus::Completed => sprintf('%s task completed.', ucfirst($label)),
            TaskStatus::Cancelled => sprintf('%s task cancelled.', ucfirst($label)),
            TaskStatus::Failed => sprintf('%s task failed.', ucfirst($label)),
        };
    }

    private static function taskWaitingForCompatibleWorker(WorkflowTask $task, ?string $compatibility): bool
    {
        if (
            WorkerCompatibility::supports($compatibility)
            || WorkerCompatibilityFleet::supports($compatibility, $task->connection, $task->queue)
        ) {
            return false;
        }

        if (TaskRepairPolicy::leaseExpired($task)) {
            return true;
        }

        return $task->status === TaskStatus::Ready
            && ($task->available_at === null || ! $task->available_at->isFuture());
    }

    private static function transportState(WorkflowTask $task): string
    {
        if ($task->status === TaskStatus::Failed && ($task->payload['replay_blocked'] ?? false) === true) {
            return 'replay_blocked';
        }

        if ($task->status === TaskStatus::Leased) {
            return TaskRepairPolicy::leaseExpired($task)
                ? 'lease_expired'
                : 'leased';
        }

        if ($task->status !== TaskStatus::Ready) {
            return $task->status->value;
        }

        if ($task->available_at !== null && $task->available_at->isFuture()) {
            if (TaskRepairPolicy::dispatchFailed($task) && ! TaskRepairPolicy::dispatchFailedNeedsRedispatch($task)) {
                return 'repair_backoff';
            }

            return TaskRepairPolicy::dispatchFailed($task)
                ? 'dispatch_failed'
                : 'scheduled';
        }

        if (TaskRepairPolicy::dispatchFailed($task)) {
            if (! TaskRepairPolicy::dispatchFailedNeedsRedispatch($task)) {
                return 'repair_backoff';
            }

            return 'dispatch_failed';
        }

        if (TaskRepairPolicy::claimFailed($task)) {
            if (! TaskRepairPolicy::claimFailedNeedsRedispatch($task)) {
                return 'repair_backoff';
            }

            return 'claim_failed';
        }

        if (TaskRepairPolicy::dispatchOverdue($task)) {
            return 'dispatch_overdue';
        }

        return 'ready';
    }

    private static function activityLabel(?array $activity): string
    {
        return self::stringValue($activity['type'] ?? null)
            ?? self::stringValue($activity['class'] ?? null)
            ?? 'activity';
    }

    private static function timerLabel(WorkflowTask $task, ?array $timer): string
    {
        $delaySeconds = self::intValue($timer['delay_seconds'] ?? null);

        if (self::stringValue($task->payload['condition_wait_id'] ?? null) !== null) {
            return $delaySeconds === null
                ? 'condition timeout'
                : sprintf(
                    'condition timeout for %s second%s',
                    $delaySeconds,
                    $delaySeconds === 1 ? '' : 's',
                );
        }

        return $delaySeconds === null
            ? 'timer'
            : sprintf('timer for %s second%s', $delaySeconds, $delaySeconds === 1 ? '' : 's');
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private static function sortTaskRows(array $left, array $right): int
    {
        $leftPriority = self::taskRowPriority($left);
        $rightPriority = self::taskRowPriority($right);

        if ($leftPriority !== $rightPriority) {
            return $leftPriority <=> $rightPriority;
        }

        $leftAvailableAt = self::timestampToMilliseconds($left['available_at'] ?? null);
        $rightAvailableAt = self::timestampToMilliseconds($right['available_at'] ?? null);

        if ($leftAvailableAt !== $rightAvailableAt) {
            return $leftAvailableAt <=> $rightAvailableAt;
        }

        $leftCreatedAt = self::timestampToMilliseconds($left['created_at'] ?? null);
        $rightCreatedAt = self::timestampToMilliseconds($right['created_at'] ?? null);

        if ($leftCreatedAt !== $rightCreatedAt) {
            return $leftCreatedAt <=> $rightCreatedAt;
        }

        return (string) ($left['id'] ?? '') <=> (string) ($right['id'] ?? '');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function taskRowPriority(array $row): int
    {
        if (($row['is_open'] ?? false) === true) {
            return 0;
        }

        if (($row['task_missing'] ?? false) === true) {
            return 1;
        }

        return 2;
    }

    private static function timestampToMilliseconds(mixed $timestamp): int
    {
        if ($timestamp instanceof \Carbon\CarbonInterface) {
            return $timestamp->getTimestampMs();
        }

        if (is_string($timestamp) && $timestamp !== '') {
            return \Illuminate\Support\Carbon::parse($timestamp)->getTimestampMs();
        }

        return PHP_INT_MAX;
    }

    /**
     * @return array<string, mixed>
     */
    private static function latestActivityRetryPayload(WorkflowRun $run, string $activityExecutionId): array
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents
            ->filter(static function (WorkflowHistoryEvent $event) use ($activityExecutionId): bool {
                if ($event->event_type !== HistoryEventType::ActivityRetryScheduled) {
                    return false;
                }

                return ($event->payload['activity_execution_id'] ?? null) === $activityExecutionId;
            })
            ->sortByDesc('sequence')
            ->first();

        return $event instanceof WorkflowHistoryEvent && is_array($event->payload)
            ? $event->payload
            : [];
    }

    private static function hasOpenWorkflowTask(WorkflowRun $run): bool
    {
        return $run->tasks
            ->contains(static fn (WorkflowTask $task): bool => $task->task_type === TaskType::Workflow
                && in_array($task->status, [TaskStatus::Ready, TaskStatus::Leased], true));
    }

    private static function timestamp(mixed $value): ?\Carbon\CarbonInterface
    {
        if ($value instanceof \Carbon\CarbonInterface) {
            return $value;
        }

        return is_string($value) && $value !== ''
            ? \Illuminate\Support\Carbon::parse($value)
            : null;
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

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
