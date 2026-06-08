<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Str;
use Tests\TestCase;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\CommandSequence;

final class CommandSequenceStorageConnectionTest extends TestCase
{
    private string $secondaryDatabase = '';

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->secondaryDatabase !== '' && is_file($this->secondaryDatabase)) {
            @unlink($this->secondaryDatabase);
        }
    }

    public function testReserveNextResolvesCommandQueriesOnStorageConnection(): void
    {
        $default = (string) config('database.default');

        $this->assertNotSame('secondary', $default);

        // In this environment the workflow tables only exist on the secondary
        // (storage) connection; the WorkflowRun resolves there via the
        // ResolvesStorageConnection trait.
        $run = WorkflowRun::create([
            'workflow_instance_id' => (string) Str::ulid(),
            'run_number' => 1,
            'workflow_class' => 'Tests\\Unit\\DummyWorkflow',
            'workflow_type' => 'dummy',
            'status' => RunStatus::Running,
            'last_command_sequence' => 0,
        ]);

        // Before the fix CommandSequence read/wrote the command sequence through
        // DB::table('workflow_commands'), which targets the application's default
        // connection — where the workflow_commands table does not exist — and
        // throws "no such table". Routing through the command model's connection
        // keeps these maintenance queries on the configured storage connection.
        $sequence = CommandSequence::reserveNext($run);

        $this->assertSame(1, $sequence);
        $this->assertSame(1, (int) $run->fresh()->last_command_sequence);
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
