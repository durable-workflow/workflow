<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Depends;
use Tests\Fixtures\InsertDatabaseIsolationProbe;
use Tests\TestCase;

final class DatabaseTruncationTest extends TestCase
{
    public function testCommittedRowsAreVisibleToQueueWorkers(): void
    {
        $now = now();
        $instanceId = 'database-isolation-probe';
        $runId = '01JDBISOLATIONRUN000000001';

        DB::table('workflow_instances')->insert([
            'id' => $instanceId,
            'workflow_class' => self::class,
            'workflow_type' => 'test.database-isolation',
            'run_count' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('workflow_runs')->insert([
            'id' => $runId,
            'workflow_instance_id' => $instanceId,
            'run_number' => 1,
            'workflow_class' => self::class,
            'workflow_type' => 'test.database-isolation',
            'status' => 'running',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('workflow_tasks')->insert([
            'id' => '01JDBISOLATIONTASK0000001',
            'workflow_run_id' => $runId,
            'task_type' => 'workflow',
            'status' => 'ready',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('workflow_updates')->insert([
            'id' => '01JDBISOLATIONUPDATE00001',
            'workflow_command_id' => '01JDBISOLATIONCOMMAND0001',
            'workflow_instance_id' => $instanceId,
            'workflow_run_id' => $runId,
            'update_name' => 'isolation-update',
            'status' => 'accepted',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('workflow_signal_records')->insert([
            'id' => '01JDBISOLATIONSIGNAL00001',
            'workflow_command_id' => '01JDBISOLATIONCOMMAND0002',
            'workflow_instance_id' => $instanceId,
            'workflow_run_id' => $runId,
            'signal_name' => 'isolation-signal',
            'status' => 'received',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $workerEmail = 'queue-worker-isolation@example.test';
        InsertDatabaseIsolationProbe::dispatch($workerEmail)
            ->onConnection('redis')
            ->onQueue('default');

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline && ! DB::table('users')->where('email', $workerEmail)->exists()) {
            usleep(50_000);
        }

        $this->assertTrue(
            DB::table('users')->where('email', $workerEmail)->exists(),
            'The external queue worker did not commit its framework row.',
        );
        $this->assertSame(1, DB::table('workflow_instances')->count());
        $this->assertSame(1, DB::table('workflow_tasks')->count());
        $this->assertSame(1, DB::table('workflow_updates')->count());
        $this->assertSame(1, DB::table('workflow_signal_records')->count());
    }

    #[Depends('testCommittedRowsAreVisibleToQueueWorkers')]
    public function testNextTestRetainsSchemaWithoutCommittedRows(): void
    {
        foreach ([
            'migrations',
            'users',
            'jobs',
            'workflow_instances',
            'workflow_runs',
            'workflow_tasks',
            'workflow_updates',
            'workflow_signal_records',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected [{$table}] to remain migrated.");
        }

        $this->assertGreaterThan(0, DB::table('migrations')->count());

        foreach ([
            'users',
            'jobs',
            'workflow_instances',
            'workflow_runs',
            'workflow_tasks',
            'workflow_updates',
            'workflow_signal_records',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "Expected [{$table}] to be empty.");
        }
    }

    #[Depends('testNextTestRetainsSchemaWithoutCommittedRows')]
    public function testLaterTestStillRetainsFrameworkSchema(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('workflow_instances'));
        $this->assertGreaterThan(0, DB::table('migrations')->count());
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('workflow_instances')->count());
    }
}
