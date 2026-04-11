<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->string('declared_entry_mode')
                ->nullable()
                ->after('compatibility')
                ->index('wfrs_decl_entry_mode_idx');
            $table->string('declared_contract_source')
                ->nullable()
                ->after('declared_entry_mode')
                ->index('wfrs_decl_contract_source_idx');
            $table->boolean('declared_contract_backfill_needed')
                ->default(false)
                ->after('declared_contract_source')
                ->index('wfrs_decl_contract_backfill_needed_idx');
            $table->boolean('declared_contract_backfill_available')
                ->default(false)
                ->after('declared_contract_backfill_needed')
                ->index('wfrs_decl_contract_backfill_available_idx');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->dropIndex('wfrs_decl_entry_mode_idx');
            $table->dropIndex('wfrs_decl_contract_source_idx');
            $table->dropIndex('wfrs_decl_contract_backfill_needed_idx');
            $table->dropIndex('wfrs_decl_contract_backfill_available_idx');
            $table->dropColumn([
                'declared_entry_mode',
                'declared_contract_source',
                'declared_contract_backfill_needed',
                'declared_contract_backfill_available',
            ]);
        });
    }
};
