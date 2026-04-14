<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_instances', static function (Blueprint $table): void {
            $table->json('memo')
                ->nullable()
                ->after('visibility_labels');
        });

        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->json('memo')
                ->nullable()
                ->after('visibility_labels');
        });

        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->json('memo')
                ->nullable()
                ->after('visibility_labels');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->dropColumn('memo');
        });

        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->dropColumn('memo');
        });

        Schema::table('workflow_instances', static function (Blueprint $table): void {
            $table->dropColumn('memo');
        });
    }
};
