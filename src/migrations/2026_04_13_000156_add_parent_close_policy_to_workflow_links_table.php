<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_links', static function (Blueprint $table): void {
            $table->string('parent_close_policy')->default('abandon')->after('is_primary_parent');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_links', static function (Blueprint $table): void {
            $table->dropColumn('parent_close_policy');
        });
    }
};
