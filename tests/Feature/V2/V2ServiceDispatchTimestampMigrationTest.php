<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\SchemaTestCase;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Models\WorkflowServiceCallDispatch;

final class V2ServiceDispatchTimestampMigrationTest extends SchemaTestCase
{
    public function testEnqueueMetadataUpdatesPreserveTheScheduledAvailability(): void
    {
        $dispatch = $this->dispatch();
        $dispatch->update([
            'dispatch_attempted_at' => now(),
            'repair_available_at' => now()
                ->addSeconds(5),
        ]);
        $this->assertSame('2026-10-11 00:00:00.123456', $dispatch->fresh()->available_at->format('Y-m-d H:i:s.u'));
    }

    public function testUpgradePreservesValuesAndAllOtherMetadataAndDoesNotRestoreAutomaticUpdates(): void
    {
        $connection = DB::connection();
        if (! $connection instanceof MySqlConnection || ! $connection->isMaria()) {
            $this->markTestSkipped('MariaDB-specific automatic timestamp correction.');
        }
        $table = $connection->getQueryGrammar()
            ->wrapTable('workflow_service_call_dispatches');
        $connection->statement(
            "ALTER TABLE {$table} MODIFY available_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)"
        );
        $dispatch = $this->dispatch();
        $before = $dispatch->fresh()
            ->getRawOriginal();
        $metadata = $this->columnMetadata();
        $this->migration()
            ->up();
        $after = $this->columnMetadata();
        $this->assertSame('on update current_timestamp(6)', $metadata['extra']);
        $this->assertSame('', $after['extra']);
        unset($metadata['extra'], $after['extra']);
        $this->assertSame($metadata, $after);
        $this->assertSame($before, $dispatch->fresh()->getRawOriginal());
        $this->migration()
            ->up();
        $this->migration()
            ->down();
        $dispatch->update([
            'dispatch_attempted_at' => now(),
        ]);
        $this->assertSame('2026-10-11 00:00:00.123456', $dispatch->fresh()->available_at->format('Y-m-d H:i:s.u'));
    }

    public function testConfiguredConnectionAndPrefixSelectOnlyTheOwnedDispatchTable(): void
    {
        $connection = DB::connection();
        if (! $connection instanceof MySqlConnection || ! $connection->isMaria()) {
            $this->markTestSkipped('MariaDB-specific automatic timestamp correction.');
        }
        $original = config('workflows.storage.connection');
        config([
            'database.connections.dispatch_timestamp' => array_replace($connection->getConfig(), [
                'prefix' => 'timestamp_',
            ]),
        ]);
        $other = DB::connection('dispatch_timestamp');
        $table = $other->getQueryGrammar()
            ->wrapTable('workflow_service_call_dispatches');
        $before = Schema::getColumns('workflow_service_call_dispatches');
        try {
            $other->statement(
                "CREATE TABLE {$table} (id INT PRIMARY KEY, state VARCHAR(32), available_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6))"
            );
            $other->insert("INSERT INTO {$table} VALUES (1, 'scheduled', '2026-10-11 00:00:00.123456')");
            config([
                'workflows.storage.connection' => 'dispatch_timestamp',
            ]);
            $this->migration()
                ->up();
            $other->update("UPDATE {$table} SET state = 'claimed' WHERE id = 1");
            $this->assertSame(
                '2026-10-11 00:00:00.123456',
                $other->selectOne("SELECT available_at FROM {$table} WHERE id = 1")
->available_at
            );
            $this->assertSame($before, Schema::getColumns('workflow_service_call_dispatches'));
        } finally {
            config([
                'workflows.storage.connection' => $original,
            ]);
            $other->statement("DROP TABLE IF EXISTS {$table}");
            DB::purge('dispatch_timestamp');
        }
    }

    private function dispatch(): WorkflowServiceCallDispatch
    {
        return WorkflowServiceCallDispatch::query()->create([
            'service_call_id' => (string) Str::ulid(),
            'state' => 'scheduled',
            'dispatch_options' => [],
            'attempt' => 1,
            'max_attempts' => 1,
            'available_at' => '2026-10-11 00:00:00.123456',
            'claim_generation' => 0,
        ]);
    }

    private function columnMetadata(): array
    {
        $connection = DB::connection();

        return (array) $connection->selectOne(
            'SELECT COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS default_value, '
            . 'DATETIME_PRECISION AS datetime_precision, EXTRA AS extra FROM information_schema.columns '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$connection->getTablePrefix() . 'workflow_service_call_dispatches', 'available_at'],
        );
    }

    private function migration(): WorkflowMigration
    {
        return require dirname(__DIR__, 3)
            . '/src/migrations/2026_11_14_000100_preserve_service_dispatch_available_at_on_mariadb.php';
    }
}
