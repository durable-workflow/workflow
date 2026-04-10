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
     * @return array{
     *     queries: list<string>,
     *     query_contracts: list<array<string, mixed>>,
     *     query_targets: list<array<string, mixed>>,
     *     signals: list<string>,
     *     signal_contracts: list<array<string, mixed>>,
     *     signal_targets: list<array<string, mixed>>,
     *     updates: list<string>,
     *     update_contracts: list<array<string, mixed>>,
     *     update_targets: list<array<string, mixed>>,
     *     source: string
     * }
     */
    public static function forRun(WorkflowRun $run): array
    {
        $contract = self::contractFromHistory($run);
        $event = self::workflowStartedEvent($run);

        if ($contract !== null && ! self::historyContractNeedsBackfill($event)) {
            return [
                ...self::withTargets($contract),
                'source' => self::SOURCE_DURABLE_HISTORY,
            ];
        }

        if ($event instanceof WorkflowHistoryEvent) {
            $contract = self::backfillContractFromDefinition($run, $event);

            if ($contract !== null) {
                return [
                    ...self::withTargets($contract),
                    'source' => self::SOURCE_DURABLE_HISTORY,
                ];
            }
        }

        if ($contract !== null) {
            return [
                ...self::withTargets($contract),
                'source' => self::SOURCE_DURABLE_HISTORY,
            ];
        }

        try {
            $resolvedClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
        } catch (LogicException) {
            return [
                ...self::withTargets([
                    'queries' => [],
                    'query_contracts' => [],
                    'signals' => [],
                    'signal_contracts' => [],
                    'updates' => [],
                    'update_contracts' => [],
                ]),
                'source' => self::SOURCE_UNAVAILABLE,
            ];
        }

        return [
            ...self::withTargets(WorkflowDefinition::commandContract($resolvedClass)),
            'source' => self::SOURCE_LIVE_DEFINITION,
        ];
    }

    public static function hasSignal(WorkflowRun $run, string $name): bool
    {
        return in_array($name, self::forRun($run)['signals'], true);
    }

    public static function hasQueryMethod(WorkflowRun $run, string $name): bool
    {
        return in_array($name, self::forRun($run)['queries'], true);
    }

    /**
     * @return array{
     *     name: string,
     *     parameters: list<array<string, mixed>>
     * }|null
     */
    public static function queryContract(WorkflowRun $run, string $target): ?array
    {
        foreach (self::forRun($run)['query_contracts'] as $contract) {
            if (($contract['name'] ?? null) === $target) {
                return $contract;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     name: string,
     *     parameters: list<array<string, mixed>>
     * }|null
     */
    public static function signalContract(WorkflowRun $run, string $target): ?array
    {
        foreach (self::forRun($run)['signal_contracts'] as $contract) {
            if (($contract['name'] ?? null) === $target) {
                return $contract;
            }
        }

        return null;
    }

    public static function hasUpdateMethod(WorkflowRun $run, string $method): bool
    {
        return in_array($method, self::forRun($run)['updates'], true);
    }

    /**
     * @return array{
     *     name: string,
     *     parameters: list<array<string, mixed>>
     * }|null
     */
    public static function updateContract(WorkflowRun $run, string $target): ?array
    {
        foreach (self::forRun($run)['update_contracts'] as $contract) {
            if (($contract['name'] ?? null) === $target) {
                return $contract;
            }
        }

        return null;
    }

    /**
     * @param class-string $workflowClass
     * @return array{
     *     queries: list<string>,
     *     query_contracts: list<array<string, mixed>>,
     *     signals: list<string>,
     *     signal_contracts: list<array<string, mixed>>,
     *     updates: list<string>,
     *     update_contracts: list<array<string, mixed>>
     * }
     */
    public static function snapshot(string $workflowClass): array
    {
        return WorkflowDefinition::commandContract($workflowClass);
    }

    public static function historyBackfillAvailable(WorkflowRun $run): bool
    {
        $event = self::workflowStartedEvent($run);

        if (! $event instanceof WorkflowHistoryEvent || ! self::historyContractNeedsBackfill($event)) {
            return false;
        }

        try {
            TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
        } catch (LogicException) {
            return false;
        }

        return true;
    }

    public static function backfillHistory(WorkflowRun $run): bool
    {
        $event = self::workflowStartedEvent($run);

        if (! $event instanceof WorkflowHistoryEvent || ! self::historyContractNeedsBackfill($event)) {
            return false;
        }

        return self::backfillContractFromDefinition($run, $event) !== null;
    }

    /**
     * @return array{
     *     queries: list<string>,
     *     query_contracts: list<array<string, mixed>>,
     *     signals: list<string>,
     *     signal_contracts: list<array<string, mixed>>,
     *     updates: list<string>,
     *     update_contracts: list<array<string, mixed>>
     * }|null
     */
    private static function contractFromHistory(WorkflowRun $run): ?array
    {
        $event = self::workflowStartedEvent($run);

        if (! $event instanceof WorkflowHistoryEvent) {
            return null;
        }

        $hasQueries = is_array($event->payload) && array_key_exists('declared_queries', $event->payload);
        $queries = $hasQueries
            ? self::normalizeList($event->payload['declared_queries'] ?? null)
            : [];
        $hasQueryContracts = is_array($event->payload) && array_key_exists('declared_query_contracts', $event->payload);
        $queryContracts = $hasQueryContracts
            ? self::normalizeCommandContracts($event->payload['declared_query_contracts'] ?? null)
            : [];
        $signals = self::normalizeList($event->payload['declared_signals'] ?? null);
        $hasSignalContracts = is_array($event->payload) && array_key_exists('declared_signal_contracts', $event->payload);
        $signalContracts = $hasSignalContracts
            ? self::normalizeCommandContracts($event->payload['declared_signal_contracts'] ?? null)
            : [];
        $updates = self::normalizeList($event->payload['declared_updates'] ?? null);
        $hasUpdateContracts = is_array($event->payload) && array_key_exists('declared_update_contracts', $event->payload);
        $updateContracts = $hasUpdateContracts
            ? self::normalizeCommandContracts($event->payload['declared_update_contracts'] ?? null)
            : [];

        if (
            ($hasQueries && $queries === null)
            || ($hasQueryContracts && $queryContracts === null)
            || $signals === null
            || $updates === null
            || ($hasSignalContracts && $signalContracts === null)
            || ($hasUpdateContracts && $updateContracts === null)
        ) {
            return null;
        }

        return [
            'queries' => $queries ?? [],
            'query_contracts' => $queryContracts ?? [],
            'signals' => $signals,
            'signal_contracts' => $signalContracts ?? [],
            'updates' => $updates,
            'update_contracts' => $updateContracts ?? [],
        ];
    }

    /**
     * @return array{
     *     queries: list<string>,
     *     query_contracts: list<array<string, mixed>>,
     *     signals: list<string>,
     *     signal_contracts: list<array<string, mixed>>,
     *     updates: list<string>,
     *     update_contracts: list<array<string, mixed>>
     * }|null
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

        $payload['declared_queries'] = $snapshot['queries'];
        $payload['declared_query_contracts'] = $snapshot['query_contracts'];
        $payload['declared_signals'] = $snapshot['signals'];
        $payload['declared_signal_contracts'] = $snapshot['signal_contracts'];
        $payload['declared_updates'] = $snapshot['updates'];
        $payload['declared_update_contracts'] = $snapshot['update_contracts'];

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

    /**
     * @param array{
     *     queries: list<string>,
     *     query_contracts: list<array<string, mixed>>,
     *     signals: list<string>,
     *     signal_contracts: list<array<string, mixed>>,
     *     updates: list<string>,
     *     update_contracts: list<array<string, mixed>>
     * } $contract
     * @return array{
     *     queries: list<string>,
     *     query_contracts: list<array<string, mixed>>,
     *     query_targets: list<array<string, mixed>>,
     *     signals: list<string>,
     *     signal_contracts: list<array<string, mixed>>,
     *     signal_targets: list<array<string, mixed>>,
     *     updates: list<string>,
     *     update_contracts: list<array<string, mixed>>,
     *     update_targets: list<array<string, mixed>>
     * }
     */
    private static function withTargets(array $contract): array
    {
        return [
            ...$contract,
            'query_targets' => self::normalizeTargets(
                $contract['queries'],
                $contract['query_contracts'],
            ),
            'signal_targets' => self::normalizeTargets(
                $contract['signals'],
                $contract['signal_contracts'],
            ),
            'update_targets' => self::normalizeTargets(
                $contract['updates'],
                $contract['update_contracts'],
            ),
        ];
    }

    /**
     * @param list<string> $names
     * @param list<array<string, mixed>> $contracts
     * @return list<array{
     *     name: string,
     *     parameters: list<array<string, mixed>>,
     *     has_contract: bool
     * }>
     */
    private static function normalizeTargets(array $names, array $contracts): array
    {
        $contractByName = [];

        foreach ($contracts as $contract) {
            if (! is_string($contract['name'] ?? null)) {
                continue;
            }

            $contractByName[$contract['name']] = [
                'name' => $contract['name'],
                'parameters' => is_array($contract['parameters'] ?? null)
                    ? array_values(array_filter(
                        $contract['parameters'],
                        static fn (mixed $parameter): bool => is_array($parameter),
                    ))
                    : [],
                'has_contract' => true,
            ];
        }

        $targets = [];

        foreach ($names as $name) {
            $targets[$name] = $contractByName[$name] ?? [
                'name' => $name,
                'parameters' => [],
                'has_contract' => false,
            ];
        }

        foreach ($contractByName as $name => $target) {
            $targets[$name] ??= $target;
        }

        ksort($targets);

        return array_values($targets);
    }

    private static function historyContractNeedsBackfill(?WorkflowHistoryEvent $event): bool
    {
        return $event instanceof WorkflowHistoryEvent
            && is_array($event->payload)
            && (
                ! array_key_exists('declared_queries', $event->payload)
                || ! array_key_exists('declared_query_contracts', $event->payload)
                || ! array_key_exists('declared_signal_contracts', $event->payload)
                || ! array_key_exists('declared_update_contracts', $event->payload)
            );
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private static function normalizeCommandContracts(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $normalized = [];

        foreach ($value as $contract) {
            if (! is_array($contract) || ! is_string($contract['name'] ?? null)) {
                return null;
            }

            $parameters = $contract['parameters'] ?? [];

            if (! is_array($parameters)) {
                return null;
            }

            $normalized[] = [
                'name' => $contract['name'],
                'parameters' => array_values(array_filter(
                    $parameters,
                    static fn (mixed $parameter): bool => is_array($parameter),
                )),
            ];
        }

        usort($normalized, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);

        return $normalized;
    }
}
