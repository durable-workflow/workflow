<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Applies exact-match predicates to typed search-attribute value columns.
 */
final class SearchAttributeValueFilter
{
    /**
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function apply(Builder $query, mixed $value): Builder
    {
        if (is_bool($value)) {
            return $query->where('value_bool', $value);
        }

        if (is_int($value)) {
            return $query->where('value_int', $value);
        }

        if (is_float($value)) {
            return $query->where('value_float', $value);
        }

        if ($value instanceof \DateTimeInterface) {
            return $query->where('value_datetime', $value);
        }

        if (is_string($value) && mb_strlen($value) <= 255) {
            return $query->where(static function (Builder $query) use ($value): void {
                $query->where('value_keyword', $value);
                self::whereKeywordListContains($query, $value, 'or');
            });
        }

        if (is_array($value) && array_is_list($value)) {
            $entries = array_values(array_filter($value, 'is_string'));

            if ($entries === []) {
                return $query->whereRaw('0 = 1');
            }

            foreach ($entries as $entry) {
                self::whereKeywordListContains($query, $entry);
            }

            return $query;
        }

        return $query->where('value_string', $value);
    }

    /**
     * Laravel 9's SQLite grammar cannot compile whereJsonContains(). SQLite's
     * json_each() table function provides the same exact membership predicate.
     *
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     */
    private static function whereKeywordListContains(
        Builder $query,
        string $value,
        string $boolean = 'and',
    ): void {
        if ($query->getQuery()->getConnection()->getDriverName() !== 'sqlite') {
            if ($boolean === 'or') {
                $query->orWhereJsonContains('value_keyword_list', $value);
            } else {
                $query->whereJsonContains('value_keyword_list', $value);
            }

            return;
        }

        $column = $query->getQuery()
            ->getGrammar()
            ->wrap('value_keyword_list');
        $sql = sprintf(
            'exists (select 1 from json_each(%s) as workflow_keyword_values'
            . ' where workflow_keyword_values.value = ?)',
            $column,
        );

        if ($boolean === 'or') {
            $query->orWhereRaw($sql, [$value]);
        } else {
            $query->whereRaw($sql, [$value]);
        }
    }
}
