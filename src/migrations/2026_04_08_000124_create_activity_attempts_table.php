<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('activity_attempts', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_run_id', 26)
                ->index();
            $table->string('activity_execution_id', 26)
                ->index();
            $table->string('workflow_task_id', 26)
                ->nullable()
                ->index();
            $table->unsignedInteger('attempt_number');
            $table->string('status');
            $table->string('lease_owner')
                ->nullable();
            $table->timestamp('started_at', 6);
            $table->timestamp('last_heartbeat_at', 6)
                ->nullable();
            $table->timestamp('lease_expires_at', 6)
                ->nullable();
            $table->timestamp('closed_at', 6)
                ->nullable();
            $table->timestamps(6);

            $table->unique(['activity_execution_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_attempts');
    }
};
