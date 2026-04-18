<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        if (
            ! Schema::hasTable('workflow_run_summaries')
            || Schema::hasColumn('workflow_run_summaries', 'memo')
        ) {
            return;
        }

        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->json('memo')
                ->nullable();
        });
    }

    public function down(): void
    {
        // Intentionally no-op: fresh 2.0 installs create this column in the
        // base workflow_run_summaries migration, and rollback must not remove it.
    }
};
