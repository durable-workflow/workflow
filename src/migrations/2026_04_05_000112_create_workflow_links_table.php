<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_links', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('link_type');
            $table->string('parent_workflow_instance_id', 26)
                ->index();
            $table->string('parent_workflow_run_id', 26)
                ->index();
            $table->string('child_workflow_instance_id', 26)
                ->index();
            $table->string('child_workflow_run_id', 26)
                ->index();
            $table->boolean('is_primary_parent')
                ->default(false);
            $table->timestamps(6);

            $table->unique(
                ['parent_workflow_run_id', 'child_workflow_run_id', 'link_type'],
                'workflow_links_parent_child_type_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_links');
    }
};
