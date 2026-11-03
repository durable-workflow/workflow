<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class FailedRunRedrivePlan
{
    /**
     * Reused steps are every step before the failed activity, in step order.
     * `completed` lists only the reused activity completions.
     *
     * @return array{
     *     eligible: bool,
     *     reason: string|null,
     *     resume_step_sequence: int|null,
     *     completed: list<array<string, mixed>>,
     *     reused_steps: list<array{
     *         event_type: HistoryEventType,
     *         payload: array<string, mixed>,
     *         recorded_at: string|null,
     *         scheduled_payload?: array<string, mixed>,
     *         signal_events?: list<array{event_type: HistoryEventType, payload: array<string, mixed>, recorded_at: string|null}>
     *     }>
     * }
     */
    public static function forRun(WorkflowRun $run): array
    {
        if ($run->status !== RunStatus::Failed) {
            return self::reject('run_not_failed');
        }

        if (! $run->relationLoaded('historyEvents')) {
            $run->load('historyEvents');
        }
        $events = $run->historyEvents->sortBy('sequence');
        $started = null;
        $failure = null;
        $activityEvents = [];
        $markerEvents = [];
        $timerEvents = [];
        $signalEvents = [];
        $lastActivityEvent = null;

        foreach ($events as $event) {
            if (! $event instanceof WorkflowHistoryEvent || ! $event->event_type instanceof HistoryEventType) {
                return self::reject('invalid_history');
            }

            if ($failure !== null) {
                return self::reject('events_after_failure');
            }

            if ($event->event_type === HistoryEventType::WorkflowStarted) {
                if ($started !== null) {
                    return self::reject('duplicate_start');
                }

                $started = $event;

                continue;
            }

            if ($event->event_type === HistoryEventType::WorkflowFailed) {
                if ($started === null) {
                    return self::reject('missing_start');
                }

                $failure = $event;

                continue;
            }

            if ($event->event_type === HistoryEventType::StartAccepted) {
                if ($started !== null) {
                    return self::reject('unexpected_start');
                }

                continue;
            }

            if (in_array($event->event_type, [
                HistoryEventType::SideEffectRecorded,
                HistoryEventType::VersionMarkerRecorded,
                HistoryEventType::MemoUpserted,
                HistoryEventType::SearchAttributesUpserted,
            ], true)) {
                $payload = $event->payload;
                $sequence = is_array($payload) ? ($payload['sequence'] ?? null) : null;

                if ($started === null || ! is_int($sequence) || $sequence < 1) {
                    return self::reject('invalid_step_sequence');
                }

                if (isset($markerEvents[$sequence])) {
                    return self::reject('duplicate_step_sequence');
                }

                if (
                    $event->event_type === HistoryEventType::SideEffectRecorded
                    && self::isExternalResult($payload['result'] ?? null)
                ) {
                    return self::reject('external_side_effect_result_not_supported');
                }

                $markerEvents[$sequence] = $event;

                continue;
            }

            if ($event->event_type === HistoryEventType::MessageCursorAdvanced) {
                // Message stream bookkeeping, not a step. Redrive carries the
                // source's cursor position to the successor instead.
                continue;
            }

            if (in_array($event->event_type, [
                HistoryEventType::SignalWaitOpened,
                HistoryEventType::SignalReceived,
                HistoryEventType::SignalApplied,
            ], true)) {
                if ($started === null) {
                    return self::reject('invalid_step_sequence');
                }

                $signalEvents[] = $event;

                continue;
            }

            if (in_array($event->event_type, [HistoryEventType::TimerScheduled, HistoryEventType::TimerFired], true)) {
                $payload = $event->payload;
                $sequence = is_array($payload) ? ($payload['sequence'] ?? null) : null;

                if ($started === null || ! is_int($sequence) || $sequence < 1) {
                    return self::reject('invalid_step_sequence');
                }

                if (! self::isPlainTimer($payload)) {
                    return self::reject('unsupported_timer');
                }

                $timerEvents[$sequence][] = $event;

                continue;
            }

            if (! in_array($event->event_type, [
                HistoryEventType::ActivityScheduled,
                HistoryEventType::ActivityStarted,
                HistoryEventType::ActivityHeartbeatRecorded,
                HistoryEventType::ActivityCompleted,
                HistoryEventType::ActivityFailed,
            ], true)) {
                return self::reject('unsupported_history');
            }

            $payload = $event->payload;
            $sequence = is_array($payload) ? ($payload['sequence'] ?? null) : null;

            if (is_array($payload) && (
                ($payload['execution_mode'] ?? null) === LocalActivityRuntime::EXECUTION_MODE
                || ($payload['local_activity'] ?? null) === true
            )) {
                return self::reject('unsupported_local_activity');
            }

            if ($started === null || ! is_int($sequence) || $sequence < 1) {
                return self::reject('invalid_activity_sequence');
            }

            $activityEvents[$sequence][] = $event;
            $lastActivityEvent = $event;
        }

        if ($started === null || $failure === null) {
            return self::reject('missing_terminal_history');
        }

        $failurePayload = $failure->payload;
        $boundary = is_array($failurePayload) ? ($failurePayload['failed_step_sequence'] ?? null) : null;

        if (
            ! is_int($boundary)
            || $boundary < 1
            || ($failurePayload['failed_step_kind'] ?? null) !== 'activity'
        ) {
            return self::reject('unrecorded_failure_boundary');
        }

        $signalSteps = self::signalSteps($signalEvents);

        if (is_string($signalSteps)) {
            return self::reject($signalSteps);
        }

        foreach ([
            ...array_keys($markerEvents),
            ...array_keys($timerEvents),
            ...array_keys($signalSteps),
        ] as $sequence) {
            if ($sequence >= $boundary) {
                return self::reject('invalid_failed_step');
            }
        }

        foreach (array_keys($timerEvents) as $sequence) {
            if (isset($activityEvents[$sequence]) || isset($markerEvents[$sequence])) {
                return self::reject('duplicate_step_sequence');
            }
        }

        foreach (array_keys($markerEvents) as $sequence) {
            if (isset($activityEvents[$sequence])) {
                return self::reject('duplicate_step_sequence');
            }
        }

        foreach (array_keys($signalSteps) as $sequence) {
            if (isset($activityEvents[$sequence]) || isset($markerEvents[$sequence]) || isset($timerEvents[$sequence])) {
                return self::reject('duplicate_step_sequence');
            }
        }

        if (count($activityEvents) + count($markerEvents) + count($timerEvents) + count($signalSteps) !== $boundary) {
            return self::reject('activity_sequence_gap');
        }

        $completed = [];
        $reusedSteps = [];

        for ($sequence = 1; $sequence <= $boundary; ++$sequence) {
            $marker = $markerEvents[$sequence] ?? null;

            if ($marker instanceof WorkflowHistoryEvent) {
                $markerPayload = $marker->payload;
                // The task and command are the source run's recording
                // context, not part of the reused step.
                unset($markerPayload['task'], $markerPayload['command']);
                $reusedSteps[] = [
                    'event_type' => $marker->event_type,
                    'payload' => $markerPayload,
                    'recorded_at' => $marker->recorded_at?->toIso8601String(),
                ];

                continue;
            }

            $signalStep = $signalSteps[$sequence] ?? null;

            if (is_array($signalStep)) {
                $reusedSteps[] = $signalStep;

                continue;
            }

            $timerStep = $timerEvents[$sequence] ?? null;

            if (is_array($timerStep)) {
                $timer = self::firedTimerStep($timerStep);

                if ($timer === null) {
                    return self::reject('incomplete_timer_step');
                }

                $reusedSteps[] = $timer;

                continue;
            }

            $stepEvents = $activityEvents[$sequence] ?? null;

            if (! is_array($stepEvents)) {
                return self::reject('activity_sequence_gap');
            }

            $activityType = null;
            $completion = null;
            $failureCount = 0;

            foreach ($stepEvents as $event) {
                $payload = $event->payload;
                $recordedType = is_array($payload) ? ($payload['activity_type'] ?? null) : null;

                if (! is_string($recordedType) || $recordedType === '') {
                    return self::reject('missing_activity_type');
                }

                if ($activityType !== null && $recordedType !== $activityType) {
                    return self::reject('activity_type_mismatch');
                }

                $activityType = $recordedType;

                if ($event->event_type === HistoryEventType::ActivityCompleted) {
                    if ($completion !== null) {
                        return self::reject('duplicate_activity_completion');
                    }

                    $completion = $event;
                }

                if ($event->event_type === HistoryEventType::ActivityFailed) {
                    ++$failureCount;
                }
            }

            if ($sequence === $boundary) {
                if ($completion !== null || $failureCount !== 1) {
                    return self::reject('invalid_failed_step');
                }

                continue;
            }

            if ($completion === null || $failureCount !== 0) {
                return self::reject('incomplete_activity_prefix');
            }

            $payload = $completion->payload;

            if (
                ! is_array($payload)
                || ! array_key_exists('result', $payload)
                || ! is_string($payload['payload_codec'] ?? null)
            ) {
                return self::reject('missing_activity_result');
            }

            if (self::isExternalResult($payload['result'])) {
                return self::reject('external_activity_result_not_supported');
            }

            try {
                $blob = ExternalPayloads::payloadBlob($payload['result'], $payload['payload_codec'], $run->namespace);

                if (! is_string($blob)) {
                    return self::reject('missing_activity_result');
                }

                Serializer::unserializeWithCodec($payload['payload_codec'], $blob);
            } catch (Throwable) {
                return self::reject('unavailable_activity_result');
            }

            $completed[] = $payload;
            $reusedSteps[] = [
                'event_type' => HistoryEventType::ActivityCompleted,
                'payload' => $payload,
                'recorded_at' => $completion->recorded_at?->toIso8601String(),
            ];
        }

        if (
            ! $lastActivityEvent instanceof WorkflowHistoryEvent
            || $lastActivityEvent->event_type !== HistoryEventType::ActivityFailed
            || ($lastActivityEvent->payload['sequence'] ?? null) !== $boundary
        ) {
            return self::reject('unrecorded_failure_boundary');
        }

        return [
            'eligible' => true,
            'reason' => null,
            'resume_step_sequence' => $boundary,
            'completed' => $completed,
            'reused_steps' => $reusedSteps,
        ];
    }

    /**
     * A durable timer authored with timer(). Condition and signal timeouts,
     * cancellation cleanup and timers in a scope or parallel group belong to
     * history redrive does not copy yet.
     *
     * @param array<string, mixed>|null $payload
     */
    private static function isPlainTimer(?array $payload): bool
    {
        if (! is_array($payload) || ($payload['timer_kind'] ?? null) !== null) {
            return false;
        }

        foreach ([
            'cancellation_cleanup',
            'cancellation_scope_id',
            'parallel_group_id',
            'condition_wait_id',
            'signal_wait_id',
        ] as $key) {
            if (($payload[$key] ?? null) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     *
     * @return array{event_type: HistoryEventType, payload: array<string, mixed>, recorded_at: string|null, scheduled_payload: array<string, mixed>}|null
     */
    private static function firedTimerStep(array $events): ?array
    {
        if (count($events) !== 2) {
            return null;
        }

        [$scheduled, $fired] = $events;

        if (
            $scheduled->event_type !== HistoryEventType::TimerScheduled
            || $fired->event_type !== HistoryEventType::TimerFired
            || ! is_string($scheduled->payload['timer_id'] ?? null)
            || ($scheduled->payload['timer_id'] ?? null) !== ($fired->payload['timer_id'] ?? null)
        ) {
            return null;
        }

        $scheduledPayload = $scheduled->payload;
        $firedPayload = $fired->payload;
        // The task and command are the source run's recording context, and
        // the successor gets its own timer identity.
        unset(
            $scheduledPayload['task'],
            $scheduledPayload['command'],
            $scheduledPayload['timer_id'],
            $firedPayload['task'],
            $firedPayload['command'],
            $firedPayload['timer_id'],
        );

        return [
            'event_type' => HistoryEventType::TimerFired,
            'payload' => $firedPayload,
            'recorded_at' => $fired->recorded_at?->toIso8601String(),
            'scheduled_payload' => $scheduledPayload,
        ];
    }

    /**
     * Group signal events into completed signal-wait steps keyed by step
     * sequence. Only a plain wait (no timeout, cancellation scope or parallel
     * group) whose received signal was applied is reusable. A received signal
     * the source never applied is refused, because the successor would lose it.
     *
     * @param list<WorkflowHistoryEvent> $events
     *
     * @return array<int, array{event_type: HistoryEventType, payload: array<string, mixed>, recorded_at: string|null, signal_events: list<array{event_type: HistoryEventType, payload: array<string, mixed>, recorded_at: string|null}>}>|string
     */
    private static function signalSteps(array $events): array|string
    {
        $opened = [];
        $applied = [];
        $received = [];

        foreach ($events as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            $waitId = $payload['signal_wait_id'] ?? null;
            $name = $payload['signal_name'] ?? null;

            if ($event->event_type === HistoryEventType::SignalReceived) {
                $signalId = $payload['signal_id'] ?? null;

                if (! is_string($signalId) || $signalId === '' || isset($received[$signalId])) {
                    return 'unsupported_signal_wait';
                }

                $received[$signalId] = $event;

                continue;
            }

            $sequence = $payload['sequence'] ?? null;

            if (! is_int($sequence) || $sequence < 1 || ! is_string($waitId) || $waitId === '' || ! is_string($name)) {
                return 'invalid_step_sequence';
            }

            if (
                ($payload['timeout_seconds'] ?? null) !== null
                || ($payload['cancellation_scope_id'] ?? null) !== null
                || ($payload['parallel_group_id'] ?? null) !== null
            ) {
                return 'unsupported_signal_wait';
            }

            if ($event->event_type === HistoryEventType::SignalWaitOpened) {
                if (isset($opened[$sequence])) {
                    return 'duplicate_step_sequence';
                }

                $opened[$sequence] = $event;

                continue;
            }

            if (isset($applied[$sequence])) {
                return 'duplicate_step_sequence';
            }

            $applied[$sequence] = $event;
        }

        $steps = [];
        $appliedSignalIds = [];

        foreach ($opened as $sequence => $wait) {
            $application = $applied[$sequence] ?? null;
            $applicationPayload = $application?->payload;
            $signalId = is_array($applicationPayload) ? ($applicationPayload['signal_id'] ?? null) : null;
            $receipt = is_string($signalId) ? ($received[$signalId] ?? null) : null;

            if (
                ! $application instanceof WorkflowHistoryEvent
                || ! $receipt instanceof WorkflowHistoryEvent
                || $applicationPayload['signal_wait_id'] !== $wait->payload['signal_wait_id']
                || $applicationPayload['signal_name'] !== $wait->payload['signal_name']
                || ($receipt->payload['signal_name'] ?? null) !== $wait->payload['signal_name']
            ) {
                return 'incomplete_signal_step';
            }

            $appliedSignalIds[$signalId] = true;
            $signalEvents = [];

            foreach ([$wait, $receipt, $application] as $event) {
                $eventPayload = $event->payload;
                // The task and command are the source run's recording context.
                unset($eventPayload['task'], $eventPayload['command']);
                $signalEvents[] = [
                    'event_type' => $event->event_type,
                    'payload' => $eventPayload,
                    'recorded_at' => $event->recorded_at?->toIso8601String(),
                ];
            }

            $steps[$sequence] = [
                'event_type' => HistoryEventType::SignalApplied,
                'payload' => $signalEvents[2]['payload'],
                'recorded_at' => $signalEvents[2]['recorded_at'],
                'signal_events' => $signalEvents,
            ];
        }

        if (count($applied) !== count($opened)) {
            return 'incomplete_signal_step';
        }

        foreach (array_keys($received) as $signalId) {
            if (! isset($appliedSignalIds[$signalId])) {
                return 'unsupported_pending_signal';
            }
        }

        return $steps;
    }

    private static function isExternalResult(mixed $result): bool
    {
        return (is_array($result) && isset($result['external_storage']))
            || (is_string($result) && ExternalPayloads::isStoredReference($result));
    }

    /**
     * @return array{eligible: false, reason: string, resume_step_sequence: null, completed: [], reused_steps: []}
     */
    private static function reject(string $reason): array
    {
        return [
            'eligible' => false,
            'reason' => $reason,
            'resume_step_sequence' => null,
            'completed' => [],
            'reused_steps' => [],
        ];
    }
}
