<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_instances', static function (Blueprint $table): void {
            $table->unsignedInteger('last_message_sequence')
                ->default(0)
                ->after('run_count');
        });

        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->unsignedInteger('message_cursor_position')
                ->default(0)
                ->after('last_command_sequence');
        });

        Schema::table('workflow_commands', static function (Blueprint $table): void {
            $table->unsignedInteger('message_sequence')
                ->nullable()
                ->after('command_sequence');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('workflow_instances', static function (Blueprint $table): void {
            $table->dropColumn('last_message_sequence');
        });

        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->dropColumn('message_cursor_position');
        });

        Schema::table('workflow_commands', static function (Blueprint $table): void {
            $table->dropColumn('message_sequence');
        });
    }
};
