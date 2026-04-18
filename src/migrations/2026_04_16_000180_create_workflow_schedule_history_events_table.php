<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    private const TABLE = 'workflow_schedule_history_events';

    private const WORKFLOW_SCHEDULE_INDEX = 'wf_schedule_history_workflow_schedule_idx';

    private const SCHEDULE_INDEX = 'wf_schedule_history_schedule_idx';

    private const NAMESPACE_INDEX = 'wf_schedule_history_namespace_idx';

    private const WORKFLOW_INSTANCE_INDEX = 'wf_schedule_history_instance_idx';

    private const WORKFLOW_RUN_INDEX = 'wf_schedule_history_run_idx';

    private const SCHEDULE_SEQUENCE_UNIQUE = 'wf_schedule_history_schedule_sequence_unique';

    private const NAMESPACE_SCHEDULE_INDEX = 'wf_schedule_history_namespace_schedule_idx';

    private const EVENT_RECORDED_INDEX = 'wf_schedule_history_event_recorded_idx';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            if (Schema::hasColumns(self::TABLE, [
                'id',
                'workflow_schedule_id',
                'schedule_id',
                'sequence',
                'event_type',
                'payload',
                'recorded_at',
            ])) {
                $this->ensureExpectedIndexes();

                return;
            }

            throw new RuntimeException(
                self::TABLE . ' already exists but is missing expected schedule-history columns.'
            );
        }

        Schema::create(self::TABLE, static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_schedule_id', 26)
                ->index(self::WORKFLOW_SCHEDULE_INDEX);
            $table->string('schedule_id', 255)
                ->index(self::SCHEDULE_INDEX);
            $table->string('namespace', 255)
                ->nullable()
                ->index(self::NAMESPACE_INDEX);
            $table->unsignedInteger('sequence');
            $table->string('event_type');
            $table->json('payload')
                ->nullable();
            $table->string('workflow_instance_id', 191)
                ->nullable()
                ->index(self::WORKFLOW_INSTANCE_INDEX);
            $table->string('workflow_run_id', 26)
                ->nullable()
                ->index(self::WORKFLOW_RUN_INDEX);
            $table->timestamp('recorded_at', 6)
                ->nullable();
            $table->timestamps(6);

            $table->unique(['workflow_schedule_id', 'sequence'], self::SCHEDULE_SEQUENCE_UNIQUE);
            $table->index(['namespace', 'schedule_id'], self::NAMESPACE_SCHEDULE_INDEX);
            $table->index(['event_type', 'recorded_at'], self::EVENT_RECORDED_INDEX);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function ensureExpectedIndexes(): void
    {
        $this->ensureIndex(['workflow_schedule_id'], self::WORKFLOW_SCHEDULE_INDEX);
        $this->ensureIndex(['schedule_id'], self::SCHEDULE_INDEX);
        $this->ensureIndex(['namespace'], self::NAMESPACE_INDEX);
        $this->ensureIndex(['workflow_instance_id'], self::WORKFLOW_INSTANCE_INDEX);
        $this->ensureIndex(['workflow_run_id'], self::WORKFLOW_RUN_INDEX);
        $this->ensureIndex(['workflow_schedule_id', 'sequence'], self::SCHEDULE_SEQUENCE_UNIQUE, unique: true);
        $this->ensureIndex(['namespace', 'schedule_id'], self::NAMESPACE_SCHEDULE_INDEX);
        $this->ensureIndex(['event_type', 'recorded_at'], self::EVENT_RECORDED_INDEX);
    }

    /**
     * @param array<int, string> $columns
     */
    private function ensureIndex(array $columns, string $name, bool $unique = false): void
    {
        if ($this->hasExpectedIndex($columns, $unique)) {
            return;
        }

        Schema::table(self::TABLE, static function (Blueprint $table) use ($columns, $name, $unique): void {
            if ($unique) {
                $table->unique($columns, $name);

                return;
            }

            $table->index($columns, $name);
        });
    }

    /**
     * @param array<int, string> $columns
     */
    private function hasExpectedIndex(array $columns, bool $unique): bool
    {
        $schema = Schema::getFacadeRoot();

        if (is_object($schema) && method_exists($schema, 'hasIndex')) {
            return Schema::hasIndex(self::TABLE, $columns, $unique ? 'unique' : null);
        }

        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => $this->hasMysqlIndex($columns, $unique),
            'pgsql' => $this->hasPostgresIndex($columns, $unique),
            'sqlite' => $this->hasSqliteIndex($columns, $unique),
            'sqlsrv' => $this->hasSqlServerIndex($columns, $unique),
            default => false,
        };
    }

    /**
     * @param array<int, string> $columns
     */
    private function hasMysqlIndex(array $columns, bool $unique): bool
    {
        return $this->indexRowsMatch(
            DB::select(
                <<<'SQL'
                select index_name, (non_unique = 0) as is_unique, column_name
                from information_schema.statistics
                where table_schema = ? and table_name = ?
                order by index_name, seq_in_index
                SQL
                ,
                [DB::connection()->getDatabaseName(), self::TABLE]
            ),
            $columns,
            $unique
        );
    }

    /**
     * @param array<int, string> $columns
     */
    private function hasPostgresIndex(array $columns, bool $unique): bool
    {
        return $this->indexRowsMatch(
            DB::select(
                <<<'SQL'
                select i.relname as index_name, ix.indisunique as is_unique, a.attname as column_name
                from pg_class t
                join pg_index ix on t.oid = ix.indrelid
                join pg_class i on i.oid = ix.indexrelid
                join unnest(ix.indkey) with ordinality as ord(attnum, ordinality) on true
                join pg_attribute a on a.attrelid = t.oid and a.attnum = ord.attnum
                where t.relname = ?
                order by i.relname, ord.ordinality
                SQL
                ,
                [self::TABLE]
            ),
            $columns,
            $unique
        );
    }

    /**
     * @param array<int, string> $columns
     */
    private function hasSqliteIndex(array $columns, bool $unique): bool
    {
        $indexes = [];

        foreach (DB::select('pragma index_list(' . $this->sqliteIdentifier(self::TABLE) . ')') as $index) {
            $indexName = (string) $index->name;

            $indexes[] = [
                'unique' => $this->truthy($index->unique ?? false),
                'columns' => array_map(
                    static fn (object $column): string => (string) $column->name,
                    DB::select('pragma index_info(' . $this->sqliteIdentifier($indexName) . ')')
                ),
            ];
        }

        return $this->indexesMatch($indexes, $columns, $unique);
    }

    /**
     * @param array<int, string> $columns
     */
    private function hasSqlServerIndex(array $columns, bool $unique): bool
    {
        return $this->indexRowsMatch(
            DB::select(
                <<<'SQL'
                select i.name as index_name, i.is_unique as is_unique, c.name as column_name
                from sys.indexes i
                join sys.index_columns ic on i.object_id = ic.object_id and i.index_id = ic.index_id
                join sys.columns c on ic.object_id = c.object_id and ic.column_id = c.column_id
                where i.object_id = object_id(?)
                order by i.name, ic.key_ordinal
                SQL
                ,
                [self::TABLE]
            ),
            $columns,
            $unique
        );
    }

    /**
     * @param array<int, object> $rows
     * @param array<int, string> $columns
     */
    private function indexRowsMatch(array $rows, array $columns, bool $unique): bool
    {
        $indexes = [];

        foreach ($rows as $row) {
            $indexName = (string) $row->index_name;
            $indexes[$indexName]['unique'] ??= $this->truthy($row->is_unique ?? false);
            $indexes[$indexName]['columns'][] = (string) $row->column_name;
        }

        return $this->indexesMatch(array_values($indexes), $columns, $unique);
    }

    /**
     * @param array<int, array{unique: bool, columns: array<int, string>}> $indexes
     * @param array<int, string> $columns
     */
    private function indexesMatch(array $indexes, array $columns, bool $unique): bool
    {
        foreach ($indexes as $index) {
            if ($index['columns'] === $columns && (! $unique || $index['unique'])) {
                return true;
            }
        }

        return false;
    }

    private function sqliteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function truthy(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 't'
            || $value === 'true';
    }
};
