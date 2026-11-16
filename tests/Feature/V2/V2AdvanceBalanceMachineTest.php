<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AdvanceBalanceProvider;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AdvanceBalanceTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\AdvanceBalancePayment;
use DurableWorkflow\Examples\ProviderStateMachines\AdvanceBalancePolicy;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCheckout;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProviderMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Recorded native advance/carrier-balance source cases; installed and full customer-matrix acceptance remain separate. */
final class V2AdvanceBalanceMachineTest extends TestCase
{
    use ProviderMachineFixture { tearDown as private tearDownProvider; }

    private ?AdvanceBalanceProvider $mixed = null;

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
            $schema = require __DIR__ . '/../../../examples/' . $example . '/schema.php';
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

    public static function policies(): array
    {
        return array_map(static fn (string $recipe): array => [$recipe], AdvanceBalancePolicy::recipes());
    }

    #[DataProvider('policies')]
    public function testAdvancePermitsPreparationButOnlyBothOriginalPortionsAndMerchantReceiptSettleThePurchase(
        string $recipe
    ): void {
        $origin = $this->startAdvance($recipe);
        $this->assertSame(AdvanceBalancePayment::class, $this->child('payment')->workflow_class);
        $this->assertSame('received', $this->state('order'), $this->progress());
        $this->assertSame([], $this->effects());
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        $ledger = (new ScopeRepository())->inspect($plan->childScope('payment'));
        $this->assertSame(['owner', 'payment.cod.merchant', 'payment.incoming', 'payment.cod.payer'], array_map(
            static fn (array $row): string => Obligation::fromArray($row['descriptor'])->key,
            $ledger['obligations']
        ));
        $this->wake('incoming-observed', 'spoofed-bank-paid');
        $this->wake('cod-observed', 'spoofed-carrier-paid');
        $this->assertSame('received', $this->state('payment'));
        $this->assertSame([], $this->effects());
        $this->receive('first-advance-portion', 200);
        $this->wake('incoming-observed', 'partial-advance');
        $this->assertSame('received', $this->state('order'));
        $this->receive('remaining-advance-portion', 300);
        $this->wake('incoming-observed', 'original-advance-confirmed');
        $this->assertSame('ready', $this->state('order'), $this->progress());
        $this->assertSame('ready', $this->state('payment'));
        $this->assertSame(['reserve'], $this->effects());
        $advanceDeadline = $this->terms->advanceBalance->advance->deadline;
        Carbon::setTestNow(Carbon::createFromTimestampUTC($advanceDeadline + 1));
        $this->pump();
        $this->assertSame('ready', $this->state('payment'), $this->progress());
        $this->assertSame($advanceDeadline, $this->terms->advanceBalance->advance->deadline);
        $origin->withRequestId('fulfill-after-original-advance')
            ->signal('fulfill');
        $this->pump();
        $this->assertSame('booked', $this->state('shipment'), $this->progress());
        $this->recordPhysical('handed-over');
        if ($recipe === AdvanceBalancePolicy::AFTER) {
            $this->recordPhysical('delivered');
        }
        $receipt = $this->collect('original-carrier-balance', 999);
        $this->wake('cod-observed', 'original-balance-collected');
        $this->assertSame('collected', $this->state('payment'), $this->progress());
        if ($recipe === AdvanceBalancePolicy::BEFORE) {
            $this->recordPhysical('delivered');
        }
        $this->assertSame('ready', $this->state('order'));
        $this->remit($receipt, 'partial-merchant-balance', 400);
        $this->wake('cod-observed', 'partial-balance-remittance');
        $this->assertSame('collected', $this->state('payment'));
        $deadline = $this->terms->advanceBalance->balance->remittanceDeadline;
        Carbon::setTestNow(Carbon::createFromTimestampUTC($deadline + 1));
        $this->pump();
        $this->wake('cod-observed', 'original-merchant-deadline-passed');
        $this->assertSame('collected', $this->state('payment'), $this->progress());
        $this->assertSame('ready', $this->state('order'));
        $this->assertSame(
            599,
            $this->mixed->cod->inspect($this->terms->advanceBalance->balance)['facts']['outstanding_remittance_minor']
        );
        $this->remit($receipt, 'remaining-merchant-balance', 599);
        $this->wake('cod-observed', 'original-full-merchant-receipt');
        $this->assertSame('paid', $this->state('payment'), $this->progress());
        $this->assertSame('fulfilled', $this->state('order'));
        $this->assertSame(
            500,
            $this->mixed->incoming->receipts->inspect($this->terms->advanceBalance->advance)['receivedAmountMinor']
        );
        $this->assertSame(['reserve', 'ship-book', 'deduct-stock'], $this->effects());
        $effects = $this->effects();
        app(WorkflowReplayer::class)->replay($this->child('payment'));
        $this->assertSame($effects, $this->effects());
    }

