<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->string('liveness_state')
                ->nullable()
                ->after('next_task_at');
            $table->text('liveness_reason')
                ->nullable()
                ->after('liveness_state');
            $table->string('next_task_id', 26)
                ->nullable()
                ->after('liveness_reason');
            $table->string('next_task_type')
                ->nullable()
                ->after('next_task_id');
            $table->string('next_task_status')
                ->nullable()
                ->after('next_task_type');
            $table->timestamp('next_task_lease_expires_at', 6)
                ->nullable()
                ->after('next_task_status');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->dropColumn([
                'liveness_state',
                'liveness_reason',
                'next_task_id',
                'next_task_type',
                'next_task_status',
                'next_task_lease_expires_at',
            ]);
        });
    }
};
