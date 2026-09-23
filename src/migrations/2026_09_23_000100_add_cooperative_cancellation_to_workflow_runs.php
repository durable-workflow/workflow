<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    public function up(): void
    {
        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->string('cancellation_request_command_id', 26)
                ->nullable()
                ->index();
            $table->timestamp('cancellation_requested_at', 6)
                ->nullable();
            $table->timestamp('cancellation_deadline_at', 6)
                ->nullable();
            $table->unsignedInteger('cancellation_delivery_sequence')
                ->nullable();
            $table->timestamp('cancellation_delivered_at', 6)
                ->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->dropIndex(['cancellation_request_command_id']);
            $table->dropColumn([
                'cancellation_request_command_id',
                'cancellation_requested_at',
                'cancellation_deadline_at',
                'cancellation_delivery_sequence',
                'cancellation_delivered_at',
            ]);
        });
    }
};
