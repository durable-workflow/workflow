<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\SchemaTestCase;
use Tests\Support\RuntimeProfileRetainedIdentityControls;
use Workflow\V2\Exceptions\RuntimeProfileRefusal;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\RuntimeProfileSchema;

final class RuntimeProfileStorageBackendTest extends SchemaTestCase
{
    use RuntimeProfileRetainedIdentityControls;

    protected function tearDown(): void
    {
        config()->set('workflows.v2.run_model', WorkflowRun::class);
        DB::purge('profile_guard_prefix');
        parent::tearDown();
    }

    public function testConfiguredPrefixedAuthorityIsGuardedAndAmbiguousDownIsNonDestructive(): void
    {
        $settings = config('database.connections.' . config('database.default'));
        $settings['prefix'] = 'rp_guard_';
        config()
            ->set('database.connections.profile_guard_prefix', $settings);
        $storage = DB::connection('profile_guard_prefix');
        foreach (['runs', 'workflow_runs'] as $name) {
            $storage->getSchemaBuilder()
                ->create($name, static function (Blueprint $table): void {
                    $table->string('id')
                        ->primary();
                    $table->string('workflow_instance_id');
                    $table->string('namespace')
                        ->nullable();
                });
        }
        config()
            ->set('workflows.v2.run_model', RuntimeProfilePrefixedRun::class);
        RuntimeProfileSchema::up(null);
        // Repeating up must inspect the actual unprefixed backend metadata.
        RuntimeProfileSchema::up(null);
        $storage->table('runs')
            ->insert([
                'id' => 'prefix-future',
                'workflow_instance_id' => 'prefix-owner',
                'namespace' => 'prefix-tests',
                'runtime_profile' => 'future',
            ]);
        $before = $storage->table('runs')
            ->get()
            ->toArray();
        foreach (['id', 'workflow_instance_id', 'namespace', 'runtime_profile'] as $column) {
            try {
                $storage->table('runs')
                    ->where('id', 'prefix-future')
                    ->update([
                        $column => 'other',
                    ]);
                $this->fail('Configured protected authority cannot move.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('runtime_profile_immutable', $error->getMessage());
            }
            $this->assertEquals($before, $storage->table('runs')->get()->toArray());
        }
        try {
            RuntimeProfileSchema::down(null);
            $this->fail('A protected or independent target must prevent automatic down.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_storage', $error->reason);
        }
        $this->assertTrue($storage->getSchemaBuilder()->hasColumn('runs', 'runtime_profile'));
        $this->assertEquals($before, $storage->table('runs')->get()->toArray());
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('workflow_runs', 'runtime_profile'));
    }
}

final class RuntimeProfilePrefixedRun extends WorkflowRun
{
    protected $table = 'runs';

    protected $connection = 'profile_guard_prefix';
}
