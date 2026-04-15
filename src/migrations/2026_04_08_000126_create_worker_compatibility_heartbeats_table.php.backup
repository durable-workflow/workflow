<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_worker_compatibility_heartbeats', static function (Blueprint $table): void {
            $table->id();
            $table->string('worker_id');
            $table->string('scope_key', 64);
            $table->string('host')
                ->nullable();
            $table->string('process_id')
                ->nullable();
            $table->string('connection')
                ->nullable()
                ->index();
            $table->string('queue')
                ->nullable()
                ->index();
            $table->json('supported');
            $table->timestamp('recorded_at', 6);
            $table->timestamp('expires_at', 6)
                ->index();
            $table->timestamps(6);

            $table->unique(['worker_id', 'scope_key'], 'workflow_worker_compatibility_heartbeats_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_worker_compatibility_heartbeats');
    }
};
