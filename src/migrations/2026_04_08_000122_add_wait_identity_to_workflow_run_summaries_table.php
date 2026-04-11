<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->string('open_wait_id', 191)
                ->nullable()
                ->after('wait_deadline_at');
            $table->string('resume_source_kind')
                ->nullable()
                ->after('open_wait_id');
            $table->string('resume_source_id', 191)
                ->nullable()
                ->after('resume_source_kind');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->dropColumn(['open_wait_id', 'resume_source_kind', 'resume_source_id']);
        });
    }
};
