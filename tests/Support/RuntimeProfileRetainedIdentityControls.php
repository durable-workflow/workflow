<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Workflow\V2\Exceptions\RuntimeProfileRefusal;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\RuntimeProfileSchema;

/** Shared native controls run in both SQLite unit and service-DB feature jobs. */
trait RuntimeProfileRetainedIdentityControls
{
    public function testProfileByteBoundaryRefusesNewOversizedRowsWithoutEffects(): void
    {
        $this->identityRow(null);
        $this->identityRow(str_repeat('x', 64));
        foreach ([str_repeat('x', 65), str_repeat('é', 33)] as $profile) {
            $before = $this->durableRows();
            try {
                $this->identityRow($profile);
                $this->fail('A fresh row must not bypass the stored byte boundary.');
            } catch (QueryException) {
                $this->assertSame($before, $this->durableRows());
            }
        }
    }

    public function testOversizedPreexistingProfileRefusesAdoptionBeforeAnyDdl(): void
    {
        DB::getSchemaBuilder()->create('profile_adoption_runs', static function (Blueprint $table): void {
            $table->string('id')
                ->primary();
            $table->string('workflow_instance_id');
            $table->string('namespace')
                ->nullable();
            $table->string('runtime_profile', 64)
                ->nullable();
        });
        DB::table('profile_adoption_runs')->insert([
            'id' => 'oversized-adoption',
            'workflow_instance_id' => 'owner',
            'namespace' => null,
            'runtime_profile' => str_repeat('é', 33),
        ]);
        config()
            ->set('workflows.v2.run_model', RuntimeProfileAdoptionRun::class);
        $before = $this->durableRows();
        $ddl = [];
        DB::listen(static function ($event) use (&$ddl): void {
            if (preg_match('/\A\s*(?:create|alter|drop|rename|truncate)\b/i', $event->sql)) {
                $ddl[] = $event->sql;
            }
        });
        try {
            RuntimeProfileSchema::up(null);
            $this->fail('An existing oversized value cannot be adopted as a bounded floor.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_storage', $error->reason);
            $this->assertSame([], $ddl);
            $this->assertSame($before, $this->durableRows());
        } finally {
            config()->set('workflows.v2.run_model', WorkflowRun::class);
        }
    }

    #[DataProvider('retainedProfileConflicts')]
    public function testRetainedIdentityRefusesBackendConflictForms(
        ?string $old,
        ?string $new,
        string $backend,
        string $form,
        bool $recursive
    ): void {
        if (DB::connection()->getDriverName() !== $backend) {
            $this->markTestSkipped('Backend-native conflict control.');
        }
        if ($backend === 'sqlite') {
            DB::statement('PRAGMA recursive_triggers = ' . ($recursive ? 'ON' : 'OFF'));
        }
        $row = $this->identityRow($old);
        $before = $this->durableRows();
        $replacement = $row;
        $replacement['runtime_profile'] = $new;
        $grammar = DB::connection()->getQueryGrammar();
        $columns = implode(', ', array_map($grammar->wrap(...), array_keys($replacement)));
        $values = implode(', ', array_fill(0, count($replacement), '?'));
        $prefix = $form === 'replace' ? ($backend === 'sqlite' ? 'INSERT OR REPLACE' : 'REPLACE') : 'INSERT';
        $sql = $prefix . ' INTO ' . $grammar->wrapTable(
            'workflow_runs'
        ) . ' (' . $columns . ') VALUES (' . $values . ')';
        if ($form === 'upsert') {
            $sql .= in_array($backend, ['mysql', 'mariadb'], true)
                ? ' ON DUPLICATE KEY UPDATE runtime_profile = VALUES(runtime_profile)'
                : ' ON CONFLICT (id) DO UPDATE SET runtime_profile = excluded.runtime_profile';
        }
        try {
            DB::statement($sql, array_values($replacement));
            $this->fail('A retained profile cannot be converted or replaced.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('runtime_profile_immutable', $error->getMessage());
        }
        $this->assertSame($before, $this->durableRows());
    }

    /**
     * @return iterable<string, array{?string, ?string, string, string, bool}>
     */
    public static function retainedProfileConflicts(): iterable
    {
        foreach ([
            'null to future' => [null, 'future'],
            'future to null' => ['future', null],
            'future to same' => ['future', 'future'],
            'future to different' => ['future', 'other'],
        ] as $name => [$old, $new]) {
            foreach (['sqlite', 'mysql', 'mariadb', 'pgsql'] as $backend) {
                foreach ($backend === 'pgsql' ? ['upsert'] : ['replace', 'upsert'] as $form) {
                    foreach ($backend === 'sqlite' && $form === 'replace' ? [false, true] : [false] as $recursive) {
                        yield $name . ' ' . $backend . ' ' . $form . ' ' . (int) $recursive => [
                            $old,
                            $new,
                            $backend,
                            $form,
                            $recursive,
                        ];
                    }
                }
            }
        }
    }

    #[DataProvider('protectedIdentityColumns')]
    public function testProtectedAuthorityCannotMoveWhileLegacyMetadataRemainsMutable(string $column): void
    {
        $row = $this->identityRow('future');
        $before = $this->durableRows();
        try {
            DB::table('workflow_runs')->where('id', $row['id'])->update([
                $column => 'moved-identity',
            ]);
            $this->fail('A protected authority cannot move around its retained marker.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('runtime_profile_immutable', $error->getMessage());
        }
        $this->assertSame($before, $this->durableRows());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function protectedIdentityColumns(): iterable
    {
        yield 'primary key' => ['id'];
        yield 'owner' => ['workflow_instance_id'];
        yield 'namespace' => ['namespace'];
    }

    public function testProtectedDeletionRefusesButLegacyNullDeletionRemainsAvailable(): void
    {
        $future = $this->identityRow('future');
        $before = $this->durableRows();
        try {
            DB::table('workflow_runs')->where('id', $future['id'])->delete();
            $this->fail('A retained unsupported row cannot be deleted.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('runtime_profile_immutable', $error->getMessage());
        }
        $this->assertSame($before, $this->durableRows());
        $legacy = $this->identityRow(null);
        $this->assertSame(1, DB::table('workflow_runs')->where('id', $legacy['id'])->delete());
        $this->assertFalse(DB::table('workflow_runs')->where('id', $legacy['id'])->exists());
        // Synthetic reuse is not proof of a lawful future producer admission.
        $legacy['runtime_profile'] = 'future';
        DB::table('workflow_runs')->insert($legacy);
        try {
            WorkflowRun::query()->findOrFail($legacy['id']);
            $this->fail('Current readers must refuse a retained unsupported row.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_unsupported', $error->reason);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function identityRow(?string $profile): array
    {
        $id = (string) Str::ulid();
        $row = [
            'id' => $id,
            'workflow_instance_id' => 'identity-' . $id,
            'run_number' => 1,
            'workflow_class' => 'identity-fixture',
            'workflow_type' => 'identity-fixture',
            'namespace' => 'profile-tests',
            'status' => 'waiting',
            'runtime_profile' => $profile,
        ];
        DB::table('workflow_runs')->insert($row);
        return $row;
    }

    /**
     * @return array<string, list<string>>
     */
    private function durableRows(): array
    {
        $rows = [];
        foreach (DB::getSchemaBuilder()->getTableListing() as $table) {
            $values = DB::table($table)->get()->map(
                static fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR)
            )->all();
            sort($values);
            $rows[$table] = $values;
        }
        ksort($rows);
        return $rows;
    }
}

final class RuntimeProfileAdoptionRun extends WorkflowRun
{
    protected $table = 'profile_adoption_runs';
}
