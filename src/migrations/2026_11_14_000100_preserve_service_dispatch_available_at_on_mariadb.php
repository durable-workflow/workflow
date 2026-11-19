<?php

declare(strict_types=1);

use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    public $withinTransaction = false;

    public function up(): void
    {
        $this->preserveMariaDbScheduledTimestamp(
            'workflow_service_call_dispatches',
            'available_at',
            'workflow_service_dispatch_available_at_legacy_shape_unavailable',
        );
    }

    public function down(): void
    {
        // Preserve scheduling evidence rather than restore automatic rewrites.
    }
};
