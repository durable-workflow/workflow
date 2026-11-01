<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/** @internal Keeps database namespace comparisons consistent with PHP identity. */
final class NamespaceIdentity
{
    public static function columnsEqual(QueryBuilder $query, string $left, string $right): string
    {
        $left = $query->getGrammar()
            ->wrap($left);
        $right = $query->getGrammar()
            ->wrap($right);
        $comparison = match ($query->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => "CAST({$left} AS BINARY) = CAST({$right} AS BINARY)",
            'sqlite' => "CAST({$left} AS BLOB) = CAST({$right} AS BLOB)",
            'pgsql' => "CAST({$left} AS TEXT) COLLATE \"C\" = CAST({$right} AS TEXT) COLLATE \"C\"",
            'sqlsrv' => "CONVERT(varbinary(max), {$left}) = CONVERT(varbinary(max), {$right})",
            default => "{$left} = {$right}",
        };

        return "({$comparison} OR ({$left} IS NULL AND {$right} IS NULL))";
    }

    /**
     * @template TModel of Model
     * @param Builder<TModel> $query
     * @return Builder<TModel>
     */
    public static function constrain(Builder $query, ?string $namespace): Builder
    {
        $column = $query->getModel()
            ->qualifyColumn('namespace');
        $query->where($column, $namespace);
        if ($namespace === null) {
            return $query;
        }

        // Retain the ordinary indexed predicate and enforce exact identity
        // even when the database's default collation folds case.
        $column = $query->getQuery()
            ->getGrammar()
            ->wrap($column);
        $comparison = match ($query->getQuery()->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => "CAST({$column} AS BINARY) = CAST(? AS BINARY)",
            'sqlite' => "CAST({$column} AS BLOB) = CAST(? AS BLOB)",
            'pgsql' => "CAST({$column} AS TEXT) COLLATE \"C\" = CAST(? AS TEXT) COLLATE \"C\"",
            'sqlsrv' => "CONVERT(varbinary(max), {$column}) = CONVERT(varbinary(max), CAST(? AS nvarchar(max)))",
            default => null,
        };
        if ($comparison !== null) {
            $query->whereRaw($comparison, [$namespace]);
        }

        return $query;
    }
}
