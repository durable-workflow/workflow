<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRunTimerEntry;

final class MigrationsTest extends TestCase
{
    public function testV2MigrationSetDoesNotShipPreviewBackfillMigrations(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/src/migrations/2026_04_*.php') ?: [];

        $this->assertNotEmpty($files);

        $backfillFiles = array_values(array_filter(
            array_map('basename', $files),
            static fn (string $file): bool => str_contains($file, 'backfill'),
        ));

        $this->assertSame(
            [],
            $backfillFiles,
            'Unreleased v2 migrations must not ship preview-era backfill migrations.',
        );
    }

    public function testDownMethodsDropTables(): void
    {
        $this->assertTrue(Schema::hasTable('workflows'));
        $this->assertTrue(Schema::hasTable('workflow_logs'));
        $this->assertTrue(Schema::hasTable('workflow_signals'));
        $this->assertTrue(Schema::hasTable('workflow_timers'));
        $this->assertTrue(Schema::hasTable('workflow_exceptions'));
        $this->assertTrue(Schema::hasTable('workflow_relationships'));
        $this->assertTrue(Schema::hasTable('workflow_commands'));
        $this->assertTrue(Schema::hasTable('workflow_links'));
        $this->assertTrue(Schema::hasTable('activity_attempts'));
        $this->assertTrue(Schema::hasTable('workflow_signal_records'));
        $this->assertTrue(Schema::hasTable('workflow_run_timeline_entries'));
        $this->assertTrue(Schema::hasTable('workflow_run_timer_entries'));
        $this->assertTrue(Schema::hasTable('workflow_run_lineage_entries'));
        $this->assertTrue(Schema::hasColumn('activity_executions', 'current_attempt_id'));
        $this->assertTrue(Schema::hasColumn('workflow_history_events', 'workflow_command_id'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'sort_timestamp'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'sort_key'));
        $this->assertTrue(Schema::hasColumn('workflow_run_timer_entries', 'schema_version'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'open_wait_id'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'resume_source_kind'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'resume_source_id'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'repair_blocked_reason'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'repair_attention'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'task_problem'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'declared_entry_mode'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'declared_contract_source'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'declared_contract_backfill_needed'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'declared_contract_backfill_available'));
        $this->assertTrue(Schema::hasColumn('workflow_links', 'sequence'));
        $this->assertTrue(Schema::hasColumn('workflow_tasks', 'last_dispatch_attempt_at'));
        $this->assertTrue(Schema::hasColumn('workflow_tasks', 'last_dispatch_error'));
        $this->assertTrue(Schema::hasColumn('workflow_tasks', 'last_claim_failed_at'));
        $this->assertTrue(Schema::hasColumn('workflow_tasks', 'last_claim_error'));
        $this->assertTrue(Schema::hasColumn('workflow_commands', 'requested_workflow_run_id'));
        $this->assertTrue(Schema::hasColumn('workflow_commands', 'resolved_workflow_run_id'));
        $this->assertTrue(Schema::hasColumn('activity_executions', 'parallel_group_path'));
        $this->assertTrue(Schema::hasColumn('workflow_links', 'parallel_group_path'));
        $this->assertTrue(Schema::hasColumn('workflow_instances', 'memo'));
        $this->assertTrue(Schema::hasColumn('workflow_runs', 'memo'));

        $this->artisan('migrate:reset', [
            '--path' => dirname(__DIR__, 3) . '/src/migrations',
            '--realpath' => true,
        ])->run();

        $this->assertFalse(Schema::hasTable('workflows'));
        $this->assertFalse(Schema::hasTable('workflow_logs'));
        $this->assertFalse(Schema::hasTable('workflow_signals'));
        $this->assertFalse(Schema::hasTable('workflow_timers'));
        $this->assertFalse(Schema::hasTable('workflow_exceptions'));
        $this->assertFalse(Schema::hasTable('workflow_relationships'));
        $this->assertFalse(Schema::hasTable('workflow_commands'));
        $this->assertFalse(Schema::hasTable('workflow_links'));
        $this->assertFalse(Schema::hasTable('activity_attempts'));
        $this->assertFalse(Schema::hasTable('workflow_signal_records'));
        $this->assertFalse(Schema::hasTable('workflow_run_timeline_entries'));
        $this->assertFalse(Schema::hasTable('workflow_run_timer_entries'));
        $this->assertFalse(Schema::hasTable('workflow_run_lineage_entries'));
    }

    public function testTimerProjectionRowsDefaultToCurrentSchemaVersion(): void
    {
        /** @var WorkflowRunTimerEntry $entry */
        $entry = WorkflowRunTimerEntry::query()->create([
            'id' => 'migration-default-timer-schema',
            'workflow_run_id' => 'migration-default-run',
            'workflow_instance_id' => 'migration-default-instance',
            'timer_id' => 'migration-default-timer',
            'position' => 0,
            'status' => 'pending',
        ]);

        $this->assertSame(WorkflowRunTimerEntry::CURRENT_SCHEMA_VERSION, $entry->refresh()->schema_version);
        $this->assertTrue($entry->usesCurrentSchema());
    }
}
