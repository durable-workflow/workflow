<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_commands', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_instance_id', 26)
                ->nullable()
                ->index();
            $table->string('workflow_run_id', 26)
                ->nullable()
                ->index();
            $table->string('command_type')
                ->index();
            $table->string('target_scope')
                ->default('instance');
            $table->string('status')
                ->index();
            $table->string('outcome')
                ->nullable();
            $table->string('workflow_class')
                ->nullable();
            $table->string('workflow_type')
                ->nullable();
            $table->string('payload_codec')
                ->nullable();
            $table->longText('payload')
                ->nullable();
            $table->string('rejection_reason')
                ->nullable();
            $table->timestamp('accepted_at', 6)
                ->nullable();
            $table->timestamp('applied_at', 6)
                ->nullable();
            $table->timestamp('rejected_at', 6)
                ->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_commands');
    }
};
