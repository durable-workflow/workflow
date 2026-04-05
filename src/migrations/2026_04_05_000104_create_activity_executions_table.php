<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('activity_executions', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_run_id', 26)
                ->index();
            $table->unsignedInteger('sequence');
            $table->string('activity_class');
            $table->string('activity_type');
            $table->string('status');
            $table->longText('arguments')
                ->nullable();
            $table->longText('result')
                ->nullable();
            $table->longText('exception')
                ->nullable();
            $table->string('connection')
                ->nullable();
            $table->string('queue')
                ->nullable();
            $table->unsignedInteger('attempt_count')
                ->default(1);
            $table->timestamp('started_at', 6)
                ->nullable();
            $table->timestamp('closed_at', 6)
                ->nullable();
            $table->timestamp('last_heartbeat_at', 6)
                ->nullable();
            $table->timestamps(6);

            $table->unique(['workflow_run_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_executions');
    }
};
