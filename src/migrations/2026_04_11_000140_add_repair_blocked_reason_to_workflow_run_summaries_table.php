<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->string('repair_blocked_reason')
                ->nullable()
                ->after('liveness_reason')
                ->index('workflow_run_summaries_repair_blocked_reason_index');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->dropIndex('workflow_run_summaries_repair_blocked_reason_index');
            $table->dropColumn('repair_blocked_reason');
        });
    }
};
