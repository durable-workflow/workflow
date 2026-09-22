<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workflows', static function (Blueprint $blueprint): void {
            $blueprint->id('id');
            $blueprint->text('class');
            $blueprint->text('arguments')
                ->nullable();
            $blueprint->text('output')
                ->nullable();
            $blueprint->string('status')
                ->default('pending')
                ->index();
            $blueprint->timestamps(6);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflows');
    }
};
