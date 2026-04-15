<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_history_events', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_run_id', 26)
                ->index();
            $table->unsignedInteger('sequence');
            $table->string('event_type');
            $table->json('payload')
                ->nullable();
            $table->string('workflow_task_id', 26)
                ->nullable()
                ->index();
            $table->timestamp('recorded_at', 6)
                ->nullable();
            $table->timestamps(6);

            $table->unique(['workflow_run_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_history_events');
    }
};
