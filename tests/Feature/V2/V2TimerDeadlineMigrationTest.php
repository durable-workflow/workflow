<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\SchemaTestCase;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\WorkflowTimer;

final class V2TimerDeadlineMigrationTest extends SchemaTestCase
{
    public function testStatusChangesPreserveTheExactScheduledInstant(): void
    {
        $timer = WorkflowTimer::query()->create([
            'workflow_run_id' => (string) Str::ulid(),
            'sequence' => 1,
            'status' => TimerStatus::Pending,
            'delay_seconds' => 20,
            'fire_at' => '2026-11-08 04:00:20.234567',
        ]);
        foreach ([TimerStatus::Cancelled, TimerStatus::Fired] as $status) {
            $timer->update([
                'status' => $status,
            ]);
            $this->assertSame('2026-11-08 04:00:20.234567', $timer->fresh()->fire_at->format('Y-m-d H:i:s.u'));
        }
    }

    public function testItPreservesStoredValuesAndCanRunAgainWithoutRestoringAutomaticUpdates(): void
    {
        $connection = DB::connection();
        if (! $connection instanceof MySqlConnection || ! $connection->isMaria()) {
            $before = Schema::getColumns('workflow_run_timers');
            $this->migration()
                ->up();
            $this->migration()
                ->down();
            $this->assertSame($before, Schema::getColumns('workflow_run_timers'));
            return;
        }

        $table = $connection->getQueryGrammar()
            ->wrapTable('workflow_run_timers');
        $connection->statement(
            "ALTER TABLE {$table} MODIFY fire_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)",
        );
        $id = (string) Str::ulid();
        DB::table('workflow_run_timers')->insert([
            'id' => $id,
            'workflow_run_id' => (string) Str::ulid(),
            'sequence' => 1,
            'status' => 'pending',
            'delay_seconds' => 20,
            'fire_at' => '2026-11-08 04:00:20.234567',
        ]);
        DB::table('workflow_run_timers')->where('id', $id)->update([
            'status' => 'cancelled',
        ]);
        $drifted = DB::table('workflow_run_timers')->where('id', $id)->value('fire_at');
        $this->assertNotSame('2026-11-08 04:00:20.234567', $drifted);
        $migration = $this->migration();
        $this->assertFalse($migration->withinTransaction);
        $lockBudget = $connection->selectOne('SELECT @@SESSION.lock_wait_timeout AS value')
->value;
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame($lockBudget, $connection->selectOne('SELECT @@SESSION.lock_wait_timeout AS value')->value);
        $this->assertSame($drifted, DB::table('workflow_run_timers')->where('id', $id)->value('fire_at'));
        DB::table('workflow_run_timers')->where('id', $id)->update([
            'status' => 'fired',
        ]);
        $this->assertSame($drifted, DB::table('workflow_run_timers')->where('id', $id)->value('fire_at'));
    }

    public function testItUsesTheConfiguredConnectionAndItsTablePrefix(): void
    {
        $connection = DB::connection();
        if (! $connection instanceof MySqlConnection || ! $connection->isMaria()) {
            $this->markTestSkipped('MariaDB-specific timestamp schema correction.');
        }

        $original = config('workflows.storage.connection');
        config([
            'database.connections.timer_deadline_migration' => [
                ...$connection->getConfig(),
                'prefix' => 'timer_deadline_',
            ],
        ]);
        $other = DB::connection('timer_deadline_migration');
        $table = $other->getQueryGrammar()
            ->wrapTable('workflow_run_timers');
        try {
            $other->statement(
                "CREATE TABLE {$table} (id INT PRIMARY KEY, status VARCHAR(20), fire_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6))"
            );
            $other->insert("INSERT INTO {$table} VALUES (1, 'pending', '2026-11-08 04:00:20.234567')");
            $before = Schema::getColumns('workflow_run_timers');
            config([
                'workflows.storage.connection' => 'timer_deadline_migration',
            ]);
            $this->migration()
                ->up();
            $other->update("UPDATE {$table} SET status = 'cancelled' WHERE id = 1");
            $this->assertSame(
                '2026-11-08 04:00:20.234567',
                $other->selectOne("SELECT fire_at FROM {$table} WHERE id = 1")->fire_at
            );
            $this->assertSame($before, Schema::getColumns('workflow_run_timers'));
        } finally {
            config([
                'workflows.storage.connection' => $original,
            ]);
            $other->statement("DROP TABLE IF EXISTS {$table}");
            DB::purge('timer_deadline_migration');
        }
    }

    private function migration(): WorkflowMigration
    {
        return require dirname(
            __DIR__,
            3
        ) . '/src/migrations/2026_11_13_000200_preserve_workflow_timer_fire_at_on_mariadb.php';
    }
}
