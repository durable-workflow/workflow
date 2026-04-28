<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

final class RequestedTaskScope
{
    public static function apply($query, ?string $connection, ?string $queue): void
    {
        self::applyValue($query, 'connection', $connection);
        self::applyValue($query, 'queue', $queue);
    }

    private static function applyValue($query, string $column, ?string $value): void
    {
        $values = self::normalizeValues($value);

        if ($values === []) {
            return;
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
