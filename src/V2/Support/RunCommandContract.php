<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class RunCommandContract
{
    public const SOURCE_DURABLE_HISTORY = 'durable_history';

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
     *     entry_method: 'handle'|'execute'|null,
     *     entry_mode: 'canonical'|'compatibility'|null,
     *     entry_declaring_class: class-string|null,
     *     source: string
     * }
     */
    public static function forRun(WorkflowRun $run): array
    {
        $contract = self::contractFromHistory($run);

        if ($contract !== null) {
            return [
                ...self::withTargets($contract),
                'source' => self::SOURCE_DURABLE_HISTORY,
            ];
        }

        return [
            ...self::withTargets(self::emptyContract()),
            'source' => self::SOURCE_UNAVAILABLE,
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
     *     update_contracts: list<array<string, mixed>>,
     *     entry_method: 'handle'|'execute',
     *     entry_mode: 'canonical'|'compatibility',
     *     entry_declaring_class: class-string
     * }
     */
    public static function snapshot(string $workflowClass): array
    {
        return WorkflowDefinition::commandContract($workflowClass);
    }

    /**
     * @return array{
     *     queries: list<string>,
     *     query_contracts: list<array<string, mixed>>,
     *     signals: list<string>,
     *     signal_contracts: list<array<string, mixed>>,
     *     updates: list<string>,
     *     update_contracts: list<array<string, mixed>>,
     *     entry_method: 'handle'|'execute'|null,
     *     entry_mode: 'canonical'|'compatibility'|null,
     *     entry_declaring_class: class-string|null
     * }|null
     */
    private static function contractFromHistory(WorkflowRun $run): ?array
    {
        return self::contractFromHistoryEvent(self::workflowStartedEvent($run));
    }

    /**
     * @return array{
     *     queries: list<string>,
     *     query_contracts: list<array<string, mixed>>,
     *     signals: list<string>,
     *     signal_contracts: list<array<string, mixed>>,
     *     updates: list<string>,
     *     update_contracts: list<array<string, mixed>>,
     *     entry_method: 'handle'|'execute'|null,
     *     entry_mode: 'canonical'|'compatibility'|null,
     *     entry_declaring_class: class-string|null
     * }|null
     */
    private static function contractFromHistoryEvent(?WorkflowHistoryEvent $event): ?array
    {
        if (! $event instanceof WorkflowHistoryEvent) {
            return null;
        }

        if (! is_array($event->payload)) {
            return null;
        }

        $queries = self::normalizeList($event->payload['declared_queries'] ?? null);
        $queryContracts = self::normalizeCommandContracts($event->payload['declared_query_contracts'] ?? null);
        $signals = self::normalizeList($event->payload['declared_signals'] ?? null);
        $signalContracts = self::normalizeCommandContracts($event->payload['declared_signal_contracts'] ?? null);
        $updates = self::normalizeList($event->payload['declared_updates'] ?? null);
        $updateContracts = self::normalizeCommandContracts($event->payload['declared_update_contracts'] ?? null);
        $entryMethod = self::normalizeEntryMethod($event->payload['declared_entry_method'] ?? null);
        $entryMode = self::normalizeEntryMode($event->payload['declared_entry_mode'] ?? null);
        $entryDeclaringClass = self::normalizeClassString($event->payload['declared_entry_declaring_class'] ?? null);

        $hasStrictPayload = array_key_exists('declared_queries', $event->payload)
            && array_key_exists('declared_query_contracts', $event->payload)
            && array_key_exists('declared_signals', $event->payload)
            && array_key_exists('declared_signal_contracts', $event->payload)
            && array_key_exists('declared_updates', $event->payload)
            && array_key_exists('declared_update_contracts', $event->payload)
            && array_key_exists('declared_entry_method', $event->payload)
            && array_key_exists('declared_entry_mode', $event->payload)
            && array_key_exists('declared_entry_declaring_class', $event->payload);

        $strictContractValid = $hasStrictPayload
            && $queries !== null
            && $queryContracts !== null
            && $signals !== null
            && $updates !== null
            && $signalContracts !== null
            && $updateContracts !== null
            && $entryMethod !== null
            && $entryMode !== null
            && $entryDeclaringClass !== null
            && self::missingDeclaredContractNames($queries, $queryContracts) === []
            && self::missingDeclaredContractNames($updates, $updateContracts) === [];

        if ($strictContractValid) {
            /** @var list<string> $queries */
            /** @var list<array<string, mixed>> $queryContracts */
            /** @var list<string> $signals */
            /** @var list<array<string, mixed>> $signalContracts */
            /** @var list<string> $updates */
            /** @var list<array<string, mixed>> $updateContracts */

            return [
                'queries' => $queries,
                'query_contracts' => $queryContracts,
                'signals' => $signals,
                'signal_contracts' => $signalContracts,
                'updates' => $updates,
                'update_contracts' => $updateContracts,
                'entry_method' => $entryMethod,
                'entry_mode' => $entryMode,
                'entry_declaring_class' => $entryDeclaringClass,
            ];
        }

        // Legacy-shape fallback: a WorkflowStarted payload persisted before the
        // declared_*_contracts / declared_entry_* fields were required can
        // still declare the set of signals/updates/queries by name. Keep
        // those declared names addressable (so `hasSignal()` is truthful and
        // callers can reject named arguments with a contract-required error)
        // while leaving the per-name contract lists empty. When the payload
        // has none of the declared_* fields at all, we have no contract.
        $legacyShape = $signals !== null || $updates !== null || $queries !== null;

        if (! $legacyShape) {
            return null;
        }

        return [
            'queries' => $queries ?? [],
            'query_contracts' => [],
            'signals' => $signals ?? [],
            'signal_contracts' => [],
            'updates' => $updates ?? [],
            'update_contracts' => [],
            'entry_method' => $entryMethod,
            'entry_mode' => $entryMode,
            'entry_declaring_class' => $entryDeclaringClass,
        ];
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
        $event = ConfiguredV2Models::query('history_event_model', WorkflowHistoryEvent::class)
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
     * @return array{
     *     queries: list<string>,
     *     query_contracts: list<array<string, mixed>>,
     *     signals: list<string>,
     *     signal_contracts: list<array<string, mixed>>,
     *     updates: list<string>,
     *     update_contracts: list<array<string, mixed>>,
     *     entry_method: 'handle'|'execute'|null,
     *     entry_mode: 'canonical'|'compatibility'|null,
     *     entry_declaring_class: class-string|null
     * }
     */
    private static function emptyContract(): array
    {
        return [
            'queries' => [],
            'query_contracts' => [],
            'signals' => [],
            'signal_contracts' => [],
            'updates' => [],
            'update_contracts' => [],
            'entry_method' => null,
            'entry_mode' => null,
            'entry_declaring_class' => null,
        ];
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
     *     update_targets: list<array<string, mixed>>,
     *     entry_method: 'handle'|'execute'|null,
     *     entry_mode: 'canonical'|'compatibility'|null,
     *     entry_declaring_class: class-string|null
     * }
     */
    private static function withTargets(array $contract): array
    {
        return [
            ...$contract,
            'query_targets' => self::normalizeTargets($contract['queries'], $contract['query_contracts']),
            'signal_targets' => self::normalizeTargets($contract['signals'], $contract['signal_contracts']),
            'update_targets' => self::normalizeTargets($contract['updates'], $contract['update_contracts']),
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

    /**
     * @param list<string> $names
     * @param list<array<string, mixed>> $contracts
     * @return list<string>
     */
    private static function missingDeclaredContractNames(array $names, array $contracts): array
    {
        $contractNames = array_values(array_filter(
            array_column($contracts, 'name'),
            static fn (mixed $name): bool => is_string($name) && $name !== '',
        ));

        return array_values(array_diff($names, $contractNames));
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

    /**
     * @return 'handle'|'execute'|null
     */
    private static function normalizeEntryMethod(mixed $value): ?string
    {
        return in_array($value, ['handle', 'execute'], true)
            ? $value
            : null;
    }

    /**
     * @return 'canonical'|'compatibility'|null
     */
    private static function normalizeEntryMode(mixed $value): ?string
    {
        return in_array($value, ['canonical', 'compatibility'], true)
            ? $value
            : null;
    }

    /**
     * @return class-string|null
     */
    private static function normalizeClassString(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
