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
        Schema::create('workflow_relationships', static function (Blueprint $blueprint): void {
            $blueprint->id('id');
            $blueprint->foreignId('parent_workflow_id')
                ->nullable()
                ->index();
            $blueprint->unsignedBigInteger('parent_index');
            $blueprint->timestamp('parent_now');
            $blueprint->foreignId('child_workflow_id')
                ->nullable()
                ->index();
            $blueprint->foreign('parent_workflow_id')
                ->references('id')
                ->on('workflows');
            $blueprint->foreign('child_workflow_id')
                ->references('id')
                ->on('workflows');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_relationships');
    }
};
