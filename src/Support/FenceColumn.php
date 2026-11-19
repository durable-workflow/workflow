<?php

declare(strict_types=1);

namespace Workflow\Support;

use Illuminate\Database\Connection;

/**
 * Migration steps for one nullable workflow_instances JSON column whose non-null
 * values are permanent fences. The using WorkflowMigration defines COLUMN and
 * REFUSAL; rollback refuses while any row holds a fence.
 */
trait FenceColumn
{
    public function up(): void
    {
        $this->addNullableJsonColumn('workflow_instances', static::COLUMN);
    }

    public function rollbackRefusal(Connection $connection): ?string
    {
        return self::populatedColumnRefusal($connection, 'workflow_instances', static::COLUMN, static::REFUSAL);
    }

    public function down(): void
    {
        $this->dropColumnUnlessRetained('workflow_instances', static::COLUMN);
    }
}
