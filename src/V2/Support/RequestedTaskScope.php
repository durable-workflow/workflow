<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * The task scope a repair, timeout or matching pass may act on: the requested
 * connection and queue, always inside the application's namespace allowlist.
 *
 * A missing namespace scope resolves to the configured allowlist, never to
 * every namespace in storage.
 */
final class RequestedTaskScope
{
    /**
     * Scope rows that record their namespace in `$namespaceColumn`.
     */
    public static function apply(
        $query,
        ?string $connection,
        ?string $queue,
        ?NamespaceScope $namespaces = null,
        string $namespaceColumn = 'namespace',
    ): void {
        self::applyValue($query, 'connection', $connection);
        self::applyValue($query, 'queue', $queue);
        ($namespaces ?? NamespaceScope::configured())->apply($query, $namespaceColumn);
    }

    /**
     * Scope workflow tasks by their authoritative namespace, which is the
     * run's namespace when the task has none recorded.
     */
    public static function applyToTasks(
        $query,
        ?string $connection,
        ?string $queue,
        ?NamespaceScope $namespaces = null,
    ): void {
        self::applyValue($query, 'connection', $connection);
        self::applyValue($query, 'queue', $queue);
        ($namespaces ?? NamespaceScope::configured())->applyToTasks($query);
    }

    /**
     * Scope run summaries by their run's namespace.
     */
    public static function applyToRunSummaries(
        $query,
        ?string $connection,
        ?string $queue,
        ?NamespaceScope $namespaces = null,
    ): void {
        self::applyValue($query, 'connection', $connection);
        self::applyValue($query, 'queue', $queue);
        ($namespaces ?? NamespaceScope::configured())->applyToRunSummaries($query);
    }

    /**
     * Scope rows that carry no namespace by their run's namespace.
     */
    public static function applyThroughRun(
        $query,
        ?string $connection,
        ?string $queue,
        ?NamespaceScope $namespaces = null,
        string $runColumn = 'workflow_run_id',
    ): void {
        self::applyValue($query, 'connection', $connection);
        self::applyValue($query, 'queue', $queue);
        ($namespaces ?? NamespaceScope::configured())->applyThroughRun($query, $runColumn);
    }

    private static function applyValue($query, string $column, ?string $value): void
    {
        $values = self::normalizeValues($value);

        if ($values === []) {
            return;
        }

        // Tasks that inherit the default connection store null instead of the
        // resolved driver name supplied by a queue worker's Looping event.
        if ($column === 'connection' && in_array(config('queue.default'), $values, true)) {
            $values[] = 'default';
        }

        $query->where(static function ($requestedScope) use ($column, $values): void {
            $nonDefault = array_values(array_filter(
                $values,
                static fn (string $candidate): bool => $candidate !== 'default',
            ));

            if (in_array('default', $values, true)) {
                $requestedScope->where(static function ($defaultScope) use ($column): void {
                    $defaultScope->whereNull($column)
                        ->orWhere($column, '')
                        ->orWhere($column, 'default');
                });

                if ($nonDefault !== []) {
                    self::applyExactValues($requestedScope, $column, $nonDefault, true);
                }

                return;
            }

            self::applyExactValues($requestedScope, $column, $nonDefault, false);
        });
    }

    /**
     * @param list<string> $values
     */
    private static function applyExactValues($query, string $column, array $values, bool $orWhere): void
    {
        if ($values === []) {
            return;
        }

        if (count($values) === 1) {
            if ($orWhere) {
                $query->orWhere($column, $values[0]);
            } else {
                $query->where($column, $values[0]);
            }

            return;
        }

        if ($orWhere) {
            $query->orWhereIn($column, $values);

            return;
        }

        $query->whereIn($column, $values);
    }

    /**
     * @return list<string>
     */
    private static function normalizeValues(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $normalized = array_map(static function (string $candidate): ?string {
            $candidate = trim($candidate);

            return $candidate === '' ? null : $candidate;
        }, explode(',', $value));

        return array_values(array_unique(array_filter(
            $normalized,
            static fn (?string $candidate): bool => $candidate !== null,
        )));
    }
}
