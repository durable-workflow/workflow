<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Carbon;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class RunActivityView
{
    public const HISTORY_AUTHORITY_TYPED = 'typed_history';

    public const HISTORY_AUTHORITY_MUTABLE_OPEN_FALLBACK = 'mutable_open_fallback';

    public const HISTORY_AUTHORITY_UNSUPPORTED_TERMINAL = 'unsupported_terminal_without_history';

    public const UNSUPPORTED_TERMINAL_REASON = 'terminal_activity_row_without_typed_history';

    /**
     * @return list<array<string, mixed>>
     */
    public static function activitiesForRun(WorkflowRun $run): array
    {
        return self::activityStates($run);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function logsForRun(WorkflowRun $run): array
    {
        return self::logsFromActivities(self::activityStates($run));
    }

    /**
     * @param list<array<string, mixed>> $activities
     * @return list<array<string, mixed>>
     */
    public static function logsFromActivities(array $activities): array
    {
        return array_map(static fn (array $activity): array => [
            'id' => $activity['id'],
            'index' => max(($activity['sequence'] ?? 1) - 1, 0),
            'now' => $activity['started_at'] ?? $activity['created_at'],
            'class' => $activity['class'],
            'type' => $activity['type'] ?? null,
            'status' => $activity['status'] ?? null,
            'source_status' => $activity['row_status'] ?? ($activity['status'] ?? null),
            'history_authority' => self::stringValue($activity['history_authority'] ?? null),
            'history_unsupported_reason' => self::stringValue($activity['history_unsupported_reason'] ?? null),
            'diagnostic_only' => self::isDiagnosticOnly($activity),
            'result' => $activity['result'],
            'created_at' => $activity['closed_at']
                ?? $activity['last_heartbeat_at']
                ?? $activity['started_at']
                ?? $activity['created_at'],
        ], $activities);
    }

    /**
     * @return array<string, string>
     */
    public static function classesForRun(WorkflowRun $run): array
    {
        return self::classesFromActivities(self::activityStates($run));
    }

    /**
     * @param list<array<string, mixed>> $activities
     * @return array<string, string>
     */
    public static function classesFromActivities(array $activities): array
    {
        $classes = [];

        foreach ($activities as $activity) {
            if (! is_string($activity['id'] ?? null) || ! is_string($activity['class'] ?? null)) {
                continue;
            }

            $classes[$activity['id']] = $activity['class'];
        }

        return $classes;
    }

    /**
     * @param array<string, mixed> $activity
     */
    public static function isDiagnosticOnly(array $activity): bool
    {
        $historyAuthority = self::stringValue($activity['history_authority'] ?? null);

        return $historyAuthority !== null && $historyAuthority !== self::HISTORY_AUTHORITY_TYPED;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function activityStates(WorkflowRun $run): array
    {
        $run->loadMissing(['activityExecutions.attempts', 'historyEvents']);

        $states = [];
        $executions = $run->activityExecutions->keyBy('id');
        $attemptsByActivityId = ActivityAttemptSnapshots::forRun($run);

        foreach (self::activityEvents($run) as $event) {
            $snapshot = ActivitySnapshot::fromEvent($event);
            $activityId = is_array($snapshot) && is_string($snapshot['id'] ?? null)
                ? $snapshot['id']
                : null;

            if ($activityId === null) {
                continue;
            }

            $state = $states[$activityId] ?? [
                'id' => $activityId,
            ];
            $eventTypes = is_array($state['history_event_types'] ?? null)
                ? $state['history_event_types']
                : [];
            $eventTypes[] = $event->event_type->value;

            $state['history_authority'] = self::HISTORY_AUTHORITY_TYPED;
            $state['history_event_types'] = array_values(array_unique($eventTypes));

            $states[$activityId] = ActivitySnapshot::merge($state, $snapshot);
        }

        foreach ($run->activityExecutions as $execution) {
            if (! $execution instanceof ActivityExecution) {
                continue;
            }

            if (array_key_exists($execution->id, $states)) {
                continue;
            }

            $snapshot = ActivitySnapshot::fromExecution($execution);
            $snapshot['history_event_types'] = [];
            $snapshot['row_status'] = $execution->status?->value;

            if (in_array($execution->status, [ActivityStatus::Pending, ActivityStatus::Running], true)) {
                $snapshot['history_authority'] = self::HISTORY_AUTHORITY_MUTABLE_OPEN_FALLBACK;
            } else {
                $snapshot['history_authority'] = self::HISTORY_AUTHORITY_UNSUPPORTED_TERMINAL;
                $snapshot['history_unsupported_reason'] = self::UNSUPPORTED_TERMINAL_REASON;
                unset($snapshot['result'], $snapshot['closed_at'], $snapshot['exception']);
            }

            $states[$execution->id] = $snapshot;
        }

        $activities = [];

        foreach ($states as $activityId => $state) {
            /** @var ActivityExecution|null $execution */
            $execution = $executions->get($activityId);
            $activities[] = self::presentActivity($state, $execution, $attemptsByActivityId[$activityId] ?? []);
        }

        usort($activities, static function (array $left, array $right): int {
            $leftSequence = is_int($left['sequence'] ?? null) ? $left['sequence'] : PHP_INT_MAX;
            $rightSequence = is_int($right['sequence'] ?? null) ? $right['sequence'] : PHP_INT_MAX;

            if ($leftSequence !== $rightSequence) {
                return $leftSequence <=> $rightSequence;
            }

            $leftCreatedAt = self::timestampToMilliseconds($left['created_at'] ?? null);
            $rightCreatedAt = self::timestampToMilliseconds($right['created_at'] ?? null);

            if ($leftCreatedAt !== $rightCreatedAt) {
                return $leftCreatedAt <=> $rightCreatedAt;
            }

            return ($left['id'] ?? '') <=> ($right['id'] ?? '');
        });

        return $activities;
    }

    /**
     * @return iterable<WorkflowHistoryEvent>
     */
    private static function activityEvents(WorkflowRun $run): iterable
    {
        return $run->historyEvents
            ->filter(static fn (WorkflowHistoryEvent $event): bool => in_array($event->event_type, [
                HistoryEventType::ActivityScheduled,
                HistoryEventType::ActivityStarted,
                HistoryEventType::ActivityHeartbeatRecorded,
                HistoryEventType::ActivityRetryScheduled,
                HistoryEventType::ActivityCompleted,
                HistoryEventType::ActivityFailed,
                HistoryEventType::ActivityCancelled,
            ], true))
            ->sortBy('sequence');
    }

    /**
     * @param array<string, mixed> $state
     * @param list<array<string, mixed>> $attemptStates
     * @return array<string, mixed>
     */
    private static function presentActivity(
        array $state,
        ?ActivityExecution $execution = null,
        array $attemptStates = [],
    ): array
    {
        $attempts = self::presentAttempts($state, $execution, $attemptStates);
        $latestAttempt = $attempts === []
            ? null
            : $attempts[array_key_last($attempts)];
        $attemptCount = is_int($state['attempt_count'] ?? null)
            ? $state['attempt_count']
            : (is_int($latestAttempt['attempt_number'] ?? null) ? $latestAttempt['attempt_number'] : 0);
        $unsupportedReason = self::stringValue($state['history_unsupported_reason'] ?? null);
        $status = $unsupportedReason === self::UNSUPPORTED_TERMINAL_REASON
            ? 'unsupported'
            : ($state['status'] ?? 'pending');

        return [
            'id' => $state['id'] ?? null,
            'idempotency_key' => $state['idempotency_key'] ?? $state['id'] ?? null,
            'sequence' => $state['sequence'] ?? null,
            'type' => $state['type'] ?? null,
            'class' => $state['class'] ?? null,
            'history_authority' => self::stringValue($state['history_authority'] ?? null),
            'diagnostic_only' => self::isDiagnosticOnly($state),
            'history_event_types' => is_array($state['history_event_types'] ?? null)
                ? array_values(array_filter(
                    $state['history_event_types'],
                    static fn (mixed $eventType): bool => is_string($eventType) && $eventType !== '',
                ))
                : [],
            'history_unsupported_reason' => $unsupportedReason,
            'row_status' => self::stringValue($state['row_status'] ?? null),
            'parallel_group_kind' => $state['parallel_group_kind'] ?? null,
            'parallel_group_id' => $state['parallel_group_id'] ?? null,
            'parallel_group_base_sequence' => $state['parallel_group_base_sequence'] ?? null,
            'parallel_group_size' => $state['parallel_group_size'] ?? null,
            'parallel_group_index' => $state['parallel_group_index'] ?? null,
            'parallel_group_path' => $state['parallel_group_path'] ?? [],
            'attempt_id' => $state['attempt_id'] ?? ($latestAttempt['id'] ?? null),
            'status' => $status,
            'attempt_count' => $attemptCount,
            'retry_policy' => $state['retry_policy'] ?? ($execution?->retry_policy ?? null),
            'connection' => $state['connection'] ?? null,
            'queue' => $state['queue'] ?? null,
            'last_heartbeat_at' => $state['last_heartbeat_at'] ?? ($latestAttempt['last_heartbeat_at'] ?? null),
            'created_at' => $state['created_at'] ?? null,
            'started_at' => $state['started_at'] ?? ($latestAttempt['started_at'] ?? null),
            'closed_at' => self::activityClosedAt($status, $state, $latestAttempt),
            'arguments' => self::publicSerializedValue($state['arguments'] ?? null, []),
            'result' => self::publicSerializedValue(
                $unsupportedReason === null ? ($state['result'] ?? null) : null,
                null,
            ),
            'attempts' => $attempts,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed>|null $latestAttempt
     */
    private static function activityClosedAt(mixed $status, array $state, ?array $latestAttempt): mixed
    {
        if (! in_array($status, [
            ActivityStatus::Completed->value,
            ActivityStatus::Failed->value,
            ActivityStatus::Cancelled->value,
        ], true)) {
            return null;
        }

        return $state['closed_at'] ?? ($latestAttempt['closed_at'] ?? null);
    }

    /**
     * @param array<string, mixed> $state
     * @param list<array<string, mixed>> $attemptStates
     * @return list<array<string, mixed>>
     */
    private static function presentAttempts(
        array $state,
        ?ActivityExecution $execution,
        array $attemptStates,
    ): array
    {
        $attempts = array_map(
            static fn (array $attempt): array => self::presentAttempt($attempt, $execution),
            $attemptStates,
        );

        $syntheticAttempt = self::syntheticAttempt($state, $execution);

        if (
            $syntheticAttempt !== null
            && ! collect($attempts)->contains(static fn (array $attempt): bool => ($attempt['id'] ?? null) === $syntheticAttempt['id'])
        ) {
            $attempts[] = $syntheticAttempt;
        }

        usort($attempts, static function (array $left, array $right): int {
            $leftAttemptNumber = is_int($left['attempt_number'] ?? null) ? $left['attempt_number'] : PHP_INT_MAX;
            $rightAttemptNumber = is_int($right['attempt_number'] ?? null) ? $right['attempt_number'] : PHP_INT_MAX;

            if ($leftAttemptNumber !== $rightAttemptNumber) {
                return $leftAttemptNumber <=> $rightAttemptNumber;
            }

            $leftStartedAt = self::timestampToMilliseconds($left['started_at'] ?? null);
            $rightStartedAt = self::timestampToMilliseconds($right['started_at'] ?? null);

            if ($leftStartedAt !== $rightStartedAt) {
                return $leftStartedAt <=> $rightStartedAt;
            }

            return ($left['id'] ?? '') <=> ($right['id'] ?? '');
        });

        return array_values($attempts);
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    private static function presentAttempt(array $attempt, ?ActivityExecution $execution): array
    {
        return [
            'id' => $attempt['id'] ?? null,
            'attempt_number' => $attempt['attempt_number'] ?? null,
            'status' => $attempt['status'] ?? null,
            'task_id' => $attempt['workflow_task_id'] ?? null,
            'lease_owner' => $attempt['lease_owner'] ?? null,
            'lease_expires_at' => self::timestamp($attempt['lease_expires_at'] ?? null),
            'started_at' => self::timestamp($attempt['started_at'] ?? null),
            'last_heartbeat_at' => self::timestamp($attempt['last_heartbeat_at'] ?? null),
            'closed_at' => self::timestamp($attempt['closed_at'] ?? null),
            'can_continue' => self::attemptStateCanContinue($attempt, $execution),
            'cancel_requested' => self::attemptStateCancelRequested($attempt, $execution),
            'stop_reason' => self::attemptStateStopReason($attempt, $execution),
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    private static function syntheticAttempt(array $state, ?ActivityExecution $execution): ?array
    {
        $attemptId = self::stringValue($state['attempt_id'] ?? null)
            ?? self::stringValue($execution?->current_attempt_id);
        $attemptNumber = is_int($state['attempt_count'] ?? null)
            ? $state['attempt_count']
            : self::executionAttemptCount($execution);

        if ($attemptId === null || $attemptNumber === null || $attemptNumber <= 0) {
            return null;
        }

        return [
            'id' => $attemptId,
            'attempt_number' => $attemptNumber,
            'status' => $state['status'] ?? $execution?->status?->value ?? null,
            'task_id' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'started_at' => $state['started_at'] ?? $execution?->started_at,
            'last_heartbeat_at' => $state['last_heartbeat_at'] ?? $execution?->last_heartbeat_at,
            'closed_at' => $state['closed_at'] ?? $execution?->closed_at,
            'can_continue' => self::syntheticAttemptCanContinue($state, $execution),
            'cancel_requested' => self::syntheticAttemptCancelRequested($state, $execution),
            'stop_reason' => self::syntheticAttemptStopReason($state, $execution),
        ];
    }

    /**
     * @param array<string, mixed> $attempt
     */
    private static function attemptStateCanContinue(array $attempt, ?ActivityExecution $execution): bool
    {
        if ($execution instanceof ActivityExecution) {
            return self::stringValue($attempt['id'] ?? null) === self::stringValue($execution->current_attempt_id)
                && self::intValue($attempt['attempt_number'] ?? null) === self::intValue($execution->attempt_count)
                && $execution->status === ActivityStatus::Running
                && self::stringValue($attempt['status'] ?? null) === ActivityAttemptStatus::Running->value;
        }

        return self::stringValue($attempt['status'] ?? null) === ActivityAttemptStatus::Running->value;
    }

    /**
     * @param array<string, mixed> $attempt
     */
    private static function attemptStateCancelRequested(array $attempt, ?ActivityExecution $execution): bool
    {
        return self::stringValue($attempt['status'] ?? null) === ActivityAttemptStatus::Cancelled->value
            || $execution?->status === ActivityStatus::Cancelled;
    }

    /**
     * @param array<string, mixed> $attempt
     */
    private static function attemptStateStopReason(array $attempt, ?ActivityExecution $execution): ?string
    {
        $status = self::stringValue($attempt['status'] ?? null);

        if ($status === ActivityAttemptStatus::Cancelled->value) {
            return 'attempt_cancelled';
        }

        if ($execution?->status === ActivityStatus::Cancelled) {
            return 'activity_cancelled';
        }

        if ($status === ActivityAttemptStatus::Expired->value) {
            return 'attempt_expired';
        }

        if (in_array($status, [
            ActivityAttemptStatus::Completed->value,
            ActivityAttemptStatus::Failed->value,
        ], true)) {
            return 'attempt_closed';
        }

        if (in_array($execution?->status, [ActivityStatus::Completed, ActivityStatus::Failed], true)) {
            return 'activity_closed';
        }

        if (! self::attemptStateCanContinue($attempt, $execution)) {
            return 'stale_attempt';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function syntheticAttemptCanContinue(array $state, ?ActivityExecution $execution): bool
    {
        if ($execution instanceof ActivityExecution) {
            return self::stringValue($state['attempt_id'] ?? null) === self::stringValue($execution->current_attempt_id)
                && $execution->status === ActivityStatus::Running
                && self::stringValue($state['status'] ?? null) === ActivityStatus::Running->value;
        }

        return self::stringValue($state['status'] ?? null) === ActivityStatus::Running->value;
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function syntheticAttemptCancelRequested(array $state, ?ActivityExecution $execution): bool
    {
        return self::stringValue($state['status'] ?? null) === ActivityStatus::Cancelled->value
            || $execution?->status === ActivityStatus::Cancelled;
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function syntheticAttemptStopReason(array $state, ?ActivityExecution $execution): ?string
    {
        $status = self::stringValue($state['status'] ?? null) ?? $execution?->status?->value;

        if ($status === ActivityStatus::Cancelled->value || $execution?->status === ActivityStatus::Cancelled) {
            return 'activity_cancelled';
        }

        if ($status === ActivityStatus::Completed->value || $status === ActivityStatus::Failed->value) {
            return 'attempt_closed';
        }

        if ($status === ActivityStatus::Pending->value || $execution?->status === ActivityStatus::Pending) {
            return null;
        }

        if (! self::syntheticAttemptCanContinue($state, $execution)) {
            return 'stale_attempt';
        }

        return null;
    }

    private static function publicSerializedValue(mixed $value, mixed $default): string
    {
        if (! is_string($value) || $value === '') {
            return serialize($default);
        }

        try {
            return serialize(Serializer::unserialize($value));
        } catch (Throwable) {
            return serialize($default);
        }
    }

    private static function timestampToMilliseconds(mixed $timestamp): int
    {
        if ($timestamp instanceof \Carbon\CarbonInterface) {
            return $timestamp->getTimestampMs();
        }

        if (is_string($timestamp) && $timestamp !== '') {
            return Carbon::parse($timestamp)->getTimestampMs();
        }

        return PHP_INT_MAX;
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

        return is_string($value) && is_numeric($value)
            ? (int) $value
            : null;
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

    private static function executionAttemptCount(?ActivityExecution $execution): ?int
    {
        $attemptCount = is_int($execution?->attempt_count) ? $execution->attempt_count : 0;

        return $attemptCount > 0 ? $attemptCount : null;
    }
}
