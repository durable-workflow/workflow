<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MigrationsTest extends TestCase
{
    public function testDownMethodsDropTables(): void
    {
        $this->assertTrue(Schema::hasTable('workflows'));
        $this->assertTrue(Schema::hasTable('workflow_logs'));
        $this->assertTrue(Schema::hasTable('workflow_signals'));
        $this->assertTrue(Schema::hasTable('workflow_timers'));
        $this->assertTrue(Schema::hasTable('workflow_exceptions'));
        $this->assertTrue(Schema::hasTable('workflow_relationships'));
        $this->assertTrue(Schema::hasTable('workflow_commands'));
        $this->assertTrue(Schema::hasColumn('workflow_history_events', 'workflow_command_id'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'sort_timestamp'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'sort_key'));

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
    }
}
