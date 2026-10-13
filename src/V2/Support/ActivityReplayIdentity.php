<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Exceptions\ActivityRetryPolicyMismatchException;
use Workflow\V2\Models\WorkflowRun;

final class ActivityReplayIdentity
{
    /**
     * @param callable(int): int|null $historySequence
     */
    public static function assertCall(
        WorkflowRun $run,
        mixed $call,
        int $sequence,
        ?callable $historySequence = null,
    ): void {
        if ($call instanceof DurableOperationHandle) {
            self::assertCall($run, $call->call, $call->baseSequence);

            return;
        }

        if ($call instanceof AllCall) {
            foreach ($call->leafDescriptors($sequence) as $descriptor) {
                self::assertCall($run, $descriptor['call'], $sequence + $descriptor['offset'], $historySequence);
            }

            return;
        }

        if (! $call instanceof ActivityCall) {
            return;
        }

        $sequence = $historySequence === null ? $sequence : $historySequence($sequence);
        $run->loadMissing('historyEvents');
        foreach ($run->historyEvents as $event) {
            if ($event->event_type !== HistoryEventType::ActivityScheduled
                || ($event->payload['sequence'] ?? null) !== $sequence
                || ($event->payload['execution_mode'] ?? null) === LocalActivityRuntime::EXECUTION_MODE
                || ($event->payload['activity']['execution_mode'] ?? null) === LocalActivityRuntime::EXECUTION_MODE) {
                continue;
            }

            $snapshot = $event->payload['activity']['retry_policy'] ?? $event->payload['retry_policy'] ?? null;
            if ($snapshot === null) {
                // Legacy absence is not an invitation to invent today's class defaults.
                return;
            }
            if (! is_array($snapshot)) {
                throw new ActivityRetryPolicyMismatchException($sequence, 'retry_policy');
            }

            self::assertPolicy($snapshot, $call->options, $sequence);

            return;
        }
    }

    /**
     * Compare explicit authored overrides only; never instantiate an activity
     * or recompute its class-derived budget during replay.
     *
     * @param array<string, mixed> $snapshot
     */
    public static function assertPolicy(array $snapshot, ?ActivityOptions $options, int $sequence): void
    {
        $authored = [
            'max_attempts' => $options?->maxAttempts,
            'backoff_seconds' => $options?->backoff,
            'start_to_close_timeout' => $options?->startToCloseTimeout,
            'schedule_to_start_timeout' => $options?->scheduleToStartTimeout,
            'schedule_to_close_timeout' => $options?->scheduleToCloseTimeout,
            'heartbeat_timeout' => $options?->heartbeatTimeout,
            'non_retryable_error_types' => $options?->nonRetryableErrorTypes ?: null,
        ];

        foreach ($snapshot as $field => $recorded) {
            $valid = match ($field) {
                'snapshot_version' => $recorded === ActivityRetryPolicy::SNAPSHOT_VERSION,
                'max_attempts' => $recorded === null || (is_int($recorded) && $recorded > 0),
                'backoff_seconds' => is_array($recorded) && array_is_list($recorded)
                    && array_filter($recorded, static fn (mixed $value): bool => ! is_int($value) || $value < 0) === [],
                'non_retryable_error_types' => is_array($recorded) && array_is_list($recorded)
                    && array_filter(
                        $recorded,
                        static fn (mixed $value): bool => ! is_string($value) || trim($value) === ''
                    ) === [],
                'start_to_close_timeout', 'schedule_to_start_timeout', 'schedule_to_close_timeout', 'heartbeat_timeout'
                    => $recorded === null || (is_int($recorded) && $recorded >= 0),
                default => true,
            };
            if (! $valid) {
                throw new ActivityRetryPolicyMismatchException($sequence, $field);
            }

            $current = $authored[$field] ?? null;
            if ($current === null) {
                continue;
            }
            // Apply exactly the same normalization as initial scheduling.
            $normalized = ActivityRetryPolicy::snapshotExternal(null, $options);
            if ($recorded !== ($normalized[$field] ?? null)) {
                throw new ActivityRetryPolicyMismatchException($sequence, $field);
            }
        }
    }
}
