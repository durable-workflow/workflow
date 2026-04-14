<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('workflow_run_summaries', 'memo')) {
            return;
        }

        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->json('memo')
                ->nullable()
                ->after('visibility_labels');
        });
    }

    public function down(): void
    {
    }
};
