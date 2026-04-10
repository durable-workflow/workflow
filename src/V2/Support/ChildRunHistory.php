<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use RuntimeException;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;

final class ChildRunHistory
{
    /**
     * @return list<int>
     */
    public static function knownSequences(WorkflowRun $run): array
    {
        $run->loadMissing(['historyEvents', 'childLinks']);

        $sequences = array_merge(
            $run->historyEvents
                ->filter(
                    static fn (WorkflowHistoryEvent $event): bool => in_array(
                        $event->event_type,
                        array_merge(
                            [HistoryEventType::ChildWorkflowScheduled, HistoryEventType::ChildRunStarted],
                            self::resolutionEventTypes(),
                        ),
                        true,
                    ) && is_int($event->payload['sequence'] ?? null)
                )
                ->map(static fn (WorkflowHistoryEvent $event): int => $event->payload['sequence'])
                ->all(),
            $run->childLinks
                ->filter(
                    static fn (WorkflowLink $link): bool => $link->link_type === 'child_workflow'
                        && $link->sequence !== null
                )
                ->map(static fn (WorkflowLink $link): int => (int) $link->sequence)
                ->all(),
        );

        $sequences = array_values(array_unique($sequences));
        sort($sequences);

        return $sequences;
    }

    /**
     * @return list<HistoryEventType>
     */
    public static function resolutionEventTypes(): array
    {
        return [
            HistoryEventType::ChildRunCompleted,
            HistoryEventType::ChildRunFailed,
            HistoryEventType::ChildRunCancelled,
            HistoryEventType::ChildRunTerminated,
        ];
    }

