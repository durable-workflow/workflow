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
        $run->loadMissing(['commands', 'historyEvents', 'updates.command', 'updates.failure']);

        $rows = [];
        $updateEvents = $run->historyEvents
            ->filter(
                static fn (WorkflowHistoryEvent $event): bool => in_array($event->event_type, [
                    HistoryEventType::UpdateAccepted,
                    HistoryEventType::UpdateRejected,
                    HistoryEventType::UpdateApplied,
                    HistoryEventType::UpdateCompleted,
                ], true)
            )
            ->sortBy('sequence')
            ->values();
        $eventsByCommandId = $updateEvents
            ->filter(static fn (WorkflowHistoryEvent $event): bool => self::commandIdForEvent($event) !== null)
            ->groupBy(static fn (WorkflowHistoryEvent $event): string => self::commandIdForEvent($event) ?? '');
        $eventsByUpdateId = $updateEvents
            ->filter(
                static fn (WorkflowHistoryEvent $event): bool => self::stringValue(
                    $event->payload['update_id'] ?? null
                ) !== null
            )
            ->groupBy(
                static fn (WorkflowHistoryEvent $event): string => self::stringValue(
                    $event->payload['update_id'] ?? null
                ) ?? ''
            );
        $failureSnapshots = FailureSnapshots::keyedForRun($run);
        $updatesByCommandId = $run->updates
            ->filter(static fn (WorkflowUpdate $update): bool => $update->workflow_command_id !== null)
            ->keyBy('workflow_command_id');
        $seenUpdateIds = [];

        foreach ($run->updates as $update) {
            if (! $update instanceof WorkflowUpdate) {
                continue;
            }

            $row = self::rowFromUpdate(
                $update,
                self::mergeEvents(
                    $eventsByUpdateId->get($update->id),
                    $update->workflow_command_id === null ? null : $eventsByCommandId->get(
                        $update->workflow_command_id
                    ),
                ),
                $failureSnapshots,
            );
            $rows[] = $row;
            $seenUpdateIds[$update->id] = true;
        }

        foreach ($run->commands as $command) {
            if (! $command instanceof WorkflowCommand || $command->command_type->value !== 'update') {
                continue;
            }

            if ($updatesByCommandId->has($command->id)) {
                continue;
            }

            $row = self::rowFromCommandFallback(
                $command,
                $eventsByCommandId->get($command->id),
                $failureSnapshots,
            );
            $rows[] = $row;

            $updateId = self::stringValue($row['id'] ?? null);

            if ($updateId !== null) {
                $seenUpdateIds[$updateId] = true;
            }
        }

        foreach ($eventsByUpdateId as $updateId => $events) {
            if (! is_string($updateId) || $updateId === '' || isset($seenUpdateIds[$updateId])) {
                continue;
            }

            $rows[] = self::rowFromHistoryFallback(self::mergeEvents($events), $failureSnapshots);
            $seenUpdateIds[$updateId] = true;
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
     * @param list<WorkflowHistoryEvent> $events
     * @param array<string, array<string, mixed>> $failureSnapshots
     * @return array<string, mixed>
     */
    private static function rowFromUpdate(WorkflowUpdate $update, array $events, array $failureSnapshots): array
    {
        $command = $update->command;
        $failure = $update->failure;
        $accepted = self::findEvent($events, HistoryEventType::UpdateAccepted);
        $rejected = self::findEvent($events, HistoryEventType::UpdateRejected);
        $applied = self::findEvent($events, HistoryEventType::UpdateApplied);
        $completed = self::findEvent($events, HistoryEventType::UpdateCompleted);
        $failureId = self::failureId($completed) ?? $update->failure_id;
        $failureSnapshot = is_string($failureId) ? ($failureSnapshots[$failureId] ?? null) : null;
        $result = self::resultPayload($completed, $update);
        $arguments = self::argumentsPayload($update, $accepted, $rejected, $applied, $completed);
        $commandSnapshot = self::commandSnapshot($accepted, $rejected, $applied, $completed);

        return [
            'id' => $update->id,
            'command_id' => $update->workflow_command_id,
            'command_sequence' => self::intValue($accepted?->payload['command_sequence'] ?? null)
                ?? self::intValue($commandSnapshot['sequence'] ?? null)
                ?? $update->command_sequence,
            'workflow_sequence' => self::workflowSequence($accepted, $rejected, $applied, $completed)
                ?? $update->workflow_sequence,
            'name' => self::updateName($command, $accepted, $rejected, $completed) ?? $update->update_name,
            'target_scope' => $update->target_scope
                ?? self::stringValue($commandSnapshot['target_scope'] ?? null),
            'requested_run_id' => $update->requested_workflow_run_id
                ?? self::stringValue($commandSnapshot['requested_run_id'] ?? null),
            'resolved_run_id' => $update->resolved_workflow_run_id
                ?? self::stringValue($commandSnapshot['resolved_run_id'] ?? null),
            'status' => self::eventBackedStatus($update, $command, $rejected, $completed),
            'outcome' => self::eventBackedOutcome($update, $command, $rejected, $completed),
            'source' => $command?->source ?? self::stringValue($commandSnapshot['source'] ?? null),
            'rejection_reason' => self::stringValue($rejected?->payload['rejection_reason'] ?? null)
                ?? $update->rejection_reason,
            'validation_errors' => self::validationErrors($rejected) ?: $update->normalizedValidationErrors(),
            'payload_codec' => $update->payload_codec,
            'arguments_available' => is_string($arguments),
            'arguments' => self::normalizeTypedValue($arguments),
            'result_available' => is_string($result) && $failureId === null,
            'result' => $failureId === null ? self::normalizeTypedValue($result) : null,
            'failure_id' => $failureId,
            'failure_message' => self::failureMessage($completed, $failureSnapshot)
                ?? $update->failure_message
                ?? ($failure instanceof WorkflowFailure ? $failure->message : null),
            'exception_type' => $failureSnapshot['exception_type'] ?? null,
            'exception_class' => $failureSnapshot['exception_class'] ?? null,
            'exception_resolved_class' => $failureSnapshot['exception_resolved_class'] ?? null,
            'exception_resolution_source' => $failureSnapshot['exception_resolution_source'] ?? null,
            'exception_resolution_error' => $failureSnapshot['exception_resolution_error'] ?? null,
            'exception_replay_blocked' => (bool) ($failureSnapshot['exception_replay_blocked'] ?? false),
            'accepted_at' => self::timestamp($accepted?->recorded_at ?? $update->accepted_at),
            'applied_at' => self::timestamp($applied?->recorded_at ?? $completed?->recorded_at ?? $update->applied_at),
            'rejected_at' => self::timestamp($rejected?->recorded_at ?? $update->rejected_at),
            'closed_at' => self::timestamp($completed?->recorded_at ?? $rejected?->recorded_at ?? $update->closed_at),
        ];
    }

    /**
     * @param iterable<int, WorkflowHistoryEvent>|null $events
     * @param array<string, array<string, mixed>> $failureSnapshots
     * @return array<string, mixed>
     */
    private static function rowFromCommandFallback(
        WorkflowCommand $command,
        iterable|null $events,
        array $failureSnapshots,
    ): array {
        $eventList = self::mergeEvents($events);

        $accepted = self::findEvent($eventList, HistoryEventType::UpdateAccepted);
        $rejected = self::findEvent($eventList, HistoryEventType::UpdateRejected);
        $completed = self::findEvent($eventList, HistoryEventType::UpdateCompleted);
        $workflowSequence = self::workflowSequence($accepted, $rejected, $completed);
        $failureId = self::failureId($completed);
        $failureSnapshot = is_string($failureId) ? ($failureSnapshots[$failureId] ?? null) : null;
        $result = self::resultPayload($completed, null);
        $updateId = self::updateIdForEvents($eventList);

        return [
            'id' => $updateId,
            'command_id' => $command->id,
            'command_sequence' => $command->command_sequence,
            'workflow_sequence' => $workflowSequence,
            'name' => self::updateName($command, $accepted, $rejected, $completed),
            'target_scope' => $command->target_scope,
            'requested_run_id' => $command->requestedRunId(),
            'resolved_run_id' => $command->resolvedRunId(),
            'status' => $completed instanceof WorkflowHistoryEvent
                ? ($failureId === null ? UpdateStatus::Completed->value : UpdateStatus::Failed->value)
                : ($rejected instanceof WorkflowHistoryEvent ? UpdateStatus::Rejected->value : self::fallbackStatus(
                    $command
                )),
            'outcome' => $completed instanceof WorkflowHistoryEvent
                ? ($failureId === null ? CommandOutcome::UpdateCompleted->value : CommandOutcome::UpdateFailed->value)
                : $command->outcome?->value,
            'source' => $command->source,
            'rejection_reason' => $command->rejection_reason,
            'validation_errors' => $command->validationErrors(),
            'payload_codec' => $command->payload_codec,
            'arguments_available' => true,
            'arguments' => $command->payloadArguments(),
            'result_available' => is_string($result) && $failureId === null,
            'result' => $failureId === null ? self::normalizeTypedValue($result) : null,
            'failure_id' => $failureId,
            'failure_message' => self::failureMessage($completed, $failureSnapshot),
            'exception_type' => $failureSnapshot['exception_type'] ?? null,
            'exception_class' => $failureSnapshot['exception_class'] ?? null,
            'exception_resolved_class' => $failureSnapshot['exception_resolved_class'] ?? null,
            'exception_resolution_source' => $failureSnapshot['exception_resolution_source'] ?? null,
            'exception_resolution_error' => $failureSnapshot['exception_resolution_error'] ?? null,
            'exception_replay_blocked' => (bool) ($failureSnapshot['exception_replay_blocked'] ?? false),
            'accepted_at' => self::timestamp($command->accepted_at),
            'applied_at' => self::timestamp($command->applied_at),
            'rejected_at' => self::timestamp($command->rejected_at),
            'closed_at' => self::timestamp(
                $rejected?->recorded_at ?? $completed?->recorded_at ?? $command->rejected_at ?? $command->applied_at
            ),
        ];
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     * @param array<string, array<string, mixed>> $failureSnapshots
     * @return array<string, mixed>
     */
    private static function rowFromHistoryFallback(array $events, array $failureSnapshots): array
    {
        $accepted = self::findEvent($events, HistoryEventType::UpdateAccepted);
        $rejected = self::findEvent($events, HistoryEventType::UpdateRejected);
        $applied = self::findEvent($events, HistoryEventType::UpdateApplied);
        $completed = self::findEvent($events, HistoryEventType::UpdateCompleted);
        $failureId = self::failureId($completed);
        $failureSnapshot = is_string($failureId) ? ($failureSnapshots[$failureId] ?? null) : null;
        $result = self::resultPayload($completed, null);
        $arguments = self::argumentsPayloadFromEvents($accepted, $rejected, $applied, $completed);
        $commandSnapshot = self::commandSnapshot($accepted, $rejected, $applied, $completed);

        return [
            'id' => self::updateIdForEvents($events),
            'command_id' => self::commandIdForEvents($events),
            'command_sequence' => self::intValue($accepted?->payload['command_sequence'] ?? null)
                ?? self::intValue($commandSnapshot['sequence'] ?? null),
            'workflow_sequence' => self::workflowSequence($accepted, $rejected, $applied, $completed),
            'name' => self::updateName(null, $accepted, $rejected, $completed),
            'target_scope' => self::stringValue($commandSnapshot['target_scope'] ?? null),
            'requested_run_id' => self::stringValue($commandSnapshot['requested_run_id'] ?? null),
            'resolved_run_id' => self::stringValue($commandSnapshot['resolved_run_id'] ?? null),
            'status' => $completed instanceof WorkflowHistoryEvent
                ? ($failureId === null ? UpdateStatus::Completed->value : UpdateStatus::Failed->value)
                : ($rejected instanceof WorkflowHistoryEvent ? UpdateStatus::Rejected->value : UpdateStatus::Accepted->value),
            'outcome' => $completed instanceof WorkflowHistoryEvent
                ? ($failureId === null ? CommandOutcome::UpdateCompleted->value : CommandOutcome::UpdateFailed->value)
                : ($rejected instanceof WorkflowHistoryEvent ? self::stringValue(
                    $commandSnapshot['outcome'] ?? null
                ) : null),
            'source' => self::stringValue($commandSnapshot['source'] ?? null),
            'rejection_reason' => self::stringValue($rejected?->payload['rejection_reason'] ?? null),
            'validation_errors' => self::validationErrors($rejected),
            'payload_codec' => self::stringValue($commandSnapshot['payload_codec'] ?? null),
            'arguments_available' => is_string($arguments),
            'arguments' => self::normalizeTypedValue($arguments),
            'result_available' => is_string($result) && $failureId === null,
            'result' => $failureId === null ? self::normalizeTypedValue($result) : null,
            'failure_id' => $failureId,
            'failure_message' => self::failureMessage($completed, $failureSnapshot),
            'exception_type' => $failureSnapshot['exception_type'] ?? null,
            'exception_class' => $failureSnapshot['exception_class'] ?? null,
            'exception_resolved_class' => $failureSnapshot['exception_resolved_class'] ?? null,
            'exception_resolution_source' => $failureSnapshot['exception_resolution_source'] ?? null,
            'exception_resolution_error' => $failureSnapshot['exception_resolution_error'] ?? null,
            'exception_replay_blocked' => (bool) ($failureSnapshot['exception_replay_blocked'] ?? false),
            'accepted_at' => self::timestamp($accepted?->recorded_at),
            'applied_at' => self::timestamp($applied?->recorded_at ?? $completed?->recorded_at),
            'rejected_at' => self::timestamp($rejected?->recorded_at),
            'closed_at' => self::timestamp($completed?->recorded_at ?? $rejected?->recorded_at),
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
        ?WorkflowCommand $command,
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

        return $command?->targetName();
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

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function updateIdForEvents(array $events): ?string
    {
        foreach ($events as $event) {
            $updateId = self::stringValue($event->payload['update_id'] ?? null);

            if ($updateId !== null) {
                return $updateId;
            }
        }

        return null;
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function commandIdForEvents(array $events): ?string
    {
        foreach ($events as $event) {
            $commandId = self::commandIdForEvent($event);

            if ($commandId !== null) {
                return $commandId;
            }
        }

        return null;
    }

    private static function commandIdForEvent(WorkflowHistoryEvent $event): ?string
    {
        return self::stringValue($event->workflow_command_id)
            ?? self::stringValue($event->payload['workflow_command_id'] ?? null)
            ?? self::stringValue($event->payload['command']['id'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function commandSnapshot(?WorkflowHistoryEvent ...$events): array
    {
        foreach ($events as $event) {
            if (! $event instanceof WorkflowHistoryEvent) {
                continue;
            }

            $command = $event->payload['command'] ?? null;

            if (is_array($command)) {
                return $command;
            }
        }

        return [];
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

    /**
     * @param iterable<int, WorkflowHistoryEvent>|null ...$groups
     * @return list<WorkflowHistoryEvent>
     */
    private static function mergeEvents(iterable|null ...$groups): array
    {
        $events = [];

        foreach ($groups as $group) {
            foreach ($group ?? [] as $event) {
                if (! $event instanceof WorkflowHistoryEvent) {
                    continue;
                }

                $events[$event->id] = $event;
            }
        }

        $events = array_values($events);

        usort($events, static function (WorkflowHistoryEvent $left, WorkflowHistoryEvent $right): int {
            if ($left->sequence !== $right->sequence) {
                return $left->sequence <=> $right->sequence;
            }

            return (string) $left->id <=> (string) $right->id;
        });

        return $events;
    }

    private static function eventBackedStatus(
        WorkflowUpdate $update,
        ?WorkflowCommand $command,
        ?WorkflowHistoryEvent $rejected,
        ?WorkflowHistoryEvent $completed,
    ): string {
        if ($completed instanceof WorkflowHistoryEvent) {
            return self::failureId($completed) === null
                ? UpdateStatus::Completed->value
                : UpdateStatus::Failed->value;
        }

        if ($rejected instanceof WorkflowHistoryEvent) {
            return UpdateStatus::Rejected->value;
        }

        if ($update->status instanceof UpdateStatus) {
            return $update->status->value;
        }

        if (is_string($update->status) && $update->status !== '') {
            return $update->status;
        }

        return $command instanceof WorkflowCommand
            ? self::fallbackStatus($command)
            : UpdateStatus::Accepted->value;
    }

    private static function eventBackedOutcome(
        WorkflowUpdate $update,
        ?WorkflowCommand $command,
        ?WorkflowHistoryEvent $rejected,
        ?WorkflowHistoryEvent $completed,
    ): ?string {
        if ($completed instanceof WorkflowHistoryEvent) {
            return self::failureId($completed) === null
                ? CommandOutcome::UpdateCompleted->value
                : CommandOutcome::UpdateFailed->value;
        }

        if ($rejected instanceof WorkflowHistoryEvent) {
            return $command?->outcome?->value
                ?? ($update->outcome instanceof CommandOutcome ? $update->outcome->value : null)
                ?? (is_string($update->outcome) ? $update->outcome : null);
        }

        if ($update->outcome instanceof CommandOutcome) {
            return $update->outcome->value;
        }

        if (is_string($update->outcome) && $update->outcome !== '') {
            return $update->outcome;
        }

        return $command?->outcome?->value;
    }

    private static function failureId(?WorkflowHistoryEvent $completed): ?string
    {
        return self::stringValue($completed?->payload['failure_id'] ?? null);
    }

    /**
     * @param array<string, mixed>|null $failureSnapshot
     */
    private static function failureMessage(?WorkflowHistoryEvent $completed, ?array $failureSnapshot): ?string
    {
        return self::stringValue($failureSnapshot['message'] ?? null)
            ?? self::stringValue($completed?->payload['message'] ?? null);
    }

    private static function resultPayload(?WorkflowHistoryEvent $completed, ?WorkflowUpdate $update): mixed
    {
        if (array_key_exists('result', (array) ($completed?->payload ?? []))) {
            return $completed?->payload['result'] ?? null;
        }

        return $update?->result;
    }

    private static function argumentsPayload(WorkflowUpdate $update, ?WorkflowHistoryEvent ...$events): mixed
    {
        foreach ($events as $event) {
            if (! $event instanceof WorkflowHistoryEvent) {
                continue;
            }

            if (array_key_exists('arguments', $event->payload)) {
                return $event->payload['arguments'];
            }
        }

        return $update->arguments;
    }

    private static function argumentsPayloadFromEvents(?WorkflowHistoryEvent ...$events): mixed
    {
        foreach ($events as $event) {
            if (! $event instanceof WorkflowHistoryEvent) {
                continue;
            }

            if (array_key_exists('arguments', $event->payload)) {
                return $event->payload['arguments'];
            }
        }

        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function validationErrors(?WorkflowHistoryEvent $event): array
    {
        $validationErrors = $event?->payload['validation_errors'] ?? null;

        if (! is_array($validationErrors)) {
            return [];
        }

        $normalized = [];

        foreach ($validationErrors as $field => $messages) {
            if (! is_string($field) || ! is_array($messages)) {
                continue;
            }

            $fieldMessages = array_values(array_filter(
                $messages,
                static fn (mixed $message): bool => is_string($message) && $message !== '',
            ));

            if ($fieldMessages !== []) {
                $normalized[$field] = $fieldMessages;
            }
        }

        return $normalized;
    }

    private static function intValue(mixed $value): ?int
    {
        return is_int($value)
            ? $value
            : null;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
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
