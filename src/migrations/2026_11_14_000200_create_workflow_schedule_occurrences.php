<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    private const TABLE = 'workflow_schedule_occurrences';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->withSchemaLockBudget(static function (): void {
            Schema::create(self::TABLE, static function (Blueprint $table): void {
                $table->string('id', 26)
                    ->primary();
                $table->string('workflow_schedule_id', 26);
                $table->string('namespace')
                    ->nullable()
                    ->index();
                $table->string('occurrence_at_utc', 26);
                $table->unique(['workflow_schedule_id', 'occurrence_at_utc'], 'wf_schedule_occurrence_unique');
            });
        });
    }

    public function rollbackRefusal(Connection $connection): ?string
    {
        // Removing receipts after audit compaction could admit a duplicate
        // historical backfill. A rollback needs a restored pre-compaction DB.
        if ($connection->getSchemaBuilder()->hasTable(self::TABLE) && $connection->table(self::TABLE)->exists()) {
            return 'Schedule occurrence receipts must be preserved after audit compaction.';
        }
        return null;
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }
        $this->refuseRetainedRollback();
        Schema::drop(self::TABLE);
    }
};
