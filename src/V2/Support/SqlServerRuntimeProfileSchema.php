<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Connection;
use Workflow\V2\Exceptions\RuntimeProfileRefusal;

/** @internal SQL Server guard-floor dialect; never a profile admission writer. */
final class SqlServerRuntimeProfileSchema
{
    /**
     * Inspect at most the sentinel's 65 UTF-16 code units. The fixed binary
     * collation counts surrogate halves independently; none is canonical ASCII.
     * DATALENGTH includes trailing spaces, unlike LEN.
     */
    public static function invalidValue(string $column): string
    {
        $positions = implode(', ', array_map(static fn (int $position): string => '(' . $position . ')', range(1, 65)));
        $unit = "COALESCE(UNICODE(SUBSTRING({$column} COLLATE Latin1_General_100_BIN2, positions.n, 1)), -1)";
        $first = "COALESCE(UNICODE(SUBSTRING({$column} COLLATE Latin1_General_100_BIN2, 1, 1)), -1)";

        return "({$column} IS NOT NULL AND (
            DATALENGTH({$column}) < 2 OR DATALENGTH({$column}) > 128
            OR {$first} NOT BETWEEN 97 AND 122
            OR EXISTS (SELECT 1 FROM (VALUES {$positions}) AS positions(n)
                WHERE positions.n <= DATALENGTH({$column}) / 2
                AND NOT ({$unit} BETWEEN 97 AND 122 OR {$unit} BETWEEN 48 AND 57
                    OR {$unit} IN (45, 46, 95)))))";
    }

    /**
     * Translate storage units only after proving the exact ASCII grammar.
     */
    public static function canonicalBytes(string $column): string
    {
        return "CASE WHEN {$column} IS NULL THEN NULL WHEN " . self::invalidValue($column)
            . " THEN -1 ELSE DATALENGTH({$column}) / 2 END";
    }

    public static function assertColumn(
        Connection $connection,
        string $schema,
        string $table,
        bool $allowMissing,
    ): void {
        // CREATE TRIGGER must be the first statement of its batch. Refuse an
        // incompatible session before any DDL rather than altering its options.
        $options = $connection->selectOne(
            "SELECT SESSIONPROPERTY('ANSI_NULLS') AS ansi_nulls, SESSIONPROPERTY('QUOTED_IDENTIFIER') AS quoted_identifier",
            [],
            false,
        );
        if ((int) $options->ansi_nulls !== 1 || (int) $options->quoted_identifier !== 1) {
            throw new RuntimeProfileRefusal('runtime_profile_storage');
        }

        $column = $connection->selectOne(
            'SELECT c.name, c.system_type_id, c.user_type_id, c.max_length, c.is_nullable,
                c.is_computed, c.is_identity, c.generated_always_type, c.default_object_id,
                c.rule_object_id, c.encryption_type, c.is_sparse, c.is_column_set, c.is_hidden
                FROM sys.columns c JOIN sys.tables t ON t.object_id = c.object_id
                JOIN sys.schemas s ON s.schema_id = t.schema_id
                WHERE s.name = ? AND t.name = ? AND c.name = ?',
            [$schema, $table, 'runtime_profile'],
            false,
        );
        if ($column === null && $allowMissing) {
            return;
        }
        // NVARCHAR(65): the final slot is a refusal sentinel, never capacity.
        if ($column === null || $column->name !== 'runtime_profile'
            || (int) $column->system_type_id !== 231 || (int) $column->user_type_id !== 231
            || (int) $column->max_length !== 130 || (int) $column->is_nullable !== 1
            || (int) $column->is_computed !== 0 || (int) $column->is_identity !== 0
            || (int) $column->generated_always_type !== 0 || (int) $column->default_object_id !== 0
            || (int) $column->rule_object_id !== 0 || $column->encryption_type !== null
            || (int) $column->is_sparse !== 0 || (int) $column->is_column_set !== 0
            || (int) $column->is_hidden !== 0) {
            throw new RuntimeProfileRefusal('runtime_profile_storage');
        }
    }

    public static function assertPrimaryKey(Connection $connection, string $schema, string $table, string $key): void
    {
        $rows = $connection->select(
            'SELECT i.name, i.type, i.is_unique, i.is_disabled, i.is_hypothetical,
                i.ignore_dup_key, i.has_filter, i.filter_definition, c.name AS column_name,
                ic.key_ordinal, ic.is_included_column, t.is_memory_optimized, t.temporal_type
                FROM sys.indexes i JOIN sys.tables t ON t.object_id = i.object_id
                JOIN sys.schemas s ON s.schema_id = t.schema_id
                JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
                JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
                WHERE s.name = ? AND t.name = ? AND i.is_primary_key = 1',
            [$schema, $table],
            false,
        );
        if (count($rows) !== 1) {
            throw new RuntimeProfileRefusal('runtime_profile_storage');
        }
        $row = $rows[0];
        if ($row->column_name !== $key || (int) $row->key_ordinal !== 1
            || (int) $row->is_included_column !== 0 || ! in_array((int) $row->type, [1, 2], true)
            || (int) $row->is_unique !== 1 || (int) $row->is_disabled !== 0
            || (int) $row->is_hypothetical !== 0 || (int) $row->ignore_dup_key !== 0
            || (int) $row->has_filter !== 0 || $row->filter_definition !== null
            || (int) $row->is_memory_optimized !== 0 || (int) $row->temporal_type !== 0) {
            throw new RuntimeProfileRefusal('runtime_profile_storage');
        }
    }

    public static function hasIndex(Connection $connection, string $schema, string $table, string $name): bool
    {
        $rows = $connection->select(
            'SELECT i.name, i.type, i.is_unique, i.is_primary_key, i.is_unique_constraint,
                i.is_disabled, i.is_hypothetical, i.ignore_dup_key, i.has_filter, i.filter_definition,
                c.name AS column_name, ic.key_ordinal, ic.is_included_column
                FROM sys.indexes i JOIN sys.tables t ON t.object_id = i.object_id
                JOIN sys.schemas s ON s.schema_id = t.schema_id
                LEFT JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
                LEFT JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
                WHERE s.name = ? AND t.name = ? AND i.name = ?',
            [$schema, $table, $name],
            false,
        );
        if ($rows === []) {
            return false;
        }
        if (count($rows) !== 1) {
            throw new RuntimeProfileRefusal('runtime_profile_storage');
        }
        $row = $rows[0];
        if ($row->name !== $name || $row->column_name !== 'runtime_profile'
            || (int) $row->key_ordinal !== 1 || (int) $row->is_included_column !== 0
            || (int) $row->type !== 2 || (int) $row->is_unique !== 0 || (int) $row->is_primary_key !== 0
            || (int) $row->is_unique_constraint !== 0 || (int) $row->is_disabled !== 0
            || (int) $row->is_hypothetical !== 0 || (int) $row->ignore_dup_key !== 0
            || (int) $row->has_filter !== 0 || $row->filter_definition !== null) {
            throw new RuntimeProfileRefusal('runtime_profile_storage');
        }

        return true;
    }

    public static function hasImmutability(
        Connection $connection,
        string $schema,
        string $table,
        string $name,
        string $operation,
        string $expected,
    ): bool {
        $row = $connection->selectOne(
            'SELECT o.object_id, o.type AS object_type, tr.name, tr.parent_class, tr.is_disabled,
                tr.is_instead_of_trigger, tr.is_not_for_replication,
                ps.name AS parent_schema, t.name AS parent_table,
                m.definition, m.uses_ansi_nulls, m.uses_quoted_identifier, m.is_schema_bound,
                m.is_recompiled, m.execute_as_principal_id, m.uses_native_compilation
                FROM sys.objects o LEFT JOIN sys.triggers tr ON o.object_id = tr.object_id
                JOIN sys.schemas s ON s.schema_id = o.schema_id
                LEFT JOIN sys.tables t ON t.object_id = tr.parent_id
                LEFT JOIN sys.schemas ps ON ps.schema_id = t.schema_id
                LEFT JOIN sys.sql_modules m ON m.object_id = tr.object_id
                WHERE s.name = ? AND o.name = ?',
            [$schema, $name],
            false,
        );
        if ($row === null) {
            return false;
        }
        $events = array_map(
            static fn (object $event): string => $event->type_desc,
            $connection->select(
                'SELECT type_desc FROM sys.trigger_events WHERE object_id = ? ORDER BY type_desc',
                [$row->object_id],
                false,
            ),
        );
        $normalize = RuntimeProfileSqlDefinition::normalize(...);
        if ($row->object_type !== 'TR' || $row->name !== $name || (int) $row->parent_class !== 1
            || $row->parent_schema !== $schema || $row->parent_table !== $table
            || (int) $row->is_disabled !== 0 || (int) $row->is_instead_of_trigger !== 0
            || (int) $row->is_not_for_replication !== 0 || $events !== [strtoupper($operation)]
            || ! is_string($row->definition) || $normalize($row->definition) !== $normalize($expected)
            || (int) $row->uses_ansi_nulls !== 1 || (int) $row->uses_quoted_identifier !== 1
            || (int) $row->is_schema_bound !== 0 || (int) $row->is_recompiled !== 0
            || $row->execute_as_principal_id !== null || (int) $row->uses_native_compilation !== 0) {
            throw new RuntimeProfileRefusal('runtime_profile_storage');
        }

        return true;
    }

    public static function triggerSql(
        Connection $connection,
        string $table,
        string $quotedName,
        string $operation
    ): string {
        $profile = $connection->getQueryGrammar()
            ->wrap('runtime_profile');
        $condition = match ($operation) {
            'insert' => 'EXISTS (SELECT 1 FROM inserted AS i WHERE ' . self::invalidValue('i.' . $profile) . ')',
            'update' => "EXISTS (SELECT 1 FROM deleted WHERE {$profile} IS NOT NULL)
                OR EXISTS (SELECT 1 FROM inserted WHERE {$profile} IS NOT NULL)",
            'delete' => "EXISTS (SELECT 1 FROM deleted WHERE {$profile} IS NOT NULL)",
            default => throw new RuntimeProfileRefusal('runtime_profile_storage'),
        };
        $event = strtoupper($operation);
        $table = $connection->getQueryGrammar()
            ->wrapTable($table);

        // Set-based pseudo tables cover every row, including MERGE actions.
        // An explicit rollback also refuses a caller that catches the error.
        return "CREATE TRIGGER {$quotedName} ON {$table} AFTER {$event} AS
            BEGIN SET NOCOUNT ON;
                IF {$condition} BEGIN
                    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
                    THROW 50001, 'runtime_profile_immutable', 1;
                END;
            END;";
    }
}
