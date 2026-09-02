<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\ServiceProvider;

final class TestDatabaseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Queue workers use Redis, but product tests also exercise Laravel's
        // database queue explicitly. Include its framework-owned tables in the
        // one-time schema so truncation discovers them from the first test.
        $legacyQueueMigrations = \Orchestra\Testbench\default_migration_path()
            . DIRECTORY_SEPARATOR . 'queue';

        if (is_dir($legacyQueueMigrations)) {
            $this->loadMigrationsFrom($legacyQueueMigrations);
        }
    }
}
