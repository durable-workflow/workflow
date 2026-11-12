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
     * `completed` lists only the reused activity completions. A completed
     * child workflow call is reused with its scheduled, started and completed
     * events; the child itself does not run again.
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
     *         signal_events?: list<array{event_type: HistoryEventType, payload: array<string, mixed>, recorded_at: string|null}>,
     *         child_events?: list<array{event_type: HistoryEventType, payload: array<string, mixed>, recorded_at: string|null}>
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
        $childEvents = [];
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

            if (in_array($event->event_type, [
                HistoryEventType::ChildWorkflowScheduled,
                HistoryEventType::ChildRunStarted,
                HistoryEventType::ChildRunCompleted,
                HistoryEventType::ChildRunFailed,
                HistoryEventType::ChildRunCancelled,
                HistoryEventType::ChildRunTerminated,
                HistoryEventType::ChildCancellationRequested,
                HistoryEventType::ChildCancellationResolved,
            ], true)) {
                $payload = $event->payload;
                $sequence = is_array($payload) ? ($payload['sequence'] ?? null) : null;

                if ($started === null || ! is_int($sequence) || $sequence < 1) {
                    return self::reject('invalid_step_sequence');
                }

                $childEvents[$sequence][] = $event;

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

        $provenance = self::provenance($run, $started);
        $signalSteps = self::signalSteps($signalEvents, $run, $provenance);

        if (is_string($signalSteps)) {
            return self::reject($signalSteps);
        }

        $updateBundles = self::updateBundles($updateEvents, $run, $boundary, $provenance);

        if (is_string($updateBundles)) {
            return self::reject($updateBundles);
        }

        $childSteps = [];

        foreach ($childEvents as $sequence => $events) {
            $childStep = self::completedChildStep($events, $run, $provenance);

            if (is_string($childStep)) {
                return self::reject($childStep);
            }

            $childSteps[$sequence] = $childStep;
        }

        foreach ([
            ...array_keys($markerEvents),
            ...array_keys($timerEvents),
            ...array_keys($signalSteps),
            ...array_keys($childSteps),
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

        foreach (array_keys($childSteps) as $sequence) {
            if (
                isset($activityEvents[$sequence])
                || isset($markerEvents[$sequence])
                || isset($timerEvents[$sequence])
                || isset($signalSteps[$sequence])
            ) {
                return self::reject('duplicate_step_sequence');
            }
        }

        if (count($activityEvents) + count($markerEvents) + count($timerEvents) + count($signalSteps) + count(
            $childSteps
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

            $childStep = $childSteps[$sequence] ?? null;

            if (is_array($childStep)) {
                $reusedSteps[] = $childStep;

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
                            'reused_recorded_at' => self::originalRecordedAt(
                                $updatePayload,
                                $updateEvent['recorded_at']
                            ),
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

            if (isset($step['child_events'])) {
                // A completed child call: the successor records the call and
                // its result. The child is not started again and the
                // successor gets no child link of its own.
                foreach ($step['child_events'] as $childEvent) {
                    $childPayload = $childEvent['payload'];
                    $events[] = [
                        'event_type' => $childEvent['event_type'],
                        'payload' => array_merge($childPayload, [
                            'reused_from_run_id' => $sourceRunId,
                            'reused_recorded_at' => self::originalRecordedAt($childPayload, $childEvent['recorded_at']),
                        ]),
                    ];
                }

                continue;
            }

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
                            'reused_recorded_at' => self::originalRecordedAt(
                                $signalPayload,
                                $signalEvent['recorded_at']
                            ),
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
     * Who may have recorded a reused-step payload in this run's history: the
     * run itself, or, for a step it reused, its immediate redrive source while
     * the payload keeps the earlier run of the same instance that recorded it.
     *
     * @return array{immediate_source: string|null, earlier_runs: array<string, true>}
     */
    private static function provenance(WorkflowRun $run, ?WorkflowHistoryEvent $started): array
    {
        $startedPayload = is_array($started?->payload) ? $started->payload : [];
        $immediateSource = ($startedPayload['recovery_kind'] ?? null) === 'redrive'
            ? ($startedPayload['continued_from_run_id'] ?? null)
            : null;
        $earlierRuns = [];

        if (is_string($immediateSource) && $immediateSource !== '') {
            foreach (WorkflowRun::query()
                ->where('workflow_instance_id', $run->workflow_instance_id)
                ->where('run_number', '<', $run->run_number)
                ->pluck('id') as $id) {
                $earlierRuns[(string) $id] = true;
            }
        } else {
            $immediateSource = null;
        }

        return [
            'immediate_source' => $immediateSource,
            'earlier_runs' => $earlierRuns,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{immediate_source: string|null, earlier_runs: array<string, true>} $provenance
     */
    private static function boundToRun(array $payload, WorkflowRun $run, array $provenance): bool
    {
        if (($payload['workflow_instance_id'] ?? null) !== $run->workflow_instance_id
            || ! self::hasReuseProvenance($payload, $provenance)) {
            return false;
        }

        if (! array_key_exists('reused_from_run_id', $payload)) {
            return ($payload['workflow_run_id'] ?? null) === $run->id;
        }

        $original = $payload['workflow_run_id'] ?? null;

        return is_string($original) && isset($provenance['earlier_runs'][$original]);
    }

    /**
     * The reuse keys come as a pair. Both absent: the event was recorded in
     * this run. Both present: this run reused it from its immediate redrive
     * source, keeping the time the event was first recorded.
     *
     * @param array<string, mixed> $payload
     * @param array{immediate_source: string|null, earlier_runs: array<string, true>} $provenance
     */
    private static function hasReuseProvenance(array $payload, array $provenance): bool
    {
        $hasSource = array_key_exists('reused_from_run_id', $payload);

        if ($hasSource !== array_key_exists('reused_recorded_at', $payload)) {
            return false;
        }

        if (! $hasSource) {
            return true;
        }

        $recordedAt = $payload['reused_recorded_at'];

        return $provenance['immediate_source'] !== null
            && $payload['reused_from_run_id'] === $provenance['immediate_source']
            && is_string($recordedAt)
            && trim($recordedAt) !== ''
            && strtotime($recordedAt) !== false;
    }

    /**
     * When the event was first recorded. Only called for events whose reuse
     * pair was validated, so a reused event always carries its original time.
     *
     * @param array<string, mixed> $payload
     */
    private static function originalRecordedAt(array $payload, ?string $recordedAt): ?string
    {
        return array_key_exists('reused_from_run_id', $payload)
            ? $payload['reused_recorded_at']
            : $recordedAt;
    }

    /**
     * Every event of one update names the same update, command and handler,
     * and was recorded by the same run with the same reuse provenance.
     *
     * @param list<WorkflowHistoryEvent> $events
     * @param array{immediate_source: string|null, earlier_runs: array<string, true>} $provenance
     */
    private static function sameUpdate(array $events, WorkflowRun $run, array $provenance): bool
    {
        $first = $events[0]->payload;

        foreach ($events as $event) {
            $payload = $event->payload;

            if (! is_array($payload)) {
                return false;
            }

            foreach (['update_id', 'workflow_command_id', 'update_name'] as $key) {
                if (! is_string($payload[$key] ?? null) || $payload[$key] === '' || $payload[$key] !== $first[$key]) {
                    return false;
                }
            }

            if (! self::boundToRun($payload, $run, $provenance)
                || ($payload['workflow_run_id'] ?? null) !== ($first['workflow_run_id'] ?? null)
                || ($payload['reused_from_run_id'] ?? null) !== ($first['reused_from_run_id'] ?? null)
                || (! array_key_exists('reused_from_run_id', $payload) && $event->recorded_at === null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Group update events into the update's exact lifecycle. Only three shapes
     * are accepted, in history order: a rejection alone; an acceptance closed
     * by the run's failure before it ran; or an acceptance, application and
     * successful completion, which the successor reuses at the step it ran
     * before. Anything else is refused, because replay would not reproduce it.
     *
     * @param list<WorkflowHistoryEvent> $events
     * @param array{immediate_source: string|null, earlier_runs: array<string, true>} $provenance
     *
     * @return list<array{sequence: int, events: list<array{event_type: HistoryEventType, payload: array<string, mixed>, recorded_at: string|null}>}>|string
     */
    private static function updateBundles(
        array $events,
        WorkflowRun $run,
        int $boundary,
        array $provenance
    ): array|string {
        $byUpdate = [];

        foreach ($events as $event) {
            $updateId = is_array($event->payload) ? ($event->payload['update_id'] ?? null) : null;

            if (! is_string($updateId) || $updateId === '') {
                return 'mismatched_update_history';
            }

            $byUpdate[$updateId][] = $event;
        }

        $bundles = [];

        foreach ($byUpdate as $updateEvents) {
            usort(
                $updateEvents,
                static fn (WorkflowHistoryEvent $left, WorkflowHistoryEvent $right): int => $left->sequence <=> $right->sequence
            );
            $shape = array_map(
                static fn (WorkflowHistoryEvent $event): string => $event->event_type->value,
                $updateEvents
            );

            if ($shape === [HistoryEventType::UpdateRejected->value]) {
                // Rejected before acceptance, so replay never sees it.
                if (! self::sameUpdate($updateEvents, $run, $provenance)) {
                    return 'mismatched_update_history';
                }

                continue;
            }

            if ($shape === [HistoryEventType::UpdateAccepted->value, HistoryEventType::UpdateCompleted->value]) {
                // Closed with the run before its handler ran: a terminal
                // failure with no result and no step.
                $closed = $updateEvents[1]->payload;

                if (
                    ! self::sameUpdate($updateEvents, $run, $provenance)
                    || ! is_string($closed['terminal_reason'] ?? null)
                    || trim($closed['terminal_reason']) === ''
                    || ! is_string($closed['failure_id'] ?? null)
                    || trim($closed['failure_id']) === ''
                    || array_key_exists('result', $closed)
                    || array_key_exists('sequence', $closed)
                ) {
                    return 'mismatched_update_history';
                }

                continue;
            }

            if ($shape === [HistoryEventType::UpdateAccepted->value]) {
                return 'unsupported_pending_update';
            }

            if ($shape !== [
                HistoryEventType::UpdateAccepted->value,
                HistoryEventType::UpdateApplied->value,
                HistoryEventType::UpdateCompleted->value,
            ]) {
                return 'mismatched_update_history';
            }

            [$accepted, $applied, $completed] = $updateEvents;
            $first = $accepted->payload;

            if (! self::sameUpdate($updateEvents, $run, $provenance)) {
                return 'mismatched_update_history';
            }

            // Replay runs the handler from the applied arguments, so they must
            // be exactly the accepted ones.
            if (
                ! array_key_exists('arguments', $first)
                || ! array_key_exists('arguments', $applied->payload)
                || $first['arguments'] !== $applied->payload['arguments']
            ) {
                return 'mismatched_update_history';
            }

            if (
                ! array_key_exists('result', $completed->payload)
                || array_key_exists('failure_id', $completed->payload)
                || array_key_exists('terminal_reason', $completed->payload)
            ) {
                return 'incomplete_update_step';
            }

            $sequence = $applied->payload['sequence'] ?? null;

            if (! is_int($sequence) || $sequence < 1 || $sequence > $boundary
                || ($completed->payload['sequence'] ?? null) !== $sequence) {
                return 'invalid_step_sequence';
            }

            foreach ([$first['arguments'], $completed->payload['result']] as $value) {
                if (self::holdsExternalPayload($value)) {
                    return 'external_update_payload_not_supported';
                }

                if (! self::decodesInline($value, $run)) {
                    return 'unavailable_update_payload';
                }
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
     * Any external-storage shape, including a key whose value is null.
     */
    private static function holdsExternalPayload(mixed $value): bool
    {
        return (is_array($value) && array_key_exists('external_storage', $value))
            || (is_string($value) && ExternalPayloads::isStoredReference($value));
    }

    /**
     * An inline payload the successor's replay can decode with the run's codec.
     */
    private static function decodesInline(mixed $value, WorkflowRun $run, ?string $codec = null): bool
    {
        $codec ??= is_string($run->payload_codec) && $run->payload_codec !== '' ? $run->payload_codec : 'avro';

        try {
            $blob = ExternalPayloads::payloadBlob($value, $codec, $run->namespace);

            if (! is_string($blob)) {
                return false;
            }

            Serializer::unserializeWithCodec($codec, $blob);

            return true;
        } catch (Throwable) {
            return false;
        }
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
    private static function signalSteps(array $events, WorkflowRun $run, array $provenance): array|string
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
                || ! self::boundToRun($receiptPayload, $run, $provenance)
            ) {
                return 'mismatched_signal_receipt';
            }

            // The wait and application carry no run id, so the reuse pair is
            // their provenance. All three events come from the same run.
            foreach ([$wait, $application, $receipt] as $event) {
                $eventPayload = is_array($event->payload) ? $event->payload : [];

                if (
                    ! self::hasReuseProvenance($eventPayload, $provenance)
                    || ($eventPayload['reused_from_run_id'] ?? null) !== ($receiptPayload['reused_from_run_id'] ?? null)
                    || (! array_key_exists('reused_from_run_id', $eventPayload) && $event->recorded_at === null)
                ) {
                    return 'mismatched_signal_receipt';
                }
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
                self::holdsExternalPayload($receiptPayload['arguments'] ?? null)
                || self::holdsExternalPayload($applicationPayload['value'])
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

    /**
     * A plain child call that completed before the failure: exactly one
     * scheduled, started and completed event, in that order, all naming the
     * same child call and child run. A child in a parallel group or a
     * cancellation scope, a retried or continued child, and a child that
     * failed, was cancelled or was terminated stay refused, because replay
     * would not reproduce what the source did with them.
     *
     * @param list<WorkflowHistoryEvent> $events
     * @param array{immediate_source: string|null, earlier_runs: array<string, true>} $provenance
     *
     * @return array{event_type: HistoryEventType, payload: array<string, mixed>, recorded_at: string|null, child_events: list<array{event_type: HistoryEventType, payload: array<string, mixed>, recorded_at: string|null}>}|string
     */
    private static function completedChildStep(array $events, WorkflowRun $run, array $provenance): array|string
    {
        $types = array_map(static fn (WorkflowHistoryEvent $event): HistoryEventType => $event->event_type, $events);

        foreach ($types as $type) {
            if (in_array($type, [
                HistoryEventType::ChildRunFailed,
                HistoryEventType::ChildRunCancelled,
                HistoryEventType::ChildRunTerminated,
            ], true)) {
                return 'unsupported_child_outcome';
            }
        }

        if (! in_array(HistoryEventType::ChildRunCompleted, $types, true)) {
            return in_array(HistoryEventType::ChildCancellationRequested, $types, true)
                || in_array(HistoryEventType::ChildCancellationResolved, $types, true)
                ? 'unsupported_child_workflow'
                : 'unsupported_open_child';
        }

        if ($types !== [
            HistoryEventType::ChildWorkflowScheduled,
            HistoryEventType::ChildRunStarted,
            HistoryEventType::ChildRunCompleted,
        ]) {
            return 'unsupported_child_workflow';
        }

        $first = is_array($events[0]->payload) ? $events[0]->payload : [];

        foreach ($events as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];

            foreach ([
                'child_workflow_instance_id',
                'child_workflow_run_id',
                'child_workflow_type',
                'workflow_link_id',
            ] as $key) {
                if (! is_string($payload[$key] ?? null) || $payload[$key] === '' || $payload[$key] !== $first[$key]) {
                    return 'unsupported_child_workflow';
                }
            }

            if (($payload['child_call_id'] ?? null) !== ($first['child_call_id'] ?? null)) {
                return 'unsupported_child_workflow';
            }

            foreach ([
                'cancellation_scope_id',
                'parallel_group_id',
                'parallel_group_path',
                'retry_attempt',
                'retry_of_child_workflow_run_id',
            ] as $key) {
                if (($payload[$key] ?? null) !== null) {
                    return 'unsupported_child_workflow';
                }
            }

            // All three events come from the same run, with the same reuse
            // provenance when an earlier redrive already reused them.
            if (
                ! self::hasReuseProvenance($payload, $provenance)
                || ($payload['reused_from_run_id'] ?? null) !== ($first['reused_from_run_id'] ?? null)
                || (! array_key_exists('reused_from_run_id', $payload) && $event->recorded_at === null)
            ) {
                return 'unsupported_child_workflow';
            }
        }

        $completed = is_array($events[2]->payload) ? $events[2]->payload : [];

        if (($completed['child_status'] ?? RunStatus::Completed->value) !== RunStatus::Completed->value) {
            return 'unsupported_child_outcome';
        }

        // Every replay path resumes the call with the recorded output, so it
        // must be recorded inline.
        if (! array_key_exists('output', $completed)) {
            return 'unavailable_child_result';
        }

        if (self::holdsExternalPayload($completed['output'])) {
            return 'external_child_result_not_supported';
        }

        $codec = is_string($completed['payload_codec'] ?? null) && $completed['payload_codec'] !== ''
            ? $completed['payload_codec']
            : null;

        if (! self::decodesInline($completed['output'], $run, $codec)) {
            return 'unavailable_child_result';
        }

        $childEvents = [];

        foreach ($events as $event) {
            $eventPayload = $event->payload;
            // The task and command are the source run's recording context.
            unset($eventPayload['task'], $eventPayload['command']);
            $childEvents[] = [
                'event_type' => $event->event_type,
                'payload' => $eventPayload,
                'recorded_at' => $event->recorded_at?->toIso8601String(),
            ];
        }

        return [
            'event_type' => HistoryEventType::ChildRunCompleted,
            'payload' => $childEvents[2]['payload'],
            'recorded_at' => $childEvents[2]['recorded_at'],
            'child_events' => $childEvents,
        ];
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
