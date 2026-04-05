<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_failures', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_run_id', 26)
                ->index();
            $table->string('source_kind');
            $table->string('source_id');
            $table->string('propagation_kind');
            $table->boolean('handled')
                ->default(false);
            $table->string('exception_class');
            $table->text('message');
            $table->text('file');
            $table->unsignedInteger('line')
                ->nullable();
            $table->longText('trace_preview')
                ->nullable();
            $table->timestamps(6);

            $table->index(['source_kind', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_failures');
    }
};
