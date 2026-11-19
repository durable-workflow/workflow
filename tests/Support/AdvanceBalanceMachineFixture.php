<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AdvanceBalanceProvider;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AdvanceBalanceTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\AdvanceBalancePolicy;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
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

/** Original mixed-payment host and explicit native task driver shared by route and refund cases. */
trait AdvanceBalanceMachineFixture
{
    use ProviderMachineFixture { tearDown as private tearDownProvider; }

    protected ?AdvanceBalanceProvider $mixed = null;

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
        $this->mixed = new AdvanceBalanceProvider($this->providerDb, 'shop-a', static fn (): int => now()->getTimestamp());
        $this->provider = $this->mixed->commands;
        $this->mixed->install();
    }

    protected function tearDown(): void
    {
        $this->mixed = null;
        $this->tearDownProvider();
    }

    protected function startAdvance(string $recipe): WorkflowStub
    {
        $origin = $this->beginAdvance($recipe);
        $this->pump();
        return $origin;
    }

    /**
     * Seeds and starts the original Order without running any of its tasks.
     */
    protected function beginAdvance(string $recipe): WorkflowStub
    {
        $now = now()
            ->getTimestamp();
        $base = new OrderTerms('shop-a', 'purchase-advance', 'g1', 1499, 'USD', 'sku-1', 2);
        $advance = new IncomingPaymentDescriptor(
            new Identity('shop-a', 'purchase-advance', 'g1', 'original-bank-advance'),
            'bank-original',
            500,
            'USD',
            $now + 600
        );
        $balance = new CodDescriptor(
            new Identity('shop-a', 'purchase-advance', 'g1', 'original-carrier-balance'),
            ShipmentDescriptor::fromArray($base->physicalDescriptor()),
            'carrier-original',
            'point-original',
            999,
            'USD',
            $now + 1800,
            $now + 2400,
            AdvanceBalancePolicy::handover($recipe)
        );
        $parts = new AdvanceBalanceTerms(
            $advance,
            $balance,
            1499,
            'USD',
            IncomingPaymentJournal::CLOSABLE,
            CodJournal::CLOSABLE
        );
        $this->terms = new OrderTerms(
            'shop-a',
            'purchase-advance',
            'g1',
            1499,
            'USD',
            'sku-1',
            2,
            fulfillmentDeadline: $now + 3600,
            advanceBalance: $parts
        );
        $authority = new Authority(DB::connection(), 'provider-worker', [$this->terms->subjectKey()], [
            $this->terms->subjectKey() => $recipe,
        ]);
        $this->store = MachineStore::configure(
            $this->app,
            $authority,
            $this->provider,
            $this->mixed->incoming,
            $this->mixed->cod
        );
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

    protected function receive(string $id, int $amount): IncomingPaymentReceipt
    {
        $original = $this->terms->advanceBalance->advance;
        $receipt = new IncomingPaymentReceipt($original->identity->key(), $id, $amount, 'USD', now()->getTimestamp());
        $this->mixed->incoming->receipts->record($original, $receipt);
        return $receipt;
    }

    protected function collect(string $id, int $amount): CodReceipt
    {
        $original = $this->terms->advanceBalance->balance;
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
        $this->mixed->cod->collections->record($original, $receipt);
        return $receipt;
    }

    protected function remit(CodReceipt $collection, string $id, int $amount): void
    {
        $this->mixed->cod->collections->record($this->terms->advanceBalance->balance, new CodReceipt(
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

    protected function wake(string $signal, string $id): void
    {
        WorkflowStub::loadRun($this->child('payment')->id, Catalogue::NAMESPACE)->withRequestId($id)
            ->signal($signal, [
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
