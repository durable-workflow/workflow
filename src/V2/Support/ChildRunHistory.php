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

        return FailureFactory::restore($payload ?? [], $fallbackClass, $fallbackMessage, $fallbackCode);
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

        return FailureFactory::restore(
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
