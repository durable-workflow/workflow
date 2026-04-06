<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_links', static function (Blueprint $table): void {
            $table->unsignedInteger('sequence')
                ->nullable()
                ->after('link_type');

            $table->index(
                ['parent_workflow_run_id', 'sequence', 'link_type'],
                'workflow_links_parent_sequence_type_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('workflow_links', static function (Blueprint $table): void {
            $table->dropIndex('workflow_links_parent_sequence_type_index');
            $table->dropColumn('sequence');
        });
    }
};
