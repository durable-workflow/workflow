<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\SchemaTestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Exceptions\RuntimeProfileRefusal;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\RuntimeProfileGuard;

final class RuntimeProfileGuardTest extends SchemaTestCase
{
    public function testLegacyNullCanBeReadAndUpdatedWithoutExposingTheField(): void
    {
        $run = $this->legacyRun();
        RuntimeProfileGuard::assertRun($run);
        $this->assertNull($run->fresh()->getRawOriginal('runtime_profile'));
        $this->assertArrayNotHasKey('runtime_profile', $run->fresh()->toArray());
        $run->forceFill([
            'last_command_sequence' => 3,
        ])->save();
        $this->assertSame(3, $run->fresh()->last_command_sequence);
    }

    public function testLegacyScalarReadsAndOrdinaryMetadataUpdatesKeepTheirIdentity(): void
    {
        $run = $this->legacyRun();
        $this->assertSame('profile-tests', WorkflowRun::query()->whereKey($run->id)->value('namespace'));
        $this->assertSame($run->id, WorkflowRun::query()->whereKey($run->id)->soleValue('id'));
        $this->assertSame('profile-tests', WorkflowRun::query()->whereKey($run->id)->valueOrFail('namespace'));
        $run->forceFill([
            'namespace' => 'updated-profile-tests',
        ])->save();
        $this->assertSame('updated-profile-tests', $run->fresh()->namespace);
    }

    #[DataProvider('profileValues')]
    public function testEveryNonNullValueIsRefusedBeforeRetrievedObservers(string $profile, string $reason): void
    {
        $legacy = $this->legacyRun();
        $attributes = $legacy->getRawOriginal();
        $attributes['id'] = (string) Str::ulid();
        $attributes['run_number'] = 2;
        $attributes['runtime_profile'] = $profile;
        // Explicit fixture admission, never a production setter or update.
        DB::table('workflow_runs')->insert($attributes);
        $before = DB::table('workflow_runs')->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all();
        $calls = 0;
        WorkflowRun::retrieved(static function () use (&$calls): void {
            ++$calls;
        });

        try {
            WorkflowRun::query()->findOrFail($attributes['id']);
            $this->fail('A protected run must not reach model observers.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame($reason, $error->reason);
            $this->assertSame(0, $calls);
            $this->assertSame(
                $before,
                DB::table('workflow_runs')->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all()
            );
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function profileValues(): iterable
    {
        yield 'future token' => ['future.profile-v1', 'runtime_profile_unsupported'];
        yield 'maximum token' => [str_repeat('x', 64), 'runtime_profile_unsupported'];
        yield 'empty' => ['', 'runtime_profile_invalid'];
        yield 'case' => ['Future', 'runtime_profile_invalid'];
        yield 'trailing newline' => ["future\n", 'runtime_profile_invalid'];
        yield 'non-ASCII' => ['futuré', 'runtime_profile_invalid'];
    }

    public function testPublicFillAndDirectDirtyMutationCannotSetTheProfile(): void
    {
        $run = $this->legacyRun();
        $before = $run->getRawOriginal();
        foreach ([
            static fn () => $run->fill([
                'runtime_profile' => 'future',
            ]),
            static fn () => $run->forceFill([
                'runtime_profile' => null,
            ]),
            static function () use ($run): void {
                $run->runtime_profile = 'future';
                $run->save();
            },
        ] as $mutate) {
            try {
                $mutate();
                $this->fail('The engine-owned profile must not be assigned.');
            } catch (RuntimeProfileRefusal $error) {
                $this->assertSame('runtime_profile_assignment', $error->reason);
            }
            $this->assertSame($before, $run->fresh()->getRawOriginal());
        }
    }

    public function testBulkUpdateCannotBypassAdmissionImmutability(): void
    {
        $run = $this->legacyRun();
        $before = $run->getRawOriginal();
        try {
            DB::table('workflow_runs')->where('id', $run->id)->update([
                'runtime_profile' => 'future',
            ]);
            $this->fail('Bulk writes must not convert a legacy run.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('runtime_profile_immutable', $error->getMessage());
        }
        $this->assertSame($before, $run->fresh()->getRawOriginal());
    }

    public function testDownMigrationRefusesBeforeDroppingAnyProtectedStorage(): void
    {
        $run = $this->legacyRun();
        $attributes = $run->getRawOriginal();
        $attributes['id'] = (string) Str::ulid();
        $attributes['run_number'] = 2;
        $attributes['runtime_profile'] = 'future';
        DB::table('workflow_runs')->insert($attributes);
        $migration = require __DIR__ . '/../../../src/migrations/2026_11_04_000100_add_runtime_profile_to_workflow_runs.php';
        $before = DB::table('workflow_runs')->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all();
        try {
            $migration->down();
            $this->fail('A protected discriminator cannot be dropped.');
        } catch (RuntimeProfileRefusal) {
            $this->assertTrue(Schema::hasColumn('workflow_runs', 'runtime_profile'));
            $this->assertSame(
                $before,
                DB::table('workflow_runs')->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all()
            );
        }
    }

    private function legacyRun(): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'profile-' . strtolower((string) Str::ulid()),
            'workflow_class' => 'profile-fixture',
            'workflow_type' => 'profile-fixture',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'profile-fixture',
            'workflow_type' => 'profile-fixture',
            'namespace' => 'profile-tests',
            'status' => RunStatus::Waiting,
            'payload_codec' => 'avro',
            'arguments' => Serializer::serializeWithCodec('avro', []),
            'started_at' => now(),
        ]);

        return $run->refresh();
    }
}
