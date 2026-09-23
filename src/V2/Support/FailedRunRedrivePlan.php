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
     * @return array{eligible: bool, reason: string|null, resume_step_sequence: int|null, completed: list<array<string, mixed>>}
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

        if (count($activityEvents) !== $boundary) {
            return self::reject('activity_sequence_gap');
        }

        $completed = [];

        for ($sequence = 1; $sequence <= $boundary; ++$sequence) {
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

            if (is_array($payload['result']) && isset($payload['result']['external_storage'])) {
                return self::reject('external_activity_result_not_supported');
            }

            if (is_string($payload['result']) && ExternalPayloads::isStoredReference($payload['result'])) {
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
        }

        return [
            'eligible' => true,
            'reason' => null,
            'resume_step_sequence' => $boundary,
            'completed' => $completed,
        ];
    }

    /**
     * @return array{eligible: false, reason: string, resume_step_sequence: null, completed: []}
     */
    private static function reject(string $reason): array
    {
        return [
            'eligible' => false,
            'reason' => $reason,
            'resume_step_sequence' => null,
            'completed' => [],
        ];
    }
}
