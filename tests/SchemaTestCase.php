<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

abstract class SchemaTestCase extends TestCase
{
    protected const USE_DATABASE_TRUNCATION = false;

    protected function tearDown(): void
    {
        try {
            self::stopWorkers();

            if ($this->app !== null) {
                // Deliberate DDL tests may leave a partial or extended schema.
                // Restore the canonical schema before the fast harness resumes.
                $this->migrateFreshDatabase();
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function setUpWithLaravelMigrations(): void
    {
        // migrateFreshDatabase registers the framework path without creating a
        // Testbench migration processor that would roll the schema back.
    }

    protected function setUpTraits(): array
    {
        return $this->setUpTraitsWithoutDatabase();
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->migrateFreshDatabase();
    }

    private function migrateFreshDatabase(): void
    {
        $this->app->make('migrator')
            ->path(\Orchestra\Testbench\default_migration_path());
        $connection = DB::connection();
        if ($connection->getDriverName() === 'pgsql') {
            // PostgreSQL DROP TABLE removes triggers but retains their functions.
            // This is a privileged reset of a disposable Testbench database, not
            // permission for production migrations to adopt detached objects.
            $functions = array_filter($connection->select(
                'SELECT n.nspname, p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = current_schema() AND p.pronargs = 0',
                [],
                false,
            ), static fn ($function): bool => preg_match(
                '/\Awf_runtime_profile_[a-f0-9]{20}(?:_insert|_delete)?\z/D',
                $function->proname,
            ) === 1);
            if ($functions !== []) {
                $connection->getSchemaBuilder()
                    ->dropAllTables();
                $grammar = $connection->getQueryGrammar();
                foreach ($functions as $function) {
                    $connection->unprepared('DROP FUNCTION ' . $grammar->wrap($function->nspname)
                        . '.' . $grammar->wrap($function->proname) . '()');
                }
            }
        }
        $this->artisan('migrate:fresh')
            ->run();
        $this->app[Kernel::class]->setArtisan(null);
        $this->app->offsetUnset(OutputStyle::class);
    }
}
