<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    private const TABLE = 'activity_executions';

    private const COLUMN = 'last_heartbeat_checkpoint';

    public $withinTransaction = false;

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        if ($schema->hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        $this->withSchemaLockBudget(static fn () => $schema->table(
            self::TABLE,
            static fn (Blueprint $table) => $table->longText(self::COLUMN)->nullable(),
        ));
    }

    public function rollbackRefusal(Connection $connection): ?string
    {
        return self::populatedColumnRefusal(
            $connection,
            self::TABLE,
            self::COLUMN,
            'Rolling back activity heartbeat checkpoints would discard acknowledged activity state.',
        );
    }

    public function down(): void
    {
        $this->dropColumnUnlessRetained(self::TABLE, self::COLUMN);
    }
};
