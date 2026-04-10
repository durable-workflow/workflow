<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowUpdate;

final class CommandLifecycleBackfill
{
    /**
     * @return array{
     *     type: 'signal'|'update',
     *     row_missing: bool,
     *     history_events_missing: int
     * }|null
     */
    public static function plan(WorkflowCommand $command): ?array
    {
        $command->loadMissing(['historyEvents', 'signalRecord', 'updateRecord']);

        return match ($command->command_type) {
            CommandType::Signal => self::signalPlan($command),
            CommandType::Update => self::updatePlan($command),
            default => null,
        };
    }

    /**
     * @return array{
     *     type: 'signal'|'update',
     *     row_missing: bool,
     *     history_events_missing: int
     * }|null
     */
    public static function backfill(WorkflowCommand $command): ?array
    {
        $command = WorkflowCommand::query()
            ->with(['historyEvents', 'signalRecord', 'updateRecord'])
            ->findOrFail($command->id);

        return match ($command->command_type) {
            CommandType::Signal => self::backfillSignal($command),
            CommandType::Update => self::backfillUpdate($command),
            default => null,
        };
    }

    /**
     * @return array{
     *     type: 'signal',
     *     row_missing: bool,
     *     history_events_missing: int
     * }
     */
    private static function signalPlan(WorkflowCommand $command): array
    {
        $events = self::signalEvents($command);
        $resolvedId = self::signalLifecycleId($command, $events);
        $resolvedWaitId = self::signalWaitId($command, $events);
        $missingEvents = 0;

        foreach ($events as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            $dirty = ($payload['signal_id'] ?? null) !== $resolvedId;

            if ($resolvedWaitId !== null && ($payload['signal_wait_id'] ?? null) !== $resolvedWaitId) {
                $dirty = true;
            }

            if ($dirty) {
                $missingEvents++;
            }
        }

        return [
            'type' => 'signal',
            'row_missing' => ! $command->relationLoaded('signalRecord') || ! ($command->signalRecord instanceof WorkflowSignal),
            'history_events_missing' => $missingEvents,
        ];
    }

    /**
     * @return array{
     *     type: 'update',
     *     row_missing: bool,
     *     history_events_missing: int
     * }
     */
    private static function updatePlan(WorkflowCommand $command): array
    {
        $events = self::updateEvents($command);
        $resolvedId = self::updateLifecycleId($command, $events);
        $missingEvents = 0;

        foreach ($events as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];

            if (($payload['update_id'] ?? null) !== $resolvedId) {
                $missingEvents++;
            }
        }

