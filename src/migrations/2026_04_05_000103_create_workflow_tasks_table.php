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
            $table->string('namespace')
                ->nullable()
                ->index();
            $table->string('task_type');
            $table->string('status');
            $table->string('compatibility')
                ->nullable();
            $table->json('payload')
                ->nullable();
            $table->string('connection')
                ->nullable();
            $table->string('queue')
                ->nullable();
            $table->string('sticky_worker_id')
                ->nullable()
                ->index();
            $table->timestamp('sticky_until', 6)
                ->nullable()
                ->index();
            $table->string('sticky_replay_mode')
                ->nullable()
                ->index();
            $table->timestamp('sticky_claimed_at', 6)
                ->nullable()
                ->index();
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
            $table->timestamp('last_dispatch_attempt_at', 6)
                ->nullable();
            $table->timestamp('last_dispatched_at', 6)
                ->nullable();
            $table->text('last_dispatch_error')
                ->nullable();
            $table->timestamp('last_claim_failed_at', 6)
                ->nullable();
            $table->text('last_claim_error')
                ->nullable();
            $table->unsignedInteger('repair_count')
                ->default(0);
            $table->timestamp('repair_available_at', 6)
                ->nullable();
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
