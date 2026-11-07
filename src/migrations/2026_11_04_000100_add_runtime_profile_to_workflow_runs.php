<?php

declare(strict_types=1);

use Workflow\Support\WorkflowMigration;
use Workflow\V2\Support\RuntimeProfileSchema;

return new class() extends WorkflowMigration {
    public function up(): void
    {
        RuntimeProfileSchema::up($this->getConnection());
    }

    public function down(): void
    {
        RuntimeProfileSchema::down($this->getConnection());
    }
};
