<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Throwable;
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
     *     source: string,
     *     backfill_needed: bool,
     *     backfill_available: bool
     * }
     */
    public static function forRun(WorkflowRun $run): array
    {
        $event = self::workflowStartedEvent($run);
        $state = self::contractStateFromHistoryEvent($event);

        if ($state['contract'] !== null && $state['strict']) {
            return self::payload($state['contract'], self::SOURCE_DURABLE_HISTORY, false, false);
        }

        if ($event instanceof WorkflowHistoryEvent && ! $state['backfill_needed']) {
            $backfilled = self::backfillStartedEvent($run, $event);

            if ($backfilled !== null) {
                return self::payload($backfilled, self::SOURCE_DURABLE_HISTORY, false, false);
            }
        }

        return self::payload(
            $state['contract'] ?? self::emptyContract(),
            self::SOURCE_UNAVAILABLE,
            $state['backfill_needed'],
            $state['backfill_needed'] && self::snapshotForRun($run) !== null,
        );
    }

    public static function hasSignal(WorkflowRun $run, string $name): bool
    {
        return in_array($name, self::forRun($run)['signals'], true);
    }

    /**
     * @return array{needed: bool, available: bool}
     */
    public static function backfillStatus(WorkflowRun $run): array
    {
        $state = self::contractStateFromHistoryEvent(self::workflowStartedEvent($run));
        $needed = $state['backfill_needed'];

        return [
            'needed' => $needed,
            'available' => $needed && self::snapshotForRun($run) !== null,
        ];
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
     *     contract: array{
     *         queries: list<string>,
     *         query_contracts: list<array<string, mixed>>,
     *         signals: list<string>,
     *         signal_contracts: list<array<string, mixed>>,
     *         updates: list<string>,
     *         update_contracts: list<array<string, mixed>>,
     *         entry_method: 'handle'|'execute'|null,
     *         entry_mode: 'canonical'|'compatibility'|null,
     *         entry_declaring_class: class-string|null
     *     }|null,
     *     strict: bool,
     *     backfill_needed: bool
     * }
     */
    private static function contractStateFromHistoryEvent(?WorkflowHistoryEvent $event): array
    {
        if (! $event instanceof WorkflowHistoryEvent) {
            return [
                'contract' => null,
                'strict' => false,
                'backfill_needed' => false,
            ];
        }

        if (! is_array($event->payload)) {
            return [
                'contract' => null,
                'strict' => false,
                'backfill_needed' => false,
            ];
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

        $declaredPayloadKeys = [
            'declared_queries',
            'declared_query_contracts',
            'declared_signals',
            'declared_signal_contracts',
            'declared_updates',
            'declared_update_contracts',
            'declared_entry_method',
            'declared_entry_mode',
            'declared_entry_declaring_class',
        ];
        $hasAnyDeclaredPayload = false;

        foreach ($declaredPayloadKeys as $key) {
            if (array_key_exists($key, $event->payload)) {
                $hasAnyDeclaredPayload = true;

                break;
            }
        }

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
                'contract' => [
                    'queries' => $queries,
                    'query_contracts' => $queryContracts,
                    'signals' => $signals,
                    'signal_contracts' => $signalContracts,
                    'updates' => $updates,
                    'update_contracts' => $updateContracts,
                    'entry_method' => $entryMethod,
                    'entry_mode' => $entryMode,
                    'entry_declaring_class' => $entryDeclaringClass,
                ],
                'strict' => true,
                'backfill_needed' => false,
            ];
        }

        if (! $hasAnyDeclaredPayload) {
            return [
                'contract' => null,
                'strict' => false,
                'backfill_needed' => false,
            ];
        }

        return [
            'contract' => [
                'queries' => $queries ?? [],
                'query_contracts' => $queryContracts ?? [],
                'signals' => $signals ?? [],
                'signal_contracts' => $signalContracts ?? [],
                'updates' => $updates ?? [],
                'update_contracts' => $updateContracts ?? [],
                'entry_method' => $entryMethod,
                'entry_mode' => $entryMode,
                'entry_declaring_class' => $entryDeclaringClass,
            ],
            'strict' => false,
            'backfill_needed' => true,
        ];
    }

    /**
     * @param array{
     *     queries: list<string>,
     *     query_contracts: list<array<string, mixed>>,
     *     signals: list<string>,
     *     signal_contracts: list<array<string, mixed>>,
     *     updates: list<string>,
     *     update_contracts: list<array<string, mixed>>,
     *     entry_method: 'handle'|'execute'|null,
     *     entry_mode: 'canonical'|'compatibility'|null,
     *     entry_declaring_class: class-string|null
     * } $contract
     * @return array<string, mixed>
     */
    private static function payload(
        array $contract,
        string $source,
        bool $backfillNeeded,
        bool $backfillAvailable,
    ): array {
        return [
            ...self::withTargets($contract),
            'source' => $source,
            'backfill_needed' => $backfillNeeded,
            'backfill_available' => $backfillAvailable,
        ];
    }

    /**
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
     * }|null
     */
    private static function backfillStartedEvent(WorkflowRun $run, WorkflowHistoryEvent $event): ?array
    {
        $contract = self::snapshotForRun($run);

        if ($contract === null) {
            return null;
        }

        $payload = is_array($event->payload) ? $event->payload : [];
        $payload['declared_queries'] = $contract['queries'];
        $payload['declared_query_contracts'] = $contract['query_contracts'];
        $payload['declared_signals'] = $contract['signals'];
        $payload['declared_signal_contracts'] = $contract['signal_contracts'];
        $payload['declared_updates'] = $contract['updates'];
        $payload['declared_update_contracts'] = $contract['update_contracts'];
        $payload['declared_entry_method'] = $contract['entry_method'];
        $payload['declared_entry_mode'] = $contract['entry_mode'];
        $payload['declared_entry_declaring_class'] = $contract['entry_declaring_class'];

        $event->forceFill([
            'payload' => $payload,
        ])->save();

        return $contract;
    }

    /**
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
     * }|null
     */
    private static function snapshotForRun(WorkflowRun $run): ?array
    {
        if (! is_string($run->workflow_class) || $run->workflow_class === '') {
            return null;
        }

        try {
            return self::snapshot($run->workflow_class);
        } catch (Throwable) {
            return null;
        }
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
