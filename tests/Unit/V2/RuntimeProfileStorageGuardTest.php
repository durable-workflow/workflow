<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\SchemaTestCase;
use Tests\Support\RuntimeProfileRetainedIdentityControls;
use Workflow\V2\Exceptions\RuntimeProfileRefusal;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\RuntimeProfileGuard;
use Workflow\V2\Support\RuntimeProfileSchema;

final class RuntimeProfileStorageGuardTest extends SchemaTestCase
{
    use RuntimeProfileRetainedIdentityControls;

    protected function tearDown(): void
    {
        config()->set('workflows.v2.run_model', WorkflowRun::class);
        DB::purge('profile_guard_storage');
        DB::purge('profile_guard_alias');
        parent::tearDown();
    }

    #[DataProvider('invalidAuthorityShapes')]
    public function testUnverifiableAuthorityRefusesBeforeAnyDdl(string $shape): void
    {
        $this->configuredStorage();
        $storage = DB::connection('profile_guard_storage');
        $schema = $storage->getSchemaBuilder();
        $schema->drop('guard_runs');
        $schema->create('guard_runs', static function (Blueprint $table) use ($shape): void {
            $table->string('id');
            $table->string('workflow_instance_id');
            if ($shape !== 'missing namespace') {
                $table->string('namespace')
                    ->nullable();
            }
            if ($shape === 'composite primary') {
                $table->primary(['id', 'workflow_instance_id']);
            } elseif ($shape !== 'missing primary') {
                $table->primary('id');
            }
        });
        $before = $this->schemaObjects();
        $target = $storage->select('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name');
        try {
            RuntimeProfileSchema::up(null);
            $this->fail('Incomplete authority must refuse before expanding any target.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_storage', $error->reason);
        }
        $this->assertSame($before, $this->schemaObjects());
        $this->assertEquals(
            $target,
            $storage->select('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name')
        );
    }

    public static function invalidAuthorityShapes(): iterable
    {
        foreach (['missing primary', 'composite primary', 'missing namespace'] as $shape) {
            yield $shape => [$shape];
        }
    }

