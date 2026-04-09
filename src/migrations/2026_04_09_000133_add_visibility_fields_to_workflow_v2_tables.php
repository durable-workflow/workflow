<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_instances', static function (Blueprint $table): void {
            $table->string('business_key', 191)
                ->nullable()
                ->index('workflow_instances_business_key_index')
                ->after('workflow_type');
            $table->json('visibility_labels')
                ->nullable()
                ->after('business_key');
        });

        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->string('business_key', 191)
                ->nullable()
                ->index('workflow_runs_business_key_index')
                ->after('workflow_type');
            $table->json('visibility_labels')
                ->nullable()
                ->after('business_key');
        });

        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->string('business_key', 191)
                ->nullable()
                ->index('workflow_run_summaries_business_key_index')
                ->after('workflow_type');
            $table->json('visibility_labels')
                ->nullable()
                ->after('business_key');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->dropIndex('workflow_run_summaries_business_key_index');
            $table->dropColumn(['business_key', 'visibility_labels']);
        });

        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->dropIndex('workflow_runs_business_key_index');
            $table->dropColumn(['business_key', 'visibility_labels']);
        });

        Schema::table('workflow_instances', static function (Blueprint $table): void {
            $table->dropIndex('workflow_instances_business_key_index');
            $table->dropColumn(['business_key', 'visibility_labels']);
        });
    }
};
