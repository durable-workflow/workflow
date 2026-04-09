<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workflow_timers', static function (Blueprint $blueprint): void {
            $blueprint->id('id');
            $blueprint->foreignId('stored_workflow_id')
                ->index();
            $blueprint->integer('index');
            $blueprint->timestamp('stop_at', 6);
            $blueprint->timestamp('created_at', 6)
                ->nullable();
            $blueprint->index(['stored_workflow_id', 'created_at']);
            $blueprint->foreign('stored_workflow_id')
                ->references('id')
                ->on('workflows');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_timers');
    }
};
