<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_history_events', static function (Blueprint $table): void {
            $table->string('workflow_command_id', 26)
                ->nullable()
                ->after('workflow_task_id')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_history_events', static function (Blueprint $table): void {
            $table->dropIndex(['workflow_command_id']);
            $table->dropColumn('workflow_command_id');
        });
    }
};