    public static function receivedAdvances(): array
    {
        return [
            'partial-advance' => [200],
            'full-advance' => [500],
        ];
    }

    #[DataProvider('receivedAdvances')]
    public function testCancellationRefundsOnlyTheOriginalBankAdvanceWhenCarrierBalanceNeverArrived(int $amount): void
    {
        $origin = $this->startAdvance(AdvanceBalancePolicy::BEFORE);
        $receipt = $this->receive('original-bank-advance', $amount);
        $this->wake('incoming-observed', 'original-advance-paid');
        $this->assertSame($amount === 500 ? 'ready' : 'received', $this->state('order'), $this->progress());
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        $refund = IncomingPaymentOperation::refundRequest(
            $plan->childScope('payment'),
            'payment.incoming',
            $this->terms->advanceBalance->advance,
            $receipt
        );
        $this->provider->plan($refund, Status::Confirmed);
        $store = app(CancellationStore::class);
        $store->request($this->terms->subjectKey(), $origin->runId(), 'cancel-original-advance-order');
        $this->pump();
        $this->assertSame(
            'verified',
            (new ScopeRepository())->metadata($plan->rootScope())['status'],
            $this->progress()
        );
        $store->finish($this->terms->subjectKey(), $origin->runId(), 'cancel-original-advance-order');
        $this->pump();
        $this->assertSame('cancelled', $this->state('order'), $this->progress());
        $this->assertSame(
            $amount === 500 ? ['reserve', 'refund-incoming', 'release'] : ['refund-incoming'],
            $this->effects()
        );
        $this->assertSame(
            [],
            $this->mixed->cod->collections->inspect($this->terms->advanceBalance->balance)['receipts']
        );
        try {
            $this->receive('late-after-original-cancellation', 1);
            $this->fail('The original closable advance cannot accept new money after verified cancellation.');
        } catch (LogicException) {
            $this->assertSame(
                $amount,
                $this->mixed->incoming->receipts->inspect($this->terms->advanceBalance->advance)['receivedAmountMinor']
            );
        }
    }

    private function startAdvance(string $recipe): WorkflowStub
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
        $this->pump();
        return $origin;
    }

    private function receive(string $id, int $amount): IncomingPaymentReceipt
    {
        $original = $this->terms->advanceBalance->advance;
        $receipt = new IncomingPaymentReceipt($original->identity->key(), $id, $amount, 'USD', now()->getTimestamp());
        $this->mixed->incoming->receipts->record($original, $receipt);
        return $receipt;
    }

    private function collect(string $id, int $amount): CodReceipt
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

    private function remit(CodReceipt $collection, string $id, int $amount): void
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

    private function wake(string $signal, string $id): void
    {
        WorkflowStub::loadRun($this->child('payment')->id, Catalogue::NAMESPACE)->withRequestId($id)
            ->signal($signal, [
                'paid' => true,
                'merchantPaid' => true,
            ]);
        $this->pump();
    }

    private function recordPhysical(string $kind): void
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

    private function child(string $role): WorkflowRun
    {
        $actor = DB::table('provider_machine_actors')->where(
            'id',
            Authority::subjectId($this->terms->subjectKey(), $role)
        )->sole();
        return WorkflowRun::query()->findOrFail($actor->native_run);
    }

    private function state(string $role): string
    {
        return $this->store->authority->subject($this->terms->subjectKey(), $role)
->state;
    }

    private function pump(): void
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
