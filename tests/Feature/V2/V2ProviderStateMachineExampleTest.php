<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\Booking;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use DurableWorkflow\Examples\ProviderStateMachines\Order;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use DurableWorkflow\Examples\ProviderStateMachines\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
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
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Actual embedded native machines, with a provider database independent of DW/business transactions. */
final class V2ProviderStateMachineExampleTest extends TestCase
{
    private OrderTerms $terms;

    private MachineStore $store;

    private string $providerPath;

    private ?PDO $providerDb = null;

    private ?Journal $provider = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        config([
            'workflows.v2.namespaces' => [Catalogue::NAMESPACE],
            'workflows.v2.task_dispatch_mode' => 'queue',
            'queue.default' => 'redis',
        ]);
        foreach (['business-state-machines', 'provider-state-machines'] as $example) {
            $schema = require __DIR__ . '/../../../examples/' . $example . '/schema.php';
            $schema(DB::connection()->getSchemaBuilder());
        }
        $this->terms = new OrderTerms('shop-a', 'purchase-1', 'business-generation-1', 1499, 'USD', 'sku-1', 2);
        $this->providerPath = tempnam(sys_get_temp_dir(), 'machine-provider-');
        $this->providerDb = new PDO('sqlite:' . $this->providerPath);
        $this->provider = new Journal($this->providerDb, $this->terms->client);
        $this->provider->install();
        $authority = new Authority(DB::connection(), 'provider-worker', [$this->terms->subjectKey()]);
        $this->store = MachineStore::configure($this->app, $authority, $this->provider);
        $this->store->seed($this->terms);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->app->forgetInstance(HandlerRegistry::class);
        foreach (['provider_machine_cancellations', 'provider_machine_milestones', 'provider_machine_admissions',
            'provider_machine_assessments', 'provider_machine_actors', 'provider_machine_assignments',
            'example_machine_publications', 'example_machine_decisions', 'example_machine_subjects'] as $table) {
            DB::connection()->getSchemaBuilder()->dropIfExists($table);
        }
        $this->provider = null;
        $this->providerDb = null;
        if (isset($this->providerPath)) {
            unlink($this->providerPath);
        }
        parent::tearDown();
    }

    public static function acknowledgements(): array
    {
        return [
            'ordinary acknowledgements' => [false],
            'lost capture booking and refund acknowledgements' => [true],
        ];
    }

    #[DataProvider('acknowledgements')]
    public function testPreHandoverCancellationConfirmsBookingCancelThenRefundThenOriginalReservationRelease(
        bool $lost
    ): void {
        foreach (['order', 'payment', 'shipment'] as $role) {
            $descriptor = $this->terms->descriptor($role);
            $this->provider->plan(
                Request::fromArray($descriptor['forward']),
                Status::Confirmed,
                $lost && $role !== 'order'
            );
            $this->provider->plan(
                Request::fromArray($descriptor['compensation']),
                Status::Confirmed,
                $lost && $role === 'payment'
            );
        }
        $stub = WorkflowStub::make(Order::class, 'provider-order-' . $this->terms->subjectKey(), Catalogue::NAMESPACE);
        $stub->start($this->terms->subjectKey());
        $this->drain();
        $root = $this->store->authority->subject($this->terms->subjectKey(), 'order');
        $this->assertSame('booked', $root->state, $this->progress());
        $this->assertSame(4, (int) $root->revision);
        $children = WorkflowRun::query()->whereIn('workflow_class', [Payment::class, Booking::class])->get();
        $this->assertCount(2, $children);
        $childHistories = [];
        foreach ($children as $child) {
            $this->assertSame(RunStatus::Completed, $child->status, $this->progress());
            $this->assertContains($child->workflowOutput()['businessOutcome'], ['captured', 'booked']);
            $childHistories[$child->id] = $this->history($child->id);
        }
        $this->assertSame(['reserve', 'capture', 'book'], $this->effects());
        $request = app(CancellationStore::class)->request(
            $this->terms->subjectKey(),
            $stub->runId(),
            'customer-cancel-1'
        );
        $this->assertSame('requested', $request['status']);
        $this->drain();
        $this->assertSame(
            'cancelled',
            $this->store->authority->subject($this->terms->subjectKey(), 'order')
->state,
            $this->progress()
        );
        $this->assertSame(['reserve', 'capture', 'book', 'cancel-booking', 'refund', 'release'], $this->effects());
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        foreach ([$plan->rootScope(), $plan->childScope('payment'), $plan->childScope('shipment')] as $scope) {
            $this->assertSame('verified', (new ScopeRepository())->metadata($scope)['status'], $this->progress());
        }
        foreach ($childHistories as $id => $history) {
            $this->assertSame($history, $this->history($id));
        }
        $requests = (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
            ->fetchColumn();
        foreach (WorkflowRun::query()->where('namespace', Catalogue::NAMESPACE)->get() as $run) {
            app(WorkflowReplayer::class)->replay($run);
        }
        $this->assertSame(
            $requests,
            (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
                ->fetchColumn()
        );
        $this->assertSame(6, count($this->effects()));
    }

    private function effects(): array
    {
        return $this->providerDb->query(
            'SELECT json_extract(o.request, \'$.action\') FROM provider_effects e JOIN provider_operations o ON o.id = e.operation_id ORDER BY e.sequence'
        )
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    private function history(string $run): array
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $run)->orderBy('sequence')->get()->map(
            static fn (WorkflowHistoryEvent $event): array => [$event->event_type->value, $event->payload]
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

    private function drain(): void
    {
        for ($i = 0; $i < 800; ++$i) {
            $task = WorkflowTask::query()->whereRelation('run', 'namespace', Catalogue::NAMESPACE)
                ->where('status', TaskStatus::Ready)->orderBy('available_at')->orderBy('created_at')->orderBy(
                    'id'
                )->first();
            if ($task === null) {
                return;
            }
            if ($task->available_at !== null && $task->available_at->isFuture()) {
                Carbon::setTestNow($task->available_at->copy()->addSecond());
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
