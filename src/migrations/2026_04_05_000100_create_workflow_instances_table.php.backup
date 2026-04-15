<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_instances', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_class');
            $table->string('workflow_type');
            $table->string('current_run_id', 26)
                ->nullable()
                ->index();
            $table->unsignedInteger('run_count')
                ->default(0);
            $table->timestamp('reserved_at', 6)
                ->nullable();
            $table->timestamp('started_at', 6)
                ->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_instances');
    }
};
