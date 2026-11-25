<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\MachineStore;
use DurableWorkflow\Examples\ConfiguredStateMachines\Order;
use DurableWorkflow\Examples\ConfiguredStateMachines\Ownership;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../examples/configured-state-machines/bootstrap.php';

/**
 * Shared application tables and a deterministic native task drain for the recorded Order/Shipment example.
 */
trait ConfiguredMachineFixture
{
    private const SUBJECT = 'case-aaaaaaaaaaaaaaaa-g1';

    private const PRINCIPAL = 'configured-example-worker';

    private MachineStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        // drain() advances task time explicitly; SQL latency must not advance it too.
        Carbon::setTestNow('2026-01-01T00:00:00Z');
        config([
            'workflows.v2.namespaces' => [Ownership::NAMESPACE],
            'workflows.v2.task_dispatch_mode' => 'queue',
            'queue.default' => 'redis',
        ]);
        foreach (['schema.php', 'provider-schema.php', 'application-schema.php'] as $path) {
            $schema = require __DIR__ . '/../../examples/business-state-machines/' . $path;
            $schema(DB::connection()->getSchemaBuilder());
        }
        $schema = require __DIR__ . '/../../examples/configured-state-machines/schema.php';
        $schema(DB::connection()->getSchemaBuilder());
        $this->store = MachineStore::configure($this->app, DB::connection(), self::PRINCIPAL, [self::SUBJECT]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (['configured_machine_cancellations', 'configured_machine_admissions', 'configured_machine_assessments',
            'example_machine_bindings', 'example_machine_compensation_log', 'example_machine_effects',
            'example_machine_publications', 'example_machine_decisions', 'example_machine_subjects'] as $table) {
            DB::connection()->getSchemaBuilder()->dropIfExists($table);
        }
        parent::tearDown();
    }

    private function root(): object
    {
        return DB::table('example_machine_subjects')->whereNull('parent_subject_id')->sole();
    }

    private function start(string $integration, string $version = '1'): WorkflowStub
    {
        $this->store->seed(self::SUBJECT, $integration, version: $version);
        $stub = $this->admit();
        $this->drain();
        $this->assertSame(
            RunStatus::Waiting,
            WorkflowRun::query()->findOrFail($stub->runId())->status,
            $this->progress()
        );
        return $stub;
    }

    private function admit(string $event = 'begin'): WorkflowStub
    {
        $stub = WorkflowStub::make(Order::class, Ownership::instance(self::SUBJECT), Ownership::NAMESPACE);
        $stub->start(self::SUBJECT, $event);
        return $stub;
    }

    private function history(string $run): array
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $run)->orderBy('sequence')->get()
            ->map(
                static fn (WorkflowHistoryEvent $event): array => [
                    $event->sequence,
                    $event->event_type->value,
                    $event->payload,
                ]
            )->all();
    }

    private function progress(): string
    {
        return json_encode([
            'runs' => WorkflowRun::query()->get(['id', 'workflow_class', 'status', 'output'])->toArray(),
            'failures' => WorkflowFailure::query()->pluck('message')->all(),
            'tasks' => WorkflowTask::query()->get(['task_type', 'status', 'last_error'])->toArray(),
        ], JSON_THROW_ON_ERROR);
    }

    private function drain(?callable $stopWhen = null, ?string $pausedInstance = null): void
    {
        for ($i = 0; $i < 250; ++$i) {
            $tasks = WorkflowTask::query()->where('status', TaskStatus::Ready);
            if ($pausedInstance !== null) {
                $tasks->whereRelation('run', 'workflow_instance_id', '<>', $pausedInstance);
            }
            $task = $tasks->orderBy('available_at')
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();
            if ($task === null) {
                return;
            }
            if ($task->available_at !== null && $task->available_at->isFuture()) {
                Carbon::setTestNow($task->available_at->copy()->addSecond());
            }
            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id), TaskType::Activity => new RunActivityTask(
                    $task->id
                ),
                TaskType::Timer => new RunTimerTask($task->id),
            };
            $this->app->call([$job, 'handle']);
            if ($stopWhen !== null && $stopWhen()) {
                return;
            }
        }
        $this->fail('The bounded native task drain did not settle.');
    }
}
