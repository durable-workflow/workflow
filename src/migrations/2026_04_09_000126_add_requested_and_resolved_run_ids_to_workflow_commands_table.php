<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_commands', static function (Blueprint $table): void {
            $table->string('requested_workflow_run_id', 26)
                ->nullable()
                ->after('workflow_run_id')
                ->index();
            $table->string('resolved_workflow_run_id', 26)
                ->nullable()
                ->after('requested_workflow_run_id')
                ->index();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('workflow_commands', static function (Blueprint $table): void {
            $table->dropColumn(['requested_workflow_run_id', 'resolved_workflow_run_id']);
        });
    }
};
