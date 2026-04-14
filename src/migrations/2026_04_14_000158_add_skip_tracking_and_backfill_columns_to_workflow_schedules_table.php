<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE workflow_schedules ALTER COLUMN latest_workflow_instance_id TYPE varchar(191)');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE workflow_schedules MODIFY latest_workflow_instance_id varchar(191) NULL');
        } elseif ($driver === 'sqlite') {
            // SQLite does not enforce varchar length; no-op.
        }

        Schema::table('workflow_schedules', static function (Blueprint $table): void {
            $table->string('last_skip_reason', 64)->nullable();
            $table->timestamp('last_skipped_at', 6)->nullable();
            $table->unsignedBigInteger('skipped_trigger_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('workflow_schedules', static function (Blueprint $table): void {
            $table->dropColumn(['last_skip_reason', 'last_skipped_at', 'skipped_trigger_count']);
        });
    }
};