    public function testMigrationAndRefusalUseTheConfiguredTableAndConnection(): void
    {
        $this->configuredStorage();
        RuntimeProfileSchema::up(null);
        $storage = DB::connection('profile_guard_storage');
        $this->assertTrue($storage->getSchemaBuilder()->hasColumn('guard_runs', 'runtime_profile'));
        $this->assertTrue(Schema::hasColumn('workflow_runs', 'runtime_profile'));
        $storage->table('guard_runs')
            ->insert([
                'id' => 'guard-configured',
                'workflow_instance_id' => 'instance',
                'namespace' => 'guard',
            ]);
        $legacy = RuntimeProfileConfiguredRun::query()->findOrFail('guard-configured');
        RuntimeProfileGuard::assertRun($legacy);
        $this->assertNull($legacy->getRawOriginal('runtime_profile'));
        try {
            $storage->table('guard_runs')
                ->where('id', 'guard-configured')
                ->update([
                    'runtime_profile' => 'future',
                ]);
            $this->fail('The configured table must also be immutable.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('runtime_profile_immutable', $error->getMessage());
        }
        $storage->table('guard_runs')
            ->insert([
                'id' => 'guard-protected',
                'workflow_instance_id' => 'instance',
                'namespace' => 'guard',
                'runtime_profile' => 'future',
            ]);
        $this->expectException(RuntimeProfileRefusal::class);
        RuntimeProfileConfiguredRun::query()->findOrFail('guard-protected');
    }

    public function testDownPreflightsEveryStorageBeforeDroppingTheDefaultColumn(): void
    {
        $this->configuredStorage();
        RuntimeProfileSchema::up(null);
        DB::connection('profile_guard_storage')->table('guard_runs')->insert([
            'id' => 'guard-protected',
            'workflow_instance_id' => 'instance',
            'namespace' => 'guard',
            'runtime_profile' => 'future',
        ]);
        try {
            RuntimeProfileSchema::down(null);
            $this->fail('A later configured table must be checked before the first DDL.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_storage', $error->reason);
        }
        $this->assertTrue(Schema::hasColumn('workflow_runs', 'runtime_profile'));
        $this->assertTrue(
            DB::connection('profile_guard_storage')->getSchemaBuilder()->hasColumn('guard_runs', 'runtime_profile')
        );
        $this->assertSame(
            'future',
            DB::connection('profile_guard_storage')->table('guard_runs')->value('runtime_profile')
        );
    }

    public function testMissingConfiguredColumnIsAHealthFailureRatherThanLegacyNull(): void
    {
        $this->configuredStorage();
        DB::connection('profile_guard_storage')->table('guard_runs')->insert([
            'id' => 'guard-missing',
            'workflow_instance_id' => 'instance',
            'namespace' => 'guard',
        ]);
        try {
            RuntimeProfileConfiguredRun::query()->findOrFail('guard-missing');
            $this->fail('Omitted storage cannot manufacture a legacy profile.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_storage', $error->reason);
        }
        $this->assertFalse(
            DB::connection('profile_guard_storage')->getSchemaBuilder()->hasColumn('guard_runs', 'runtime_profile')
        );
    }

    public function testWrongDefaultTableObjectCannotStandInForConfiguredAuthority(): void
    {
        $this->configuredStorage();
        RuntimeProfileSchema::up(null);
        $wrong = new WorkflowRun();
        $wrong->setRawAttributes([
            'id' => 'guard-wrong',
            'runtime_profile' => null,
        ], true);
        $this->expectException(RuntimeProfileRefusal::class);
        RuntimeProfileGuard::assertRun($wrong);
    }

    public function testHealthyNullOnlyDownMigrationCanBeReapplied(): void
    {
        RuntimeProfileSchema::down(null);
        $this->assertFalse(Schema::hasColumn('workflow_runs', 'runtime_profile'));
        RuntimeProfileSchema::up(null);
        RuntimeProfileSchema::up(null);
        $this->assertTrue(Schema::hasColumn('workflow_runs', 'runtime_profile'));
    }

    #[DataProvider('migrationDirections')]
    public function testMissingConfiguredTableRefusesBeforeAnyDdl(string $direction): void
    {
        $this->configuredStorage();
        RuntimeProfileSchema::up(null);
        DB::connection('profile_guard_storage')->getSchemaBuilder()->drop('guard_runs');
        $before = $this->schemaObjects();
        try {
            RuntimeProfileSchema::{$direction}(null);
            $this->fail('Missing configured inventory must not be skipped.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_storage', $error->reason);
        }
        $this->assertSame($before, $this->schemaObjects());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function migrationDirections(): iterable
    {
        yield 'up' => ['up'];
        yield 'down' => ['down'];
    }

    public function testAliasesForOnePhysicalTableAreDroppedOnlyOnce(): void
    {
        $configuration = config('database.connections.' . config('database.default'));
        if (($configuration['driver'] ?? null) !== 'sqlite' || ($configuration['database'] ?? null) === ':memory:') {
            $this->markTestSkipped('This control requires the file-backed SQLite harness.');
        }
        config()
            ->set('database.connections.profile_guard_alias', $configuration);
        config()
            ->set('workflows.v2.run_model', RuntimeProfileAliasRun::class);
        RuntimeProfileSchema::up(null);
        RuntimeProfileSchema::down(null);
        $this->assertFalse(Schema::hasColumn('workflow_runs', 'runtime_profile'));
        $this->assertFalse(
            DB::connection('profile_guard_alias')->getSchemaBuilder()->hasColumn('workflow_runs', 'runtime_profile')
        );
        RuntimeProfileSchema::up(null);
        $this->assertTrue(Schema::hasColumn('workflow_runs', 'runtime_profile'));
    }

    public function testIndependentTargetsRefuseDownWithoutAnyDdl(): void
    {
        $this->configuredStorage();
        RuntimeProfileSchema::up(null);
        $before = $this->schemaObjects();
        $other = DB::connection('profile_guard_storage')->select(
            'SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name'
        );
        try {
            RuntimeProfileSchema::down(null);
            $this->fail('Independent targets cannot share an atomic rollback.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_storage', $error->reason);
        }
        $this->assertSame($before, $this->schemaObjects());
        $this->assertEquals(
            $other,
            DB::connection(
                'profile_guard_storage'
            )->select('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name')
        );
    }

    #[DataProvider('ddlFailureSteps')]
    public function testTransactionalDownRestoresEveryObjectAfterInjectedDdlFailure(string $statement): void
    {
        $before = $this->schemaObjects();
        $injected = false;
        DB::listen(static function ($event) use ($statement, &$injected): void {
            if (! $injected && str_starts_with(strtolower($event->sql), $statement)) {
                $injected = true;
                throw new RuntimeException('Injected DDL failure after execution.');
            }
        });
        try {
            RuntimeProfileSchema::down(null);
            $this->fail('The injected DDL failure must escape.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Injected DDL failure', $error->getMessage());
        }
        $this->assertTrue($injected);
        $this->assertSame($before, $this->schemaObjects());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ddlFailureSteps(): iterable
    {
        yield 'trigger' => ['drop trigger'];
        yield 'index' => ['drop index'];
        yield 'column' => ['alter table'];
    }

    #[DataProvider('wrongColumnDefinitions')]
    public function testPreexistingWrongColumnShapeIsRefusedWithoutDdl(string $definition): void
    {
        RuntimeProfileSchema::down(null);
        DB::statement('ALTER TABLE workflow_runs ADD COLUMN runtime_profile ' . $definition);
        $before = $this->schemaObjects();
        try {
            RuntimeProfileSchema::up(null);
            $this->fail('A preexisting wrong column must not be adopted.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_storage', $error->reason);
        }
        $this->assertSame($before, $this->schemaObjects());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrongColumnDefinitions(): iterable
    {
        yield 'type' => ['TEXT NULL'];
        yield 'nullability' => ['VARCHAR NOT NULL'];
        yield 'default' => ["VARCHAR NULL DEFAULT 'future'"];
        yield 'length' => ['VARCHAR(32) NULL'];
    }

    public function testWrongBodyTriggerIsRefusedWithoutReplacingIt(): void
    {
        $name = 'wf_runtime_profile_' . substr(hash('sha256', 'workflow_runs'), 0, 20);
        DB::statement('DROP TRIGGER "' . $name . '"');
        DB::unprepared(
            'CREATE TRIGGER "' . $name . '" BEFORE UPDATE OF runtime_profile ON workflow_runs BEGIN SELECT 1; END'
        );
        $before = $this->schemaObjects();
        try {
            RuntimeProfileSchema::up(null);
            $this->fail('A trigger name does not prove its body.');
        } catch (RuntimeProfileRefusal) {
            $this->assertSame($before, $this->schemaObjects());
        }
    }

    public function testWrongColumnIndexIsRefusedWithoutReplacingIt(): void
    {
        $name = 'wf_rp_idx_' . substr(hash('sha256', 'workflow_runs'), 0, 20);
        DB::statement('DROP INDEX "' . $name . '"');
        DB::statement('CREATE INDEX "' . $name . '" ON workflow_runs (namespace)');
        $before = $this->schemaObjects();
        try {
            RuntimeProfileSchema::up(null);
            $this->fail('An index name does not prove its columns.');
        } catch (RuntimeProfileRefusal) {
            $this->assertSame($before, $this->schemaObjects());
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function schemaObjects(): array
    {
        return array_map(
            static fn ($row): array => (array) $row,
            DB::select('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name')
        );
    }

    private function configuredStorage(): void
    {
        config()->set('database.connections.profile_guard_storage', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::connection('profile_guard_storage')->getSchemaBuilder()->create(
            'guard_runs',
            static function (Blueprint $table): void {
                $table->string('id')
                    ->primary();
                $table->string('workflow_instance_id');
                $table->string('namespace')
                    ->nullable();
            }
        );
        DB::connection('profile_guard_storage')->getSchemaBuilder()->create(
            'workflow_runs',
            static function (Blueprint $table): void {
                $table->string('id')
                    ->primary();
                $table->string('workflow_instance_id');
                $table->string('namespace')
                    ->nullable();
            }
        );
        config()
            ->set('workflows.v2.run_model', RuntimeProfileConfiguredRun::class);
    }
}

final class RuntimeProfileConfiguredRun extends WorkflowRun
{
    protected $table = 'guard_runs';

    protected $connection = 'profile_guard_storage';
}

final class RuntimeProfileAliasRun extends WorkflowRun
{
    protected $connection = 'profile_guard_alias';
}
