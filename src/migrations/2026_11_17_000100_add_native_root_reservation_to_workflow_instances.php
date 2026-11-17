<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasColumn('workflow_instances', 'native_root_reservation')) {
            $this->withSchemaLockBudget(static function (): void {
                Schema::table('workflow_instances', static function (Blueprint $table): void {
                    $table->json('native_root_reservation')
                        ->nullable();
                });
            });
        }
    }

    public function down(): void
    {
        if (DB::table('workflow_instances')->whereNotNull('native_root_reservation')->exists()) {
            throw new LogicException('Native root reservation fences cannot be discarded by a schema rollback.');
        }
        Schema::table('workflow_instances', static function (Blueprint $table): void {
            $table->dropColumn('native_root_reservation');
        });
    }
};
