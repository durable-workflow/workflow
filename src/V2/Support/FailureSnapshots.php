<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class FailureSnapshots
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forRun(WorkflowRun $run): array
    {
        $run->loadMissing(['historyEvents', 'failures']);

        $snapshots = [];
        $failuresById = $run->failures->keyBy('id');
        $handledFailureIds = [];

        foreach ($run->failures as $failure) {
            if (! $failure instanceof WorkflowFailure) {
                continue;
            }

            $snapshots[$failure->id] = self::snapshotFromFailure($failure);
        }

        foreach ($run->historyEvents->sortBy('sequence') as $event) {
            if (! $event instanceof WorkflowHistoryEvent) {
                continue;
            }

            $failureId = self::stringValue($event->payload['failure_id'] ?? null);

            if ($failureId === null) {
                continue;
            }

            if ($event->event_type === HistoryEventType::FailureHandled) {
                $handledFailureIds[$failureId] = true;

                continue;
            }

            if (! self::supportsEvent($event)) {
                continue;
            }

            $snapshot = self::snapshotFromEvent(
                $event,
                $failuresById->get($failureId),
            );

            if ($snapshot === null) {
                continue;
            }

            $snapshots[$failureId] = $snapshot;
        }

        foreach (array_keys($handledFailureIds) as $failureId) {
            if (! isset($snapshots[$failureId])) {
                continue;
            }

            $snapshots[$failureId]['handled'] = true;
        }

        $snapshots = array_values($snapshots);

        usort($snapshots, static function (array $left, array $right): int {
            $leftSequence = self::intValue($left['event_sequence'] ?? null) ?? PHP_INT_MAX;
            $rightSequence = self::intValue($right['event_sequence'] ?? null) ?? PHP_INT_MAX;

            if ($leftSequence !== $rightSequence) {
                return $leftSequence <=> $rightSequence;
            }

            $leftCreatedAt = self::timestampToMilliseconds($left['created_at'] ?? null);
            $rightCreatedAt = self::timestampToMilliseconds($right['created_at'] ?? null);

            if ($leftCreatedAt !== $rightCreatedAt) {
                return $leftCreatedAt <=> $rightCreatedAt;
            }

            return (string) ($left['id'] ?? '') <=> (string) ($right['id'] ?? '');
        });

        return array_values($snapshots);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function keyedForRun(WorkflowRun $run): array
    {
        $snapshots = [];

        foreach (self::forRun($run) as $snapshot) {
            $id = self::stringValue($snapshot['id'] ?? null);

            if ($id === null) {
                continue;
            }

            $snapshots[$id] = $snapshot;
        }

        return $snapshots;
    }

    private static function supportsEvent(WorkflowHistoryEvent $event): bool
    {
        return in_array($event->event_type, [
            HistoryEventType::ActivityFailed,
            HistoryEventType::ChildRunFailed,
            HistoryEventType::WorkflowFailed,
            HistoryEventType::UpdateCompleted,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshotFromFailure(WorkflowFailure $failure): array
    {
        $resolution = self::exceptionResolution($failure->exception_class, null);

        return [
            'id' => $failure->id,
            'source_kind' => $failure->source_kind,
            'source_id' => $failure->source_id,
            'propagation_kind' => $failure->propagation_kind,
            'handled' => (bool) $failure->handled,
            'exception_type' => null,
            'exception_class' => $failure->exception_class,
            'exception_resolved_class' => $resolution['class'],
            'exception_resolution_source' => $resolution['source'],
            'exception_resolution_error' => $resolution['error'],
            'exception_replay_blocked' => self::replayBlocked($resolution),
            'message' => $failure->message,
            'code' => 0,
            'file' => $failure->file,
            'line' => $failure->line,
            'trace_preview' => $failure->trace_preview,
            'created_at' => $failure->created_at,
            'event_sequence' => null,
            'exception_payload' => [
                '__constructor' => $failure->exception_class,
                'type' => null,
                'message' => $failure->message,
                'code' => 0,
                'file' => $failure->file,
                'line' => $failure->line,
                'trace' => [],
                'properties' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function snapshotFromEvent(
        WorkflowHistoryEvent $event,
        ?WorkflowFailure $failure,
    ): ?array {
        $failureId = self::stringValue($event->payload['failure_id'] ?? null);

        if ($failureId === null) {
            return null;
        }

        $fallbackClass = self::stringValue($event->payload['exception_class'] ?? null)
            ?? $failure?->exception_class;
        $fallbackMessage = self::stringValue($event->payload['message'] ?? null)
            ?? $failure?->message;
        $fallbackCode = self::intValue($event->payload['code'] ?? null);
        $exceptionPayload = self::exceptionPayload(
            $event->payload['exception'] ?? null,
            $fallbackClass,
            $fallbackMessage,
            $fallbackCode,
            $failure,
        );
        $exceptionType = self::stringValue($exceptionPayload['type'] ?? null)
            ?? self::stringValue($event->payload['exception_type'] ?? null);
        $exceptionClass = self::stringValue($exceptionPayload['__constructor'] ?? null)
            ?? $failure?->exception_class;
        $resolution = self::exceptionResolution($exceptionClass, $exceptionType);

        return [
            'id' => $failure?->id ?? $failureId,
            'source_kind' => self::stringValue($event->payload['source_kind'] ?? null)
                ?? $failure?->source_kind
                ?? self::sourceKindForEvent($event),
            'source_id' => self::stringValue($event->payload['source_id'] ?? null)
                ?? $failure?->source_id
                ?? self::sourceIdForEvent($event),
            'propagation_kind' => $failure?->propagation_kind
                ?? self::propagationKindForEvent($event),
            'handled' => (bool) ($failure?->handled ?? false),
            'exception_type' => $exceptionType,
            'exception_class' => $exceptionClass,
            'exception_resolved_class' => $resolution['class'],
            'exception_resolution_source' => $resolution['source'],
            'exception_resolution_error' => $resolution['error'],
            'exception_replay_blocked' => self::replayBlocked($resolution),
            'message' => self::stringValue($exceptionPayload['message'] ?? null)
                ?? $failure?->message,
            'code' => self::intValue($exceptionPayload['code'] ?? null) ?? 0,
            'file' => self::stringValue($exceptionPayload['file'] ?? null) ?? $failure?->file,
            'line' => self::intValue($exceptionPayload['line'] ?? null) ?? $failure?->line,
            'trace_preview' => $failure?->trace_preview ?? self::tracePreviewFromPayload($exceptionPayload),
            'created_at' => $failure?->created_at ?? $event->recorded_at ?? $event->created_at,
            'event_sequence' => $event->sequence,
            'exception_payload' => $exceptionPayload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function exceptionPayload(
        mixed $payload,
        ?string $fallbackClass,
        ?string $fallbackMessage,
        ?int $fallbackCode,
        ?WorkflowFailure $failure,
    ): array {
        if (is_string($payload)) {
            $payload = Serializer::unserialize($payload);
        }

        if (! is_array($payload)) {
            $payload = [];
        }

        $trace = is_array($payload['trace'] ?? null)
            ? array_values(array_filter($payload['trace'], static fn (mixed $frame): bool => is_array($frame)))
            : [];
        $properties = is_array($payload['properties'] ?? null)
            ? array_values(array_filter($payload['properties'], static fn (mixed $frame): bool => is_array($frame)))
            : [];

        return [
            '__constructor' => self::stringValue($payload['class'] ?? null)
                ?? $fallbackClass
                ?? $failure?->exception_class,
            'type' => self::stringValue($payload['type'] ?? null),
            'message' => self::stringValue($payload['message'] ?? null)
                ?? $fallbackMessage
                ?? $failure?->message,
            'code' => self::intValue($payload['code'] ?? null)
                ?? $fallbackCode
                ?? 0,
            'file' => self::stringValue($payload['file'] ?? null) ?? $failure?->file,
            'line' => self::intValue($payload['line'] ?? null) ?? $failure?->line,
            'trace' => $trace,
            'properties' => $properties,
        ];
    }

    private static function tracePreviewFromPayload(array $payload): string
    {
        $trace = is_array($payload['trace'] ?? null) ? $payload['trace'] : [];

        if ($trace === []) {
            return '';
        }

        $lines = [];

        foreach (array_slice($trace, 0, 5) as $frame) {
            if (! is_array($frame)) {
                continue;
            }

            $class = self::stringValue($frame['class'] ?? null) ?? '';
            $type = self::stringValue($frame['type'] ?? null) ?? '';
            $function = self::stringValue($frame['function'] ?? null) ?? 'unknown';
            $file = self::stringValue($frame['file'] ?? null) ?? 'unknown';
            $line = self::intValue($frame['line'] ?? null);

            $lines[] = sprintf('%s%s%s @ %s:%s', $class, $type, $function, $file, (string) ($line ?? 0));
        }

        return implode("\n", $lines);
    }

    private static function sourceKindForEvent(WorkflowHistoryEvent $event): ?string
    {
        return match ($event->event_type) {
            HistoryEventType::ActivityFailed => 'activity_execution',
            HistoryEventType::ChildRunFailed => 'child_workflow_run',
            HistoryEventType::WorkflowFailed => 'workflow_run',
            HistoryEventType::UpdateCompleted => 'workflow_command',
            default => null,
        };
    }

    private static function sourceIdForEvent(WorkflowHistoryEvent $event): ?string
    {
        return match ($event->event_type) {
            HistoryEventType::ActivityFailed => self::stringValue($event->payload['activity_execution_id'] ?? null),
            HistoryEventType::ChildRunFailed => self::stringValue($event->payload['child_workflow_run_id'] ?? null),
            HistoryEventType::WorkflowFailed => $event->workflow_run_id,
            HistoryEventType::UpdateCompleted => self::stringValue($event->workflow_command_id)
                ?? self::stringValue($event->payload['update_id'] ?? null),
            default => null,
        };
    }

    private static function propagationKindForEvent(WorkflowHistoryEvent $event): ?string
    {
        return match ($event->event_type) {
            HistoryEventType::ActivityFailed => 'activity',
            HistoryEventType::ChildRunFailed => 'child',
            HistoryEventType::WorkflowFailed => 'terminal',
            HistoryEventType::UpdateCompleted => self::stringValue($event->payload['failure_id'] ?? null) === null
                ? null
                : 'update',
            default => null,
        };
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

    /**
     * @return array{class: ?string, source: 'exception_type'|'class_alias'|'recorded_class'|'unresolved'|'misconfigured', error: ?string}
     */
    private static function exceptionResolution(?string $recordedClass, ?string $exceptionType): array
    {
        if ($recordedClass === null) {
            return [
                'class' => null,
                'source' => 'unresolved',
                'error' => null,
            ];
        }

        try {
            $resolution = TypeRegistry::resolveThrowableClassWithSource($recordedClass, $exceptionType);
        } catch (Throwable $throwable) {
            return [
                'class' => null,
                'source' => 'misconfigured',
                'error' => $throwable->getMessage(),
            ];
        }

        if ($resolution === null) {
            return [
                'class' => null,
                'source' => 'unresolved',
                'error' => null,
            ];
        }

        return [
            'class' => $resolution['class'],
            'source' => $resolution['source'],
            'error' => null,
        ];
    }

    private static function timestampToMilliseconds(mixed $timestamp): int
    {
        if ($timestamp instanceof CarbonInterface) {
            return $timestamp->getTimestampMs();
        }

        if (is_string($timestamp) && $timestamp !== '') {
            return \Illuminate\Support\Carbon::parse($timestamp)->getTimestampMs();
        }

        return 0;
    }

    /**
     * @param array{class: ?string, source: string, error: ?string} $resolution
     */
    private static function replayBlocked(array $resolution): bool
    {
        return in_array($resolution['source'] ?? null, ['unresolved', 'misconfigured'], true);
    }
}
