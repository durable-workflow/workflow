<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    public function up(): void
    {
        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->string('import_source', 64)
                ->nullable()
                ->index();
            $table->string('import_id', 64)
                ->nullable()
                ->index();
            $table->string('import_dedupe_key', 191)
                ->nullable()
                ->index();
            $table->unsignedSmallInteger('import_contract_version')
                ->nullable();
            $table->timestamp('imported_at', 6)
                ->nullable()
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->dropIndex(['import_source']);
            $table->dropIndex(['import_id']);
            $table->dropIndex(['import_dedupe_key']);
            $table->dropIndex(['imported_at']);
            $table->dropColumn([
                'import_source',
                'import_id',
                'import_dedupe_key',
                'import_contract_version',
                'imported_at',
            ]);
        });
    }
};
