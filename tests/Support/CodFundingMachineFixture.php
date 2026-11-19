<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodPaymentProvider;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\CodFundingPolicy;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCheckout;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../examples/provider-state-machines/bootstrap.php';

/** Recorded native COD source cases; installed and full customer-matrix acceptance remain separate. */
trait CodFundingMachineFixture
{
    use ProviderMachineFixture { tearDown as private tearDownProvider; }

    protected ?CodPaymentProvider $cod = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
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
        $this->providerPath = tempnam(sys_get_temp_dir(), 'cod-funding-');
        $this->providerDb = new PDO('sqlite:' . $this->providerPath);
        $this->cod = new CodPaymentProvider($this->providerDb, 'shop-a', static fn (): int => now()->getTimestamp(), true);
        $this->provider = $this->cod->commands;
        $this->provider->install();
        $this->provider->shipments()
            ->install();
        $this->cod->install();
    }

    protected function tearDown(): void
    {
        $this->cod = null;
        $this->tearDownProvider();
    }

    protected function startCod(string $recipe): WorkflowStub
    {
        $origin = $this->beginCod($recipe);
        $this->pump();
        return $origin;
    }

    /**
     * Seeds and starts the original Order without running any of its tasks.
     */
    protected function beginCod(string $recipe): WorkflowStub
    {
        $now = now()
            ->getTimestamp();
        $base = new OrderTerms('shop-a', 'purchase-cod', 'g1', 1499, 'USD', 'sku-1', 2);
        $cod = new CodDescriptor(
            new Identity('shop-a', 'purchase-cod', 'g1', 'original-cod'),
            ShipmentDescriptor::fromArray($base->physicalDescriptor()),
            'carrier-original',
            'point-original',
            1499,
            'USD',
            $now + 3600,
            $now + 7200,
            CodFundingPolicy::handover($recipe)
        );
        $this->terms = new OrderTerms(
            'shop-a',
            'purchase-cod',
            'g1',
            1499,
            'USD',
            'sku-1',
            2,
            fulfillmentDeadline: $now + 2400,
            codPayment: $cod,
            codAdmissionContract: CodJournal::CLOSABLE
        );
        $authority = new Authority(DB::connection(), 'provider-worker', [$this->terms->subjectKey()], [
            $this->terms->subjectKey() => $recipe,
        ]);
        $this->store = MachineStore::configure($this->app, $authority, $this->provider, cod: $this->cod);
        $this->store->seed($this->terms);
        foreach ([$this->terms->descriptor('order'), $this->terms->physicalDescriptor()] as $graph) {
            foreach ($graph as $value) {
                $this->provider->plan(Request::fromArray($value), Status::Confirmed);
            }
        }
        $origin = WorkflowStub::make(
            FundingCheckout::class,
            'provider-order-' . $this->terms->subjectKey(),
            Catalogue::NAMESPACE
        );
        $origin->start($this->terms->subjectKey());
        return $origin;
    }

    protected function collect(string $id, int $amount): CodReceipt
    {
        $original = $this->terms->codPayment;
        $receipt = new CodReceipt(
            $original->identity->key(),
            $id,
            CodReceipt::COLLECTION,
            $amount,
            'USD',
            now()
                ->getTimestamp(),
            $original->collectionPoint
        );
        $this->cod->collections->record($original, $receipt);
        return $receipt;
    }

    protected function remit(CodReceipt $collection, string $id, int $amount): void
    {
        $this->cod->collections->record($this->terms->codPayment, new CodReceipt(
            $collection->payment,
            $id,
            CodReceipt::REMITTANCE,
            $amount,
            'USD',
            now()
                ->getTimestamp(),
            $collection->receipt
        ));
    }

    protected function wakePayment(string $id): void
    {
        WorkflowStub::loadRun($this->child('payment')->id, Catalogue::NAMESPACE)->withRequestId($id)
            ->signal('cod-observed', [
                'paid' => true,
                'merchantPaid' => true,
            ]);
        $this->pump();
    }

    protected function recordPhysical(string $kind): void
    {
        $this->provider->shipments()
            ->record(
                ShipmentDescriptor::fromArray($this->terms->physicalDescriptor()),
                'original-' . $kind,
                $kind,
                $this->terms->quantity
            );
        WorkflowStub::loadRun($this->child('shipment')->id, Catalogue::NAMESPACE)->withRequestId('physical-' . $kind)
            ->signal('physical-milestone');
        $this->pump();
    }

    protected function child(string $role): WorkflowRun
    {
        $actor = DB::table('provider_machine_actors')->where(
            'id',
            Authority::subjectId($this->terms->subjectKey(), $role)
        )->sole();
        return WorkflowRun::query()->findOrFail($actor->native_run);
    }

    protected function state(string $role): string
    {
        return $this->store->authority->subject($this->terms->subjectKey(), $role)
->state;
    }

    protected function pump(): void
    {
        $through = Carbon::now()->copy()->addSeconds(30);
        $this->drain(
            static fn (): bool => ! WorkflowTask::query()->whereRelation('run', 'namespace', Catalogue::NAMESPACE)
                ->where('status', TaskStatus::Ready)->where(
                    static fn ($q) => $q->whereNull('available_at')
                        ->orWhere('available_at', '<=', $through)
                )->exists()
        );
    }
}
