<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use Illuminate\Support\Facades\Schema;
use Tests\SchemaTestCase;

final class StorageConnectionMigrationTest extends SchemaTestCase
{
    private string $secondaryDatabase = '';

    protected function tearDown(): void
    {
        config()->set('workflows.storage.connection', null);

        parent::tearDown();

        if ($this->secondaryDatabase !== '' && is_file($this->secondaryDatabase)) {
            @unlink($this->secondaryDatabase);
        }
    }

    public function testWorkflowMigrationsRunOnConfiguredStorageConnection(): void
    {
        $default = (string) config('database.default');

        $this->assertNotSame('secondary', $default);

        foreach (['workflows', 'workflow_runs', 'workflow_messages'] as $table) {
            $this->assertTrue(
                Schema::connection('secondary')->hasTable($table),
                "Expected the {$table} table to be created on the secondary connection.",
            );

            $this->assertFalse(
                Schema::connection($default)->hasTable($table),
                "Did not expect the {$table} table on the default connection.",
            );
        }
    }

    protected function defineEnvironment($app): void
    {
        $this->secondaryDatabase = (string) tempnam(sys_get_temp_dir(), 'wf_storage_');

        $app['config']->set('database.connections.secondary', [
            'driver' => 'sqlite',
            'database' => $this->secondaryDatabase,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        // Route every workflow model and migration to the secondary connection
        // for the lifetime of this test class.
        $app['config']->set('workflows.storage.connection', 'secondary');
    }
}
