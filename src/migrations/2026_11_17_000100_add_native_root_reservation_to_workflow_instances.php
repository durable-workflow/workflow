<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
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

    public function rollbackRefusal(Connection $connection): ?string
    {
        if ($connection->getSchemaBuilder()->hasColumn('workflow_instances', 'native_root_reservation')
            && $connection->table('workflow_instances')
                ->whereNotNull('native_root_reservation')
                ->exists()) {
            return 'Native root reservation fences cannot be discarded by a schema rollback.';
        }
        return null;
    }

    public function down(): void
    {
        $this->refuseRetainedRollback();
        Schema::table('workflow_instances', static function (Blueprint $table): void {
            $table->dropColumn('native_root_reservation');
        });
    }
};
