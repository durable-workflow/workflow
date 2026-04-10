<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_run_lineage_entries', static function (Blueprint $table): void {
            $table->string('id', 64)
                ->primary();
            $table->string('workflow_run_id', 26)
                ->index();
            $table->string('workflow_instance_id', 191)
                ->index();
            $table->string('direction')
                ->index();
            $table->string('lineage_id', 191);
            $table->unsignedInteger('position');
            $table->string('link_type')
                ->index();
            $table->string('child_call_id', 26)
                ->nullable()
                ->index();
            $table->unsignedInteger('sequence')
                ->nullable()
                ->index();
            $table->boolean('is_primary_parent')
                ->default(false)
                ->index();
            $table->string('related_workflow_instance_id', 191)
                ->nullable()
                ->index();
            $table->string('related_workflow_run_id', 26)
                ->nullable()
                ->index();
            $table->unsignedInteger('related_run_number')
                ->nullable();
            $table->string('related_workflow_type', 191)
                ->nullable()
                ->index();
            $table->string('related_workflow_class')
                ->nullable();
            $table->string('status')
                ->nullable()
                ->index();
            $table->string('status_bucket')
                ->nullable()
                ->index();
            $table->string('closed_reason')
                ->nullable()
                ->index();
            $table->timestamp('linked_at', 6)
                ->nullable()
                ->index();
            $table->json('payload')
                ->nullable();
            $table->timestamps(6);

            $table->unique(
                ['workflow_run_id', 'direction', 'lineage_id'],
                'workflow_run_lineage_run_direction_lineage_unique',
            );
            $table->index(
                ['workflow_run_id', 'direction', 'position'],
                'workflow_run_lineage_run_direction_position_index',
            );
            $table->index(
                ['workflow_instance_id', 'direction', 'link_type'],
                'workflow_run_lineage_instance_direction_type_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_run_lineage_entries');
    }
};
