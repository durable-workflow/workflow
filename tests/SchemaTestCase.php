<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;

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
        $this->artisan('migrate:fresh')
            ->run();
        $this->app[Kernel::class]->setArtisan(null);
        $this->app->offsetUnset(OutputStyle::class);
    }
}
