<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Observers\WorkflowHistoryEventObserver;
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

    public function testHistoryEventRecordTransactionUsesStorageConnection(): void
    {
        $default = (string) config('database.default');

        $this->assertNotSame('secondary', $default);

        $run = WorkflowRun::create([
            'workflow_instance_id' => (string) Str::ulid(),
            'run_number' => 1,
            'workflow_class' => 'Tests\\Unit\\DummyWorkflow',
            'workflow_type' => 'dummy',
            'status' => RunStatus::Running,
            'last_history_sequence' => 0,
        ]);

        $observedTransactionLevels = null;

        WorkflowHistoryEvent::creating(static function (WorkflowHistoryEvent $event) use (
            &$observedTransactionLevels,
            $default,
        ): void {
            $observedTransactionLevels = [
                'default' => DB::connection($default)->transactionLevel(),
                'storage' => DB::connection('secondary')->transactionLevel(),
                'event_connection' => $event->getConnectionName(),
            ];
        });

        try {
            $event = WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
                'workflow_class' => $run->workflow_class,
                'workflow_type' => $run->workflow_type,
                'workflow_instance_id' => $run->workflow_instance_id,
                'workflow_run_id' => $run->id,
            ]);
        } finally {
            WorkflowHistoryEvent::flushEventListeners();
            WorkflowHistoryEvent::observe(WorkflowHistoryEventObserver::class);
        }

        $this->assertSame([
            'default' => 0,
            'storage' => 1,
            'event_connection' => 'secondary',
        ], $observedTransactionLevels);
        $this->assertSame(1, $event->sequence);
        $this->assertSame(1, (int) $run->fresh()->last_history_sequence);
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
