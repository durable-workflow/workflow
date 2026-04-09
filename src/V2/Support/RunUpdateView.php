<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowUpdate;

final class RunUpdateView
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forRun(WorkflowRun $run): array
    {
        $run->loadMissing([
            'commands',
            'historyEvents',
            'updates.command',
            'updates.failure',
        ]);

        $rows = [];
        $eventsByCommandId = $run->historyEvents
            ->filter(
                static fn (WorkflowHistoryEvent $event): bool => $event->workflow_command_id !== null
                    && in_array($event->event_type, [
                        HistoryEventType::UpdateAccepted,
                        HistoryEventType::UpdateRejected,
                        HistoryEventType::UpdateApplied,
                        HistoryEventType::UpdateCompleted,
                    ], true)
            )
            ->groupBy('workflow_command_id');
        $updatesByCommandId = $run->updates
            ->filter(static fn (WorkflowUpdate $update): bool => $update->workflow_command_id !== null)
            ->keyBy('workflow_command_id');

        foreach ($run->updates as $update) {
            if (! $update instanceof WorkflowUpdate) {
                continue;
            }

            $rows[] = self::rowFromUpdate($update);
        }

        foreach ($run->commands as $command) {
            if (! $command instanceof WorkflowCommand || $command->command_type->value !== 'update') {
                continue;
            }

            if ($updatesByCommandId->has($command->id)) {
                continue;
            }

            $rows[] = self::rowFromCommandFallback(
                $command,
                $eventsByCommandId->get($command->id),
            );
        }

        usort($rows, static function (array $left, array $right): int {
            $leftSequence = is_int($left['command_sequence'] ?? null) ? $left['command_sequence'] : PHP_INT_MAX;
            $rightSequence = is_int($right['command_sequence'] ?? null) ? $right['command_sequence'] : PHP_INT_MAX;

            if ($leftSequence !== $rightSequence) {
                return $leftSequence <=> $rightSequence;
            }

            $leftAccepted = is_string($left['accepted_at'] ?? null) ? $left['accepted_at'] : '';
            $rightAccepted = is_string($right['accepted_at'] ?? null) ? $right['accepted_at'] : '';

            if ($leftAccepted !== $rightAccepted) {
                return $leftAccepted <=> $rightAccepted;
            }

            return (string) ($left['command_id'] ?? $left['id'] ?? '') <=> (string) ($right['command_id'] ?? $right['id'] ?? '');
        });

        return array_values($rows);
    }

    /**
     * @return array<string, mixed>
     */
    private static function rowFromUpdate(WorkflowUpdate $update): array
    {
        $command = $update->command;
        $failure = $update->failure;

        return [
            'id' => $update->id,
            'command_id' => $update->workflow_command_id,
            'command_sequence' => $update->command_sequence,
            'workflow_sequence' => $update->workflow_sequence,
            'name' => $update->update_name,
            'target_scope' => $update->target_scope,
            'requested_run_id' => $update->requested_workflow_run_id,
            'resolved_run_id' => $update->resolved_workflow_run_id,
            'status' => $update->status?->value ?? $update->status,
            'outcome' => $update->outcome?->value ?? $update->outcome,
            'source' => $command?->source,
            'rejection_reason' => $update->rejection_reason,
            'validation_errors' => $update->normalizedValidationErrors(),
            'payload_codec' => $update->payload_codec,
            'arguments_available' => is_string($update->arguments),
            'arguments' => self::normalizeSerializedValue($update->arguments),
            'result_available' => is_string($update->result),
            'result' => self::normalizeSerializedValue($update->result),
            'failure_id' => $update->failure_id,
            'failure_message' => $update->failure_message
                ?? ($failure instanceof WorkflowFailure ? $failure->message : null),
            'accepted_at' => self::timestamp($update->accepted_at),
            'applied_at' => self::timestamp($update->applied_at),
            'rejected_at' => self::timestamp($update->rejected_at),
            'closed_at' => self::timestamp($update->closed_at),
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

        $accepted = self::findEvent($eventList, HistoryEventType::UpdateAccepted);
        $rejected = self::findEvent($eventList, HistoryEventType::UpdateRejected);
        $completed = self::findEvent($eventList, HistoryEventType::UpdateCompleted);
        $workflowSequence = self::workflowSequence($accepted, $rejected, $completed);

        return [
            'id' => null,
            'command_id' => $command->id,
            'command_sequence' => $command->command_sequence,
            'workflow_sequence' => $workflowSequence,
            'name' => self::updateName($command, $accepted, $rejected, $completed),
            'target_scope' => $command->target_scope,
            'requested_run_id' => $command->requestedRunId(),
            'resolved_run_id' => $command->resolvedRunId(),
            'status' => self::fallbackStatus($command),
            'outcome' => $command->outcome?->value,
            'source' => $command->source,
            'rejection_reason' => $command->rejection_reason,
            'validation_errors' => $command->validationErrors(),
            'payload_codec' => $command->payload_codec,
            'arguments_available' => true,
            'arguments' => serialize($command->payloadArguments()),
            'result_available' => array_key_exists('result', (array) ($completed?->payload ?? [])),
            'result' => self::normalizeSerializedValue($completed?->payload['result'] ?? null),
            'failure_id' => is_string($completed?->payload['failure_id'] ?? null)
                ? $completed->payload['failure_id']
                : null,
            'failure_message' => is_string($completed?->payload['message'] ?? null)
                ? $completed->payload['message']
                : null,
            'accepted_at' => self::timestamp($command->accepted_at),
            'applied_at' => self::timestamp($command->applied_at),
            'rejected_at' => self::timestamp($command->rejected_at),
            'closed_at' => self::timestamp($rejected?->recorded_at ?? $completed?->recorded_at ?? $command->rejected_at ?? $command->applied_at),
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

    private static function updateName(
        WorkflowCommand $command,
        ?WorkflowHistoryEvent $accepted,
        ?WorkflowHistoryEvent $rejected,
        ?WorkflowHistoryEvent $completed,
    ): ?string {
        foreach ([$accepted, $rejected, $completed] as $event) {
            $name = $event?->payload['update_name'] ?? null;

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return $command->targetName();
    }

    private static function workflowSequence(
        ?WorkflowHistoryEvent $accepted,
        ?WorkflowHistoryEvent $rejected,
        ?WorkflowHistoryEvent $completed,
    ): ?int {
        foreach ([$accepted, $rejected, $completed] as $event) {
            $sequence = $event?->payload['sequence'] ?? null;

            if (is_int($sequence)) {
                return $sequence;
            }
        }

        return null;
    }

    private static function fallbackStatus(WorkflowCommand $command): string
    {
        if ($command->status->value === 'rejected') {
            return UpdateStatus::Rejected->value;
        }

        return match ($command->outcome) {
            CommandOutcome::UpdateCompleted => UpdateStatus::Completed->value,
            CommandOutcome::UpdateFailed => UpdateStatus::Failed->value,
            default => UpdateStatus::Accepted->value,
        };
    }

    private static function normalizeSerializedValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return serialize(Serializer::unserialize($value));
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
