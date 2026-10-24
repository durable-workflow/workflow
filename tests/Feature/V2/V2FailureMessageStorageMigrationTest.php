<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Models\WorkflowFailure;

final class V2FailureMessageStorageMigrationTest extends TestCase
{
    public function testUpgradeIsAdditiveAndPackageRollbackRetainsCanonicalEvidence(): void
    {
        $legacy = $this->legacyRow();
        $migration = $this->migration();
        $migration->up();
        $connection = Schema::getConnection();
        $row = $connection->table('workflow_failures')->where('id', $legacy['id'])->first();
        $this->assertNull($row->message_full);
        unset($row->message_full);
        $this->assertSame($legacy, (array) $row);

        $message = str_repeat("\0界🦀", 10_000);
        $failure = WorkflowFailure::query()->findOrFail($legacy['id']);
        $failure->message = $message;
        $failure->save();
        $this->assertSame($message, $failure->fresh()->message);

        $migration->down();
        $this->assertTrue(Schema::hasColumn('workflow_failures', 'message_full'));
        $this->assertSame($message, $failure->fresh()->message);
        $migration->up();
        $this->assertSame($message, $failure->fresh()->message);
        $this->assertSame(1, WorkflowFailure::query()->count());
    }

    public function testHeldWriterBoundsMetadataWaitAndRestoresConnectionPolicy(): void
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->markTestSkipped('Network database metadata-lock control.');
        }

        $legacy = $this->legacyRow();
        $timeoutQuery = $driver === 'pgsql' ? 'SHOW lock_timeout' : 'SELECT @@SESSION.lock_wait_timeout AS value';
        $previous = (array) $connection->selectOne($timeoutQuery);
        $blockerName = 'failure_message_metadata_blocker';
        config()->set('database.connections.' . $blockerName, $connection->getConfig());
        DB::purge($blockerName);
        $blocker = DB::connection($blockerName);
        $blocker->beginTransaction();
        $blocker->table('workflow_failures')->where('id', $legacy['id'])->lockForUpdate()->first();
        $migration = $this->migration();
        $started = microtime(true);

        try {
            try {
                $migration->up();
                $this->fail('The held writer must prevent an unbounded metadata upgrade.');
            } catch (QueryException) {
                $elapsed = microtime(true) - $started;
                $this->assertGreaterThanOrEqual(0.8, $elapsed);
                $this->assertLessThan(3.0, $elapsed);
                $this->assertSame($previous, (array) $connection->selectOne($timeoutQuery));
                $this->assertFalse(Schema::hasColumn('workflow_failures', 'message_full'));
                $this->assertSame($legacy, (array) $connection->table('workflow_failures')->first());
            }
        } finally {
            $blocker->rollBack();
            DB::purge($blockerName);
            $migration->up();
        }
    }

    public function testUnsupportedMySqlLayoutNeverFallsBackToCopyingTheTable(): void
    {
        $connection = Schema::getConnection();
        if ($connection->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL compressed-layout control.');
        }

        $version = (array) $connection->selectOne('SELECT VERSION() AS version');
        if (str_contains($version['version'], 'MariaDB')) {
            $this->markTestSkipped('MariaDB has a separate instant-DDL support contract.');
        }

        $legacy = $this->legacyRow();
        $table = $connection->getQueryGrammar()->wrapTable('workflow_failures');
        // This is disposable fixture preparation, not a rolling-upgrade operation.
        $connection->statement("ALTER TABLE {$table} ROW_FORMAT=COMPRESSED");
        $migration = $this->migration();

        try {
            try {
                $migration->up();
                $this->fail('The unsupported instant operation must fail without a copying fallback.');
            } catch (QueryException $error) {
                $this->assertContains($error->errorInfo[1], [1845, 1846]);
                $this->assertFalse(Schema::hasColumn('workflow_failures', 'message_full'));
                $this->assertSame($legacy, (array) $connection->table('workflow_failures')->first());
            }
        } finally {
            $connection->statement("ALTER TABLE {$table} ROW_FORMAT=DYNAMIC");
            $migration->up();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyRow(): array
    {
        Schema::table('workflow_failures', static function (Blueprint $table): void {
            $table->dropColumn('message_full');
        });
        $connection = Schema::getConnection();
        $connection->table('workflow_failures')->insert([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => (string) Str::ulid(),
            'source_kind' => 'activity_execution',
            'source_id' => 'original-attempt',
            'propagation_kind' => 'activity',
            'failure_category' => null,
            'non_retryable' => false,
            'handled' => false,
            'exception_class' => 'RuntimeException',
            'message' => 'unchanged legacy message',
            'file' => '/fixture/activity.php',
            'line' => 7,
            'trace_preview' => null,
            'created_at' => '2026-10-23 23:00:00',
            'updated_at' => '2026-10-23 23:00:00',
        ]);

        return (array) $connection->table('workflow_failures')->first();
    }

    private function migration(): WorkflowMigration
    {
        return require __DIR__ . '/../../../src/migrations/2026_10_23_000100_add_full_failure_message_storage.php';
    }
}
