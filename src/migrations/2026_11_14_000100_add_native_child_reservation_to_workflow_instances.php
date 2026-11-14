<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasColumn('workflow_instances', 'native_child_reservation')) {
            $this->withSchemaLockBudget(static function (): void {
                Schema::table('workflow_instances', static function (Blueprint $table): void {
                    $table->json('native_child_reservation')
                        ->nullable();
                });
            });
        }
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('workflow_instances')->whereNotNull(
            'native_child_reservation'
        )->exists()) {
            throw new LogicException('Native child reservation fences cannot be discarded by a schema rollback.');
        }
        Schema::table('workflow_instances', static function (Blueprint $table): void {
            $table->dropColumn('native_child_reservation');
        });
    }
};
