<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_tasks', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_run_id', 26)
                ->index();
            $table->string('task_type');
            $table->string('status');
            $table->json('payload')
                ->nullable();
            $table->string('connection')
                ->nullable();
            $table->string('queue')
                ->nullable();
            $table->timestamp('available_at', 6)
                ->nullable();
            $table->timestamp('leased_at', 6)
                ->nullable();
            $table->string('lease_owner')
                ->nullable();
            $table->timestamp('lease_expires_at', 6)
                ->nullable();
            $table->unsignedInteger('attempt_count')
                ->default(0);
            $table->timestamp('last_dispatched_at', 6)
                ->nullable();
            $table->unsignedInteger('repair_count')
                ->default(0);
            $table->text('last_error')
                ->nullable();
            $table->timestamps(6);

            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_tasks');
    }
};
