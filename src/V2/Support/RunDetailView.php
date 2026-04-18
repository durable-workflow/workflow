<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowRun;

final class RunDetailView
{
    /**
     * @return array<string, mixed>
     */
    public static function forRun(WorkflowRun $run): array
    {
        $run->loadMissing([
            'summary',
            'commands',
            'signals.command',
            'updates.command',
            'updates.failure',
            'tasks',
            'activityExecutions.attempts',
            'timers',
            'failures',
            'historyEvents',
            'waits',
            'timelineEntries',
            'timerEntries',
            'lineageEntries',
            'parentLinks.parentRun.summary',
            'childLinks.childRun.summary',
            'childLinks.childRun.historyEvents',
            'instance.runs.summary',
        ]);

        $summary = $run->summary;
        $selectedRun = SelectedRunSnapshot::forRun($run);
        $currentRunResolution = $selectedRun['current_run'];
        $currentRun = $currentRunResolution['run'];
        $currentSummary = $currentRunResolution['summary'];
        $isCurrentRun = $summary?->is_current_run ?? ($currentRun?->id === $run->id);
        $commandContract = RunCommandContract::forRun($run, persistBackfill: true);
        $tasks = RunTaskView::forRun($run);
        $taskLinks = RunTaskLinkMap::forRun($run, $tasks);
        $cancelBlockedReason = self::actionBlockedReason($run, $isCurrentRun);
        $terminateBlockedReason = self::actionBlockedReason($run, $isCurrentRun);
        $signalBlockedReason = self::actionBlockedReason($run, $isCurrentRun);
        $queryBlockedReason = self::queryBlockedReason($run);
        $updateBlockedReason = self::updateBlockedReason($run, $isCurrentRun);
        $repairBlockedReason = self::repairBlockedReason(
            $run,
            $isCurrentRun,
            $summary?->liveness_state,
            self::hasReplayBlockedTask($tasks),
        );
        $archiveBlockedReason = self::archiveBlockedReason($run);
        $canCancel = $cancelBlockedReason === null;
        $canTerminate = $terminateBlockedReason === null;
        $canIssueTerminalCommands = $canCancel && $canTerminate;
        $canQuery = $queryBlockedReason === null;
        $canSignal = $signalBlockedReason === null;
        $canUpdate = $updateBlockedReason === null;
        $canRepair = $repairBlockedReason === null;
        $canArchive = $archiveBlockedReason === null;
        $compatibilityFleet = WorkerCompatibilityFleet::details(
            $run->compatibility,
            $run->connection,
            $run->queue,
        );

        $activities = RunActivityView::activitiesForRun($run);
        $activityClasses = collect(RunActivityView::classesFromActivities($activities));
        $linkedIntakes = RunLinkedIntakeView::forRun($run);
        $signals = self::attachTaskLinks(
            RunSignalView::forRun($run),
            $taskLinks['signals'],
            $taskLinks['commands'],
        );
        $signalsByCommandId = collect($signals)
            ->filter(static fn (array $signal): bool => is_string($signal['command_id'] ?? null))
            ->keyBy('command_id');
        $updates = self::attachTaskLinks(
            RunUpdateView::forRun($run),
            $taskLinks['updates'],
            $taskLinks['commands'],
        );
        $updatesByCommandId = collect($updates)
            ->filter(static fn (array $update): bool => is_string($update['command_id'] ?? null))
            ->keyBy('command_id');
        $failureSnapshots = FailureSnapshots::forRun($run);
        $waitSnapshot = $selectedRun['waits'];
        $waits = $waitSnapshot['waits'];
        $openWaitCount = collect($waits)
            ->filter(static fn (array $wait): bool => ($wait['status'] ?? null) === 'open')
            ->count();
        $currentOpenWait = self::currentOpenWait($waits);
        $workflowTaskResumeSource = self::currentWorkflowTaskResumeSource($tasks, $summary?->next_task_id);
        $fleetCompatibility = self::fleetCompatibility($run, $tasks);
        $summaryOpenWaitId = $summary?->open_wait_id;
        $summaryHasOpenWait = $summaryOpenWaitId !== null;
        $openWaitId = $summaryHasOpenWait
            ? $summaryOpenWaitId
            : ($currentOpenWait['id'] ?? $workflowTaskResumeSource['open_wait_id']);
        $resumeSourceKind = $summaryHasOpenWait
            ? $summary?->resume_source_kind
            : ($currentOpenWait['resume_source_kind'] ?? $workflowTaskResumeSource['resume_source_kind']);
        $resumeSourceId = $summaryHasOpenWait
            ? $summary?->resume_source_id
            : ($currentOpenWait['resume_source_id'] ?? $workflowTaskResumeSource['resume_source_id']);
        $recordedDefinitionFingerprint = WorkflowDefinitionFingerprint::recordedForRun($run);
        $currentDefinitionFingerprint = WorkflowDefinitionFingerprint::currentForRun($run);
        $definitionMatchesCurrent = WorkflowDefinitionFingerprint::matchesCurrent($run);
        $determinismDiagnostics = WorkflowDeterminismDiagnostics::forRun($run);
        $commandContractBackfill = RunCommandContract::historyBackfillState($run);
        $historyBudget = $summary === null
            ? HistoryBudget::forRun($run)
            : [
                'history_event_count' => (int) $summary->history_event_count,
                'history_size_bytes' => (int) $summary->history_size_bytes,
                'continue_as_new_recommended' => (bool) $summary->continue_as_new_recommended,
            ];
        $timelineSnapshot = $selectedRun['timeline'];
        $timerSnapshot = $selectedRun['timers'];
        $lineageSnapshot = $selectedRun['lineage'];

        return [
            'id' => $run->id,
            'instance_id' => $run->workflow_instance_id,
            'selected_run_id' => $run->id,
            'run_id' => $run->id,
            'is_current_run' => $isCurrentRun,
            'current_run_id' => $currentRun?->id,
            'current_run_source' => $currentRunResolution['source'],
            'current_run_status' => $currentRun?->status?->value,
            'current_run_status_bucket' => $currentSummary?->status_bucket,
            'current_run_closed_reason' => $currentSummary?->closed_reason ?? $currentRun?->closed_reason,
            'engine_source' => 'v2',
            'class' => $run->workflow_class,
            'workflow_type' => $run->workflow_type,
            'business_key' => $summary?->business_key ?? $run->business_key ?? $run->instance?->business_key,
            'visibility_labels' => $summary?->visibility_labels ?? $run->visibility_labels ?? $run->instance?->visibility_labels ?? [],
            'memo' => $run->memo ?? $run->instance?->memo ?? [],
            'search_attributes' => $summary?->search_attributes ?? $run->search_attributes ?? [],
            'workflow_definition_fingerprint' => $recordedDefinitionFingerprint,
            'workflow_definition_current_fingerprint' => $currentDefinitionFingerprint,
            'workflow_definition_matches_current' => $definitionMatchesCurrent,
            'workflow_determinism_status' => $determinismDiagnostics['status'],
            'workflow_determinism_source' => $determinismDiagnostics['source'],
            'workflow_determinism_findings' => $determinismDiagnostics['findings'],
            'compatibility' => $summary?->compatibility ?? $run->compatibility,
            'compatibility_namespace' => WorkerCompatibilityFleet::scopeNamespace(),
            'compatibility_supported' => WorkerCompatibility::supports($run->compatibility),
            'compatibility_reason' => WorkerCompatibility::mismatchReason($run->compatibility),
            'compatibility_supported_in_fleet' => $fleetCompatibility['supported'],
            'compatibility_fleet_reason' => $fleetCompatibility['reason'],
            'compatibility_fleet' => $compatibilityFleet,
            'arguments' => $run->workflowArguments(),
            'connection' => $run->connection,
            'queue' => $run->queue,
            'execution_timeout_seconds' => $run->instance?->execution_timeout_seconds !== null
                ? (int) $run->instance->execution_timeout_seconds
                : null,
            'run_timeout_seconds' => $run->run_timeout_seconds !== null
                ? (int) $run->run_timeout_seconds
                : null,
            'execution_deadline_at' => $run->execution_deadline_at?->toIso8601String(),
            'run_deadline_at' => $run->run_deadline_at?->toIso8601String(),
            'output' => $run->output === null ? null : $run->workflowOutput(),
            'status' => $run->status->value,
            'is_terminal' => $run->status->isTerminal(),
            'declared_queries' => $commandContract['queries'],
            'declared_query_contracts' => $commandContract['query_contracts'],
            'declared_query_targets' => $commandContract['query_targets'],
            'declared_signals' => $commandContract['signals'],
            'declared_signal_contracts' => $commandContract['signal_contracts'],
            'declared_signal_targets' => $commandContract['signal_targets'],
            'declared_updates' => $commandContract['updates'],
            'declared_update_contracts' => $commandContract['update_contracts'],
            'declared_update_targets' => $commandContract['update_targets'],
            'declared_entry_method' => $commandContract['entry_method'],
            'declared_entry_mode' => $commandContract['entry_mode'],
            'declared_entry_declaring_class' => $commandContract['entry_declaring_class'],
            'declared_contract_source' => $commandContract['source'],
            'declared_contract_backfill_needed' => $commandContractBackfill['needed'],
            'declared_contract_backfill_available' => $commandContractBackfill['available'],
            'status_bucket' => $summary?->status_bucket,
            'closed_reason' => $summary?->closed_reason ?? $run->closed_reason,
            'closed_at' => $summary?->closed_at ?? $run->closed_at,
            'archived_at' => $summary?->archived_at ?? $run->archived_at,
            'archive_command_id' => $summary?->archive_command_id ?? $run->archive_command_id,
            'archive_reason' => $summary?->archive_reason ?? $run->archive_reason,
            'duration_ms' => $summary?->duration_ms,
            'wait_kind' => $summary?->wait_kind,
            'wait_reason' => $summary?->wait_reason,
            'wait_started_at' => $summary?->wait_started_at,
            'wait_deadline_at' => $summary?->wait_deadline_at,
            'open_wait_id' => $openWaitId,
            'open_wait_count' => $openWaitCount,
            'resume_source_kind' => $resumeSourceKind,
            'resume_source_id' => $resumeSourceId,
            'next_task_at' => $summary?->next_task_at,
            'next_task_id' => $summary?->next_task_id,
            'next_task_type' => $summary?->next_task_type,
            'next_task_status' => $summary?->next_task_status,
            'next_task_lease_expires_at' => $summary?->next_task_lease_expires_at,
            'liveness_state' => $summary?->liveness_state,
            'liveness_reason' => $summary?->liveness_reason,
            'task_problem' => (bool) ($summary?->task_problem ?? false),
            'task_problem_badge' => WorkflowTaskProblem::metadata(
                (bool) ($summary?->task_problem ?? false),
                $summary?->liveness_state,
                $summary?->wait_kind,
            ),
            'exception_count' => $summary?->exception_count ?? count($failureSnapshots),
            'exceptions_count' => $summary?->exceptions_count ?? count($failureSnapshots),
            'history_event_count' => $historyBudget['history_event_count'],
            'history_size_bytes' => $historyBudget['history_size_bytes'],
            'history_event_threshold' => HistoryBudget::eventThreshold(),
            'history_size_bytes_threshold' => HistoryBudget::sizeBytesThreshold(),
            'continue_as_new_recommended' => $historyBudget['continue_as_new_recommended'],
            'can_issue_terminal_commands' => $canIssueTerminalCommands,
            'can_query' => $canQuery,
            'query_blocked_reason' => $queryBlockedReason,
            'can_cancel' => $canCancel,
            'cancel_blocked_reason' => $cancelBlockedReason,
            'can_terminate' => $canTerminate,
            'terminate_blocked_reason' => $terminateBlockedReason,
            'can_signal' => $canSignal,
            'signal_blocked_reason' => $signalBlockedReason,
            'can_update' => $canUpdate,
            'update_blocked_reason' => $updateBlockedReason,
            'can_repair' => $canRepair,
            'repair_blocked_reason' => $repairBlockedReason,
            'repair_attention' => (bool) ($summary?->repair_attention ?? RepairBlockedReason::needsAttention(
                $repairBlockedReason
            )),
            'repair_blocked' => RepairBlockedReason::metadata($repairBlockedReason),
            'can_archive' => $canArchive,
            'archive_blocked_reason' => $archiveBlockedReason,
            'read_only_reason' => self::readOnlyReason($run, $isCurrentRun),
            'created_at' => $summary?->started_at ?? $run->started_at ?? $run->created_at,
            'updated_at' => $summary?->closed_at ?? $run->last_progress_at ?? $run->updated_at,
            'run_navigation' => ($run->instance?->runs ?? collect())
                ->sortBy('run_number')
                ->map(static function (WorkflowRun $instanceRun) use ($run, $currentRun): array {
                    $instanceRunSummary = $instanceRun->summary;

                    return [
                        'instance_id' => $instanceRun->workflow_instance_id,
                        'run_id' => $instanceRun->id,
                        'run_number' => $instanceRun->run_number,
                        'status' => $instanceRun->status->value,
                        'status_bucket' => $instanceRunSummary?->status_bucket,
                        'closed_reason' => $instanceRunSummary?->closed_reason ?? $instanceRun->closed_reason,
                        'started_at' => $instanceRunSummary?->started_at ?? $instanceRun->started_at ?? $instanceRun->created_at,
                        'closed_at' => $instanceRunSummary?->closed_at ?? $instanceRun->closed_at,
                        'is_current_run' => $currentRun?->id === $instanceRun->id,
                        'is_selected_run' => $run->id === $instanceRun->id,
                    ];
                })
                ->values()
                ->all(),
            'activities_scope' => 'selected_run',
            'activities' => $activities,
            'linked_intakes_scope' => 'selected_run',
            'linked_intakes' => $linkedIntakes,
            'commands_scope' => 'selected_run',
            'commands' => $run->commands
                ->map(static function (WorkflowCommand $command) use (
                    $signalsByCommandId,
                    $taskLinks,
                    $updatesByCommandId,
                ): array {
                    $signal = $signalsByCommandId->get($command->id);
                    $update = $updatesByCommandId->get($command->id);
                    $taskLink = self::taskLink($taskLinks['commands'][$command->id] ?? null);

                    return [
                        'id' => $command->id,
                        'sequence' => $command->command_sequence,
                        'type' => $command->command_type->value,
                        'target_scope' => $command->target_scope,
                        'requested_run_id' => $command->requestedRunId(),
                        'resolved_run_id' => $command->resolvedRunId(),
                        'target_name' => $command->targetName(),
                        'payload_codec' => $command->payload_codec,
                        'payload_available' => CommandPayloadPreview::available($command->payload),
                        'payload' => CommandPayloadPreview::previewWithCodec(
                            $command->payload,
                            is_string($command->payload_codec) ? $command->payload_codec : null,
                        ),
                        'source' => $command->source,
                        'context' => $command->publicContext(),
                        'caller_label' => $command->callerLabel(),
                        'auth_status' => $command->authStatus(),
                        'auth_method' => $command->authMethod(),
                        'request_method' => $command->requestMethod(),
                        'request_path' => $command->requestPath(),
                        'request_route_name' => $command->requestRouteName(),
                        'request_fingerprint' => $command->requestFingerprint(),
                        'request_id' => $command->requestId(),
                        'correlation_id' => $command->correlationId(),
                        'status' => $command->status->value,
                        'outcome' => $command->outcome?->value,
                        'reason' => $command->commandReason(),
                        'rejection_reason' => $command->rejection_reason,
                        'validation_errors' => $command->validationErrors(),
                        'workflow_type' => $command->workflow_type,
                        'workflow_class' => $command->workflow_class,
                        'accepted_at' => $command->accepted_at,
                        'applied_at' => $command->applied_at,
                        'rejected_at' => $command->rejected_at,
                        'signal_id' => $signal['id'] ?? null,
                        'signal_status' => $signal['status'] ?? null,
                        'signal_wait_id' => $signal['signal_wait_id'] ?? null,
                        'update_id' => $update['id'] ?? null,
                        'update_status' => $update['status'] ?? null,
                        'result_available' => $update['result_available'] ?? false,
                        'result' => $update['result'] ?? null,
                        'failure_id' => $update['failure_id'] ?? null,
                        'failure_message' => $update['failure_message'] ?? null,
                        'completed_at' => $update['closed_at'] ?? null,
                        'current_task_id' => $taskLink['current_task_id'],
                        'current_task_status' => $taskLink['current_task_status'],
                        'task_transport_state' => $taskLink['task_transport_state'],
                        'task_ids' => $taskLink['task_ids'],
                        'task_missing' => $taskLink['task_missing'],
                    ];
                })
                ->values()
                ->all(),
            'signals_scope' => 'selected_run',
            'signals' => $signals,
            'updates_scope' => 'selected_run',
            'updates' => $updates,
            'waits_scope' => 'selected_run',
            'waits_projection_source' => $waitSnapshot['source'],
            'waits' => $waits,
            'tasks_scope' => 'selected_run',
            'tasks' => $tasks,
            'timeline_scope' => 'selected_run',
            'timeline_projection_source' => $timelineSnapshot['source'],
            'timeline' => $timelineSnapshot['timeline'],
            'timers_projection_source' => $timerSnapshot['source'],
            'timers_projection_rebuild_reasons' => $timerSnapshot['rebuild_reasons'],
            'logs' => RunActivityView::logsFromActivities($activities),
            'exceptions' => collect($failureSnapshots)
                ->map(static function (array $failure) use ($activityClasses): array {
                    $sourceId = self::stringValue($failure['source_id'] ?? null);

                    return [
                        'id' => $failure['id'] ?? null,
                        'code' => $failure['trace_preview'] ?? null,
                        'exception' => self::exceptionPayload($failure),
                        'history_authority' => $failure['history_authority'] ?? null,
                        'diagnostic_only' => (bool) ($failure['diagnostic_only'] ?? false),
                        'failure_category' => $failure['failure_category'] ?? null,
                        'non_retryable' => (bool) ($failure['non_retryable'] ?? false),
                        'exception_type' => $failure['exception_type'] ?? null,
                        'exception_class' => $failure['exception_class'] ?? null,
                        'exception_resolved_class' => $failure['exception_resolved_class'] ?? null,
                        'exception_resolution_source' => $failure['exception_resolution_source'] ?? null,
                        'exception_resolution_error' => $failure['exception_resolution_error'] ?? null,
                        'exception_replay_blocked' => $failure['exception_replay_blocked'] ?? false,
                        'class' => ($sourceId === null ? null : ($activityClasses[$sourceId] ?? null))
                            ?? ($failure['exception_class'] ?? null),
                        'created_at' => $failure['created_at'] ?? null,
                    ];
                })
                ->values(),
            'lineage_scope' => 'selected_run',
            'lineage_projection_source' => $lineageSnapshot['source'],
            'timers' => $timerSnapshot['timers'],
            'parents' => $lineageSnapshot['parents'],
            'continuedWorkflows' => $lineageSnapshot['continued_workflows'],
            'chartData' => self::chartData($run, $activities),
        ];
    }

