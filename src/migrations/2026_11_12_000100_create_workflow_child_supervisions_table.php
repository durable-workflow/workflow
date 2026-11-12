<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

// Which run of a continue-as-new chain supervises a child that an earlier run
// started and left open (#868). Each row names the original child link, so the
// child link, child call and its parent run, sequence and policy stay as
// recorded. Insert-only at continue-as-new, so the transfer never locks rows a
// closing child holds.
return new class() extends WorkflowMigration {
    private const TABLE = 'workflow_child_supervisions';

    public function up(): void
    {
        Schema::create(self::TABLE, static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('supervising_workflow_run_id', 26)
                ->index();
            $table->string('transferred_from_workflow_run_id', 26);
            $table->string('workflow_link_id', 26)
                ->index();
            $table->string('parent_workflow_instance_id', 191);
            $table->string('parent_workflow_run_id', 26);
            $table->unsignedInteger('sequence')
                ->nullable();
            $table->string('child_workflow_instance_id', 191)
                ->index();
            $table->string('child_workflow_run_id', 26);
            $table->string('parent_close_policy')
                ->default('abandon');
            $table->timestamps(6);

            $table->unique(
                ['supervising_workflow_run_id', 'workflow_link_id'],
                'workflow_child_supervisions_run_link_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
