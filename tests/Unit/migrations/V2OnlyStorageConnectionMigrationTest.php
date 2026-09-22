<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use Illuminate\Support\Facades\Schema;
use Tests\SchemaTestCase;

final class V2OnlyStorageConnectionMigrationTest extends SchemaTestCase
{
    private string $secondaryDatabase = '';

    private string|false $previousV1Flag = false;

    protected function setUp(): void
    {
        $this->previousV1Flag = getenv('DW_V1_ENABLED');
        putenv('DW_V1_ENABLED=false');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        config()->set('workflows.storage.connection', null);

        try {
            parent::tearDown();
        } finally {
            $this->previousV1Flag === false
                ? putenv('DW_V1_ENABLED')
                : putenv("DW_V1_ENABLED={$this->previousV1Flag}");

            if ($this->secondaryDatabase !== '' && is_file($this->secondaryDatabase)) {
                @unlink($this->secondaryDatabase);
            }
        }
    }

    public function testV2OnlyMigrationsUseConfiguredStorageConnection(): void
    {
        $default = (string) config('database.default');

        $this->assertNotSame('secondary', $default);

        foreach (['workflow_instances', 'workflow_runs', 'workflow_messages'] as $table) {
            $this->assertTrue(Schema::connection('secondary')->hasTable($table));
            $this->assertFalse(Schema::connection($default)->hasTable($table));
        }

        $this->assertFalse(Schema::connection('secondary')->hasTable('workflows'));
    }

    protected function defineEnvironment($app): void
    {
        $this->secondaryDatabase = (string) tempnam(sys_get_temp_dir(), 'wf_v2_storage_');

        $app['config']->set('database.connections.secondary', [
            'driver' => 'sqlite',
            'database' => $this->secondaryDatabase,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        $app['config']->set('workflows.storage.connection', 'secondary');
    }
}
