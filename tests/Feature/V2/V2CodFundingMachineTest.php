<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodPaymentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodPaymentProvider;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\CodFundingPayment;
use DurableWorkflow\Examples\ProviderStateMachines\CodFundingPolicy;
use DurableWorkflow\Examples\ProviderStateMachines\CodInspectionStore;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCheckout;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProviderMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Recorded native COD source cases; installed and full customer-matrix acceptance remain separate. */
final class V2CodFundingMachineTest extends TestCase
{
    use ProviderMachineFixture { tearDown as private tearDownProvider; }

    private ?CodPaymentProvider $cod = null;

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

    public static function policies(): array
    {
        return array_map(static fn (string $recipe): array => [$recipe], CodFundingPolicy::recipes());
    }

    #[DataProvider('policies')]
    public function testOriginalCarrierOrderSeparatesPayerCollectionFromLateMerchantSettlement(string $recipe): void
    {
        $origin = $this->startCod($recipe);
        $this->assertSame('ready', $this->state('order'), $this->progress());
        $this->assertSame('ready', $this->state('payment'), $this->progress());
        $this->assertSame(CodFundingPayment::class, $this->child('payment')->workflow_class);
        $origin->withRequestId('fulfill-original-cod')
            ->signal('fulfill');
        $this->pump();
        $this->assertSame('booked', $this->state('shipment'), $this->progress());
        $this->recordPhysical('handed-over');
        if ($recipe === CodFundingPolicy::AFTER) {
            $this->recordPhysical('delivered');
        }
        $receipt = $this->collect('original-collection', 1499);
        $this->wakePayment('original-payer-paid');
        $this->assertSame('collected', $this->state('payment'), $this->progress());
        if ($recipe === CodFundingPolicy::BEFORE) {
            $this->recordPhysical('delivered');
        }
        $this->assertSame('delivered', $this->state('shipment'), $this->progress());
        $this->assertSame('ready', $this->state('order'));
        $this->remit($receipt, 'first-merchant-remittance', 500);
        $this->wakePayment('partial-merchant-receipt');
        $this->assertSame('collected', $this->state('payment'), $this->progress());
        $originalDeadline = $this->terms->codPayment->remittanceDeadline;
        Carbon::setTestNow(Carbon::createFromTimestampUTC($originalDeadline + 1));
        $this->pump();
        $this->wakePayment('merchant-deadline-passed');
        $this->assertSame('ready', $this->state('order'), $this->progress());
        $this->assertSame('collected', $this->state('payment'));
        $facts = $this->cod->inspect($this->terms->codPayment)['facts'];
        $this->assertSame(999, $facts['outstanding_remittance_minor']);
        $this->assertTrue($facts['remittance_deadline_passed']);
        $this->assertSame(
            $originalDeadline,
            $this->cod->inspect($this->terms->codPayment)['original']['remittanceDeadline']
        );
        $this->assertSame(['reserve', 'ship-book', 'deduct-stock'], $this->effects());
        $this->remit($receipt, 'remaining-merchant-remittance', 999);
        $this->wakePayment('original-merchant-paid');
        $this->assertSame('paid', $this->state('payment'), $this->progress());
        $this->assertSame('fulfilled', $this->state('order'), $this->progress());
        $before = $this->effects();
        app(WorkflowReplayer::class)->replay($this->child('payment'));
        $this->assertSame($before, $this->effects());
        $this->assertSame(0, $this->cod->inspect($this->terms->codPayment)['facts']['outstanding_remittance_minor']);
    }

    public function testSpoofedAndPartialCollectionDoNotConfirmPayerOrMerchantPayment(): void
    {
        $origin = $this->startCod(CodFundingPolicy::BEFORE);
        $this->wakePayment('forged-paid-hint');
        $this->assertSame('ready', $this->state('payment'), $this->progress());
        $origin->withRequestId('fulfill-partial-cod')
            ->signal('fulfill');
        $this->pump();
        $this->recordPhysical('handed-over');
        $partial = $this->collect('partial-collection', 500);
        $this->wakePayment('partial-payer-hint');
        $this->assertSame('ready', $this->state('payment'), $this->progress());
        $this->cod->collections->record($this->terms->codPayment, $partial);
        $this->assertSame(500, $this->cod->inspect($this->terms->codPayment)['facts']['collected_amount_minor']);
        $this->collect('remaining-collection', 999);
        $this->wakePayment('full-payer-hint');
        $this->assertSame('collected', $this->state('payment'), $this->progress());
        $this->assertSame('ready', $this->state('order'));
        $this->assertSame(1499, $this->cod->inspect($this->terms->codPayment)['facts']['outstanding_remittance_minor']);
        $this->assertSame(['reserve', 'ship-book', 'deduct-stock'], $this->effects());
    }

