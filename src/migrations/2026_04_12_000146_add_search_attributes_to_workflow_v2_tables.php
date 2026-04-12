<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->json('search_attributes')
                ->nullable()
                ->after('memo');
        });

        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->json('search_attributes')
                ->nullable()
                ->after('memo');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->dropColumn('search_attributes');
        });

        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->dropColumn('search_attributes');
        });
    }
};
