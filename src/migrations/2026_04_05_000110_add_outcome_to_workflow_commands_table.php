<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('workflow_commands') || Schema::hasColumn('workflow_commands', 'outcome')) {
            return;
        }

        Schema::table('workflow_commands', static function (Blueprint $table): void {
            $table->string('outcome')
                ->nullable()
                ->after('status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('workflow_commands') || ! Schema::hasColumn('workflow_commands', 'outcome')) {
            return;
        }

        Schema::table('workflow_commands', static function (Blueprint $table): void {
            $table->dropColumn('outcome');
        });
    }
};
