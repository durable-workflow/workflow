<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('activity_executions', static function (Blueprint $table): void {
            $table->json('parallel_group_path')
                ->nullable()
                ->after('retry_policy');
        });

        Schema::table('workflow_links', static function (Blueprint $table): void {
            $table->json('parallel_group_path')
                ->nullable()
                ->after('sequence');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('activity_executions', static function (Blueprint $table): void {
            $table->dropColumn('parallel_group_path');
        });

        Schema::table('workflow_links', static function (Blueprint $table): void {
            $table->dropColumn('parallel_group_path');
        });
    }
};
