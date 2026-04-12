<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_instances', static function (Blueprint $table): void {
            $table->unsignedInteger('execution_timeout_seconds')
                ->nullable()
                ->after('memo');
        });

        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->unsignedInteger('run_timeout_seconds')
                ->nullable()
                ->after('search_attributes');
            $table->timestamp('execution_deadline_at', 6)
                ->nullable()
                ->after('run_timeout_seconds');
            $table->timestamp('run_deadline_at', 6)
                ->nullable()
                ->after('execution_deadline_at');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('workflow_instances', static function (Blueprint $table): void {
            $table->dropColumn('execution_timeout_seconds');
        });

        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->dropColumn(['run_timeout_seconds', 'execution_deadline_at', 'run_deadline_at']);
        });
    }
};
