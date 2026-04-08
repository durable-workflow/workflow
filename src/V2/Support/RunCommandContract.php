<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class RunCommandContract
{
    public const SOURCE_DURABLE_HISTORY = 'durable_history';

    public const SOURCE_LIVE_DEFINITION = 'live_definition';

    public const SOURCE_UNAVAILABLE = 'unavailable';

    /**
     * @return array{signals: list<string>, updates: list<string>, source: string}
     */
    public static function forRun(WorkflowRun $run): array
    {
        $contract = self::contractFromHistory($run);

        if ($contract !== null) {
            return [
                ...$contract,
                'source' => self::SOURCE_DURABLE_HISTORY,
            ];
        }

        $event = self::workflowStartedEvent($run);

        if ($event instanceof WorkflowHistoryEvent) {
            $contract = self::backfillContractFromDefinition($run, $event);

            if ($contract !== null) {
                return [
                    ...$contract,
                    'source' => self::SOURCE_DURABLE_HISTORY,
                ];
            }
        }

        try {
            $resolvedClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
        } catch (LogicException) {
            return [
                'signals' => [],
                'updates' => [],
                'source' => self::SOURCE_UNAVAILABLE,
            ];
        }

        return [
            ...WorkflowDefinition::commandContract($resolvedClass),
            'source' => self::SOURCE_LIVE_DEFINITION,
        ];
    }

    public static function hasSignal(WorkflowRun $run, string $name): bool
    {
        return in_array($name, self::forRun($run)['signals'], true);
    }

    public static function hasUpdateMethod(WorkflowRun $run, string $method): bool
    {
        return in_array($method, self::forRun($run)['updates'], true);
    }

    /**
     * @param class-string $workflowClass
     * @return array{signals: list<string>, updates: list<string>}
     */
    public static function snapshot(string $workflowClass): array
    {
        return WorkflowDefinition::commandContract($workflowClass);
    }

    /**
     * @return array{signals: list<string>, updates: list<string>}|null
     */
    private static function contractFromHistory(WorkflowRun $run): ?array
    {
        $event = self::workflowStartedEvent($run);

        if (! $event instanceof WorkflowHistoryEvent) {
            return null;
        }

        $signals = self::normalizeList($event->payload['declared_signals'] ?? null);
        $updates = self::normalizeList($event->payload['declared_updates'] ?? null);

        if ($signals === null || $updates === null) {
            return null;
        }

        return [
            'signals' => $signals,
            'updates' => $updates,
        ];
    }

    /**
     * @return array{signals: list<string>, updates: list<string>}|null
     */
    private static function backfillContractFromDefinition(
        WorkflowRun $run,
        WorkflowHistoryEvent $event,
    ): ?array {
        try {
            $resolvedClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
        } catch (LogicException) {
            return null;
        }

        $snapshot = WorkflowDefinition::commandContract($resolvedClass);
        $payload = is_array($event->payload) ? $event->payload : [];

        $payload['declared_signals'] = $snapshot['signals'];
        $payload['declared_updates'] = $snapshot['updates'];

        $event->forceFill([
            'payload' => $payload,
        ])->save();

        return $snapshot;
    }

    private static function workflowStartedEvent(WorkflowRun $run): ?WorkflowHistoryEvent
    {
        if ($run->relationLoaded('historyEvents')) {
            /** @var WorkflowHistoryEvent|null $event */
            $event = $run->historyEvents->first(
                static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::WorkflowStarted
            );

            return $event;
        }

        /** @var WorkflowHistoryEvent|null $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->orderBy('sequence')
            ->first();

        return $event;
    }

    /**
     * @return list<string>|null
     */
    private static function normalizeList(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $normalized = array_values(array_unique(array_filter(
            $value,
            static fn (mixed $entry): bool => is_string($entry) && $entry !== '',
        )));

        sort($normalized);

        return $normalized;
    }
}
