<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Carbon;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class SignalWaits
{
    public static function bufferedWaitIdForCommandId(string $commandId): string
    {
        return sprintf('signal-command:%s', $commandId);
    }

    /**
     * @return list<array{
     *     id: string,
     *     signal_wait_id: string,
     *     signal_id: string|null,
     *     signal_name: string,
     *     sequence: int|null,
     *     status: string,
     *     source_status: string,
     *     opened_at: \Carbon\CarbonInterface|null,
     *     deadline_at: \Carbon\CarbonInterface|null,
     *     resolved_at: \Carbon\CarbonInterface|null,
     *     timeout_fired_at: \Carbon\CarbonInterface|null,
     *     timeout_seconds: int|null,
     *     timer_id: string|null,
     *     command_id: string|null,
     *     command_sequence: int|null,
     *     command_status: string|null,
     *     command_outcome: string|null
     * }>
     *
     * A cooperative cancellation delivered at a signal wait, or at a group
     * containing one, ends that wait: workflow code was interrupted there and
     * can never resume it with a signal. A workflow timeout closes open waits
     * like every other terminal event. Signals accepted afterwards therefore
     * do not resolve, and are not attributed to, the interrupted wait.
     *
     * Pass $replayCompatible to read the states replay decisions have always
     * read, in which neither a delivered cancellation nor a workflow timeout
     * closes a wait. Execution paths that derive replay cursors or
     * history-writing decisions from these states use it, so that a history
     * recorded before these closures existed replays unchanged.
     */
    public static function forRun(WorkflowRun $run, bool $replayCompatible = false): array
    {
        $run->loadMissing('historyEvents');

        $waits = [];
        $openWaitIdsByName = [];

        foreach ($run->historyEvents->sortBy('sequence') as $event) {
            if ($event instanceof WorkflowHistoryEvent) {
                self::applyEvent($waits, $openWaitIdsByName, $event, $replayCompatible);
            }
        }

        return array_values($waits);
    }

    public static function openWaitIdForName(
        WorkflowRun $run,
        string $signalName,
        bool $replayCompatible = false,
    ): ?string {
        $openWaits = array_values(array_filter(
            self::forRun($run, $replayCompatible),
            static fn (array $wait): bool => $wait['signal_name'] === $signalName && $wait['status'] === 'open',
        ));

        if ($openWaits === []) {
            return null;
        }

        usort($openWaits, static function (array $left, array $right): int {
            $leftSequence = $left['sequence'] ?? PHP_INT_MIN;
            $rightSequence = $right['sequence'] ?? PHP_INT_MIN;

            if ($leftSequence !== $rightSequence) {
                return $leftSequence <=> $rightSequence;
            }

            $leftOpenedAt = $left['opened_at']?->getTimestampMs() ?? PHP_INT_MIN;
            $rightOpenedAt = $right['opened_at']?->getTimestampMs() ?? PHP_INT_MIN;

            if ($leftOpenedAt !== $rightOpenedAt) {
                return $leftOpenedAt <=> $rightOpenedAt;
            }

            return $left['signal_wait_id'] <=> $right['signal_wait_id'];
        });

        return end($openWaits)['signal_wait_id'] ?? null;
    }

    /**
     * @param array<string, array<string, mixed>> $waits
     * @param array<string, list<string>> $openWaitIdsByName
     */
    private static function closeCancelledSelectionWaits(
        array &$waits,
        array &$openWaitIdsByName,
        WorkflowHistoryEvent $event,
    ): void {
        $baseSequence = self::intValue($event->payload['member_base_sequence'] ?? null);
        $memberSize = self::intValue($event->payload['member_size'] ?? null);

        if ($baseSequence === null || $memberSize === null || $memberSize < 1) {
            return;
        }

        foreach ($waits as $waitId => &$wait) {
            $sequence = self::intValue($wait['sequence'] ?? null);
            if ($sequence === null
                || $sequence < $baseSequence
                || $sequence >= $baseSequence + $memberSize
                || ($wait['status'] ?? null) !== 'open') {
                continue;
            }

            $wait['status'] = 'cancelled';
            $wait['source_status'] = 'selection_cancelled';
            $wait['resolved_at'] = $event->recorded_at ?? $event->created_at;

            $signalName = self::stringValue($wait['signal_name'] ?? null);
            if ($signalName === null || ! isset($openWaitIdsByName[$signalName])) {
                continue;
            }

            $index = array_search($waitId, $openWaitIdsByName[$signalName], true);
            if ($index !== false) {
                array_splice($openWaitIdsByName[$signalName], $index, 1);
            }
        }
        unset($wait);
    }

    /**
     * @param array<string, array<string, mixed>> $waits
     * @param array<string, list<string>> $openWaitIdsByName
     */
    private static function applyEvent(
        array &$waits,
        array &$openWaitIdsByName,
        WorkflowHistoryEvent $event,
        bool $replayCompatible,
    ): void {
        switch ($event->event_type) {
            case HistoryEventType::SignalWaitOpened:
                self::openWait($waits, $openWaitIdsByName, $event);
                break;
            case HistoryEventType::TimerScheduled:
            case HistoryEventType::TimerFired:
            case HistoryEventType::TimerCancelled:
                self::applySignalTimeoutEvent($waits, $openWaitIdsByName, $event);
                break;
            case HistoryEventType::SignalWaitCancelled:
                self::closeScopeCancelledWait($waits, $openWaitIdsByName, $event);
                break;
            case HistoryEventType::SelectionOperationCancelled:
                self::closeCancelledSelectionWaits($waits, $openWaitIdsByName, $event);
                break;
            case HistoryEventType::SignalReceived:
            case HistoryEventType::SignalApplied:
                self::applySignalEvent($waits, $openWaitIdsByName, $event);
                break;
            case HistoryEventType::WorkflowCompleted:
            case HistoryEventType::WorkflowFailed:
            case HistoryEventType::WorkflowCancelled:
            case HistoryEventType::WorkflowTerminated:
            case HistoryEventType::WorkflowContinuedAsNew:
                self::closeOpenWaits($waits, $openWaitIdsByName, $event);
                break;
            case HistoryEventType::CooperativeCancellationDelivered:
                if (! $replayCompatible) {
                    self::closeInterruptedWaits($waits, $openWaitIdsByName, $event);
                }
                break;
            case HistoryEventType::WorkflowTimedOut:
                if (! $replayCompatible) {
                    self::closeOpenWaits($waits, $openWaitIdsByName, $event);
                }
                break;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $waits
     * @param array<string, list<string>> $openWaitIdsByName
     */
    private static function openWait(array &$waits, array &$openWaitIdsByName, WorkflowHistoryEvent $event): void
    {
        $signalName = self::stringValue($event->payload['signal_name'] ?? null);
        $waitId = $signalName === null ? null : self::waitIdForOpenedEvent($event);

        if ($signalName === null || $waitId === null) {
            return;
        }

        $waits[$waitId] = [
            'id' => $waitId,
            'signal_wait_id' => $waitId,
            'signal_id' => null,
            'signal_name' => $signalName,
            'sequence' => self::intValue($event->payload['sequence'] ?? null),
            'status' => 'open',
            'source_status' => 'waiting',
            'opened_at' => $event->recorded_at ?? $event->created_at,
            'deadline_at' => null,
            'resolved_at' => null,
            'timeout_fired_at' => null,
            'timeout_seconds' => self::intValue($event->payload['timeout_seconds'] ?? null),
            'timer_id' => null,
            'command_id' => null,
            'command_sequence' => null,
            'command_status' => null,
            'command_outcome' => null,
        ];

        $openWaitIdsByName[$signalName] ??= [];
        $openWaitIdsByName[$signalName][] = $waitId;
    }

    /**
     * @param array<string, array<string, mixed>> $waits
     * @param array<string, list<string>> $openWaitIdsByName
     */
    private static function applySignalTimeoutEvent(
        array &$waits,
        array &$openWaitIdsByName,
        WorkflowHistoryEvent $event,
    ): void {
        $signalName = self::stringValue($event->payload['signal_name'] ?? null);

        if (self::stringValue($event->payload['timer_kind'] ?? null) !== 'signal_timeout' || $signalName === null) {
            return;
        }

        $explicitWaitId = self::stringValue($event->payload['signal_wait_id'] ?? null);
        $waitId = $event->event_type === HistoryEventType::TimerScheduled
            ? $explicitWaitId
            : self::consumeOpenWaitId($openWaitIdsByName, $signalName, $explicitWaitId);

        if ($waitId === null || ! isset($waits[$waitId])) {
            return;
        }

        $waits[$waitId]['timer_id'] = self::stringValue($event->payload['timer_id'] ?? null)
            ?? $waits[$waitId]['timer_id'];
        $waits[$waitId]['timeout_seconds'] = self::intValue($event->payload['delay_seconds'] ?? null)
            ?? self::intValue($event->payload['timeout_seconds'] ?? null)
            ?? $waits[$waitId]['timeout_seconds'];
        $waits[$waitId]['deadline_at'] = self::timestamp($event->payload['fire_at'] ?? null)
            ?? $waits[$waitId]['deadline_at'];

        if ($event->event_type === HistoryEventType::TimerScheduled
            || ($waits[$waitId]['status'] ?? null) !== 'open') {
            return;
        }

        $fired = $event->event_type === HistoryEventType::TimerFired;
        $waits[$waitId]['status'] = $fired ? 'resolved' : 'cancelled';
        $waits[$waitId]['source_status'] = $fired ? 'timed_out' : 'timeout_cancelled';
        $waits[$waitId]['resolved_at'] = $event->recorded_at ?? $event->created_at;
        $waits[$waitId]['timeout_fired_at'] = $fired
            ? self::timestamp($event->payload['fired_at'] ?? null) ?? ($event->recorded_at ?? $event->created_at)
            : $waits[$waitId]['timeout_fired_at'];
    }

    /**
     * @param array<string, array<string, mixed>> $waits
     * @param array<string, list<string>> $openWaitIdsByName
     */
    private static function closeScopeCancelledWait(
        array &$waits,
        array &$openWaitIdsByName,
        WorkflowHistoryEvent $event,
    ): void {
        $waitId = self::stringValue($event->payload['signal_wait_id'] ?? null);

        if ($waitId !== null && isset($waits[$waitId]) && $waits[$waitId]['status'] === 'open') {
            $waits[$waitId]['status'] = 'cancelled';
            $waits[$waitId]['source_status'] = 'scope_cancelled';
            $waits[$waitId]['resolved_at'] = $event->recorded_at ?? $event->created_at;
            self::consumeOpenWaitId($openWaitIdsByName, $waits[$waitId]['signal_name'], $waitId);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $waits
     * @param array<string, list<string>> $openWaitIdsByName
     */
    private static function applySignalEvent(
        array &$waits,
        array &$openWaitIdsByName,
        WorkflowHistoryEvent $event,
    ): void {
        $signalName = self::stringValue($event->payload['signal_name'] ?? null);

        if ($signalName === null) {
            return;
        }

        $waitId = self::consumeOpenWaitId(
            $openWaitIdsByName,
            $signalName,
            self::stringValue($event->payload['signal_wait_id'] ?? null),
        );

        if ($waitId === null || ! isset($waits[$waitId])) {
            return;
        }

        $applied = $event->event_type === HistoryEventType::SignalApplied;
        $currentStatus = $waits[$waitId]['status'] ?? null;
        $canApplyReceivedSignal = $applied
            && $currentStatus === 'resolved'
            && ($waits[$waitId]['source_status'] ?? null) === 'received';

        if ($currentStatus !== 'open' && ! $canApplyReceivedSignal) {
            return;
        }

        $waits[$waitId]['status'] = 'resolved';
        $waits[$waitId]['source_status'] = $applied ? 'applied' : 'received';
        $waits[$waitId]['signal_id'] = self::stringValue($event->payload['signal_id'] ?? null)
            ?? $waits[$waitId]['signal_id'];
        $waits[$waitId]['resolved_at'] = $event->recorded_at ?? $event->created_at;
        $waits[$waitId]['command_id'] = self::stringValue($event->workflow_command_id)
            ?? self::stringValue($event->payload['workflow_command_id'] ?? null)
            ?? self::stringValue(self::commandSnapshot($event)['id'] ?? null);
        $waits[$waitId]['command_sequence'] = self::commandSequence($event);
        $waits[$waitId]['command_status'] = self::commandStatus($event);
        $waits[$waitId]['command_outcome'] = self::commandOutcome($event);
    }

    /**
     * Close the open signal waits a delivered cooperative cancellation
     * interrupted: the wait at a signal delivery, or the member waits of a
     * delivered parallel or selection group. A delivery at a durable handle
     * await or a started child await leaves the operation itself awaitable,
     * so it closes nothing here.
     *
     * @param array<string, array<string, mixed>> $waits
     * @param array<string, list<string>> $openWaitIdsByName
     */
    private static function closeInterruptedWaits(
        array &$waits,
        array &$openWaitIdsByName,
        WorkflowHistoryEvent $event,
    ): void {
        $callKind = self::stringValue($event->payload['call_kind'] ?? null);
        $baseSequence = self::intValue($event->payload['sequence'] ?? null);
        $span = match ($callKind) {
            'signal' => 1,
            'parallel' => self::intValue($event->payload['sequence_span'] ?? null) ?? 1,
            default => null,
        };

        if ($baseSequence === null || $span === null || $span < 1) {
            return;
        }

        foreach ($waits as $waitId => $wait) {
            $sequence = self::intValue($wait['sequence'] ?? null);
            if ($sequence === null
                || $sequence < $baseSequence
                || $sequence - $baseSequence >= $span
                || ($wait['status'] ?? null) !== 'open') {
                continue;
            }

            $waits[$waitId]['status'] = 'cancelled';
            $waits[$waitId]['source_status'] = 'cancellation_delivered';
            $waits[$waitId]['resolved_at'] = $event->recorded_at ?? $event->created_at;
            self::consumeOpenWaitId($openWaitIdsByName, $wait['signal_name'], (string) $waitId);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $waits
     * @param array<string, list<string>> $openWaitIdsByName
     */
    private static function closeOpenWaits(
        array &$waits,
        array &$openWaitIdsByName,
        WorkflowHistoryEvent $event,
    ): void {
        $sourceStatus = match ($event->event_type) {
            HistoryEventType::WorkflowCancelled => 'cancelled',
            HistoryEventType::WorkflowTerminated => 'terminated',
            HistoryEventType::WorkflowContinuedAsNew => 'continued',
            default => 'closed',
        };

        foreach ($openWaitIdsByName as $signalName => $waitIds) {
            while ($waitIds !== []) {
                $waitId = array_shift($waitIds);

                if ($waitId === null || ! isset($waits[$waitId])) {
                    continue;
                }

                $waits[$waitId]['status'] = 'cancelled';
                $waits[$waitId]['source_status'] = $sourceStatus;
                $waits[$waitId]['resolved_at'] = $event->recorded_at ?? $event->created_at;
            }

            $openWaitIdsByName[$signalName] = [];
        }
    }

    /**
     * @param array<string, list<string>> $openWaitIdsByName
     */
    private static function consumeOpenWaitId(
        array &$openWaitIdsByName,
        string $signalName,
        ?string $explicitWaitId,
    ): ?string {
        $openWaitIdsByName[$signalName] ??= [];

        if ($explicitWaitId !== null) {
            $index = array_search($explicitWaitId, $openWaitIdsByName[$signalName], true);

            if ($index !== false) {
                array_splice($openWaitIdsByName[$signalName], $index, 1);
            }

            return $explicitWaitId;
        }

        $waitId = array_shift($openWaitIdsByName[$signalName]);

        return is_string($waitId) && $waitId !== ''
            ? $waitId
            : null;
    }

    private static function waitIdForOpenedEvent(WorkflowHistoryEvent $event): ?string
    {
        $waitId = self::stringValue($event->payload['signal_wait_id'] ?? null);

        if ($waitId !== null) {
            return $waitId;
        }

        $signalName = self::stringValue($event->payload['signal_name'] ?? null);
        $sequence = self::intValue($event->payload['sequence'] ?? null);

        if ($signalName === null || $sequence === null) {
            return null;
        }

        return sprintf('signal:%d:%s', $sequence, $signalName);
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

    /**
     * @return array<string, mixed>
     */
    private static function commandSnapshot(WorkflowHistoryEvent $event): array
    {
        $snapshot = $event->payload['command'] ?? null;

        return is_array($snapshot)
            ? $snapshot
            : [];
    }

    private static function commandSequence(WorkflowHistoryEvent $event): ?int
    {
        return self::intValue(self::commandSnapshot($event)['sequence'] ?? null);
    }

    private static function commandStatus(WorkflowHistoryEvent $event): ?string
    {
        $status = self::stringValue(self::commandSnapshot($event)['status'] ?? null);

        return match ($event->event_type) {
            HistoryEventType::SignalReceived,
            HistoryEventType::SignalApplied => 'accepted',
            default => $status,
        };
    }

    private static function commandOutcome(WorkflowHistoryEvent $event): ?string
    {
        $outcome = self::stringValue($event->payload['outcome'] ?? null)
            ?? self::stringValue(self::commandSnapshot($event)['outcome'] ?? null);

        return $outcome ?? match ($event->event_type) {
            HistoryEventType::SignalReceived,
            HistoryEventType::SignalApplied => 'signal_received',
            default => null,
        };
    }
}