    /**
     * @param list<array<string, mixed>> $waits
     *
     * @return array<string, mixed>|null
     */
    private static function currentOpenWait(array $waits): ?array
    {
        foreach ($waits as $wait) {
            if (($wait['status'] ?? null) === 'open' && ($wait['diagnostic_only'] ?? false) !== true) {
                return $wait;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @return array{supported: bool, reason: ?string}
     */
    private static function fleetCompatibility(WorkflowRun $run, array $tasks): array
    {
        foreach ($tasks as $task) {
            if (($task['id'] ?? null) !== $run->summary?->next_task_id) {
                continue;
            }

            return [
                'supported' => (bool) ($task['compatibility_supported_in_fleet'] ?? false),
                'reason' => is_string($task['compatibility_fleet_reason'] ?? null)
                    ? $task['compatibility_fleet_reason']
                    : null,
            ];
        }

        return [
            'supported' => WorkerCompatibilityFleet::supports($run->compatibility, $run->connection, $run->queue),
            'reason' => WorkerCompatibilityFleet::mismatchReason($run->compatibility, $run->connection, $run->queue),
        ];
    }

    /**
     * @param list<array<string, mixed>> $tasks
     *
     * @return array{
     *     open_wait_id: string|null,
     *     resume_source_kind: string|null,
     *     resume_source_id: string|null
     * }
     */
    private static function currentWorkflowTaskResumeSource(array $tasks, ?string $nextTaskId): array
    {
        foreach ($tasks as $task) {
            if (($task['type'] ?? null) !== 'workflow') {
                continue;
            }

            $taskId = is_string($task['id'] ?? null) ? $task['id'] : null;
            $isOpen = $task['is_open'] ?? false;

            if ($taskId === null || $isOpen !== true) {
                continue;
            }

            if ($nextTaskId !== null && $taskId !== $nextTaskId) {
                continue;
            }

            return [
                'open_wait_id' => sprintf('workflow-task:%s', $taskId),
                'resume_source_kind' => 'workflow_task',
                'resume_source_id' => $taskId,
            ];
        }

        return [
            'open_wait_id' => null,
            'resume_source_kind' => null,
            'resume_source_id' => null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, array{
     *     current_task_id: string|null,
     *     current_task_status: string|null,
     *     task_transport_state: string|null,
     *     task_ids: list<string>,
     *     task_missing: bool
     * }> $primaryLinks
     * @param array<string, array{
     *     current_task_id: string|null,
     *     current_task_status: string|null,
     *     task_transport_state: string|null,
     *     task_ids: list<string>,
     *     task_missing: bool
     * }> $commandLinks
     * @return list<array<string, mixed>>
     */
    private static function attachTaskLinks(array $rows, array $primaryLinks, array $commandLinks): array
    {
        return array_values(array_map(
            static function (array $row) use ($primaryLinks, $commandLinks): array {
                $primaryId = self::stringValue($row['id'] ?? null);
                $commandId = self::stringValue($row['command_id'] ?? null);

                $link = ($primaryId === null ? null : ($primaryLinks[$primaryId] ?? null))
                    ?? ($commandId === null ? null : ($commandLinks[$commandId] ?? null));
                $taskLink = self::taskLink($link);

                return $row + [
                    'current_task_id' => $taskLink['current_task_id'],
                    'current_task_status' => $taskLink['current_task_status'],
                    'task_transport_state' => $taskLink['task_transport_state'],
                    'task_ids' => $taskLink['task_ids'],
                    'task_missing' => $taskLink['task_missing'],
                ];
            },
            $rows,
        ));
    }

    /**
     * @param array{
     *     current_task_id: string|null,
     *     current_task_status: string|null,
     *     task_transport_state: string|null,
     *     task_ids: list<string>,
     *     task_missing: bool
     * }|null $taskLink
     * @return array{
     *     current_task_id: string|null,
     *     current_task_status: string|null,
     *     task_transport_state: string|null,
     *     task_ids: list<string>,
     *     task_missing: bool
     * }
     */
    private static function taskLink(?array $taskLink): array
    {
        return $taskLink ?? [
            'current_task_id' => null,
            'current_task_status' => null,
            'task_transport_state' => null,
            'task_ids' => [],
            'task_missing' => false,
        ];
    }

    private static function actionBlockedReason(WorkflowRun $run, bool $isCurrentRun): ?string
    {
        if (! $isCurrentRun) {
            return 'selected_run_not_current';
        }

        if (self::isClosed($run)) {
            return 'run_closed';
        }

        return null;
    }

    private static function updateBlockedReason(WorkflowRun $run, bool $isCurrentRun): ?string
    {
        $blockedReason = self::actionBlockedReason($run, $isCurrentRun);

        if ($blockedReason !== null) {
            return $blockedReason;
        }

        $blockedReason = UpdateCommandGate::blockedReason($run);

        if ($blockedReason !== null) {
            return $blockedReason;
        }

        return WorkflowExecutionGate::blockedReason($run);
    }

    private static function queryBlockedReason(WorkflowRun $run): ?string
    {
        return WorkflowExecutionGate::blockedReason($run);
    }

    private static function repairBlockedReason(
        WorkflowRun $run,
        bool $isCurrentRun,
        ?string $livenessState,
        bool $hasReplayBlockedTask,
    ): ?string {
        return RepairBlockedReason::forRun($run, $isCurrentRun, $livenessState, $hasReplayBlockedTask);
    }

    private static function archiveBlockedReason(WorkflowRun $run): ?string
    {
        if ($run->archived_at !== null) {
            return 'run_archived';
        }

        if (! self::isClosed($run)) {
            return 'run_not_closed';
        }

        return null;
    }

    private static function readOnlyReason(WorkflowRun $run, bool $isCurrentRun): ?string
    {
        if ($run->archived_at !== null) {
            return 'Run is archived.';
        }

        return match (self::actionBlockedReason($run, $isCurrentRun)) {
            'selected_run_not_current' => 'Selected run is historical. Issue commands against the current active run.',
            'run_closed' => 'Run is closed.',
            default => null,
        };
    }

    private static function isClosed(WorkflowRun $run): bool
    {
        return in_array($run->status, [
            RunStatus::Completed,
            RunStatus::Failed,
            RunStatus::Cancelled,
            RunStatus::Terminated,
        ], true);
    }

    /**
     * @param list<array<string, mixed>> $tasks
     */
    private static function hasReplayBlockedTask(array $tasks): bool
    {
        foreach ($tasks as $task) {
            if (($task['transport_state'] ?? null) === 'replay_blocked') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function chartData(WorkflowRun $run, array $activities): array
    {
        $start = self::timestampToMilliseconds($run->started_at ?? $run->created_at);
        $end = self::timestampToMilliseconds($run->closed_at ?? $run->last_progress_at ?? $run->updated_at);

        $entries = [[
            'x' => $run->workflow_class,
            'type' => 'Workflow',
            'y' => [$start, $end],
            'diagnostic_only' => false,
        ]];

        foreach ($activities as $activity) {
            $entries[] = [
                'id' => $activity['id'] ?? null,
                'x' => $activity['class'],
                'type' => 'Activity',
                'y' => [
                    self::timestampToMilliseconds($activity['started_at'] ?? $activity['created_at'] ?? null),
                    self::timestampToMilliseconds(
                        $activity['closed_at']
                        ?? $activity['last_heartbeat_at']
                        ?? $activity['started_at']
                        ?? $activity['created_at']
                        ?? null
                    ),
                ],
                'status' => $activity['status'] ?? null,
                'source_status' => $activity['row_status'] ?? ($activity['status'] ?? null),
                'history_authority' => $activity['history_authority'] ?? null,
                'history_unsupported_reason' => $activity['history_unsupported_reason'] ?? null,
                'diagnostic_only' => (bool) ($activity['diagnostic_only'] ?? false),
            ];
        }

        return $entries;
    }

    private static function timestampToMilliseconds(mixed $timestamp): int
    {
        if ($timestamp instanceof \Carbon\CarbonInterface) {
            return $timestamp->getTimestampMs();
        }

        if (is_string($timestamp) && $timestamp !== '') {
            return \Illuminate\Support\Carbon::parse($timestamp)->getTimestampMs();
        }

        return 0;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function exceptionPayload(array $failure): array
    {
        $payload = is_array($failure['exception_payload'] ?? null)
            ? $failure['exception_payload']
            : [];
        $trace = is_array($payload['trace'] ?? null)
            ? array_values(array_filter($payload['trace'], static fn (mixed $frame): bool => is_array($frame)))
            : [];
        $properties = is_array($payload['properties'] ?? null)
            ? array_values(
                array_filter($payload['properties'], static fn (mixed $property): bool => is_array($property))
            )
            : [];

        $normalized = [
            '__constructor' => is_string($payload['__constructor'] ?? null)
                ? $payload['__constructor']
                : ($failure['exception_class'] ?? null),
            'type' => is_string($payload['type'] ?? null)
                ? $payload['type']
                : ($failure['exception_type'] ?? null),
            'message' => is_string($payload['message'] ?? null)
                ? $payload['message']
                : ($failure['message'] ?? null),
            'code' => is_int($payload['code'] ?? null)
                ? $payload['code']
                : (is_int($failure['code'] ?? null) ? $failure['code'] : 0),
            'file' => is_string($payload['file'] ?? null)
                ? $payload['file']
                : ($failure['file'] ?? null),
            'line' => is_int($payload['line'] ?? null)
                ? $payload['line']
                : (is_int($failure['line'] ?? null) ? $failure['line'] : null),
            'trace' => $trace,
            'properties' => $properties,
        ];

        if (array_key_exists('details', $payload)) {
            $normalized['details'] = $payload['details'];
        }

        if (is_bool($payload['non_retryable'] ?? null)) {
            $normalized['non_retryable'] = $payload['non_retryable'];
        }

        if (is_string($payload['details_payload_codec'] ?? null) && $payload['details_payload_codec'] !== '') {
            $normalized['details_payload_codec'] = $payload['details_payload_codec'];
        }

        return $normalized;
    }
}
