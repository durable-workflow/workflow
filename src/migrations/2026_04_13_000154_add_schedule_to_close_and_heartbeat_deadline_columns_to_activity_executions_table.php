<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('activity_executions', static function (Blueprint $table): void {
            $table->timestamp('schedule_to_close_deadline_at', 6)
                ->nullable()
                ->after('close_deadline_at');
            $table->timestamp('heartbeat_deadline_at', 6)
                ->nullable()
                ->after('schedule_to_close_deadline_at');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('activity_executions', static function (Blueprint $table): void {
            $table->dropColumn(['schedule_to_close_deadline_at', 'heartbeat_deadline_at']);
        });
    }
};
