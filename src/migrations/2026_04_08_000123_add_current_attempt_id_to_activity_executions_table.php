<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('activity_executions', static function (Blueprint $table): void {
            $table->string('current_attempt_id', 26)
                ->nullable()
                ->after('attempt_count');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('activity_executions', static function (Blueprint $table): void {
            $table->dropColumn('current_attempt_id');
        });
    }
};