    public function testRefundProcessingRetainsPayerAndMerchantResponsibilitiesWithoutReleasingStock(): void
    {
        $origin = $this->startCod(CodFundingPolicy::BEFORE);
        $origin->withRequestId('fulfill-cod-to-cancel')
            ->signal('fulfill');
        $this->pump();
        $this->recordPhysical('handed-over');
        $receipt = $this->collect('payer-to-refund', 1499);
        $this->wakePayment('payer-collected-before-cancel');
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        $refund = CodPaymentOperation::refundRequest(
            $plan->childScope('payment'),
            'payment.cod.payer',
            $this->terms->codPayment,
            $receipt
        );
        $this->provider->plan($refund, Status::Processing);
        app(CancellationStore::class)->request($this->terms->subjectKey(), $origin->runId(), 'cancel-original-cod');
        $this->pump();
        $graph = ShipmentDescriptor::fromArray($this->terms->physicalDescriptor());
        $this->provider->shipments()
            ->record($graph, 'original-warehouse-receipt', 'received', $this->terms->quantity);
        $this->provider->shipments()
            ->record(
                $graph,
                'original-warehouse-inspection',
                'inspected',
                $this->terms->quantity,
                $this->terms->quantity,
                $this->terms->quantity
            );
        app(CancellationStore::class)->request(
            $this->terms->subjectKey(),
            $origin->runId(),
            'original-return-accepted'
        );
        $this->pump();
        $this->assertSame(Status::Processing, $this->provider->lookup($refund)->status, $this->progress());
        $this->assertSame(
            'requested',
            (new ScopeRepository())->metadata($plan->rootScope())['status'],
            $this->progress()
        );
        $this->assertSame('ready', $this->state('order'));
        $this->assertNotContains('release', $this->effects());
        $this->assertSame(1499, $this->cod->inspect($this->terms->codPayment)['facts']['outstanding_remittance_minor']);
        $this->assertNotSame('verified', (new ScopeRepository())->metadata($plan->childScope('payment'))['status']);
        $this->provider->resolve($refund, Status::Confirmed);
        app(CancellationStore::class)->request($this->terms->subjectKey(), $origin->runId(), 'original-payer-refunded');
        $this->pump();
        $this->assertSame(
            1,
            count(array_filter($this->effects(), static fn (string $action): bool => $action === 'refund-cod'))
        );
        $this->assertNotContains('release', $this->effects());
        $this->assertSame('requested', (new ScopeRepository())->metadata($plan->childScope('payment'))['status']);
        $this->assertSame(1499, $this->cod->inspect($this->terms->codPayment)['facts']['outstanding_remittance_minor']);
    }

    public function testCancellationBeforePayerCollectionFencesBothMoneyDutiesAndReleasesOnlyOriginalStock(): void
    {
        $origin = $this->startCod(CodFundingPolicy::BEFORE);
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        app(CancellationStore::class)->request(
            $this->terms->subjectKey(),
            $origin->runId(),
            'cancel-before-cod-collection'
        );
        $this->pump();
        $this->assertSame(
            'verified',
            (new ScopeRepository())->metadata($plan->childScope('payment'))['status'],
            $this->progress()
        );
        $this->assertSame('verified', (new ScopeRepository())->metadata($plan->rootScope())['status']);
        $this->assertSame('cancelled', $this->state('order'));
        $this->assertSame(['reserve', 'release'], $this->effects());
        $this->assertTrue($this->cod->inspect($this->terms->codPayment)['collectionAdmissionClosed']);
    }

    public function testRecordedCodInspectionRejectsSubstitutedMoneyCarrierContractAndClosureTime(): void
    {
        $this->startCod(CodFundingPolicy::BEFORE);
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        $run = $this->child('payment');
        $store = app(CodInspectionStore::class);
        $read = fn (): array => $store->inspect(
            $run,
            'original-recorded-cod-observation',
            $plan->childScope('payment')
                ->toArray(),
            $this->terms->codDescriptor()
        );
        $original = $read();
        $payload = DB::table('provider_machine_assessments')->where('id', $original['snapshotId'])->sole()->payload;
        foreach ([
            'open-time',
            'future-close',
            'missing-close',
            'merchant-money',
            'carrier-owner',
            'carrier-receipt',
            'contract',
        ] as $case) {
            $value = $original;
            switch ($case) {
                case 'open-time':
                    $value['cod']['closedAt'] = '1000';
                    break;
                case 'future-close':
                    $value['cod']['collectionAdmissionClosed'] = true;
                    $value['cod']['closedAt'] = $value['cod']['observedAt'] + 1;
                    break;
                case 'missing-close':
                    $value['cod']['collectionAdmissionClosed'] = true;
                    break;
                case 'merchant-money':
                    $value['cod']['facts']['merchant_receipt_confirmed'] = true;
                    break;
                case 'carrier-owner':
                    $value['cod']['carrierContract']['originalFingerprint'] = str_repeat('0', 64);
                    break;
                case 'carrier-receipt':
                    $value['cod']['carrierContract']['collections'][] = [
                        'receipt' => 'foreign',
                        'sha256' => str_repeat('0', 64),
                        'phase' => CodDescriptor::BEFORE,
                    ];
                    break;
                case 'contract':
                    $value['cod']['admissionContract'] = CodJournal::OPEN;
                    break;
            }
            $changed = PortableDescriptor::encode($value);
            DB::table('provider_machine_assessments')->where('id', $original['snapshotId'])->update([
                'payload' => $changed,
                'payload_sha256' => hash('sha256', $changed),
            ]);
            try {
                $read();
                $this->fail('Recorded original COD substitution was accepted: ' . $case);
            } catch (LogicException|InvalidArgumentException) {
                $this->assertTrue(true, $case);
            } finally {
                DB::table('provider_machine_assessments')->where('id', $original['snapshotId'])->update([
                    'payload' => $payload,
                    'payload_sha256' => hash('sha256', $payload),
                ]);
            }
        }
        $this->assertSame($original, $read());
        $this->assertSame(['reserve'], $this->effects());
    }

    private function startCod(string $recipe): WorkflowStub
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
        $this->pump();
        return $origin;
    }

    private function collect(string $id, int $amount): CodReceipt
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

    private function remit(CodReceipt $collection, string $id, int $amount): void
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

    private function wakePayment(string $id): void
    {
        WorkflowStub::loadRun($this->child('payment')->id, Catalogue::NAMESPACE)->withRequestId($id)
            ->signal('cod-observed', [
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
