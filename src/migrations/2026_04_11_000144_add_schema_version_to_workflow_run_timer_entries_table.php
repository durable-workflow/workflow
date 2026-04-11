<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\V2\Models\WorkflowRunTimerEntry;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_run_timer_entries', function (Blueprint $table): void {
            $table->unsignedSmallInteger('schema_version')
                ->default(WorkflowRunTimerEntry::LEGACY_SCHEMA_VERSION)
                ->after('timer_id');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('workflow_run_timer_entries', function (Blueprint $table): void {
            $table->dropColumn('schema_version');
        });
    }
};
