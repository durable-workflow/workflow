<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_tasks', static function (Blueprint $table): void {
            $table->timestamp('last_dispatch_attempt_at', 6)
                ->nullable()
                ->after('attempt_count');
            $table->text('last_dispatch_error')
                ->nullable()
                ->after('last_dispatched_at');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('workflow_tasks', static function (Blueprint $table): void {
            $table->dropColumn([
                'last_dispatch_attempt_at',
                'last_dispatch_error',
            ]);
        });
    }
};
