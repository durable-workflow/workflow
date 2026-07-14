<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    public function up(): void
    {
        Schema::table('workflow_commands', static function (Blueprint $table): void {
            if (! Schema::hasColumn('workflow_commands', 'request_id')) {
                $table->string('request_id', 191)
                    ->nullable()
                    ->after('context');
                $table->unique(
                    ['workflow_instance_id', 'command_type', 'request_id'],
                    'workflow_commands_request_lookup',
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('workflow_commands', static function (Blueprint $table): void {
            if (Schema::hasColumn('workflow_commands', 'request_id')) {
                $table->dropUnique('workflow_commands_request_lookup');
                $table->dropColumn('request_id');
            }
        });
    }
};
