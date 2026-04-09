<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;

final class HistoryExport
{
    public const SCHEMA = 'durable-workflow.v2.history-export';

    public const SCHEMA_VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function forRun(WorkflowRun $run, ?CarbonInterface $exportedAt = null): array
    {
        $exportedAt ??= now();

        $run->loadMissing([
            'instance.runs.summary',
            'summary',
            'commands',
            'updates',
            'tasks',
            'activityExecutions.attempts',
            'timers',
            'failures',
            'historyEvents',
            'parentLinks',
            'childLinks',
        ]);

        $currentRun = $run->instance === null
            ? null
            : CurrentRunResolver::forInstance($run->instance, ['summary']);
        $summary = $run->summary;

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA_VERSION,
            'exported_at' => self::timestamp($exportedAt),
            'dedupe_key' => self::dedupeKey($run),
            'history_complete' => $run->status->isTerminal(),
            'workflow' => [
                'instance_id' => $run->workflow_instance_id,
                'run_id' => $run->id,
                'run_number' => $run->run_number,
                'is_current_run' => $currentRun?->id === $run->id,
                'current_run_id' => $currentRun?->id,
                'workflow_type' => $run->workflow_type,
                'workflow_class' => $run->workflow_class,
                'status' => $run->status->value,
                'status_bucket' => $run->status->statusBucket()->value,
                'closed_reason' => $summary?->closed_reason ?? $run->closed_reason,
                'compatibility' => $run->compatibility,
                'connection' => $run->connection,
                'queue' => $run->queue,
                'last_history_sequence' => $run->last_history_sequence,
                'started_at' => self::timestamp($summary?->started_at ?? $run->started_at),
                'closed_at' => self::timestamp($summary?->closed_at ?? $run->closed_at),
                'last_progress_at' => self::timestamp($run->last_progress_at),
            ],
            'payloads' => [
                'codec' => $run->payload_codec ?? config('workflows.serializer'),
                'arguments' => [
                    'available' => $run->arguments !== null,
                    'data' => $run->arguments,
                ],
                'output' => [
                    'available' => $run->output !== null,
                    'data' => $run->output,
                ],
            ],
            'summary' => self::summary($summary),
            'history_events' => $run->historyEvents
                ->map(static fn (WorkflowHistoryEvent $event): array => self::historyEvent($event))
                ->values()
                ->all(),
            'commands' => $run->commands
                ->map(static fn (WorkflowCommand $command): array => self::command($command))
                ->values()
                ->all(),
            'updates' => $run->updates
                ->map(static fn (WorkflowUpdate $update): array => self::update($update))
                ->values()
                ->all(),
            'tasks' => $run->tasks
                ->map(static fn (WorkflowTask $task): array => self::task($task))
                ->values()
                ->all(),
            'activities' => $run->activityExecutions
                ->map(static fn (ActivityExecution $activity): array => self::activity($activity))
                ->values()
                ->all(),
            'timers' => $run->timers
                ->map(static fn (WorkflowTimer $timer): array => self::timer($timer))
                ->values()
                ->all(),
            'failures' => $run->failures
                ->map(static fn (WorkflowFailure $failure): array => self::failure($failure))
                ->values()
                ->all(),
            'links' => [
                'parents' => self::links($run->parentLinks, $run, 'parent'),
                'children' => self::links($run->childLinks, $run, 'child'),
            ],
        ];
    }

    private static function dedupeKey(WorkflowRun $run): string
    {
        return implode(':', [
            $run->id,
            (string) $run->last_history_sequence,
            self::timestamp($run->last_progress_at ?? $run->updated_at) ?? 'unknown',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function summary(?WorkflowRunSummary $summary): ?array
    {
        if ($summary === null) {
            return null;
        }

        return [
            'status' => $summary->status,
            'status_bucket' => $summary->status_bucket,
            'closed_reason' => $summary->closed_reason,
            'is_current_run' => (bool) $summary->is_current_run,
            'started_at' => self::timestamp($summary->started_at),
            'closed_at' => self::timestamp($summary->closed_at),
            'duration_ms' => $summary->duration_ms,
            'wait_kind' => $summary->wait_kind,
            'wait_reason' => $summary->wait_reason,
            'wait_started_at' => self::timestamp($summary->wait_started_at),
            'wait_deadline_at' => self::timestamp($summary->wait_deadline_at),
            'open_wait_id' => $summary->open_wait_id,
            'resume_source_kind' => $summary->resume_source_kind,
            'resume_source_id' => $summary->resume_source_id,
            'next_task_at' => self::timestamp($summary->next_task_at),
            'next_task_id' => $summary->next_task_id,
            'next_task_type' => $summary->next_task_type,
            'next_task_status' => $summary->next_task_status,
            'next_task_lease_expires_at' => self::timestamp($summary->next_task_lease_expires_at),
            'liveness_state' => $summary->liveness_state,
            'liveness_reason' => $summary->liveness_reason,
            'exception_count' => (int) $summary->exception_count,
            'history_event_count' => (int) $summary->history_event_count,
            'history_size_bytes' => (int) $summary->history_size_bytes,
            'continue_as_new_recommended' => (bool) $summary->continue_as_new_recommended,
            'sort_timestamp' => self::timestamp($summary->sort_timestamp),
            'sort_key' => $summary->sort_key,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function historyEvent(WorkflowHistoryEvent $event): array
    {
        return [
            'id' => $event->id,
            'sequence' => $event->sequence,
            'type' => $event->event_type->value,
            'workflow_task_id' => $event->workflow_task_id,
            'workflow_command_id' => $event->workflow_command_id,
            'recorded_at' => self::timestamp($event->recorded_at),
            'payload' => $event->payload ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function command(WorkflowCommand $command): array
    {
        return [
            'id' => $command->id,
            'sequence' => $command->command_sequence,
            'type' => $command->command_type->value,
            'target_scope' => $command->target_scope,
            'requested_run_id' => $command->requestedRunId(),
            'resolved_run_id' => $command->resolvedRunId(),
            'target_name' => $command->targetName(),
            'payload_codec' => $command->payload_codec,
            'payload' => $command->payload,
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
            'accepted_at' => self::timestamp($command->accepted_at),
            'applied_at' => self::timestamp($command->applied_at),
            'rejected_at' => self::timestamp($command->rejected_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function update(WorkflowUpdate $update): array
    {
        return [
            'id' => $update->id,
            'command_id' => $update->workflow_command_id,
            'command_sequence' => $update->command_sequence,
            'workflow_sequence' => $update->workflow_sequence,
            'name' => $update->name,
            'status' => $update->status->value,
            'outcome' => $update->outcome?->value,
            'rejection_reason' => $update->rejection_reason,
            'validation_errors' => $update->normalizedValidationErrors(),
            'failure_id' => $update->failure_id,
            'accepted_at' => self::timestamp($update->accepted_at),
            'applied_at' => self::timestamp($update->applied_at),
            'rejected_at' => self::timestamp($update->rejected_at),
            'closed_at' => self::timestamp($update->closed_at),
            'arguments' => $update->arguments,
            'result' => $update->result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function task(WorkflowTask $task): array
    {
        return [
            'id' => $task->id,
            'type' => $task->task_type->value,
            'status' => $task->status->value,
            'payload' => $task->payload ?? [],
            'connection' => $task->connection,
            'queue' => $task->queue,
            'compatibility' => $task->compatibility,
            'attempt_count' => $task->attempt_count,
            'repair_count' => $task->repair_count,
            'available_at' => self::timestamp($task->available_at),
            'leased_at' => self::timestamp($task->leased_at),
            'lease_owner' => $task->lease_owner,
            'lease_expires_at' => self::timestamp($task->lease_expires_at),
            'last_dispatch_attempt_at' => self::timestamp($task->last_dispatch_attempt_at),
            'last_dispatched_at' => self::timestamp($task->last_dispatched_at),
            'last_dispatch_error' => $task->last_dispatch_error,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function activity(ActivityExecution $activity): array
    {
        return [
            'id' => $activity->id,
            'sequence' => $activity->sequence,
            'activity_type' => $activity->activity_type,
            'activity_class' => $activity->activity_class,
            'status' => $activity->status->value,
            'connection' => $activity->connection,
            'queue' => $activity->queue,
            'attempt_count' => $activity->attempt_count,
            'current_attempt_id' => $activity->current_attempt_id,
            'arguments' => $activity->arguments,
            'result' => $activity->result,
            'started_at' => self::timestamp($activity->started_at),
            'last_heartbeat_at' => self::timestamp($activity->last_heartbeat_at),
            'closed_at' => self::timestamp($activity->closed_at),
            'attempts' => $activity->attempts
                ->map(static fn (ActivityAttempt $attempt): array => self::attempt($attempt))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function attempt(ActivityAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'activity_execution_id' => $attempt->activity_execution_id,
            'workflow_task_id' => $attempt->workflow_task_id,
            'attempt_number' => $attempt->attempt_number,
            'status' => $attempt->status->value,
            'lease_owner' => $attempt->lease_owner,
            'started_at' => self::timestamp($attempt->started_at),
            'last_heartbeat_at' => self::timestamp($attempt->last_heartbeat_at),
            'lease_expires_at' => self::timestamp($attempt->lease_expires_at),
            'closed_at' => self::timestamp($attempt->closed_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function timer(WorkflowTimer $timer): array
    {
        return [
            'id' => $timer->id,
            'sequence' => $timer->sequence,
            'status' => $timer->status->value,
            'delay_seconds' => $timer->delay_seconds,
            'fire_at' => self::timestamp($timer->fire_at),
            'fired_at' => self::timestamp($timer->fired_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function failure(WorkflowFailure $failure): array
    {
        return [
            'id' => $failure->id,
            'source_kind' => $failure->source_kind,
            'source_id' => $failure->source_id,
            'propagation_kind' => $failure->propagation_kind,
            'handled' => (bool) $failure->handled,
            'exception_class' => $failure->exception_class,
            'message' => $failure->message,
            'file' => $failure->file,
            'line' => $failure->line,
            'trace_preview' => $failure->trace_preview,
            'created_at' => self::timestamp($failure->created_at),
        ];
    }

    /**
     * @param Collection<int, WorkflowLink> $links
     *
     * @return list<array<string, mixed>>
     */
    private static function links(Collection $links, WorkflowRun $run, string $direction): array
    {
        return $links
            ->map(static fn (WorkflowLink $link): array => [
                'id' => $link->id,
                'type' => $link->link_type,
                'parent_workflow_instance_id' => $link->parent_workflow_instance_id,
                'parent_workflow_run_id' => $link->parent_workflow_run_id,
                'child_workflow_instance_id' => $link->child_workflow_instance_id,
                'child_workflow_run_id' => $link->child_workflow_run_id,
                'child_call_id' => self::childCallIdForLink($link, $run, $direction),
                'sequence' => $link->sequence,
                'is_primary_parent' => (bool) $link->is_primary_parent,
                'created_at' => self::timestamp($link->created_at),
            ])
            ->values()
            ->all();
    }

    private static function childCallIdForLink(WorkflowLink $link, WorkflowRun $run, string $direction): ?string
    {
        if ($link->link_type !== 'child_workflow') {
            return null;
        }

        if ($direction === 'parent') {
            return ChildRunHistory::childCallIdForRun($run) ?? $link->id;
        }

        if ($link->sequence === null) {
            return $link->id;
        }

        return ChildRunHistory::childCallIdForSequence($run, (int) $link->sequence) ?? $link->id;
    }

    private static function timestamp(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toJSON();
        }

        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
