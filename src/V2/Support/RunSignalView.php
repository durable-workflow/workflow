<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;

final class RunSignalView
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forRun(WorkflowRun $run): array
    {
        $run->loadMissing(['commands', 'historyEvents', 'signals.command']);

        $rows = [];
        $eventsByCommandId = $run->historyEvents
            ->filter(
                static fn (WorkflowHistoryEvent $event): bool => $event->workflow_command_id !== null
                    && in_array($event->event_type, [
                        HistoryEventType::SignalReceived,
                        HistoryEventType::SignalApplied,
                    ], true)
            )
            ->groupBy('workflow_command_id');
        $signalsByCommandId = $run->signals
            ->filter(static fn (WorkflowSignal $signal): bool => $signal->workflow_command_id !== null)
            ->keyBy('workflow_command_id');

        foreach ($run->signals as $signal) {
            if (! $signal instanceof WorkflowSignal) {
                continue;
            }

            $rows[] = self::rowFromSignal($signal);
        }

        foreach ($run->commands as $command) {
            if (! $command instanceof WorkflowCommand || $command->command_type->value !== 'signal') {
                continue;
            }

            if ($signalsByCommandId->has($command->id)) {
                continue;
            }

            $rows[] = self::rowFromCommandFallback($command, $eventsByCommandId->get($command->id));
        }

        usort($rows, static function (array $left, array $right): int {
            $leftSequence = is_int($left['command_sequence'] ?? null) ? $left['command_sequence'] : PHP_INT_MAX;
            $rightSequence = is_int($right['command_sequence'] ?? null) ? $right['command_sequence'] : PHP_INT_MAX;

            if ($leftSequence !== $rightSequence) {
                return $leftSequence <=> $rightSequence;
            }

            $leftReceived = is_string($left['received_at'] ?? null) ? $left['received_at'] : '';
            $rightReceived = is_string($right['received_at'] ?? null) ? $right['received_at'] : '';

            if ($leftReceived !== $rightReceived) {
                return $leftReceived <=> $rightReceived;
            }

            return (string) ($left['command_id'] ?? $left['id'] ?? '') <=> (string) ($right['command_id'] ?? $right['id'] ?? '');
        });

        return array_values($rows);
    }

    /**
     * @return array<string, mixed>
     */
    private static function rowFromSignal(WorkflowSignal $signal): array
    {
        $command = $signal->command;

        return [
            'id' => $signal->id,
            'command_id' => $signal->workflow_command_id,
            'command_sequence' => $signal->command_sequence,
            'workflow_sequence' => $signal->workflow_sequence,
            'name' => $signal->signal_name,
            'signal_wait_id' => $signal->signal_wait_id,
            'target_scope' => $signal->target_scope,
            'requested_run_id' => $signal->requested_workflow_run_id,
            'resolved_run_id' => $signal->resolved_workflow_run_id,
            'status' => $signal->status?->value ?? $signal->status,
            'outcome' => $signal->outcome?->value ?? $signal->outcome,
            'source' => $command?->source,
            'rejection_reason' => $signal->rejection_reason,
            'validation_errors' => $signal->normalizedValidationErrors(),
            'payload_codec' => $signal->payload_codec,
            'arguments_available' => is_string($signal->arguments),
            'arguments' => self::normalizeTypedValue($signal->arguments),
            'received_at' => self::timestamp($signal->received_at),
            'applied_at' => self::timestamp($signal->applied_at),
            'rejected_at' => self::timestamp($signal->rejected_at),
            'closed_at' => self::timestamp($signal->closed_at),
        ];
    }

    /**
     * @param iterable<int, WorkflowHistoryEvent>|null $events
     * @return array<string, mixed>
     */
    private static function rowFromCommandFallback(WorkflowCommand $command, iterable|null $events): array
    {
        $eventList = [];

        foreach ($events ?? [] as $event) {
            if ($event instanceof WorkflowHistoryEvent) {
                $eventList[] = $event;
            }
        }

        $received = self::findEvent($eventList, HistoryEventType::SignalReceived);
        $applied = self::findEvent($eventList, HistoryEventType::SignalApplied);

        return [
            'id' => null,
            'command_id' => $command->id,
            'command_sequence' => $command->command_sequence,
            'workflow_sequence' => self::workflowSequence($applied),
            'name' => self::signalName($command, $received, $applied),
            'signal_wait_id' => self::signalWaitId($received, $applied),
            'target_scope' => $command->target_scope,
            'requested_run_id' => $command->requestedRunId(),
            'resolved_run_id' => $command->resolvedRunId(),
            'status' => self::fallbackStatus($command, $applied),
            'outcome' => $command->outcome?->value,
            'source' => $command->source,
            'rejection_reason' => $command->rejection_reason,
            'validation_errors' => $command->validationErrors(),
            'payload_codec' => $command->payload_codec,
            'arguments_available' => true,
            'arguments' => $command->payloadArguments(),
            'received_at' => self::timestamp($command->accepted_at),
            'applied_at' => self::timestamp($command->applied_at),
            'rejected_at' => self::timestamp($command->rejected_at),
            'closed_at' => self::timestamp($command->rejected_at ?? $command->applied_at),
        ];
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function findEvent(array $events, HistoryEventType $type): ?WorkflowHistoryEvent
    {
        foreach ($events as $event) {
            if ($event->event_type === $type) {
                return $event;
            }
        }

        return null;
    }

    private static function signalName(
        WorkflowCommand $command,
        ?WorkflowHistoryEvent $received,
        ?WorkflowHistoryEvent $applied,
    ): ?string {
        foreach ([$received, $applied] as $event) {
            $name = $event?->payload['signal_name'] ?? null;

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return $command->targetName();
    }

    private static function signalWaitId(?WorkflowHistoryEvent $received, ?WorkflowHistoryEvent $applied): ?string
    {
        foreach ([$applied, $received] as $event) {
            $signalWaitId = $event?->payload['signal_wait_id'] ?? null;

            if (is_string($signalWaitId) && $signalWaitId !== '') {
                return $signalWaitId;
            }
        }

        return null;
    }

    private static function workflowSequence(?WorkflowHistoryEvent $applied): ?int
    {
        $sequence = $applied?->payload['sequence'] ?? null;

        return is_int($sequence) ? $sequence : null;
    }

    private static function fallbackStatus(WorkflowCommand $command, ?WorkflowHistoryEvent $applied): string
    {
        if ($command->status->value === 'rejected') {
            return SignalStatus::Rejected->value;
        }

        if ($applied !== null || $command->applied_at !== null) {
            return SignalStatus::Applied->value;
        }

        return SignalStatus::Received->value;
    }

    private static function normalizeTypedValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return Serializer::unserialize($value);
    }

    private static function timestamp(mixed $value): ?string
    {
        if ($value instanceof \Carbon\CarbonInterface) {
            return $value->toJSON();
        }

        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
