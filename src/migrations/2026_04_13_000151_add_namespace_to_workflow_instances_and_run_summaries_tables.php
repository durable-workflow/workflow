<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_instances', static function (Blueprint $table): void {
            $table->string('namespace')
                ->nullable()
                ->after('workflow_type')
                ->index();
        });

        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->string('namespace')
                ->nullable()
                ->after('workflow_type')
                ->index();
        });

        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->string('namespace')
                ->nullable()
                ->after('workflow_type')
                ->index();
        });

        Schema::table('workflow_tasks', static function (Blueprint $table): void {
            $table->string('namespace')
                ->nullable()
                ->after('workflow_run_id')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_instances', static function (Blueprint $table): void {
            $table->dropIndex(['namespace']);
            $table->dropColumn('namespace');
        });

        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->dropIndex(['namespace']);
            $table->dropColumn('namespace');
        });

        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->dropIndex(['namespace']);
            $table->dropColumn('namespace');
        });

        Schema::table('workflow_tasks', static function (Blueprint $table): void {
            $table->dropIndex(['namespace']);
            $table->dropColumn('namespace');
        });
    }
};
