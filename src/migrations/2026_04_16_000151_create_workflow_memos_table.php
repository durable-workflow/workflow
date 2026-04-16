<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_memos', static function (Blueprint $table): void {
            $table->id();
            $table->string('workflow_run_id', 26);
            $table->string('workflow_instance_id', 191);

            // Memo identity
            $table->string('key', 191);

            // Memo value - JSON-friendly, no codec protection
            // Memos are operator-visible metadata, not secret-bearing payloads
            $table->json('value');

            // Metadata
            $table->unsignedInteger('upserted_at_sequence');
            $table->boolean('inherited_from_parent')->default(false);

            $table->timestamps(6);

            // Unique constraint: one memo value per (run, key)
            $table->unique(['workflow_run_id', 'key'], 'workflow_memos_run_key_unique');

            // Instance-level query support (not for filtering, for detail/describe)
            $table->index(['workflow_instance_id', 'key'], 'workflow_memos_instance_key');

            // Foreign key cascade
            $table->foreign('workflow_run_id')
                ->references('id')
                ->on('workflow_runs')
                ->onDelete('cascade');

            // Note: NO indexes on value columns
            // Memos are returned-only metadata, excluded from filtering by contract
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_memos');
    }
};
