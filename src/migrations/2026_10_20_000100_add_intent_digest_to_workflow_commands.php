<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

// Additive column only. No rows are rewritten, so a rolling upgrade keeps
// serving while this runs; receipts recorded before it carry no digest.
return new class() extends WorkflowMigration {
    public function up(): void
    {
        Schema::table('workflow_commands', static function (Blueprint $table): void {
            if (! Schema::hasColumn('workflow_commands', 'intent_digest')) {
                $table->string('intent_digest', 80)
                    ->nullable()
                    ->after('request_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workflow_commands', static function (Blueprint $table): void {
            if (Schema::hasColumn('workflow_commands', 'intent_digest')) {
                $table->dropColumn('intent_digest');
            }
        });
    }
};
