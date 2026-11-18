<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    private const TABLE = 'workflow_instances';

    private const COLUMN = 'native_root_reservation';

    public $withinTransaction = false;

    public function up(): void
    {
        $this->addNullableJsonColumn(self::TABLE, self::COLUMN);
    }

    public function rollbackRefusal(Connection $connection): ?string
    {
        return self::populatedColumnRefusal(
            $connection,
            self::TABLE,
            self::COLUMN,
            'Native root reservation fences cannot be discarded by a schema rollback.',
        );
    }

    public function down(): void
    {
        $this->dropColumnUnlessRetained(self::TABLE, self::COLUMN);
    }
};
