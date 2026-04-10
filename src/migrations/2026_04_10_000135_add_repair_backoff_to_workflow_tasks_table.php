<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_tasks', static function (Blueprint $table): void {
            $table->timestamp('repair_available_at', 6)
                ->nullable()
                ->after('repair_count');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('workflow_tasks', static function (Blueprint $table): void {
            $table->dropColumn('repair_available_at');
        });
    }
};
