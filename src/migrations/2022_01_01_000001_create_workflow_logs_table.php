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
        Schema::create('workflow_logs', static function (Blueprint $blueprint): void {
            $blueprint->id('id');
            $blueprint->foreignId('stored_workflow_id')
                ->index();
            $blueprint->unsignedBigInteger('index');
            $blueprint->timestamp('now', 6);
            $blueprint->text('class');
            $blueprint->text('result')
                ->nullable();
            $blueprint->timestamp('created_at', 6)
                ->nullable();
            $blueprint->unique(['stored_workflow_id', 'index']);
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
        Schema::dropIfExists('workflow_logs');
    }
};
