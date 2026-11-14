<?php

declare(strict_types=1);

use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    public $withinTransaction = false;

    public function up(): void
    {
        $this->preserveMariaDbScheduledTimestamp(
            'workflow_run_timers',
            'fire_at',
            'workflow_timer_fire_at_legacy_shape_unavailable',
        );
    }

    public function down(): void
    {
        // Older runtime code can use the corrected column. Reinstating an
        // automatic deadline rewrite would corrupt retained timer evidence.
    }
};
