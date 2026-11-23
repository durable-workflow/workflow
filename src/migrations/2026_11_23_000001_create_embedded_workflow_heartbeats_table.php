<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    public function up(): void
    {
        Schema::connection($this->getConnection())->create(
            'workflow_embedded_workflow_heartbeats',
            static function (Blueprint $table): void {
                $table->string('scope_key', 64);
                $table->ulid('worker_id');
                $table->json('definition');
                $table->dateTime('expires_at', 6)
                    ->index();

                $table->primary(['scope_key', 'worker_id']);
                $table->index(['scope_key', 'expires_at'], 'workflow_embedded_heartbeat_admission');
                $table->index('worker_id');
            }
        );
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('workflow_embedded_workflow_heartbeats');
    }
};
