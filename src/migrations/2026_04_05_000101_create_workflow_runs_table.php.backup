<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_runs', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_instance_id', 26)
                ->index();
            $table->unsignedInteger('run_number');
            $table->string('workflow_class');
            $table->string('workflow_type');
            $table->string('status');
            $table->string('closed_reason')
                ->nullable();
            $table->string('compatibility')
                ->nullable();
            $table->string('payload_codec')
                ->nullable();
            $table->longText('arguments')
                ->nullable();
            $table->longText('output')
                ->nullable();
            $table->string('connection')
                ->nullable();
            $table->string('queue')
                ->nullable();
            $table->unsignedInteger('last_history_sequence')
                ->default(0);
            $table->timestamp('started_at', 6)
                ->nullable();
            $table->timestamp('closed_at', 6)
                ->nullable();
            $table->timestamp('last_progress_at', 6)
                ->nullable();
            $table->timestamps(6);

            $table->unique(['workflow_instance_id', 'run_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_runs');
    }
};
