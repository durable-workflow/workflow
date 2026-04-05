<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_run_timers', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_run_id', 26)
                ->index();
            $table->unsignedInteger('sequence');
            $table->string('status');
            $table->unsignedBigInteger('delay_seconds');
            $table->timestamp('fire_at', 6);
            $table->timestamp('fired_at', 6)
                ->nullable();
            $table->timestamps(6);

            $table->unique(['workflow_run_id', 'sequence']);
            $table->index(['status', 'fire_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_run_timers');
    }
};
