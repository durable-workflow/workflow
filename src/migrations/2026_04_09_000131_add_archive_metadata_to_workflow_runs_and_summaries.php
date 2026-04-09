<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->timestamp('archived_at', 6)
                ->nullable()
                ->after('closed_at')
                ->index();
            $table->string('archive_command_id', 26)
                ->nullable()
                ->after('archived_at')
                ->index();
            $table->string('archive_reason')
                ->nullable()
                ->after('archive_command_id');
        });

        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->timestamp('archived_at', 6)
                ->nullable()
                ->after('closed_at')
                ->index();
            $table->string('archive_command_id', 26)
                ->nullable()
                ->after('archived_at')
                ->index();
            $table->string('archive_reason')
                ->nullable()
                ->after('archive_command_id');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->dropColumn([
                'archived_at',
                'archive_command_id',
                'archive_reason',
            ]);
        });

        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->dropColumn([
                'archived_at',
                'archive_command_id',
                'archive_reason',
            ]);
        });
    }
};