        return [
            'type' => 'update',
            'row_missing' => ! $command->relationLoaded('updateRecord') || ! ($command->updateRecord instanceof WorkflowUpdate),
            'history_events_missing' => $missingEvents,
        ];
    }

    /**
     * @return array{
     *     type: 'signal',
     *     row_missing: bool,
     *     history_events_missing: int
     * }
     */
    private static function backfillSignal(WorkflowCommand $command): array
    {
        $plan = self::signalPlan($command);
        $events = self::signalEvents($command);
        $signalId = self::signalLifecycleId($command, $events);
        $signalWaitId = self::signalWaitId($command, $events);

        if (! $command->signalRecord instanceof WorkflowSignal) {
            WorkflowSignal::query()->create([
                'id' => $signalId,
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $command->workflow_instance_id,
                'workflow_run_id' => $command->workflow_run_id,
                'target_scope' => $command->target_scope,
                'requested_workflow_run_id' => $command->requestedRunId(),
                'resolved_workflow_run_id' => $command->resolvedRunId(),
                'signal_name' => self::signalName($command, $events),
                'signal_wait_id' => $signalWaitId,
                'status' => self::signalStatus($command, $events),
                'outcome' => $command->outcome?->value,
                'command_sequence' => $command->command_sequence,
                'payload_codec' => $command->payload_codec,
                'arguments' => self::signalArguments($command),
                'validation_errors' => $command->validationErrors(),
                'rejection_reason' => $command->rejection_reason,
                'received_at' => $command->accepted_at,
                'applied_at' => self::signalAppliedAt($command, $events),
                'rejected_at' => $command->rejected_at,
                'closed_at' => self::signalClosedAt($command, $events),
                'created_at' => self::createdAt($command),
                'updated_at' => self::updatedAt($command, $events),
            ]);
        }

        foreach ($events as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            $dirty = false;

            if (($payload['signal_id'] ?? null) !== $signalId) {
                $payload['signal_id'] = $signalId;
                $dirty = true;
            }

            if ($signalWaitId !== null && ($payload['signal_wait_id'] ?? null) !== $signalWaitId) {
                $payload['signal_wait_id'] = $signalWaitId;
                $dirty = true;
            }

            if ($dirty) {
                $event->forceFill([
                    'payload' => $payload,
                ])->save();
            }
        }

        return $plan;
    }

    /**
     * @return array{
     *     type: 'update',
     *     row_missing: bool,
     *     history_events_missing: int
     * }
     */
    private static function backfillUpdate(WorkflowCommand $command): array
    {
        $plan = self::updatePlan($command);
        $events = self::updateEvents($command);
        $updateId = self::updateLifecycleId($command, $events);

        if (! $command->updateRecord instanceof WorkflowUpdate) {
            WorkflowUpdate::query()->create([
                'id' => $updateId,
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $command->workflow_instance_id,
                'workflow_run_id' => $command->workflow_run_id,
                'target_scope' => $command->target_scope,
                'requested_workflow_run_id' => $command->requestedRunId(),
                'resolved_workflow_run_id' => $command->resolvedRunId(),
                'update_name' => self::updateName($command, $events),
                'status' => self::updateStatus($command, $events),
                'outcome' => self::updateOutcome($command, $events),
                'command_sequence' => $command->command_sequence,
                'workflow_sequence' => self::updateWorkflowSequence($events),
                'payload_codec' => $command->payload_codec,
                'arguments' => self::updateArguments($command, $events),
                'result' => self::updateResult($events),
                'validation_errors' => self::updateValidationErrors($command, $events),
                'rejection_reason' => self::updateRejectionReason($command, $events),
                'failure_id' => self::updateFailureId($events),
                'failure_message' => self::updateFailureMessage($events),
                'accepted_at' => self::updateAcceptedAt($command, $events),
                'applied_at' => self::updateAppliedAt($command, $events),
                'rejected_at' => self::updateRejectedAt($command, $events),
                'closed_at' => self::updateClosedAt($command, $events),
                'created_at' => self::createdAt($command),
                'updated_at' => self::updatedAt($command, $events),
            ]);
        }

        foreach ($events as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];

            if (($payload['update_id'] ?? null) === $updateId) {
                continue;
            }

            $payload['update_id'] = $updateId;

            $event->forceFill([
                'payload' => $payload,
            ])->save();
        }

        return $plan;
    }

    /**
     * @return list<WorkflowHistoryEvent>
     */
    private static function signalEvents(WorkflowCommand $command): array
    {
        return $command->historyEvents
            ->filter(
                static fn (WorkflowHistoryEvent $event): bool => in_array(
                    $event->event_type,
                    [HistoryEventType::SignalReceived, HistoryEventType::SignalApplied],
                    true,
                )
            )
            ->sortBy('sequence')
            ->values()
            ->all();
    }

    /**
     * @return list<WorkflowHistoryEvent>
     */
    private static function updateEvents(WorkflowCommand $command): array
    {
        return $command->historyEvents
            ->filter(
                static fn (WorkflowHistoryEvent $event): bool => in_array(
                    $event->event_type,
                    [
                        HistoryEventType::UpdateAccepted,
                        HistoryEventType::UpdateRejected,
                        HistoryEventType::UpdateApplied,
                        HistoryEventType::UpdateCompleted,
                    ],
                    true,
                )
            )
            ->sortBy('sequence')
            ->values()
            ->all();
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function signalLifecycleId(WorkflowCommand $command, array $events): string
    {
        return self::resolveLifecycleId(
            $command->signalRecord?->id,
            array_map(
                static fn (WorkflowHistoryEvent $event): mixed => $event->payload['signal_id'] ?? null,
                $events,
            ),
        );
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function updateLifecycleId(WorkflowCommand $command, array $events): string
    {
        return self::resolveLifecycleId(
            $command->updateRecord?->id,
            array_map(
                static fn (WorkflowHistoryEvent $event): mixed => $event->payload['update_id'] ?? null,
                $events,
            ),
        );
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function signalWaitId(WorkflowCommand $command, array $events): ?string
    {
        $candidates = [];

        if ($command->signalRecord instanceof WorkflowSignal) {
            $candidates[] = $command->signalRecord->signal_wait_id;
        }

        foreach ($events as $event) {
            $candidates[] = $event->payload['signal_wait_id'] ?? null;
        }

        return self::resolveOptionalString($candidates);
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function signalName(WorkflowCommand $command, array $events): string
    {
        $candidates = [$command->targetName()];

        foreach ($events as $event) {
            $candidates[] = $event->payload['signal_name'] ?? null;
        }

        return self::requiredString($candidates, 'signal name');
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function signalStatus(WorkflowCommand $command, array $events): string
    {
        if ($command->status->value === 'rejected') {
            return SignalStatus::Rejected->value;
        }

        foreach ($events as $event) {
            if ($event->event_type === HistoryEventType::SignalApplied) {
                return SignalStatus::Applied->value;
            }
        }

        return SignalStatus::Received->value;
    }

    private static function signalArguments(WorkflowCommand $command): ?string
    {
        return $command->payload_codec === null
            ? null
            : Serializer::serialize($command->payloadArguments());
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function signalAppliedAt(WorkflowCommand $command, array $events): ?Carbon
    {
        foreach ($events as $event) {
            if ($event->event_type === HistoryEventType::SignalApplied) {
                return $event->recorded_at;
            }
        }

        return $command->applied_at;
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function signalClosedAt(WorkflowCommand $command, array $events): ?Carbon
    {
        if ($command->rejected_at instanceof Carbon) {
            return $command->rejected_at;
        }

        return self::signalAppliedAt($command, $events);
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function updateName(WorkflowCommand $command, array $events): string
    {
        $candidates = [$command->targetName()];

        foreach ($events as $event) {
            $candidates[] = $event->payload['update_name'] ?? null;
        }

        return self::requiredString($candidates, 'update name');
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function updateStatus(WorkflowCommand $command, array $events): string
    {
        $completed = self::findEvent($events, HistoryEventType::UpdateCompleted);
        $rejected = self::findEvent($events, HistoryEventType::UpdateRejected);

        if ($completed instanceof WorkflowHistoryEvent) {
            return self::updateFailureId($events) === null
                ? UpdateStatus::Completed->value
                : UpdateStatus::Failed->value;
        }

        if ($rejected instanceof WorkflowHistoryEvent || $command->status->value === 'rejected') {
            return UpdateStatus::Rejected->value;
        }

        return match ($command->outcome) {
            CommandOutcome::UpdateCompleted => UpdateStatus::Completed->value,
            CommandOutcome::UpdateFailed => UpdateStatus::Failed->value,
            default => UpdateStatus::Accepted->value,
        };
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function updateOutcome(WorkflowCommand $command, array $events): ?string
    {
        $completed = self::findEvent($events, HistoryEventType::UpdateCompleted);

        if ($completed instanceof WorkflowHistoryEvent) {
            return self::updateFailureId($events) === null
                ? CommandOutcome::UpdateCompleted->value
                : CommandOutcome::UpdateFailed->value;
        }

        return $command->outcome?->value;
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function updateWorkflowSequence(array $events): ?int
    {
        foreach ($events as $event) {
            $sequence = $event->payload['sequence'] ?? null;

            if (is_int($sequence)) {
                return $sequence;
            }
        }

        return null;
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function updateArguments(WorkflowCommand $command, array $events): ?string
    {
        foreach ($events as $event) {
            $arguments = $event->payload['arguments'] ?? null;

            if (is_string($arguments) && $arguments !== '') {
                return $arguments;
            }
        }

        return $command->payload_codec === null
            ? null
            : Serializer::serialize($command->payloadArguments());
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function updateResult(array $events): ?string
    {
        $completed = self::findEvent($events, HistoryEventType::UpdateCompleted);
        $result = $completed?->payload['result'] ?? null;

        return is_string($result) && $result !== ''
            ? $result
            : null;
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     * @return array<string, list<string>>
     */
    private static function updateValidationErrors(WorkflowCommand $command, array $events): array
    {
        $rejected = self::findEvent($events, HistoryEventType::UpdateRejected);
        $errors = $rejected?->payload['validation_errors'] ?? null;

        if (! is_array($errors)) {
            return $command->validationErrors();
        }

        $normalized = [];

        foreach ($errors as $field => $messages) {
            if (! is_string($field) || ! is_array($messages)) {
                continue;
            }

            $normalizedMessages = array_values(array_filter(
                $messages,
                static fn (mixed $message): bool => is_string($message) && $message !== '',
            ));

            if ($normalizedMessages === []) {
                continue;
            }

            $normalized[$field] = $normalizedMessages;
        }

        return $normalized;
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function updateRejectionReason(WorkflowCommand $command, array $events): ?string
    {
        $rejected = self::findEvent($events, HistoryEventType::UpdateRejected);
        $reason = $rejected?->payload['rejection_reason'] ?? null;

        return is_string($reason) && $reason !== ''
            ? $reason
            : $command->rejection_reason;
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function updateFailureId(array $events): ?string
    {
        $completed = self::findEvent($events, HistoryEventType::UpdateCompleted);
        $failureId = $completed?->payload['failure_id'] ?? null;

        return is_string($failureId) && $failureId !== ''
            ? $failureId
            : null;
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function updateFailureMessage(array $events): ?string
    {
        $completed = self::findEvent($events, HistoryEventType::UpdateCompleted);
        $message = $completed?->payload['message'] ?? null;

        if (is_string($message) && $message !== '') {
            return $message;
        }

        $failureId = self::updateFailureId($events);

        if ($failureId === null) {
            return null;
        }

        /** @var WorkflowFailure|null $failure */
        $failure = WorkflowFailure::query()->find($failureId);

        return $failure?->message;
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function updateAcceptedAt(WorkflowCommand $command, array $events): ?Carbon
    {
        $accepted = self::findEvent($events, HistoryEventType::UpdateAccepted);

        return $accepted?->recorded_at ?? $command->accepted_at;
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function updateAppliedAt(WorkflowCommand $command, array $events): ?Carbon
    {
        $applied = self::findEvent($events, HistoryEventType::UpdateApplied);
        $completed = self::findEvent($events, HistoryEventType::UpdateCompleted);

        return $applied?->recorded_at ?? $completed?->recorded_at ?? $command->applied_at;
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function updateRejectedAt(WorkflowCommand $command, array $events): ?Carbon
    {
        $rejected = self::findEvent($events, HistoryEventType::UpdateRejected);

        return $rejected?->recorded_at ?? $command->rejected_at;
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function updateClosedAt(WorkflowCommand $command, array $events): ?Carbon
    {
        $rejectedAt = self::updateRejectedAt($command, $events);

        if ($rejectedAt instanceof Carbon) {
            return $rejectedAt;
        }

        return self::updateAppliedAt($command, $events);
    }

    private static function createdAt(WorkflowCommand $command): Carbon
    {
        return $command->created_at ?? now();
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private static function updatedAt(WorkflowCommand $command, array $events): Carbon
    {
        $timestamps = [
            self::signalClosedAt($command, $events),
            self::updateClosedAt($command, $events),
            self::signalAppliedAt($command, $events),
            self::updateAppliedAt($command, $events),
            self::updateAcceptedAt($command, $events),
            $command->rejected_at,
            $command->accepted_at,
            $command->updated_at,
            $command->created_at,
        ];

        foreach ($timestamps as $timestamp) {
            if ($timestamp instanceof Carbon) {
                return $timestamp;
            }
        }

        return now();
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

    /**
     * @param list<mixed> $eventIds
     */
    private static function resolveLifecycleId(?string $rowId, array $eventIds): string
    {
        $candidates = [];

        if (is_string($rowId) && $rowId !== '') {
            $candidates[] = $rowId;
        }

        foreach ($eventIds as $eventId) {
            if (is_string($eventId) && $eventId !== '') {
                $candidates[] = $eventId;
            }
        }

        $candidates = array_values(array_unique($candidates));

        if (count($candidates) > 1) {
            throw new LogicException(sprintf(
                'Command lifecycle backfill found conflicting ids [%s].',
                implode(', ', $candidates),
            ));
        }

        return $candidates[0] ?? (string) Str::ulid();
    }

    /**
     * @param list<mixed> $values
     */
    private static function resolveOptionalString(array $values): ?string
    {
        $candidates = array_values(array_unique(array_filter(
            $values,
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        )));

        if (count($candidates) > 1) {
            throw new LogicException(sprintf(
                'Command lifecycle backfill found conflicting values [%s].',
                implode(', ', $candidates),
            ));
        }

        return $candidates[0] ?? null;
    }

    /**
     * @param list<mixed> $values
     */
    private static function requiredString(array $values, string $label): string
    {
        $resolved = self::resolveOptionalString($values);

        if ($resolved === null) {
            throw new LogicException(sprintf('Command lifecycle backfill could not resolve %s.', $label));
        }

        return $resolved;
    }
}
