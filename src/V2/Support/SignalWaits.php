<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

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
     *     signal_name: string,
     *     sequence: int|null,
     *     status: string,
     *     source_status: string,
     *     opened_at: \Carbon\CarbonInterface|null,
     *     resolved_at: \Carbon\CarbonInterface|null,
     *     command_id: string|null
     * }>
     */
    public static function forRun(WorkflowRun $run): array
    {
        $run->loadMissing('historyEvents');

        $waits = [];
        $openWaitIdsByName = [];

        foreach ($run->historyEvents->sortBy('sequence') as $event) {
            if (! $event instanceof WorkflowHistoryEvent) {
                continue;
            }

            $signalName = self::stringValue($event->payload['signal_name'] ?? null);

            if ($event->event_type === HistoryEventType::SignalWaitOpened) {
                if ($signalName === null) {
                    continue;
                }

                $waitId = self::waitIdForOpenedEvent($event);

                if ($waitId === null) {
                    continue;
                }

                $waits[$waitId] = [
                    'id' => $waitId,
                    'signal_wait_id' => $waitId,
                    'signal_name' => $signalName,
                    'sequence' => self::intValue($event->payload['sequence'] ?? null),
                    'status' => 'open',
                    'source_status' => 'waiting',
                    'opened_at' => $event->recorded_at ?? $event->created_at,
                    'resolved_at' => null,
                    'command_id' => null,
                ];

                $openWaitIdsByName[$signalName] ??= [];
                $openWaitIdsByName[$signalName][] = $waitId;

                continue;
            }

            if (in_array($event->event_type, [
                HistoryEventType::SignalReceived,
                HistoryEventType::SignalApplied,
            ], true)) {
                if ($signalName === null) {
                    continue;
                }

                $waitId = self::consumeOpenWaitId(
                    $openWaitIdsByName,
                    $signalName,
                    self::stringValue($event->payload['signal_wait_id'] ?? null),
                );

                if ($waitId === null || ! isset($waits[$waitId])) {
                    continue;
                }

                $waits[$waitId]['status'] = 'resolved';
                $waits[$waitId]['source_status'] = $event->event_type === HistoryEventType::SignalApplied
                    ? 'applied'
                    : 'received';
                $waits[$waitId]['resolved_at'] = $event->recorded_at ?? $event->created_at;
                $waits[$waitId]['command_id'] = self::stringValue($event->workflow_command_id);

                continue;
            }

            if (in_array($event->event_type, [
                HistoryEventType::WorkflowCompleted,
                HistoryEventType::WorkflowFailed,
                HistoryEventType::WorkflowCancelled,
                HistoryEventType::WorkflowTerminated,
                HistoryEventType::WorkflowContinuedAsNew,
            ], true)) {
                self::closeOpenWaits($waits, $openWaitIdsByName, $event);
            }
        }

        return array_values($waits);
    }

    public static function openWaitIdForName(WorkflowRun $run, string $signalName): ?string
    {
        $openWaits = array_values(array_filter(
            self::forRun($run),
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
        return is_int($value)
            ? $value
            : null;
    }
}