    public static function scheduledEventForSequence(WorkflowRun $run, int $sequence): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::ChildWorkflowScheduled
                && ($event->payload['sequence'] ?? null) === $sequence
        );

        return $event;
    }

    public static function startedEventForSequence(WorkflowRun $run, int $sequence): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents
            ->filter(
                static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::ChildRunStarted
                    && ($event->payload['sequence'] ?? null) === $sequence
            )
            ->sortByDesc('sequence')
            ->first();

        return $event;
    }

    public static function resolutionEventForSequence(WorkflowRun $run, int $sequence): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => in_array(
                $event->event_type,
                self::resolutionEventTypes(),
                true
            )
                && ($event->payload['sequence'] ?? null) === $sequence
        );

        return $event;
    }

    public static function latestLinkForSequence(WorkflowRun $run, int $sequence): ?WorkflowLink
    {
        /** @var WorkflowLink|null $link */
        $link = $run->childLinks
            ->filter(
                static fn (WorkflowLink $link): bool => $link->link_type === 'child_workflow'
                    && $link->sequence === $sequence
            )
            ->sort(static function (WorkflowLink $left, WorkflowLink $right): int {
                $leftRunNumber = $left->childRun?->run_number ?? 0;
                $rightRunNumber = $right->childRun?->run_number ?? 0;

                if ($leftRunNumber !== $rightRunNumber) {
                    return $rightRunNumber <=> $leftRunNumber;
                }

                $leftCreatedAt = $left->created_at?->getTimestampMs() ?? 0;
                $rightCreatedAt = $right->created_at?->getTimestampMs() ?? 0;

                if ($leftCreatedAt !== $rightCreatedAt) {
                    return $rightCreatedAt <=> $leftCreatedAt;
                }

                return $right->id <=> $left->id;
            })
            ->first();

        return $link;
    }

    public static function childRunForSequence(WorkflowRun $run, int $sequence): ?WorkflowRun
    {
        $resolutionEvent = self::resolutionEventForSequence($run, $sequence);
        $startedEvent = self::startedEventForSequence($run, $sequence);
        $scheduledEvent = self::scheduledEventForSequence($run, $sequence);
        $link = self::latestLinkForSequence($run, $sequence);

        $childRunId = self::stringValue($resolutionEvent?->payload['child_workflow_run_id'] ?? null)
            ?? self::stringValue($startedEvent?->payload['child_workflow_run_id'] ?? null)
            ?? self::stringValue($scheduledEvent?->payload['child_workflow_run_id'] ?? null)
            ?? $link?->child_workflow_run_id;
        $childInstanceId = self::stringValue($resolutionEvent?->payload['child_workflow_instance_id'] ?? null)
            ?? self::stringValue($startedEvent?->payload['child_workflow_instance_id'] ?? null)
            ?? self::stringValue($scheduledEvent?->payload['child_workflow_instance_id'] ?? null)
            ?? $link?->child_workflow_instance_id;

        $childRun = $childRunId === null
            ? $link?->childRun
            : self::loadRun($childRunId);

        if ($childRun instanceof WorkflowRun && ($startedEvent !== null || $resolutionEvent !== null)) {
            return $childRun;
        }

        if (! $childRun instanceof WorkflowRun && $childInstanceId !== null) {
            $childRun = self::loadCurrentRunForInstance($childInstanceId);
        }

        return self::followContinuedRun($childRun);
    }

    public static function parentHistoryBlocksResolutionWithoutEvent(WorkflowRun $run, int $sequence): bool
    {
        return self::resolutionEventForSequence($run, $sequence) === null
            && (
                self::scheduledEventForSequence($run, $sequence) !== null
                || self::startedEventForSequence($run, $sequence) !== null
            );
    }

    /**
     * @return array{
     *     sequence: int,
     *     scheduled_event: ?WorkflowHistoryEvent,
     *     started_event: ?WorkflowHistoryEvent,
     *     resolution_event: ?WorkflowHistoryEvent,
     *     link: ?WorkflowLink,
     *     child_run: ?WorkflowRun,
     *     child_call_id: ?string,
     *     label: string,
     *     target_name: ?string,
     *     resume_source_id: ?string,
     *     opened_at: ?\Carbon\CarbonInterface,
     *     resolved_at: ?\Carbon\CarbonInterface,
     *     status: string,
     *     source_status: ?string
     * }|null
     */
    public static function waitSnapshotForSequence(WorkflowRun $run, int $sequence): ?array
    {
        $scheduledEvent = self::scheduledEventForSequence($run, $sequence);
        $startedEvent = self::startedEventForSequence($run, $sequence);
        $resolutionEvent = self::resolutionEventForSequence($run, $sequence);
        $link = self::latestLinkForSequence($run, $sequence);
        $childRun = self::childRunForSequence($run, $sequence);

        if (
            ! $scheduledEvent instanceof WorkflowHistoryEvent
            && ! $startedEvent instanceof WorkflowHistoryEvent
            && ! $resolutionEvent instanceof WorkflowHistoryEvent
            && ! $link instanceof WorkflowLink
            && ! $childRun instanceof WorkflowRun
        ) {
            return null;
        }

        $childCallId = self::childCallIdForSequence($run, $sequence);
        $resolvedStatus = self::resolvedStatus($resolutionEvent, $childRun);
        $hasParentHistory = $scheduledEvent !== null || $startedEvent !== null || $resolutionEvent !== null;
        $label = self::stringValue($resolutionEvent?->payload['child_workflow_type'] ?? null)
            ?? self::stringValue($startedEvent?->payload['child_workflow_type'] ?? null)
            ?? self::stringValue($scheduledEvent?->payload['child_workflow_type'] ?? null)
            ?? $childRun?->workflow_type
            ?? self::stringValue($resolutionEvent?->payload['child_workflow_class'] ?? null)
            ?? self::stringValue($startedEvent?->payload['child_workflow_class'] ?? null)
            ?? self::stringValue($scheduledEvent?->payload['child_workflow_class'] ?? null)
            ?? $childRun?->workflow_class
            ?? 'child workflow';
        $sourceStatus = match (true) {
            $resolutionEvent !== null => $resolvedStatus?->value ?? $childRun?->status?->value,
            $hasParentHistory => RunStatus::Waiting->value,
            default => $childRun?->status?->value,
        };
        $status = match (true) {
            $resolutionEvent !== null => in_array(
                $resolvedStatus,
                [RunStatus::Cancelled, RunStatus::Terminated],
                true,
            )
                ? 'cancelled'
                : 'resolved',
            $hasParentHistory => 'open',
            in_array(
                $childRun?->status,
                [RunStatus::Pending, RunStatus::Running, RunStatus::Waiting],
                true,
            ) => 'open',
            in_array($childRun?->status, [RunStatus::Cancelled, RunStatus::Terminated], true) => 'cancelled',
            default => 'resolved',
        };

        return [
            'sequence' => $sequence,
            'scheduled_event' => $scheduledEvent,
            'started_event' => $startedEvent,
            'resolution_event' => $resolutionEvent,
            'link' => $link,
            'child_run' => $childRun,
            'child_call_id' => $childCallId,
            'label' => $label,
            'target_name' => $childRun?->workflow_instance_id
                ?? self::stringValue($resolutionEvent?->payload['child_workflow_instance_id'] ?? null)
                ?? self::stringValue($startedEvent?->payload['child_workflow_instance_id'] ?? null)
                ?? self::stringValue($scheduledEvent?->payload['child_workflow_instance_id'] ?? null)
                ?? $link?->child_workflow_instance_id,
            'resume_source_id' => $childRun?->id
                ?? self::stringValue($resolutionEvent?->payload['child_workflow_run_id'] ?? null)
                ?? self::stringValue($startedEvent?->payload['child_workflow_run_id'] ?? null)
                ?? self::stringValue($scheduledEvent?->payload['child_workflow_run_id'] ?? null)
                ?? $link?->child_workflow_run_id,
            'opened_at' => $scheduledEvent?->recorded_at
                ?? $scheduledEvent?->created_at
                ?? $startedEvent?->recorded_at
                ?? $startedEvent?->created_at
                ?? $link?->created_at,
            'resolved_at' => $resolutionEvent?->recorded_at
                ?? $resolutionEvent?->created_at
                ?? ($hasParentHistory ? null : $childRun?->closed_at),
            'status' => $status,
            'source_status' => $sourceStatus,
        ];
    }

    public static function childCallIdForSequence(WorkflowRun $run, int $sequence): ?string
    {
        $resolutionEvent = self::resolutionEventForSequence($run, $sequence);
        $startedEvent = self::startedEventForSequence($run, $sequence);
        $scheduledEvent = self::scheduledEventForSequence($run, $sequence);
        $link = self::latestLinkForSequence($run, $sequence);

        return self::childCallIdFromPayload(
            is_array($resolutionEvent?->payload) ? $resolutionEvent->payload : null
        ) ?? self::childCallIdFromPayload(
            is_array($startedEvent?->payload) ? $startedEvent->payload : null
        ) ?? self::childCallIdFromPayload(
            is_array($scheduledEvent?->payload) ? $scheduledEvent->payload : null
        ) ?? self::legacyChildCallIdFromParentEventPayload(
            is_array($scheduledEvent?->payload) ? $scheduledEvent->payload : null
        ) ?? self::legacyChildCallIdFromParentEventPayload(
            is_array($startedEvent?->payload) ? $startedEvent->payload : null
        ) ?? $link?->id;
    }

    public static function childCallIdForRun(WorkflowRun $run): ?string
    {
        $startedEvent = self::workflowStartedEvent($run);
        $payload = is_array($startedEvent?->payload) ? $startedEvent->payload : null;
        $parentRunId = self::stringValue($payload['parent_workflow_run_id'] ?? null);
        $parentSequence = self::intValue($payload['parent_sequence'] ?? null);
        $parentRun = $parentRunId === null ? null : self::loadRun($parentRunId);

        return self::childCallIdFromPayload($payload)
            ?? self::legacyChildCallIdFromStartedWorkflowPayload($payload)
            ?? (
                $parentRun instanceof WorkflowRun && $parentSequence !== null
                    ? self::childCallIdForSequence($parentRun, $parentSequence)
                    : null
            );
    }

    /**
     * @return array{
     *     parent_workflow_instance_id: string,
     *     parent_workflow_run_id: string,
     *     parent_sequence: int|null,
     *     child_call_id: string|null
     * }|null
     */
    public static function parentReferenceForRun(WorkflowRun $run): ?array
    {
        $startedEvent = self::workflowStartedEvent($run);
        $parentRunId = self::stringValue($startedEvent?->payload['parent_workflow_run_id'] ?? null);

        if ($parentRunId === null) {
            return null;
        }

        return [
            'parent_workflow_instance_id' => self::stringValue(
                $startedEvent?->payload['parent_workflow_instance_id'] ?? null
            ) ?? $run->workflow_instance_id,
            'parent_workflow_run_id' => $parentRunId,
            'parent_sequence' => self::intValue($startedEvent?->payload['parent_sequence'] ?? null),
            'child_call_id' => self::childCallIdForRun($run),
        ];
    }

    public static function continuedFromRunId(WorkflowRun $run): ?string
    {
        return self::stringValue(self::workflowStartedEvent($run)?->payload['continued_from_run_id'] ?? null);
    }

    public static function resolvedStatus(
        ?WorkflowHistoryEvent $resolutionEvent,
        ?WorkflowRun $childRun = null,
    ): ?RunStatus {
        $status = is_string($resolutionEvent?->payload['child_status'] ?? null)
            ? RunStatus::tryFrom($resolutionEvent->payload['child_status'])
            : null;

        if ($status instanceof RunStatus) {
            return $status;
        }

        $status = match ($resolutionEvent?->event_type) {
            HistoryEventType::ChildRunCompleted => RunStatus::Completed,
            HistoryEventType::ChildRunFailed => RunStatus::Failed,
            HistoryEventType::ChildRunCancelled => RunStatus::Cancelled,
            HistoryEventType::ChildRunTerminated => RunStatus::Terminated,
            default => null,
        };

        if ($status instanceof RunStatus) {
            return $status;
        }

        return match (self::terminalEventForRun($childRun)?->event_type) {
            HistoryEventType::WorkflowCompleted => RunStatus::Completed,
            HistoryEventType::WorkflowFailed => RunStatus::Failed,
            HistoryEventType::WorkflowCancelled => RunStatus::Cancelled,
            HistoryEventType::WorkflowTerminated => RunStatus::Terminated,
            default => $childRun?->status,
        };
    }

    public static function outputForResolution(
        WorkflowHistoryEvent $resolutionEvent,
        ?WorkflowRun $childRun = null,
    ): mixed {
        $serialized = $resolutionEvent->payload['output'] ?? null;

        if (! is_string($serialized) && $childRun !== null) {
            $serialized = self::outputPayloadForChildRun($childRun);
        }

        return is_string($serialized)
            ? Serializer::unserialize($serialized)
            : null;
    }

    public static function outputForChildRun(?WorkflowRun $childRun): mixed
    {
        $serialized = self::outputPayloadForChildRun($childRun);

        return is_string($serialized)
            ? Serializer::unserialize($serialized)
            : null;
    }

    public static function exceptionForResolution(
        WorkflowHistoryEvent $resolutionEvent,
        ?WorkflowRun $childRun = null,
    ): Throwable {
        $payload = self::exceptionPayload(
            is_array($resolutionEvent->payload['exception'] ?? null) ? $resolutionEvent->payload['exception'] : null,
            $childRun,
        );

        $fallbackClass = self::stringValue($resolutionEvent->payload['exception_class'] ?? null)
            ?? self::stringValue(self::terminalEventForRun($childRun)?->payload['exception_class'] ?? null)
            ?? self::failureRow($childRun)?->exception_class
            ?? RuntimeException::class;
        $fallbackMessage = self::stringValue($resolutionEvent->payload['message'] ?? null)
            ?? self::stringValue(self::terminalEventForRun($childRun)?->payload['message'] ?? null)
            ?? self::failureRow($childRun)?->message
            ?? sprintf(
                'Child workflow %s closed as %s.',
                $childRun?->id ?? 'unknown',
                self::resolvedStatus($resolutionEvent, $childRun)?->value ?? 'unknown',
            );
        $fallbackCode = self::intValue($resolutionEvent->payload['code'] ?? null)
            ?? self::intValue(self::terminalEventForRun($childRun)?->payload['code'] ?? null)
            ?? 0;

        return FailureFactory::restoreForReplay($payload ?? [], $fallbackClass, $fallbackMessage, $fallbackCode);
    }

    public static function exceptionForChildRun(?WorkflowRun $childRun): Throwable
    {
        $terminalEvent = self::terminalEventForRun($childRun);

        if ($terminalEvent instanceof WorkflowHistoryEvent && in_array($terminalEvent->event_type, [
            HistoryEventType::WorkflowFailed,
            HistoryEventType::WorkflowCancelled,
            HistoryEventType::WorkflowTerminated,
        ], true)) {
            return self::exceptionForResolution($terminalEvent, $childRun);
        }

        return FailureFactory::restoreForReplay(
            [],
            self::failureRow($childRun)?->exception_class ?? RuntimeException::class,
            self::failureRow($childRun)?->message ?? sprintf(
                'Child workflow %s closed as %s.',
                $childRun?->id ?? 'unknown',
                $childRun?->status?->value ?? 'unknown',
            ),
            0,
        );
    }

    private static function terminalEventForRun(?WorkflowRun $childRun): ?WorkflowHistoryEvent
    {
        if (! $childRun instanceof WorkflowRun) {
            return null;
        }

        $childRun->loadMissing('historyEvents');

        /** @var WorkflowHistoryEvent|null $event */
        $event = $childRun->historyEvents
            ->filter(
                static fn (WorkflowHistoryEvent $event): bool => in_array($event->event_type, [
                    HistoryEventType::WorkflowCompleted,
                    HistoryEventType::WorkflowFailed,
                    HistoryEventType::WorkflowCancelled,
                    HistoryEventType::WorkflowTerminated,
                ], true)
            )
            ->sortByDesc('sequence')
            ->first();

        return $event;
    }

    private static function workflowStartedEvent(WorkflowRun $run): ?WorkflowHistoryEvent
    {
        $run->loadMissing('historyEvents');

        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::WorkflowStarted
        );

        return $event;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private static function childCallIdFromPayload(?array $payload): ?string
    {
        return self::stringValue($payload['child_call_id'] ?? null);
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private static function legacyChildCallIdFromParentEventPayload(?array $payload): ?string
    {
        return self::stringValue($payload['workflow_link_id'] ?? null);
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private static function legacyChildCallIdFromStartedWorkflowPayload(?array $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        if (! is_string($payload['parent_workflow_run_id'] ?? null) || is_string($payload['continued_from_run_id'] ?? null)) {
            return null;
        }

        return self::stringValue($payload['workflow_link_id'] ?? null);
    }

    private static function outputPayloadForChildRun(?WorkflowRun $childRun): ?string
    {
        if (! $childRun instanceof WorkflowRun) {
            return null;
        }

        return self::stringValue(self::terminalEventForRun($childRun)?->payload['output'] ?? null)
            ?? self::stringValue($childRun->output);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>|null
     */
    private static function exceptionPayload(?array $payload, ?WorkflowRun $childRun): ?array
    {
        if ($payload !== null) {
            return $payload;
        }

        $terminalEvent = self::terminalEventForRun($childRun);

        if (is_array($terminalEvent?->payload['exception'] ?? null)) {
            return $terminalEvent->payload['exception'];
        }

        $failure = self::failureRow($childRun);

        if (! $failure instanceof WorkflowFailure) {
            return null;
        }

        return [
            'class' => $failure->exception_class,
            'message' => $failure->message,
        ];
    }

    private static function failureRow(?WorkflowRun $childRun): ?WorkflowFailure
    {
        if (! $childRun instanceof WorkflowRun) {
            return null;
        }

        $childRun->loadMissing('failures');

        /** @var WorkflowFailure|null $failure */
        $failure = $childRun->failures->first();

        return $failure;
    }

    private static function loadRun(?string $runId): ?WorkflowRun
    {
        if ($runId === null) {
            return null;
        }

        /** @var WorkflowRun|null $run */
        $run = WorkflowRun::query()
            ->with([
                'summary',
                'instance',
                'failures',
                'historyEvents',
            ])
            ->find($runId);

        return $run;
    }

    private static function loadCurrentRunForInstance(string $instanceId): ?WorkflowRun
    {
        /** @var \Workflow\V2\Models\WorkflowInstance|null $instance */
        $instance = \Workflow\V2\Models\WorkflowInstance::query()->find($instanceId);

        if ($instance === null) {
            return null;
        }

        return CurrentRunResolver::forInstance($instance, ['summary', 'failures', 'historyEvents']);
    }

    private static function followContinuedRun(?WorkflowRun $childRun): ?WorkflowRun
    {
        $visited = [];

        while ($childRun instanceof WorkflowRun) {
            $childRun->loadMissing('instance');

            if ($childRun->closed_reason !== 'continued') {
                return $childRun;
            }

            $currentRun = CurrentRunResolver::forRun($childRun, [
                'summary',
                'failures',
                'historyEvents',
            ]);

            if (! $currentRun instanceof WorkflowRun || $currentRun->id === $childRun->id || isset($visited[$childRun->id])) {
                return $childRun;
            }

            $visited[$childRun->id] = true;
            $childRun = $currentRun;
        }

        return $childRun;
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
