<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundGateway;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\IncidentWait;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
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

trait ProviderMachineFixture
{
    protected OrderTerms $terms;

    protected MachineStore $store;

    protected string $providerPath;

    protected ?PDO $providerDb = null;

    protected ?Journal $provider = null;

    protected ?UnitRefundGateway $unitRefunds = null;

    private array $taskTrace = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        // drain() advances native time explicitly; SQL latency must not also advance that test clock.
        Carbon::setTestNow('2026-01-01T00:00:00Z');
        config([
            'workflows.v2.namespaces' => [Catalogue::NAMESPACE],
            'workflows.v2.task_dispatch_mode' => 'queue',
            'queue.default' => 'redis',
        ]);
        foreach (['business-state-machines', 'provider-state-machines'] as $example) {
            $schema = require __DIR__ . '/../../examples/' . $example . '/schema.php';
            $schema(DB::connection()->getSchemaBuilder());
        }
        if ($this->reservedRoot()) {
            $schema = require __DIR__ . '/../../examples/provider-state-machines/root-schema.php';
            $schema(DB::connection()->getSchemaBuilder());
        }
        $this->terms = $this->orderTerms();
        $this->providerPath = tempnam(sys_get_temp_dir(), 'machine-provider-');
        $this->providerDb = new PDO('sqlite:' . $this->providerPath);
        $this->provider = new Journal($this->providerDb, $this->terms->client, $this->providerClock());
        $this->provider->install();
        $authority = new Authority(
            DB::connection(),
            'provider-worker',
            [$this->terms->subjectKey()],
            [
                $this->terms->subjectKey() => $this->recipe(),
            ],
            reservedRoots: $this->reservedRoot() ? [$this->terms->subjectKey()] : [],
            unitAmounts: $this->unitAmounts() === [] ? [] : [
                $this->terms->subjectKey() => $this->unitAmounts(),
            ],
            transitPolicies: $this->transitPolicy() === null ? [] : [
                $this->terms->subjectKey() => $this->transitPolicy(),
            ],
            transitWakes: $this->transitWakes()
        );
        if ($this->unitAmounts() !== []) {
            $this->unitRefunds = new UnitRefundGateway($this->providerDb, $this->terms->client);
            $this->unitRefunds->install();
        }
        $this->store = MachineStore::configure(
            $this->app,
            $authority,
            $this->provider,
            unitRefunds: $this->unitRefunds
        );
        $this->store->seed($this->terms);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->app->forgetInstance(HandlerRegistry::class);
        foreach ([
            'provider_root_cancellations',
            'provider_machine_cancellations',
            'provider_machine_milestones',
            'provider_machine_admissions',
            'provider_machine_assessments',
            'provider_machine_actors',
            'provider_machine_root_intents',
            'provider_machine_assignments',
            'example_machine_publications',
            'example_machine_decisions',
            'example_machine_subjects',
        ] as $table) {
            DB::connection()->getSchemaBuilder()->dropIfExists($table);
        }
        $this->unitRefunds = null;
        $this->provider = null;
        $this->providerDb = null;
        if (isset($this->providerPath)) {
            unlink($this->providerPath);
        }
        parent::tearDown();
    }

    // These fixtures execute the recorded tasks explicitly and immediately stop automatic workers.
    protected function startQueueWorkersAutomatically(): bool
    {
        return false;
    }

    protected function reservedRoot(): bool
    {
        return false;
    }

    protected function recipe(): string
    {
        return Catalogue::RESERVE_FIRST;
    }

    protected function orderTerms(): OrderTerms
    {
        return new OrderTerms('shop-a', 'purchase-1', 'business-generation-1', 1499, 'USD', 'sku-1', 2);
    }

    protected function unitAmounts(): array
    {
        return [];
    }

    /**
     * Trusted transit remedy policy for a lost or damaged original parcel; null keeps legacy behavior.
     */
    protected function transitPolicy(): ?string
    {
        return null;
    }

    /**
     * Recorded transit incident wake bound of the test grant.
     */
    protected function transitWakes(): int
    {
        return IncidentWait::WAKES;
    }

    protected function providerClock(): ?\Closure
    {
        return null;
    }

    protected function start(
        Status $refund = Status::Confirmed,
        bool $lost = false,
        Status $reservation = Status::Confirmed,
        Status $capture = Status::Confirmed,
    ): WorkflowStub {
        foreach (['order', 'payment', 'shipment'] as $role) {
            $descriptor = $this->terms->descriptor($role);
            $this->provider->plan(
                Request::fromArray($descriptor['forward']),
                match ($role) {
                    'order' => $reservation, 'payment' => $capture, default => Status::Confirmed
                },
                $lost && $role !== 'order'
            );
            $this->provider->plan(
                Request::fromArray($descriptor['compensation']),
                $role === 'payment' ? $refund : Status::Confirmed,
                $lost && $role === 'payment'
            );
        }
        $stub = WorkflowStub::make(
            Catalogue::rootClass($this->recipe()),
            'provider-order-' . $this->terms->subjectKey(),
            Catalogue::NAMESPACE
        );
        $stub->start($this->terms->subjectKey());
        $this->drain();
        return $stub;
    }

    protected function effects(): array
    {
        return $this->providerDb->query(
            'SELECT json_extract(o.request, \'$.action\') FROM provider_effects e JOIN provider_operations o ON o.id = e.operation_id ORDER BY e.sequence'
        )
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    protected function history(string $run): array
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $run)->orderBy('sequence')->get()->map(
            static fn (WorkflowHistoryEvent $event): array => [$event->event_type->value, $event->payload]
        )->all();
    }

    protected function progress(): string
    {
        return json_encode([
            'runs' => WorkflowRun::query()->get(['id', 'workflow_class', 'status'])->toArray(),
            'failures' => WorkflowFailure::query()->orderByDesc('created_at')->limit(10)->pluck('message')->map(
                static fn (string $message): string => substr($message, 0, 256)
            )->all(),
            'tasks' => WorkflowTask::query()->selectRaw('task_type, status, COUNT(*) as total')->groupBy(
                'task_type',
                'status'
            )->get()
                ->toArray(),
            'scopes' => DB::table('workflow_compensation_scopes')->get(['id', 'original_run_id', 'status'])->all(),
            'obligations' => DB::table('workflow_compensation_obligations')->orderBy('scope_id')->orderBy('position')
                ->limit(32)
                ->get(['scope_id', 'id', 'forward_status', 'compensation_status', 'evidence'])->map(
                    static function (object $row): array {
                        $evidence = json_decode($row->evidence ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                        return [
                            'scope' => $row->scope_id,
                            'id' => $row->id,
                            'forward' => $row->forward_status,
                            'compensation' => $row->compensation_status,
                            'reason' => $evidence['reason'] ?? null,
                        ];
                    }
                )->all(),
            'activities' => DB::table('activity_executions')->orderByDesc('created_at')->limit(32)
                ->get(['id', 'workflow_run_id', 'activity_class', 'status', 'attempt_count', 'started_at', 'closed_at'])
                ->all(),
            'taskTrace' => $this->taskTrace,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Queue-worker semantics: a recorded activity failure (for example a child scope not yet verified) is
     * retried by the engine; the worker keeps draining instead of stopping.
     */
    protected function drainLikeAWorker(): void
    {
        for ($pass = 0; $pass < 16; ++$pass) {
            try {
                $this->drain();
                return;
            } catch (\RuntimeException $error) {
                if ($error->getMessage() !== 'scope_compensation_unknown') {
                    throw $error;
                }
            }
        }
        $this->fail('The fresh retained recovery did not settle within its bounded worker passes.');
    }

    protected function drain(?\Closure $stop = null, ?string $pausedInstance = null): void
    {
        for ($i = 0; $i < 800; ++$i) {
            if ($stop !== null && $stop()) {
                return;
            }
            $tasks = WorkflowTask::query()->whereRelation('run', 'namespace', Catalogue::NAMESPACE)
                ->where('status', TaskStatus::Ready);
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
            $this->taskTrace[] = [
                'id' => $task->id,
                'run' => $task->workflow_run_id,
                'type' => $task->task_type->value,
                'available' => $task->available_at?->toISOString(),
                'clock' => now()
                    ->toISOString(),
            ];
            if (count($this->taskTrace) > 64) {
                array_shift($this->taskTrace);
            }
            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new RunTimerTask($task->id),
            };
            $this->app->call([$job, 'handle']);
        }
        $this->fail('The bounded native provider-machine drain did not settle.');
    }
}
