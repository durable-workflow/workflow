<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_commands', static function (Blueprint $table): void {
            $table->string('source')
                ->default('php')
                ->index()
                ->after('target_scope');
            $table->json('context')
                ->nullable()
                ->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_commands', static function (Blueprint $table): void {
            $table->dropColumn(['context', 'source']);
        });
    }
};
