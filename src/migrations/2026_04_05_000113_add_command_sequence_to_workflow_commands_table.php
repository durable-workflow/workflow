<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_commands', static function (Blueprint $table): void {
            $table->unsignedInteger('command_sequence')
                ->nullable()
                ->index();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('workflow_commands', static function (Blueprint $table): void {
            $table->dropIndex('workflow_commands_command_sequence_index');
            $table->dropColumn('command_sequence');
        });
    }
};
