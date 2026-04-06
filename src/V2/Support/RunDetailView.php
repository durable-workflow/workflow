<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowLink;
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
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
            'parentLinks.parentRun.summary',
            'childLinks.childRun.summary',
            'instance.currentRun.summary',
        ]);

        $summary = $run->summary;
        $currentRun = $run->instance?->currentRun;
        $currentSummary = $currentRun?->summary;
        $isCurrentRun = $summary?->is_current_run ?? ($currentRun?->id === $run->id);
        $canIssueTerminalCommands = $isCurrentRun && in_array($run->status, [
            RunStatus::Pending,
            RunStatus::Running,
            RunStatus::Waiting,
        ], true);
        $updateBlockedReason = $canIssueTerminalCommands
            ? UpdateCommandGate::blockedReason($run)
            : null;
        $canRepair = $isCurrentRun
            && $summary?->liveness_state === 'repair_needed'
            && in_array($run->status, [RunStatus::Pending, RunStatus::Running, RunStatus::Waiting], true);

        $activities = $run->activityExecutions
            ->map(static fn (ActivityExecution $execution): array => [
                'id' => $execution->id,
                'sequence' => $execution->sequence,
                'type' => $execution->activity_type,
                'class' => $execution->activity_class,
                'status' => $execution->status->value,
                'attempt_count' => $execution->attempt_count,
                'connection' => $execution->connection,
                'queue' => $execution->queue,
                'last_heartbeat_at' => $execution->last_heartbeat_at,
                'created_at' => $execution->created_at,
                'started_at' => $execution->started_at,
                'closed_at' => $execution->closed_at,
                'arguments' => serialize($execution->activityArguments()),
                'result' => $execution->result === null ? serialize(null) : serialize($execution->activityResult()),
            ])
            ->values()
            ->all();

        $activityClasses = $run->activityExecutions
            ->keyBy('id')
            ->map(static fn (ActivityExecution $execution): string => $execution->activity_class);
        $updateCompletions = $run->historyEvents
            ->filter(
                static fn ($event): bool => $event->event_type === HistoryEventType::UpdateCompleted
                    && $event->workflow_command_id !== null
            )
            ->keyBy('workflow_command_id');
        $tasks = RunTaskView::forRun($run);
        $waits = RunWaitView::forRun($run);

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
            'compatibility_supported' => WorkerCompatibility::supports($run->compatibility),
            'compatibility_reason' => WorkerCompatibility::mismatchReason($run->compatibility),
            'arguments' => serialize($run->workflowArguments()),
            'connection' => $run->connection,
            'queue' => $run->queue,
            'output' => $run->output === null ? serialize(null) : serialize($run->workflowOutput()),
            'status' => $run->status->value,
            'status_bucket' => $summary?->status_bucket,
            'closed_reason' => $summary?->closed_reason ?? $run->closed_reason,
            'closed_at' => $summary?->closed_at ?? $run->closed_at,
            'duration_ms' => $summary?->duration_ms,
            'wait_kind' => $summary?->wait_kind,
            'wait_reason' => $summary?->wait_reason,
            'wait_started_at' => $summary?->wait_started_at,
            'wait_deadline_at' => $summary?->wait_deadline_at,
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
            'can_signal' => $canIssueTerminalCommands,
            'can_update' => $canIssueTerminalCommands && $updateBlockedReason === null,
            'update_blocked_reason' => $updateBlockedReason,
            'can_repair' => $canRepair,
            'read_only_reason' => $canIssueTerminalCommands
                ? null
                : ($isCurrentRun
                    ? 'Run is closed.'
                    : 'Selected run is historical. Issue commands against the current active run.'),
            'created_at' => $summary?->started_at ?? $run->started_at ?? $run->created_at,
            'updated_at' => $summary?->closed_at ?? $run->last_progress_at ?? $run->updated_at,
            'activities_scope' => 'selected_run',
            'activities' => $activities,
            'commands_scope' => 'selected_run',
            'commands' => $run->commands
                ->map(static fn (WorkflowCommand $command): array => [
                    'id' => $command->id,
                    'sequence' => $command->command_sequence,
                    'type' => $command->command_type->value,
                    'target_scope' => $command->target_scope,
                    'target_name' => $command->targetName(),
                    'source' => $command->source,
                    'context' => $command->commandContext(),
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
            'logs' => $run->activityExecutions->map(
                static fn (ActivityExecution $execution): array => [
                    'id' => $execution->id,
                    'index' => $execution->sequence - 1,
                    'now' => $execution->started_at ?? $execution->created_at,
                    'class' => $execution->activity_class,
                    'result' => $execution->result === null ? serialize(null) : serialize($execution->activityResult()),
                    'created_at' => $execution->closed_at ?? $execution->updated_at,
                ]
            )->values(),
            'exceptions' => $run->failures->map(
                static fn (WorkflowFailure $failure): array => [
                    'id' => $failure->id,
                    'code' => $failure->trace_preview,
                    'exception' => serialize([
                        '__constructor' => $failure->exception_class,
                        'message' => $failure->message,
                        'file' => $failure->file,
                        'line' => $failure->line,
                        'trace' => [],
                    ]),
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
            'parents' => $run->parentLinks->map(
                static function (WorkflowLink $link): array {
                    $parentRun = $link->parentRun;
                    $parentSummary = $parentRun?->summary;

                    return [
                        'id' => $link->id,
                        'link_type' => $link->link_type,
                        'sequence' => $link->sequence,
                        'is_primary_parent' => $link->is_primary_parent,
                        'parent_workflow_id' => $link->parent_workflow_instance_id,
                        'parent_workflow_run_id' => $link->parent_workflow_run_id,
                        'workflow_instance_id' => $link->parent_workflow_instance_id,
                        'workflow_run_id' => $link->parent_workflow_run_id,
                        'run_number' => $parentRun?->run_number,
                        'workflow_type' => $parentRun?->workflow_type,
                        'class' => $parentRun?->workflow_class,
                        'status' => $parentRun?->status?->value,
                        'status_bucket' => $parentSummary?->status_bucket,
                        'closed_reason' => $parentSummary?->closed_reason ?? $parentRun?->closed_reason,
                    ];
                }
            )->values(),
            'continuedWorkflows' => $run->childLinks->map(
                static function (WorkflowLink $link): array {
                    $childRun = $link->childRun;
                    $childSummary = $childRun?->summary;

                    return [
                        'id' => $link->id,
                        'link_type' => $link->link_type,
                        'sequence' => $link->sequence,
                        'is_primary_parent' => $link->is_primary_parent,
                        'child_workflow_id' => $link->child_workflow_instance_id,
                        'child_workflow_run_id' => $link->child_workflow_run_id,
                        'workflow_instance_id' => $link->child_workflow_instance_id,
                        'workflow_run_id' => $link->child_workflow_run_id,
                        'run_number' => $childRun?->run_number,
                        'workflow_type' => $childRun?->workflow_type,
                        'class' => $childRun?->workflow_class,
                        'status' => $childRun?->status?->value,
                        'status_bucket' => $childSummary?->status_bucket,
                        'closed_reason' => $childSummary?->closed_reason ?? $childRun?->closed_reason,
                    ];
                }
            )->values(),
            'chartData' => self::chartData($run),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function chartData(WorkflowRun $run): array
    {
        $start = self::timestampToMilliseconds($run->started_at ?? $run->created_at);
        $end = self::timestampToMilliseconds($run->closed_at ?? $run->last_progress_at ?? $run->updated_at);

        $entries = [[
            'x' => $run->workflow_class,
            'type' => 'Workflow',
            'y' => [$start, $end],
        ]];

        foreach ($run->activityExecutions as $execution) {
            $entries[] = [
                'x' => $execution->activity_class,
                'type' => 'Activity',
                'y' => [
                    self::timestampToMilliseconds($execution->started_at ?? $execution->created_at),
                    self::timestampToMilliseconds($execution->closed_at ?? $execution->updated_at),
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

    private static function timestampToMilliseconds($timestamp): int
    {
        return $timestamp->getTimestampMs();
    }
}
