<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTimer;

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
            'tasks',
            'activityExecutions.attempts',
            'timers',
            'failures',
            'historyEvents',
            'parentLinks.parentRun.summary',
            'childLinks.childRun.summary',
            'childLinks.childRun.historyEvents',
            'instance.runs.summary',
        ]);

        $summary = $run->summary;
        $currentRun = $run->instance === null
            ? null
            : CurrentRunResolver::forInstance($run->instance, ['summary']);
        $currentSummary = $currentRun?->summary;
        $isCurrentRun = $summary?->is_current_run ?? ($currentRun?->id === $run->id);
        $commandContract = RunCommandContract::forRun($run);
        $cancelBlockedReason = self::actionBlockedReason($run, $isCurrentRun);
        $terminateBlockedReason = self::actionBlockedReason($run, $isCurrentRun);
        $signalBlockedReason = self::actionBlockedReason($run, $isCurrentRun);
        $queryBlockedReason = self::queryBlockedReason($run);
        $updateBlockedReason = self::updateBlockedReason($run, $isCurrentRun);
        $repairBlockedReason = self::repairBlockedReason($run, $isCurrentRun, $summary?->liveness_state);
        $canCancel = $cancelBlockedReason === null;
        $canTerminate = $terminateBlockedReason === null;
        $canIssueTerminalCommands = $canCancel && $canTerminate;
        $canQuery = $queryBlockedReason === null;
        $canSignal = $signalBlockedReason === null;
        $canUpdate = $updateBlockedReason === null;
        $canRepair = $repairBlockedReason === null;
        $compatibilityFleet = WorkerCompatibilityFleet::details(
            $run->compatibility,
            $run->connection,
            $run->queue,
        );

        $activities = RunActivityView::activitiesForRun($run);
        $activityClasses = collect(RunActivityView::classesFromActivities($activities));
        $updateCompletions = $run->historyEvents
            ->filter(
                static fn ($event): bool => $event->event_type === HistoryEventType::UpdateCompleted
                    && $event->workflow_command_id !== null
            )
            ->keyBy('workflow_command_id');
        $failureEvents = $run->historyEvents
            ->filter(static fn ($event): bool => is_string($event->payload['failure_id'] ?? null))
            ->keyBy(static fn ($event): string => $event->payload['failure_id']);
        $tasks = RunTaskView::forRun($run);
        $waits = RunWaitView::forRun($run);
        $openWaitCount = collect($waits)
            ->filter(static fn (array $wait): bool => ($wait['status'] ?? null) === 'open')
            ->count();
        $currentOpenWait = self::currentOpenWait($waits);
        $workflowTaskResumeSource = self::currentWorkflowTaskResumeSource($tasks, $summary?->next_task_id);
        $fleetCompatibility = self::fleetCompatibility($run, $tasks);
        $openWaitId = $summary?->open_wait_id
            ?? $currentOpenWait['id']
            ?? $workflowTaskResumeSource['open_wait_id'];
        $resumeSourceKind = $summary?->resume_source_kind
            ?? $currentOpenWait['resume_source_kind']
            ?? $workflowTaskResumeSource['resume_source_kind'];
        $resumeSourceId = $summary?->resume_source_id
            ?? $currentOpenWait['resume_source_id']
            ?? $workflowTaskResumeSource['resume_source_id'];

        return [
            'id' => $run->id,
            'instance_id' => $run->workflow_instance_id,
            'selected_run_id' => $run->id,
            'run_id' => $run->id,
            'is_current_run' => $isCurrentRun,
            'current_run_id' => $currentRun?->id,
            'current_run_status' => $currentRun?->status?->value,
            'current_run_status_bucket' => $currentSummary?->status_bucket,
            'current_run_closed_reason' => $currentSummary?->closed_reason ?? $currentRun?->closed_reason,
            'engine_source' => 'v2',
            'class' => $run->workflow_class,
            'workflow_type' => $run->workflow_type,
            'compatibility' => $summary?->compatibility ?? $run->compatibility,
            'compatibility_namespace' => WorkerCompatibilityFleet::scopeNamespace(),
            'compatibility_supported' => WorkerCompatibility::supports($run->compatibility),
            'compatibility_reason' => WorkerCompatibility::mismatchReason($run->compatibility),
            'compatibility_supported_in_fleet' => $fleetCompatibility['supported'],
            'compatibility_fleet_reason' => $fleetCompatibility['reason'],
            'compatibility_fleet' => $compatibilityFleet,
            'arguments' => serialize($run->workflowArguments()),
            'connection' => $run->connection,
            'queue' => $run->queue,
            'output' => $run->output === null ? serialize(null) : serialize($run->workflowOutput()),
            'status' => $run->status->value,
            'declared_queries' => $commandContract['queries'],
            'declared_query_contracts' => $commandContract['query_contracts'],
            'declared_query_targets' => $commandContract['query_targets'],
            'declared_signals' => $commandContract['signals'],
            'declared_signal_contracts' => $commandContract['signal_contracts'],
            'declared_signal_targets' => $commandContract['signal_targets'],
            'declared_updates' => $commandContract['updates'],
            'declared_update_contracts' => $commandContract['update_contracts'],
            'declared_update_targets' => $commandContract['update_targets'],
            'declared_contract_source' => $commandContract['source'],
            'status_bucket' => $summary?->status_bucket,
            'closed_reason' => $summary?->closed_reason ?? $run->closed_reason,
            'closed_at' => $summary?->closed_at ?? $run->closed_at,
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
            'exception_count' => $summary?->exception_count ?? $run->failures->count(),
            'exceptions_count' => $summary?->exceptions_count ?? $run->failures->count(),
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
            'commands_scope' => 'selected_run',
            'commands' => $run->commands
                ->map(static fn (WorkflowCommand $command): array => [
                    'id' => $command->id,
                    'sequence' => $command->command_sequence,
                    'type' => $command->command_type->value,
                    'target_scope' => $command->target_scope,
                    'requested_run_id' => $command->requestedRunId(),
                    'resolved_run_id' => $command->resolvedRunId(),
                    'target_name' => $command->targetName(),
                    'payload_codec' => $command->payload_codec,
                    'payload_available' => CommandPayloadPreview::available($command->payload),
                    'payload' => CommandPayloadPreview::preview($command->payload),
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
                    'rejection_reason' => $command->rejection_reason,
                    'validation_errors' => $command->validationErrors(),
                    'workflow_type' => $command->workflow_type,
                    'workflow_class' => $command->workflow_class,
                    'accepted_at' => $command->accepted_at,
                    'applied_at' => $command->applied_at,
                    'rejected_at' => $command->rejected_at,
                    'result_available' => $updateCompletions->has($command->id)
                        && array_key_exists('result', (array) $updateCompletions->get($command->id)?->payload),
                    'result' => $updateCompletions->has($command->id)
                        ? self::normalizeUpdateResult($updateCompletions->get($command->id)?->payload['result'] ?? null)
                        : null,
                    'failure_id' => $updateCompletions->has($command->id)
                        ? $updateCompletions->get($command->id)?->payload['failure_id'] ?? null
                        : null,
                    'failure_message' => $updateCompletions->has($command->id)
                        ? $updateCompletions->get($command->id)?->payload['message'] ?? null
                        : null,
                    'completed_at' => $updateCompletions->has($command->id)
                        ? $updateCompletions->get($command->id)?->recorded_at
                        : null,
                ])
                ->values()
                ->all(),
            'waits_scope' => 'selected_run',
            'waits' => $waits,
            'tasks_scope' => 'selected_run',
            'tasks' => $tasks,
            'timeline_scope' => 'selected_run',
            'timeline' => HistoryTimeline::forRun($run),
            'logs' => RunActivityView::logsFromActivities($activities),
            'exceptions' => $run->failures->map(
                static fn (WorkflowFailure $failure): array => [
                    'id' => $failure->id,
                    'code' => $failure->trace_preview,
                    'exception' => serialize(self::exceptionPayload(
                        $failure,
                        is_object($failureEvents->get($failure->id))
                            ? $failureEvents->get($failure->id)?->payload['exception'] ?? null
                            : null,
                    )),
                    'class' => $activityClasses[$failure->source_id]
                        ?? $failure->exception_class,
                    'created_at' => $failure->created_at,
                ]
            )->values(),
            'lineage_scope' => 'selected_run',
            'timers' => $run->timers->map(
                static fn (WorkflowTimer $timer): array => [
                    'id' => $timer->id,
                    'sequence' => $timer->sequence,
                    'status' => $timer->status->value,
                    'delay_seconds' => $timer->delay_seconds,
                    'fire_at' => $timer->fire_at,
                    'fired_at' => $timer->fired_at,
                ]
            )->values(),
            'parents' => RunLineageView::parentsForRun($run),
            'continuedWorkflows' => RunLineageView::continuedWorkflowsForRun($run),
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
            if (($wait['status'] ?? null) === 'open') {
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
    ): ?string {
        $blockedReason = self::actionBlockedReason($run, $isCurrentRun);

        if ($blockedReason !== null) {
            return $blockedReason;
        }

        if ($livenessState === 'repair_needed') {
            return null;
        }

        if (is_string($livenessState) && str_contains($livenessState, 'waiting_for_compatible_worker')) {
            return 'waiting_for_compatible_worker';
        }

        return 'repair_not_needed';
    }

    private static function readOnlyReason(WorkflowRun $run, bool $isCurrentRun): ?string
    {
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
        ]];

        foreach ($activities as $activity) {
            $entries[] = [
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
            ];
        }

        return $entries;
    }

    private static function normalizeUpdateResult(mixed $result): mixed
    {
        if (! is_string($result)) {
            return $result;
        }

        return serialize(Serializer::unserialize($result));
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

    /**
     * @return array<string, mixed>
     */
    private static function exceptionPayload(WorkflowFailure $failure, mixed $payload): array
    {
        if (is_string($payload)) {
            $payload = Serializer::unserialize($payload);
        }

        if (! is_array($payload)) {
            $payload = [];
        }

        $trace = is_array($payload['trace'] ?? null)
            ? array_values(array_filter($payload['trace'], static fn (mixed $frame): bool => is_array($frame)))
            : [];
        $properties = is_array($payload['properties'] ?? null)
            ? array_values(
                array_filter($payload['properties'], static fn (mixed $property): bool => is_array($property))
            )
            : [];

        return [
            '__constructor' => is_string($payload['class'] ?? null)
                ? $payload['class']
                : $failure->exception_class,
            'message' => is_string($payload['message'] ?? null)
                ? $payload['message']
                : $failure->message,
            'code' => is_int($payload['code'] ?? null)
                ? $payload['code']
                : 0,
            'file' => is_string($payload['file'] ?? null)
                ? $payload['file']
                : $failure->file,
            'line' => is_int($payload['line'] ?? null)
                ? $payload['line']
                : $failure->line,
            'trace' => $trace,
            'properties' => $properties,
        ];
    }
}
