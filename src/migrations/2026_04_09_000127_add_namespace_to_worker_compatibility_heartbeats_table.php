<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_worker_compatibility_heartbeats', static function (Blueprint $table): void {
            $table->string('namespace')
                ->nullable()
                ->index();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('workflow_worker_compatibility_heartbeats', static function (Blueprint $table): void {
            $table->dropIndex(['namespace']);
            $table->dropColumn('namespace');
        });
    }
};
