<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Workflow\V2\Exceptions\RuntimeProfileRefusal;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\RuntimeProfileSchema;

final class RuntimeProfileQuotedIdentifierGuardTest extends TestCase
{
    public function testDifferentQuotedSelfReadRefusesAdoptionWithoutDdl(): void
    {
        $this->installDifferentSelfRead();
        $before = DB::select('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name');
        try {
            RuntimeProfileSchema::up(null);
            $this->fail('Whitespace inside a quoted table name cannot identify another self-read.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_storage', $error->reason);
        }
        $this->assertEquals(
            $before,
            DB::select('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name')
        );
    }

    public function testWrongSelfReadCannotEraseRetainedProfileAndDependent(): void
    {
        $this->installDifferentSelfRead();
        DB::table('guard  runs')->insert([
            'id' => 'protected',
            'workflow_instance_id' => 'owner',
            'namespace' => 'guard',
            'runtime_profile' => 'future',
        ]);
        DB::table('guard_dependents')->insert([
            'id' => 'keep',
            'run_id' => 'protected',
        ]);
        $before = $this->retainedState();
        try {
            RuntimeProfileSchema::up(null);
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_storage', $error->reason);
            $this->assertSame($before, $this->retainedState());
            return;
        }
        DB::statement('PRAGMA recursive_triggers = OFF');
        DB::statement(
            'INSERT OR REPLACE INTO "guard  runs" (id, workflow_instance_id, namespace, runtime_profile) VALUES (?, ?, ?, NULL)',
            ['protected', 'owner', 'guard']
        );
        $this->assertSame($before, $this->retainedState());
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('cache.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        foreach (['workflow_runs', 'guard  runs', 'guard runs'] as $name) {
            DB::getSchemaBuilder()->create($name, static function (Blueprint $table): void {
                $table->string('id')
                    ->primary();
                $table->string('workflow_instance_id');
                $table->string('namespace')
                    ->nullable();
                $table->string('runtime_profile', 64)
                    ->nullable();
            });
        }
        DB::getSchemaBuilder()->create('guard_dependents', static function (Blueprint $table): void {
            $table->string('id')
                ->primary();
            $table->string('run_id');
            $table->foreign('run_id')
                ->references('id')
                ->on('guard  runs')
                ->cascadeOnDelete();
        });
    }

    /**
     * @return array{profile: mixed, dependents: list<array<string, mixed>>}
     */
    private function retainedState(): array
    {
        return [
            'profile' => DB::table('guard  runs')->where('id', 'protected')->value('runtime_profile'),
            'dependents' => DB::table('guard_dependents')->orderBy('id')->get()->map(
                static fn ($row): array => (array) $row
            )->all(),
        ];
    }

    private function installDifferentSelfRead(): void
    {
        config()->set('workflows.v2.run_model', RuntimeProfileWhitespaceRun::class);
        RuntimeProfileSchema::up(null);
        $trigger = DB::selectOne(
            'SELECT name, sql FROM sqlite_master WHERE type = ? AND tbl_name = ? AND name LIKE ?',
            ['trigger', 'guard  runs', '%_insert']
        );
        $this->assertNotNull($trigger);
        $bad = str_replace('FROM "guard  runs"', 'FROM "guard runs"', (string) $trigger->sql, $count);
        $this->assertSame(1, $count);
        $name = DB::connection()->getQueryGrammar()->wrap($trigger->name);
        DB::statement('DROP TRIGGER ' . $name);
        DB::unprepared($bad);
    }
}

final class RuntimeProfileWhitespaceRun extends WorkflowRun
{
    protected $table = 'guard  runs';
}
