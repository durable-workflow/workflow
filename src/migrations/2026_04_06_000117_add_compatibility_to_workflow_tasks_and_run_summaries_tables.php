<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_tasks', static function (Blueprint $table): void {
            $table->string('compatibility')
                ->nullable()
                ->after('queue');
        });

        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->string('compatibility')
                ->nullable()
                ->after('workflow_type');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->dropColumn('compatibility');
        });

        Schema::table('workflow_tasks', static function (Blueprint $table): void {
            $table->dropColumn('compatibility');
        });
    }
};
