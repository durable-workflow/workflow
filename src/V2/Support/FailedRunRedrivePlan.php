<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Str;
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
     *     failed_step_kind?: string,
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
        $updateEvents = [];
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

            if (in_array($event->event_type, [
                HistoryEventType::UpdateAccepted,
                HistoryEventType::UpdateRejected,
                HistoryEventType::UpdateApplied,
                HistoryEventType::UpdateCompleted,
            ], true)) {
                if ($started === null) {
                    return self::reject('invalid_step_sequence');
                }

                $updateEvents[] = $event;

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

        $failedStepKind = is_array($failurePayload) ? ($failurePayload['failed_step_kind'] ?? null) : null;

        if (
            ! is_int($boundary)
            || $boundary < 1
            || ! in_array($failedStepKind, ['activity', 'workflow_code', 'structural_limit'], true)
        ) {
            return self::reject('unrecorded_failure_boundary');
        }

        // An escaped activity failure owns its step, which runs again. When
        // workflow code failed, the boundary is the step it never took.
        $failedActivity = $failedStepKind === 'activity';
        $expectedSteps = $failedActivity ? $boundary : $boundary - 1;

        if (! $failedActivity) {
            foreach (array_keys($activityEvents) as $sequence) {
                if ($sequence >= $boundary) {
                    return self::reject('invalid_failed_step');
                }
            }
        }

        $signalSteps = self::signalSteps($signalEvents, $run);

        if (is_string($signalSteps)) {
            return self::reject($signalSteps);
        }

        $updateBundles = self::updateBundles($updateEvents, $run, $boundary);

        if (is_string($updateBundles)) {
            return self::reject($updateBundles);
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

        if (count($activityEvents) + count($markerEvents) + count($timerEvents) + count(
            $signalSteps
        ) !== $expectedSteps) {
            return self::reject('activity_sequence_gap');
        }

        $completed = [];
        $reusedSteps = [];

        for ($sequence = 1; $sequence <= $expectedSteps; ++$sequence) {
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

        if ($failedActivity && (
            ! $lastActivityEvent instanceof WorkflowHistoryEvent
            || $lastActivityEvent->event_type !== HistoryEventType::ActivityFailed
            || ($lastActivityEvent->payload['sequence'] ?? null) !== $boundary
        )) {
            return self::reject('unrecorded_failure_boundary');
        }

        return [
            'eligible' => true,
            'reason' => null,
            'resume_step_sequence' => $boundary,
            'failed_step_kind' => $failedStepKind,
            'completed' => $completed,
            'reused_steps' => $reusedSteps,
            'update_bundles' => $updateBundles,
        ];
    }

    /**
     * The history events a redrive successor records for its reused steps, in
     * order. Redrive writes exactly these, and a definition check replays them.
     *
     * @param array{reused_steps: list<array{event_type: HistoryEventType, payload: array<string, mixed>, recorded_at: string|null, scheduled_payload?: array<string, mixed>, signal_events?: list<array{event_type: HistoryEventType, payload: array<string, mixed>, recorded_at: string|null}>}>} $plan
     *
     * @return list<array{event_type: HistoryEventType, payload: array<string, mixed>}>
     */
    public static function successorEvents(array $plan, string $sourceRunId): array
    {
        $events = [];
        $updateBundles = $plan['update_bundles'] ?? [];
        // An applied update runs before the step it is recorded at, so it is
        // written ahead of that step.
        $emitUpdatesThrough = static function (?int $sequence) use (&$updateBundles, &$events, $sourceRunId): void {
            while ($updateBundles !== [] && ($sequence === null || $updateBundles[0]['sequence'] <= $sequence)) {
                foreach (array_shift($updateBundles)['events'] as $updateEvent) {
                    $updatePayload = $updateEvent['payload'];
                    $events[] = [
                        'event_type' => $updateEvent['event_type'],
                        'payload' => array_merge($updatePayload, [
                            'reused_from_run_id' => $sourceRunId,
                            'reused_recorded_at' => is_string($updatePayload['reused_recorded_at'] ?? null)
                                ? $updatePayload['reused_recorded_at']
                                : $updateEvent['recorded_at'],
                        ]),
                    ];
                }
            }
        };

        foreach ($plan['reused_steps'] as $step) {
            $stepSequence = $step['payload']['sequence'] ?? null;
            $emitUpdatesThrough(is_int($stepSequence) ? $stepSequence : null);
            $reused = $step['payload'];
            // A step reused more than once keeps the time it first ran.
            $reusedRecordedAt = is_string($reused['reused_recorded_at'] ?? null)
                ? $reused['reused_recorded_at']
                : $step['recorded_at'];

            if (isset($step['signal_events'])) {
                // A completed signal wait: the successor records the wait, the
                // receipt and the application without a signal row or command
                // to deliver again.
                foreach ($step['signal_events'] as $signalEvent) {
                    $signalPayload = $signalEvent['payload'];
                    $events[] = [
                        'event_type' => $signalEvent['event_type'],
                        'payload' => array_merge($signalPayload, [
                            'reused_from_run_id' => $sourceRunId,
                            'reused_recorded_at' => is_string($signalPayload['reused_recorded_at'] ?? null)
                                ? $signalPayload['reused_recorded_at']
                                : $signalEvent['recorded_at'],
                        ]),
                    ];
                }

                continue;
            }

            if ($step['event_type'] === HistoryEventType::TimerFired) {
                // The timer already fired, so the successor records it fired
                // with no timer row or task to wait on.
                $timerId = (string) Str::ulid();
                $events[] = [
                    'event_type' => HistoryEventType::TimerScheduled,
                    'payload' => array_merge($step['scheduled_payload'] ?? [], [
                        'timer_id' => $timerId,
                    ]),
                ];
                $events[] = [
                    'event_type' => HistoryEventType::TimerFired,
                    'payload' => array_merge($reused, [
                        'timer_id' => $timerId,
                        'reused_from_run_id' => $sourceRunId,
                        'reused_recorded_at' => $reusedRecordedAt,
                    ]),
                ];

                continue;
            }

            if ($step['event_type'] !== HistoryEventType::ActivityCompleted) {
                $events[] = [
                    'event_type' => $step['event_type'],
                    'payload' => array_merge($reused, [
                        'reused_from_run_id' => $sourceRunId,
                        'reused_recorded_at' => $reusedRecordedAt,
                    ]),
                ];

                continue;
            }

            $events[] = [
                'event_type' => HistoryEventType::ActivityCompleted,
                'payload' => [
                    'activity_execution_id' => (string) Str::ulid(),
                    'activity_class' => $reused['activity_class'] ?? null,
                    'activity_type' => $reused['activity_type'],
                    'sequence' => $reused['sequence'],
                    'result' => $reused['result'],
                    'payload_codec' => $reused['payload_codec'],
                    'reused_from_run_id' => $sourceRunId,
                    'reused_activity_execution_id' => $reused['activity_execution_id'] ?? null,
                    'reused_recorded_at' => $reusedRecordedAt,
                ],
            ];
        }

        $emitUpdatesThrough(null);

        return $events;
    }

    /**
     * Group update events into applied updates keyed by the step they ran
     * before. A rejected update and one the failure closed before it ran are
     * not part of the replayed state. An update the source accepted but never
     * resolved is refused, because the successor would lose it.
     *
     * @param list<WorkflowHistoryEvent> $events
     *
     * @return list<array{sequence: int, events: list<array{event_type: HistoryEventType, payload: array<string, mixed>, recorded_at: string|null}>}>|string
     */
    private static function updateBundles(array $events, WorkflowRun $run, int $boundary): array|string
    {
        $byUpdate = [];
        $types = [
            HistoryEventType::UpdateAccepted->value => 'accepted',
            HistoryEventType::UpdateRejected->value => 'rejected',
            HistoryEventType::UpdateApplied->value => 'applied',
            HistoryEventType::UpdateCompleted->value => 'completed',
        ];

        foreach ($events as $event) {
            $updateId = is_array($event->payload) ? ($event->payload['update_id'] ?? null) : null;
            $kind = $types[$event->event_type->value];

            if (! is_string($updateId) || $updateId === '' || isset($byUpdate[$updateId][$kind])) {
                return 'mismatched_update_history';
            }

            $byUpdate[$updateId][$kind] = $event;
        }

        $bundles = [];

        foreach ($byUpdate as $update) {
            if (isset($update['rejected'])) {
                if (isset($update['applied']) || isset($update['completed'])) {
                    return 'mismatched_update_history';
                }

                continue;
            }

            if (! isset($update['applied'])) {
                // Closed with the run before its handler ran.
                if (isset($update['completed']) && isset($update['completed']->payload['terminal_reason'])) {
                    continue;
                }

                return 'unsupported_pending_update';
            }

            $accepted = $update['accepted'] ?? null;
            $applied = $update['applied'];
            $completed = $update['completed'] ?? null;

            if (
                ! $accepted instanceof WorkflowHistoryEvent
                || ! $completed instanceof WorkflowHistoryEvent
                || ! array_key_exists('result', $completed->payload)
                || isset($completed->payload['failure_id'])
            ) {
                return 'incomplete_update_step';
            }

            $sequence = $applied->payload['sequence'] ?? null;

            if (! is_int($sequence) || $sequence < 1 || $sequence > $boundary
                || ($completed->payload['sequence'] ?? null) !== $sequence) {
                return 'invalid_step_sequence';
            }

            foreach ([$accepted, $applied, $completed] as $event) {
                if (
                    ($event->payload['workflow_run_id'] ?? null) !== $run->id
                    || ($event->payload['workflow_instance_id'] ?? null) !== $run->workflow_instance_id
                    || ($event->payload['workflow_command_id'] ?? null) !== ($applied->payload['workflow_command_id'] ?? null)
                    || ($event->payload['update_name'] ?? null) !== ($applied->payload['update_name'] ?? null)
                ) {
                    return 'mismatched_update_history';
                }
            }

            if (
                self::isExternalResult($accepted->payload['arguments'] ?? null)
                || self::isExternalResult($applied->payload['arguments'] ?? null)
                || self::isExternalResult($completed->payload['result'])
            ) {
                return 'external_update_payload_not_supported';
            }

            $bundleEvents = [];
            foreach ([$accepted, $applied, $completed] as $event) {
                $eventPayload = $event->payload;
                // The task and command are the source run's recording context.
                unset($eventPayload['task'], $eventPayload['command']);
                $bundleEvents[] = [
                    'event_type' => $event->event_type,
                    'payload' => $eventPayload,
                    'recorded_at' => $event->recorded_at?->toIso8601String(),
                ];
            }

            $bundles[] = [
                'sequence' => $sequence,
                'order' => (int) $applied->sequence,
                'events' => $bundleEvents,
            ];
        }

        usort($bundles, static fn (array $left, array $right): int => [$left['sequence'], $left['order']]
            <=> [$right['sequence'], $right['order']]);

        return array_map(static fn (array $bundle): array => [
            'sequence' => $bundle['sequence'],
            'events' => $bundle['events'],
        ], $bundles);
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
     * The receipt must belong to the source run and instance, carry the same
     * command as the application, and name the same wait. A receipt recorded
     * before its wait opened has no wait id yet, which is the only allowed
     * difference. External signal payloads are refused, because the successor
     * gets no signal row or command that owns the stored reference.
     *
     * @param list<WorkflowHistoryEvent> $events
     *
     * @return array<int, array{event_type: HistoryEventType, payload: array<string, mixed>, recorded_at: string|null, signal_events: list<array{event_type: HistoryEventType, payload: array<string, mixed>, recorded_at: string|null}>}>|string
     */
    private static function signalSteps(array $events, WorkflowRun $run): array|string
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
            ) {
                return 'incomplete_signal_step';
            }

            $receiptPayload = is_array($receipt->payload) ? $receipt->payload : [];
            $receiptWaitId = $receiptPayload['signal_wait_id'] ?? null;
            $commandId = $applicationPayload['workflow_command_id'] ?? null;

            if (
                ($receiptPayload['signal_name'] ?? null) !== $wait->payload['signal_name']
                || ($receiptWaitId !== null && $receiptWaitId !== $wait->payload['signal_wait_id'])
                || ! is_string($commandId)
                || $commandId === ''
                || ($receiptPayload['workflow_command_id'] ?? null) !== $commandId
                || ($receiptPayload['workflow_run_id'] ?? null) !== $run->id
                || ($receiptPayload['workflow_instance_id'] ?? null) !== $run->workflow_instance_id
            ) {
                return 'mismatched_signal_receipt';
            }

            // One admitted signal is one workflow input: a receipt may satisfy
            // only one wait.
            if (isset($appliedSignalIds[$signalId])) {
                return 'mismatched_signal_receipt';
            }

            // Every replay path resumes the wait with the application's value,
            // so it must be recorded inline.
            if (! array_key_exists('value', $applicationPayload)) {
                return 'incomplete_signal_step';
            }

            if (
                self::isExternalResult($receiptPayload['arguments'] ?? null)
                || self::isExternalResult($applicationPayload['value'])
            ) {
                return 'external_signal_payload_not_supported';
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
