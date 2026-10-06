<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowTask;

final class RunCurrentWaits
{
    /**
     * Observe a bounded set of projected open waits and their own live task and
     * activity metadata. This read neither audits nor repairs history projections.
     * Missing evidence is unknown, rather than proof that the run has no waits.
     *
     * @return array<string, mixed>
     */
    public static function forRun(WorkflowRun $run, int $limit = 50, ?CarbonInterface $now = null): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Current wait summaries require a limit from 1 to 100.');
        }

        $now ??= Carbon::now();
        $pruned = $run->details_pruned_at !== null;
        $terminal = $run->status->isTerminal();
        $waits = [];
        $hasMore = false;

        if (! $pruned && ! $terminal) {
            $rows = self::query($run, 'run_wait_model', WorkflowRunWait::class)
                ->where('status', 'open')
                ->orderBy('position')
                ->orderBy('wait_id')
                ->limit($limit + 1)
                ->get([
                    'id', 'wait_id', 'kind', 'summary', 'target_name', 'target_type',
                    'deadline_at', 'resume_source_kind', 'resume_source_id', 'task_id',
                    'history_authority', 'history_unsupported_reason',
                ]);
            $hasMore = $rows->count() > $limit;
            $rows = $rows->take($limit);
            $tasks = self::query($run, 'task_model', WorkflowTask::class)
                ->whereKey($rows->pluck('task_id')->filter()->unique()->all())
                ->get(['id', 'task_type', 'status', 'payload', 'available_at', 'lease_expires_at'])
                ->keyBy('id');
            $activityIds = $rows->where('kind', 'activity')
                ->pluck('resume_source_id')
                ->filter()
                ->unique()
                ->all();
            $activities = self::query($run, 'activity_execution_model', ActivityExecution::class)
                ->whereKey($activityIds)
                ->get([
                    'id', 'status', 'activity_type', 'activity_class', 'attempt_count',
                    'current_attempt_id', 'retry_policy', 'schedule_deadline_at',
                    'close_deadline_at', 'schedule_to_close_deadline_at', 'heartbeat_deadline_at',
                ])
                ->keyBy('id');
            $attempts = self::query($run, 'activity_attempt_model', ActivityAttempt::class)
                ->whereKey($activities->pluck('current_attempt_id')->filter()->unique()->all())
                ->whereIn('activity_execution_id', $activityIds)
                ->get(['id', 'activity_execution_id', 'attempt_number', 'status'])
                ->keyBy('id');

            foreach ($rows as $row) {
                if (! $row instanceof WorkflowRunWait) {
                    continue;
                }

                $task = $tasks->get($row->task_id);
                $activity = $row->kind === 'activity' ? $activities->get($row->resume_source_id) : null;
                $attempt = $activity instanceof ActivityExecution ? $attempts->get(
                    $activity->current_attempt_id
                ) : null;
                $waits[] = self::wait(
                    $row,
                    $task instanceof WorkflowTask ? $task : null,
                    $activity instanceof ActivityExecution ? $activity : null,
                    $attempt instanceof ActivityAttempt ? $attempt : null,
                    $now,
                );
            }
        }

        return [
            'instance_id' => $run->workflow_instance_id,
            'run_id' => $run->id,
            'namespace' => $run->namespace,
            'observed_at' => $now->toIso8601String(),
            'source' => 'workflow_run_waits',
            'history_audit' => 'not_evaluated',
            'state' => $pruned ? 'pruned' : ($terminal ? 'available' : ($waits === [] ? 'unavailable' : 'partial')),
            'unavailable_reason' => ! $pruned && ! $terminal && $waits === []
                ? 'current_wait_projection_unavailable' : null,
            'waits' => $waits,
            'returned_count' => count($waits),
            'total_count' => ! $pruned && $terminal ? 0 : null,
            'limit' => $limit,
            'has_more' => $hasMore,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function wait(
        WorkflowRunWait $wait,
        ?WorkflowTask $task,
        ?ActivityExecution $activity,
        ?ActivityAttempt $attempt,
        CarbonInterface $now,
    ): array {
        $unavailableReason = $wait->history_unsupported_reason;
        if ($wait->history_authority === 'mutable_open_fallback') {
            $unavailableReason ??= 'wait_without_typed_history';
        }

        if ($wait->kind === 'activity') {
            if (! $activity instanceof ActivityExecution) {
                $unavailableReason ??= 'activity_metadata_unavailable';
            } elseif (! in_array($activity->status, [ActivityStatus::Pending, ActivityStatus::Running], true)) {
                $unavailableReason ??= 'activity_no_longer_open';
            }
        }

        if ($task instanceof WorkflowTask && ! self::taskMatchesWait($wait, $task)) {
            $task = null;
        }

        $taskOpen = $task instanceof WorkflowTask
            && in_array($task->status, [TaskStatus::Ready, TaskStatus::Leased], true);
        $retryAfter = $taskOpen && $wait->kind === 'activity'
            ? self::positiveInt($task?->payload['retry_after_attempt'] ?? null) : null;
        $retry = $retryAfter !== null;
        $resume = $unavailableReason === null && $task?->status === TaskStatus::Ready
            ? $task->available_at : null;
        $deadline = null;

        if ($unavailableReason === null) {
            if ($wait->kind === 'timer') {
                $resume ??= $wait->deadline_at;
            } elseif ($activity instanceof ActivityExecution) {
                $fields = $activity->status === ActivityStatus::Running
                    ? ['close_deadline_at', 'heartbeat_deadline_at', 'schedule_to_close_deadline_at']
                    : ['schedule_deadline_at', 'schedule_to_close_deadline_at'];

                foreach ($fields as $field) {
                    $candidate = $activity->getAttribute($field);
                    if ($candidate instanceof CarbonInterface
                        && ($deadline === null || $candidate->lessThan($deadline))) {
                        $deadline = $candidate;
                    }
                }
            } else {
                $deadline = $wait->deadline_at;
            }
        }

        // A task from another dependency is never a source of timing or retries.
        // A timer fire makes work eligible and does not expire the workflow.
        $state = $unavailableReason !== null ? 'unavailable'
            : ($deadline !== null && $deadline->lessThanOrEqualTo($now) ? 'deadline_elapsed'
                : ($resume === null ? 'resume_time_unknown' : ($resume->greaterThan($now) ? 'planned' : 'eligible')));
        $attemptMatches = $activity instanceof ActivityExecution && $attempt instanceof ActivityAttempt
            && $attempt->activity_execution_id === $activity->id;
        $attemptNumber = $attemptMatches ? (int) $attempt->attempt_number : null;
        if ($retry && $task?->status === TaskStatus::Ready) {
            $attemptNumber = $retryAfter + 1;
        }

        return [
            'id' => $wait->wait_id,
            'kind' => $retry ? 'activity_retry' : $wait->kind,
            'state' => $state,
            'reason' => $wait->summary,
            'dependency_id' => $wait->resume_source_id,
            'dependency_type' => $activity?->activity_type ?? $wait->target_type,
            'target_name' => $wait->target_name,
            'task_id' => $task?->id,
            'task_status' => $task?->status?->value,
            'task_metadata_state' => $task instanceof WorkflowTask ? 'available' : 'unavailable',
            'next_scheduled_resume_at' => $resume?->toIso8601String(),
            'deadline_at' => $deadline?->toIso8601String(),
            'lease_expires_at' => $taskOpen ? $task?->lease_expires_at?->toIso8601String() : null,
            'attempt_id' => $attemptMatches && ! ($retry && $task?->status === TaskStatus::Ready)
                ? $attempt?->id : null,
            'attempt_number' => $attemptNumber,
            'attempt_limit' => $wait->kind === 'activity'
                ? (self::positiveInt($task?->payload['max_attempts'] ?? null)
                    ?? self::positiveInt($activity?->retry_policy['max_attempts'] ?? null)) : null,
            'unavailable_reason' => $unavailableReason,
        ];
    }

    private static function taskMatchesWait(WorkflowRunWait $wait, WorkflowTask $task): bool
    {
        $sourceId = $wait->resume_source_id;
        if ($wait->kind === 'activity') {
            return $sourceId !== null && $task->task_type === TaskType::Activity
                && ($task->payload['activity_execution_id'] ?? null) === $sourceId;
        }

        if ($wait->kind === 'timer' || $wait->resume_source_kind === 'timer') {
            return $sourceId !== null && $task->task_type === TaskType::Timer
                && ($task->payload['timer_id'] ?? null) === $sourceId;
        }

        if ($task->task_type !== TaskType::Workflow) {
            return false;
        }

        if (($task->payload['open_wait_id'] ?? null) === $wait->wait_id) {
            return true;
        }

        if ($wait->kind !== 'child') {
            return false;
        }

        $callId = $task->payload['child_call_id'] ?? null;

        return ($sourceId !== null && ($task->payload['child_workflow_run_id'] ?? null) === $sourceId)
            || (is_string($callId) && $callId !== '' && 'child:' . $callId === $wait->wait_id);
    }

    /**
     * @param class-string<Model> $default
     * @return Builder<Model>
     */
    private static function query(WorkflowRun $run, string $key, string $default): Builder
    {
        $model = ConfiguredV2Models::resolve($key, $default);

        return (new $model())->setConnection($run->getConnectionName())
            ->newQuery()
            ->where('workflow_run_id', $run->id);
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
