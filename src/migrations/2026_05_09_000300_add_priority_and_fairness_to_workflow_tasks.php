<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    public function up(): void
    {
        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('priority')
                ->default(5)
                ->after('queue');
            $table->string('fairness_key', 64)
                ->nullable()
                ->after('priority');
            $table->unsignedSmallInteger('fairness_weight')
                ->default(1)
                ->after('fairness_key');
        });

        Schema::table('workflow_tasks', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('priority')
                ->default(5)
                ->after('queue');
            $table->string('fairness_key', 64)
                ->nullable()
                ->after('priority');
            $table->unsignedSmallInteger('fairness_weight')
                ->default(1)
                ->after('fairness_key');

            $table->index(
                ['queue', 'status', 'priority', 'available_at'],
                'workflow_tasks_dispatch_order_index',
            );
            $table->index(
                ['queue', 'status', 'fairness_key'],
                'workflow_tasks_fairness_class_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('workflow_tasks', static function (Blueprint $table): void {
            $table->dropIndex('workflow_tasks_dispatch_order_index');
            $table->dropIndex('workflow_tasks_fairness_class_index');
            $table->dropColumn(['priority', 'fairness_key', 'fairness_weight']);
        });

        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->dropColumn(['priority', 'fairness_key', 'fairness_weight']);
        });
    }
};
