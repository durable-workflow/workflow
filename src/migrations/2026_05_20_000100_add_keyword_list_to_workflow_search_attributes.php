<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_search_attributes', static function (Blueprint $table): void {
            $table->json('value_keyword_list')
                ->nullable()
                ->after('value_keyword')
                ->comment('For type=keyword_list (ordered exact-match values)');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_search_attributes', static function (Blueprint $table): void {
            $table->dropColumn('value_keyword_list');
        });
    }
};
