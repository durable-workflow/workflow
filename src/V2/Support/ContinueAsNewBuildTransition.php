<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\ContinueAsNewTarget;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

/** @internal */
final class ContinueAsNewBuildTransition
{
    public const KEY = 'build_transition';

    /**
     * @return array{from_compatibility: ?string, to_compatibility: ?string, resolution: string}
     */
    public static function payload(?string $from, ?string $to, ContinueAsNewTarget $target): array
    {
        return [
            'from_compatibility' => $from,
            'to_compatibility' => $to,
            'resolution' => $target->value,
        ];
    }

    /**
     * @return array{from_compatibility: ?string, to_compatibility: ?string, resolution: string}|null
     */
    public static function read(mixed $transition): ?array
    {
        if (! is_array($transition)
            || ! array_key_exists('from_compatibility', $transition)
            || ! array_key_exists('to_compatibility', $transition)
            || ! is_string($transition['resolution'] ?? null)) {
            return null;
        }

        $from = $transition['from_compatibility'];
        $to = $transition['to_compatibility'];
        $target = ContinueAsNewTarget::tryFrom($transition['resolution']);
        if (($from !== null && ! is_string($from)) || ($to !== null && ! is_string($to)) || $target === null) {
            return null;
        }

        return self::payload($from, $to, $target);
    }

    public static function recorded(WorkflowRun $run, ?int $sequence = null): ?WorkflowHistoryEvent
    {
        return $run->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::WorkflowContinuedAsNew
            && ($sequence === null || ($event->payload['sequence'] ?? null) === $sequence));
    }

    public static function assertReplay(WorkflowHistoryEvent $recorded, ContinueAsNewCall $call, int $sequence): void
    {
        $payload = $recorded->payload ?? [];
        $target = array_key_exists(self::KEY, $payload)
            ? (self::read($payload[self::KEY])['resolution'] ?? null)
            : ContinueAsNewTarget::Inherited->value;
        $authored = ($call->target ?? ContinueAsNewTarget::Inherited)->value;
        if ($target !== $authored) {
            throw new HistoryEventShapeMismatchException(
                $sequence,
                'continue as new targeting ' . $authored,
                [HistoryEventType::WorkflowContinuedAsNew->value],
                'The recorded build target is ' . ($target ?? 'malformed') . '.',
            );
        }
    }

    /**
     * @return array<string, array{run_id: string, from_compatibility: ?string, to_compatibility: ?string, resolution: string}|null>
     */
    public static function observe(?WorkflowHistoryEvent $started, ?WorkflowHistoryEvent $continued): array
    {
        return [
            'from_predecessor' => self::linkedObservation(
                $started?->payload['continued_from_run_id'] ?? null,
                $started?->payload[self::KEY] ?? null,
            ),
            'to_successor' => self::linkedObservation(
                $continued?->payload['continued_to_run_id'] ?? null,
                $continued?->payload[self::KEY] ?? null,
            ),
        ];
    }

    /**
     * @return array{run_id: string, from_compatibility: ?string, to_compatibility: ?string, resolution: string}|null
     */
    public static function linkedObservation(mixed $runId, mixed $data): ?array
    {
        $transition = self::read($data);
        return is_string($runId) && $runId !== '' && $transition !== null
            ? [
                'run_id' => $runId,
                ...$transition,
            ]
            : null;
    }
}
