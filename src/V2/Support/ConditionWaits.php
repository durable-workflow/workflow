<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Carbon;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Exceptions\ConditionWaitDefinitionMismatchException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class ConditionWaits
{
    /**
     * @return list<array{
     *     id: string,
     *     condition_wait_id: string,
     *     condition_key: string|null,
     *     condition_definition_fingerprint: string|null,
     *     sequence: int|null,
     *     status: string,
     *     source_status: string,
     *     timeout_seconds: int|null,
     *     timer_id: string|null,
     *     opened_at: \Carbon\CarbonInterface|null,
     *     deadline_at: \Carbon\CarbonInterface|null,
     *     timeout_fired_at: \Carbon\CarbonInterface|null,
     *     resolved_at: \Carbon\CarbonInterface|null,
     *     resume_source_kind: string,
     *     resume_source_id: string|null
     * }>
     */
    public static function forRun(WorkflowRun $run): array
    {
        $run->loadMissing('historyEvents');

        $waits = [];
        $openWaitIds = [];

        foreach ($run->historyEvents->sortBy('sequence') as $event) {
            if (! $event instanceof WorkflowHistoryEvent) {
                continue;
            }

            if ($event->event_type === HistoryEventType::ConditionWaitOpened) {
                $waitId = self::waitIdForEvent($event);

                if ($waitId === null) {
                    continue;
                }

                $waits[$waitId] = self::openWait($waitId, $event);
                self::rememberOpenWait($openWaitIds, $waitId);

                continue;
            }

            if (
                $event->event_type === HistoryEventType::TimerScheduled
                && self::stringValue($event->payload['timer_kind'] ?? null) === 'condition_timeout'
            ) {
                $waitId = self::waitIdForEvent($event);

                if ($waitId === null) {
                    continue;
                }

                if (! isset($waits[$waitId])) {
                    $waits[$waitId] = self::wait($waitId, $event);
                    self::rememberOpenWait($openWaitIds, $waitId);
                }

                $timerId = self::stringValue($event->payload['timer_id'] ?? null);

                $waits[$waitId]['sequence'] = self::intValue($waits[$waitId]['sequence'] ?? null)
                    ?? self::intValue($event->payload['sequence'] ?? null);
                $waits[$waitId]['condition_key'] = self::stringValue($waits[$waitId]['condition_key'] ?? null)
                    ?? self::stringValue($event->payload['condition_key'] ?? null);
                $waits[$waitId]['condition_definition_fingerprint'] = self::stringValue(
                    $waits[$waitId]['condition_definition_fingerprint'] ?? null
                ) ?? self::stringValue($event->payload['condition_definition_fingerprint'] ?? null);
                $waits[$waitId]['timeout_seconds'] = self::intValue($waits[$waitId]['timeout_seconds'] ?? null)
                    ?? self::intValue($event->payload['delay_seconds'] ?? null);
                $waits[$waitId]['timer_id'] = $timerId ?? $waits[$waitId]['timer_id'];
                $waits[$waitId]['deadline_at'] = self::timestamp($event->payload['fire_at'] ?? null)
                    ?? $waits[$waitId]['deadline_at'];

                if ($waits[$waitId]['timer_id'] !== null) {
                    $waits[$waitId]['resume_source_kind'] = 'timer';
                    $waits[$waitId]['resume_source_id'] = $waits[$waitId]['timer_id'];
                }

                continue;
            }

            if (
                $event->event_type === HistoryEventType::TimerFired
                && self::stringValue($event->payload['timer_kind'] ?? null) === 'condition_timeout'
            ) {
                $waitId = self::waitIdForEvent($event);

                if ($waitId === null) {
                    continue;
                }

                if (! isset($waits[$waitId])) {
                    $waits[$waitId] = self::wait($waitId, $event);
                    self::rememberOpenWait($openWaitIds, $waitId);
                }

                $timerId = self::stringValue($event->payload['timer_id'] ?? null);

                $waits[$waitId]['source_status'] = 'timeout_fired';
                $waits[$waitId]['sequence'] = self::intValue($waits[$waitId]['sequence'] ?? null)
                    ?? self::intValue($event->payload['sequence'] ?? null);
                $waits[$waitId]['condition_key'] = self::stringValue($waits[$waitId]['condition_key'] ?? null)
                    ?? self::stringValue($event->payload['condition_key'] ?? null);
                $waits[$waitId]['condition_definition_fingerprint'] = self::stringValue(
                    $waits[$waitId]['condition_definition_fingerprint'] ?? null
                ) ?? self::stringValue($event->payload['condition_definition_fingerprint'] ?? null);
                $waits[$waitId]['timeout_seconds'] = self::intValue($waits[$waitId]['timeout_seconds'] ?? null)
                    ?? self::intValue($event->payload['delay_seconds'] ?? null);
                $waits[$waitId]['timer_id'] = $timerId ?? $waits[$waitId]['timer_id'];
                $waits[$waitId]['deadline_at'] = self::timestamp($event->payload['fire_at'] ?? null)
                    ?? $waits[$waitId]['deadline_at'];
                $waits[$waitId]['timeout_fired_at'] = self::timestamp($event->payload['fired_at'] ?? null)
                    ?? $event->recorded_at
                    ?? $event->created_at
                    ?? $waits[$waitId]['timeout_fired_at'];

                if ($waits[$waitId]['timer_id'] !== null) {
                    $waits[$waitId]['resume_source_kind'] = 'timer';
                    $waits[$waitId]['resume_source_id'] = $waits[$waitId]['timer_id'];
                }

                continue;
            }

            if (! in_array($event->event_type, [
                HistoryEventType::ConditionWaitSatisfied,
                HistoryEventType::ConditionWaitTimedOut,
            ], true)) {
                if (in_array($event->event_type, [
                    HistoryEventType::WorkflowCompleted,
                    HistoryEventType::WorkflowFailed,
                    HistoryEventType::WorkflowCancelled,
                    HistoryEventType::WorkflowTerminated,
                    HistoryEventType::WorkflowContinuedAsNew,
                ], true)) {
                    self::closeOpenWaits($waits, $openWaitIds, $event);
                }

                continue;
            }

            $waitId = self::waitIdForEvent($event);

            if ($waitId === null) {
                continue;
            }

            if (! isset($waits[$waitId])) {
                $waits[$waitId] = self::wait($waitId, $event);
            }

            $waits[$waitId]['status'] = 'resolved';
            $waits[$waitId]['source_status'] = $event->event_type === HistoryEventType::ConditionWaitTimedOut
                ? 'timed_out'
                : 'satisfied';
            $waits[$waitId]['sequence'] = self::intValue($waits[$waitId]['sequence'] ?? null)
                ?? self::intValue($event->payload['sequence'] ?? null);
            $waits[$waitId]['condition_key'] = self::stringValue($waits[$waitId]['condition_key'] ?? null)
                ?? self::stringValue($event->payload['condition_key'] ?? null);
            $waits[$waitId]['condition_definition_fingerprint'] = self::stringValue(
                $waits[$waitId]['condition_definition_fingerprint'] ?? null
            ) ?? self::stringValue($event->payload['condition_definition_fingerprint'] ?? null);
            $waits[$waitId]['timeout_seconds'] = self::intValue($event->payload['timeout_seconds'] ?? null)
                ?? $waits[$waitId]['timeout_seconds'];
            $waits[$waitId]['timer_id'] = self::stringValue($event->payload['timer_id'] ?? null)
                ?? $waits[$waitId]['timer_id'];
            $waits[$waitId]['resolved_at'] = $event->recorded_at ?? $event->created_at;
            $waits[$waitId]['resume_source_kind'] = $event->event_type === HistoryEventType::ConditionWaitTimedOut
                ? 'timer'
                : 'external_input';
            $waits[$waitId]['resume_source_id'] = $event->event_type === HistoryEventType::ConditionWaitTimedOut
                ? $waits[$waitId]['timer_id']
                : null;

            $index = array_search($waitId, $openWaitIds, true);

            if ($index !== false) {
                array_splice($openWaitIds, $index, 1);
            }
        }

        return array_values($waits);
    }

    public static function waitIdForSequence(WorkflowRun $run, int $sequence): ?string
    {
        foreach (self::forRun($run) as $wait) {
            if (($wait['sequence'] ?? null) === $sequence) {
                return $wait['condition_wait_id'];
            }
        }

        return null;
    }

    public static function assertReplayCompatible(
        WorkflowRun $run,
        int $sequence,
        AwaitCall|AwaitWithTimeoutCall $call,
    ): void {
        WorkflowStepHistory::assertCompatible($run, $sequence, WorkflowStepHistory::CONDITION_WAIT);

        $definition = self::conditionDefinitionForSequence($run, $sequence);

        if (! $definition['recorded']) {
            return;
        }

        if ($definition['condition_key'] !== $call->conditionKey) {
            throw new ConditionWaitDefinitionMismatchException(
                $sequence,
                $definition['condition_key'],
                $call->conditionKey,
                $definition['condition_definition_fingerprint'],
                $call->conditionDefinitionFingerprint,
            );
        }

        if (
            $definition['condition_definition_fingerprint'] !== null
            && $call->conditionDefinitionFingerprint !== null
            && $definition['condition_definition_fingerprint'] !== $call->conditionDefinitionFingerprint
        ) {
            throw new ConditionWaitDefinitionMismatchException(
                $sequence,
                $definition['condition_key'],
                $call->conditionKey,
                $definition['condition_definition_fingerprint'],
                $call->conditionDefinitionFingerprint,
            );
        }
    }

    public static function conditionKeyForSequence(WorkflowRun $run, int $sequence): ?string
    {
        return self::conditionDefinitionForSequence($run, $sequence)['condition_key'];
    }

    /**
     * @return array{
     *     id: string,
     *     condition_wait_id: string,
     *     condition_key: string|null,
     *     condition_definition_fingerprint: string|null,
     *     sequence: int|null,
     *     status: string,
     *     source_status: string,
     *     timeout_seconds: int|null,
     *     timer_id: string|null,
     *     opened_at: \Carbon\CarbonInterface|null,
     *     deadline_at: \Carbon\CarbonInterface|null,
     *     timeout_fired_at: \Carbon\CarbonInterface|null,
     *     resolved_at: \Carbon\CarbonInterface|null,
     *     resume_source_kind: string,
     *     resume_source_id: string|null
     * }
     */
    private static function openWait(string $waitId, WorkflowHistoryEvent $event): array
    {
        $wait = self::wait($waitId, $event);
        $wait['opened_at'] = $event->recorded_at ?? $event->created_at;

        if ($wait['timer_id'] !== null) {
            $wait['resume_source_kind'] = 'timer';
            $wait['resume_source_id'] = $wait['timer_id'];
        }

        return $wait;
    }

    /**
     * @return array{
     *     id: string,
     *     condition_wait_id: string,
     *     condition_key: string|null,
     *     condition_definition_fingerprint: string|null,
     *     sequence: int|null,
     *     status: string,
     *     source_status: string,
     *     timeout_seconds: int|null,
     *     timer_id: string|null,
     *     opened_at: \Carbon\CarbonInterface|null,
     *     deadline_at: \Carbon\CarbonInterface|null,
     *     timeout_fired_at: \Carbon\CarbonInterface|null,
     *     resolved_at: \Carbon\CarbonInterface|null,
     *     resume_source_kind: string,
     *     resume_source_id: string|null
     * }
     */
    private static function wait(string $waitId, WorkflowHistoryEvent $event): array
    {
        $timerId = self::stringValue($event->payload['timer_id'] ?? null);

        return [
            'id' => $waitId,
            'condition_wait_id' => $waitId,
            'condition_key' => self::stringValue($event->payload['condition_key'] ?? null),
            'condition_definition_fingerprint' => self::stringValue(
                $event->payload['condition_definition_fingerprint'] ?? null
            ),
            'sequence' => self::intValue($event->payload['sequence'] ?? null),
            'status' => 'open',
            'source_status' => 'waiting',
            'timeout_seconds' => self::intValue($event->payload['timeout_seconds'] ?? null)
                ?? self::intValue($event->payload['delay_seconds'] ?? null),
            'timer_id' => $timerId,
            'opened_at' => null,
            'deadline_at' => self::timestamp($event->payload['fire_at'] ?? null),
            'timeout_fired_at' => null,
            'resolved_at' => null,
            'resume_source_kind' => $timerId === null ? 'external_input' : 'timer',
            'resume_source_id' => $timerId,
        ];
    }

    /**
     * @return array{recorded: bool, condition_key: string|null, condition_definition_fingerprint: string|null}
     */
    private static function conditionDefinitionForSequence(WorkflowRun $run, int $sequence): array
    {
        $run->loadMissing('historyEvents');
        $recorded = false;
        $conditionKey = null;
        $conditionDefinitionFingerprint = null;

        foreach ($run->historyEvents->sortBy('sequence') as $event) {
            if (! $event instanceof WorkflowHistoryEvent) {
                continue;
            }

            if (! in_array($event->event_type, [
                HistoryEventType::ConditionWaitOpened,
                HistoryEventType::TimerScheduled,
                HistoryEventType::TimerFired,
                HistoryEventType::TimerCancelled,
                HistoryEventType::ConditionWaitSatisfied,
                HistoryEventType::ConditionWaitTimedOut,
            ], true)) {
                continue;
            }

            if (self::intValue($event->payload['sequence'] ?? null) !== $sequence) {
                continue;
            }

            if (
                in_array($event->event_type, [
                    HistoryEventType::TimerScheduled,
                    HistoryEventType::TimerFired,
                    HistoryEventType::TimerCancelled,
                ], true)
                && self::stringValue($event->payload['timer_kind'] ?? null) !== 'condition_timeout'
            ) {
                continue;
            }

            $recorded = true;
            $key = self::stringValue($event->payload['condition_key'] ?? null);

            if ($key !== null) {
                $conditionKey ??= $key;
            }

            $fingerprint = self::stringValue($event->payload['condition_definition_fingerprint'] ?? null);

            if ($fingerprint !== null) {
                $conditionDefinitionFingerprint ??= $fingerprint;
            }
        }

        return [
            'recorded' => $recorded,
            'condition_key' => $conditionKey,
            'condition_definition_fingerprint' => $conditionDefinitionFingerprint,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $waits
     * @param list<string> $openWaitIds
     */
    private static function closeOpenWaits(array &$waits, array &$openWaitIds, WorkflowHistoryEvent $event): void
    {
        $sourceStatus = match ($event->event_type) {
            HistoryEventType::WorkflowCancelled => 'cancelled',
            HistoryEventType::WorkflowTerminated => 'terminated',
            HistoryEventType::WorkflowContinuedAsNew => 'continued',
            default => 'closed',
        };

        while ($openWaitIds !== []) {
            $waitId = array_shift($openWaitIds);

            if ($waitId === null || ! isset($waits[$waitId])) {
                continue;
            }

            $waits[$waitId]['status'] = 'cancelled';
            $waits[$waitId]['source_status'] = $sourceStatus;
            $waits[$waitId]['resolved_at'] = $event->recorded_at ?? $event->created_at;
        }
    }

    /**
     * @param list<string> $openWaitIds
     */
    private static function rememberOpenWait(array &$openWaitIds, string $waitId): void
    {
        if (! in_array($waitId, $openWaitIds, true)) {
            $openWaitIds[] = $waitId;
        }
    }

    private static function waitIdForEvent(WorkflowHistoryEvent $event): ?string
    {
        $waitId = self::stringValue($event->payload['condition_wait_id'] ?? null);

        if ($waitId !== null) {
            return $waitId;
        }

        $sequence = self::intValue($event->payload['sequence'] ?? null);

        return $sequence === null
            ? null
            : sprintf('condition:%d', $sequence);
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
}
