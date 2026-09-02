<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    public function up(): void
    {
        Schema::create('workflow_links', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('link_type');
            $table->unsignedInteger('sequence')
                ->nullable();
            $table->json('parallel_group_path')
                ->nullable();
            $table->string('parent_workflow_instance_id', 191)
                ->index();
            $table->string('parent_workflow_run_id', 26)
                ->index();
            $table->string('child_workflow_instance_id', 191)
                ->index();
            $table->string('child_workflow_run_id', 26)
                ->index();
            $table->boolean('is_primary_parent')
                ->default(false);
            $table->string('parent_close_policy')
                ->default('abandon');
            $table->timestamps(6);

            $table->unique(
                ['parent_workflow_run_id', 'child_workflow_run_id', 'link_type'],
                'workflow_links_parent_child_type_unique',
            );
            $table->index(
                ['parent_workflow_run_id', 'sequence', 'link_type'],
                'workflow_links_parent_sequence_type_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_links');
    }
};
