<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_failures', static function (Blueprint $table): void {
            $table->boolean('non_retryable')
                ->default(false)
                ->after('failure_category');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_failures', static function (Blueprint $table): void {
            $table->dropColumn('non_retryable');
        });
    }
};
