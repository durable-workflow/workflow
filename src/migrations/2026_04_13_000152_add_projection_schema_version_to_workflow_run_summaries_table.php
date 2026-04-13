<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->unsignedSmallInteger('projection_schema_version')
                ->nullable()
                ->after('engine_source')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->dropIndex(['projection_schema_version']);
            $table->dropColumn('projection_schema_version');
        });
    }
};
