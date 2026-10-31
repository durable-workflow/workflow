<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @internal Keeps database namespace comparisons consistent with PHP identity. */
final class NamespaceIdentity
{
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
