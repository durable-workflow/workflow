<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AuthorizationTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentProvider;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCheckout;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProviderMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Recorded native source integration; no installed, cold-process or complete customer-matrix qualification. */
final class V2CommonFundingMachineTest extends TestCase
{
    use ProviderMachineFixture { tearDown as private tearDownProvider; }

    private ?IncomingPaymentProvider $incoming = null;

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
        $this->providerPath = tempnam(sys_get_temp_dir(), 'common-funding-');
        $this->providerDb = new PDO('sqlite:' . $this->providerPath);
        $this->incoming = new IncomingPaymentProvider($this->providerDb, 'shop-a', static fn (): int => now()->getTimestamp());
        $this->provider = $this->incoming->commands;
        $this->provider->install();
        $this->provider->installAuthorizationSupport();
        $this->provider->shipments()
            ->install();
        $this->incoming->install();
    }

    protected function tearDown(): void
    {
        $this->incoming = null;
        $this->tearDownProvider();
    }

    public static function policies(): array
    {
        return array_map(static fn (string $recipe): array => [$recipe], FundingCatalogue::recipes());
    }

    #[DataProvider('policies')]
    public function testAllPoliciesUseTheSameNativeRouteAndOriginalPhysicalDelivery(string $recipe): void
    {
        $origin = $this->startFunding($recipe);
        if ($recipe === FundingCatalogue::BANK) {
            $this->receive('original-bank-transfer', 1499);
            $this->wakePayment('bank-transfer-confirmed');
        }
        $this->assertSame('ready', $this->state('order'), $this->progress());
        $this->assertSame(
            $recipe === FundingCatalogue::AFTER_DELIVERY ? 'ready' : 'paid',
            $this->state('payment'),
            $this->progress()
        );
        $origin->withRequestId('fulfill-original-purchase')
            ->signal('fulfill');
        $this->pump();
        $this->assertSame('booked', $this->state('shipment'), $this->progress());
        $this->recordPhysical('handed-over');
        $this->recordPhysical('delivered');
        $this->assertSame('fulfilled', $this->state('order'), $this->progress());
        $this->assertSame('paid', $this->state('payment'));
        $this->assertSame('delivered', $this->state('shipment'));
        $publications = DB::table('example_machine_publications')->where(
            'subject_id',
            Authority::subjectId($this->terms->subjectKey(), 'payment')
        )->get();
        $states = $publications->map(
            static fn (object $row): array => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR)
        )
            ->all();
        usort($states, static fn (array $a, array $b): int => (int) $a['revision'] <=> (int) $b['revision']);
        $this->assertSame(['ready', 'collected', 'paid'], array_column($states, 'state'));
        if ($recipe === FundingCatalogue::BANK) {
            $this->assertSame(['reserve', 'ship-book', 'deduct-stock'], $this->effects());
        } elseif ($recipe === FundingCatalogue::AFTER_DELIVERY) {
            $this->assertSame(
                ['authorize', 'reserve', 'ship-book', 'deduct-stock', 'capture-authorized'],
                $this->effects()
            );
        }
        $before = $this->effects();
        app(WorkflowReplayer::class)->replay($this->child('payment'));
        $this->assertSame($before, $this->effects());
    }

    public function testPartialAndSpoofedPaymentCannotReserveOrFulfillAndDuplicateReceiptDoesNotRepeatWork(): void
    {
        $this->startFunding(FundingCatalogue::BANK);
        $partial = $this->receive('first-portion', 500);
        $this->wakePayment('untrusted-full-payment-hint');
        $this->assertSame('received', $this->state('order'), $this->progress());
        $this->assertSame('received', $this->state('payment'));
        $this->assertSame([], $this->effects());
        $this->incoming->receipts->record($this->terms->incomingPayment, $partial);
        $this->receive('original-balance', 999);
        $this->wakePayment('balance-confirmed');
        $this->assertSame('ready', $this->state('order'), $this->progress());
        $this->assertSame('paid', $this->state('payment'));
        $this->assertSame(['reserve'], $this->effects());
        $this->assertSame(
            2,
            (int) $this->providerDb->query('SELECT COUNT(*) FROM incoming_payment_receipts')
                ->fetchColumn()
        );
        $this->assertFalse($this->wakePayment('duplicate-committed-payment'));
        $this->assertSame(['reserve'], $this->effects());
    }

    public function testCancellationWaitsForOriginalBankRefundBeforeReleasingReservedStock(): void
    {
        $origin = $this->startFunding(FundingCatalogue::BANK);
        $receipt = $this->receive('original-money', 1499);
        $this->wakePayment('incoming-confirmed');
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        $refund = IncomingPaymentOperation::refundRequest(
            $plan->childScope('payment'),
            'payment.incoming',
            $this->terms->incomingPayment,
            $receipt
        );
        $this->provider->plan($refund, Status::Processing);
        $store = app(CancellationStore::class);
        $store->request($this->terms->subjectKey(), $origin->runId(), 'original-bank-cancellation');
        $this->pump();
        $this->assertSame(
            'requested',
            (new ScopeRepository())->metadata($plan->rootScope())['status'],
            $this->progress()
        );
        $this->assertSame(['reserve'], $this->effects());
        $this->provider->resolve($refund, Status::Confirmed);
        $store->request($this->terms->subjectKey(), $origin->runId(), 'original-refund-confirmed');
        $this->pump();
        $this->assertSame(
            'verified',
            (new ScopeRepository())->metadata($plan->rootScope())['status'],
            $this->progress()
        );
        $this->assertSame(['reserve', 'refund-incoming', 'release'], $this->effects());
        $this->assertSame(
            1499,
            $this->incoming->receipts->inspect($this->terms->incomingPayment)['receivedAmountMinor']
        );
    }

    public function testUnknownReservationDoesNotRefundMoneyUntilOriginalStockRefusalIsConfirmed(): void
    {
        $origin = $this->startFunding(FundingCatalogue::BANK, Status::Unknown);
        $receipt = $this->receive('paid-before-stock', 1499);
        $this->wakePayment('bank-paid-stock-unknown');
        $this->assertSame('received', $this->state('order'), $this->progress());
        $this->assertSame('paid', $this->state('payment'));
        $this->assertSame([], $this->effects());
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        $refund = IncomingPaymentOperation::refundRequest(
            $plan->childScope('payment'),
            'payment.incoming',
            $this->terms->incomingPayment,
            $receipt
        );
        $this->provider->plan($refund, Status::Confirmed);
        $this->provider->resolve(Request::fromArray($this->terms->descriptor('order')['forward']), Status::Refused);
        $origin->withRequestId('original-stock-refusal')
            ->signal('reconcile-stock');
        $this->pump();
        $this->assertSame(['refund-incoming'], $this->effects(), $this->progress());
        $this->assertSame('verified', (new ScopeRepository())->metadata($plan->rootScope())['status']);
        $this->assertNotSame('ready', $this->state('order'));
    }

    public function testLateOriginalPaymentIsRefundedInsteadOfPromotedToFulfillment(): void
    {
        $origin = $this->startFunding(FundingCatalogue::BANK);
        $deadline = $this->terms->incomingPayment->deadline;
        Carbon::setTestNow(Carbon::createFromTimestampUTC($deadline + 1));
        $receipt = $this->receive('late-after-original-deadline', 1499);
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        $refund = IncomingPaymentOperation::refundRequest(
            $plan->childScope('payment'),
            'payment.incoming',
            $this->terms->incomingPayment,
            $receipt
        );
        $this->provider->plan($refund, Status::Confirmed);
        $this->wakePayment('late-paid-hint');
        $this->assertSame(['refund-incoming'], $this->effects(), $this->progress());
        $this->assertNotSame('ready', $this->state('order'));
        $this->assertNotSame('paid', $this->state('payment'));
        $this->assertSame($deadline, $this->terms->incomingPayment->deadline);
        $this->assertSame('verified', (new ScopeRepository())->metadata($plan->rootScope())['status']);
        $this->assertSame(
            1499,
            $this->incoming->receipts->inspect($this->terms->incomingPayment)['receivedAmountMinor']
        );
    }

    public function testOpenBankAdmissionRetainsAndRefundsNewMoneyAfterBusinessCancellation(): void
    {
        $origin = $this->startFunding(FundingCatalogue::BANK, Status::Confirmed, IncomingPaymentJournal::OPEN);
        $first = $this->receive('partial-before-cancellation', 500);
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        $refund = IncomingPaymentOperation::refundRequest(
            $plan->childScope('payment'),
            'payment.incoming',
            $this->terms->incomingPayment,
            $first
        );
        $this->provider->plan($refund, Status::Confirmed);
        app(CancellationStore::class)->request(
            $this->terms->subjectKey(),
            $origin->runId(),
            'cancel-open-bank-reference'
        );
        $this->pump();
        $this->assertSame('requested', (new ScopeRepository())->metadata($plan->rootScope())['status']);
        $late = $this->receive('late-after-cancellation', 999);
        $lateRefund = IncomingPaymentOperation::refundRequest(
            $plan->childScope('payment'),
            'payment.incoming',
            $this->terms->incomingPayment,
            $late
        );
        $this->provider->plan($lateRefund, Status::Confirmed);
        app(CancellationStore::class)->request(
            $this->terms->subjectKey(),
            $origin->runId(),
            'reconcile-late-open-bank-money'
        );
        $this->pump();
        $this->assertSame(['refund-incoming', 'refund-incoming'], $this->effects(), $this->progress());
        $this->assertSame('requested', (new ScopeRepository())->metadata($plan->rootScope())['status']);
        $this->assertSame('received', $this->state('order'));
        $this->assertSame('received', $this->state('payment'));
        $this->assertSame(
            1499,
            $this->incoming->receipts->inspect($this->terms->incomingPayment)['receivedAmountMinor']
        );
    }

    private function startFunding(
        string $recipe,
        Status $stock = Status::Confirmed,
        string $contract = IncomingPaymentJournal::CLOSABLE
    ): WorkflowStub {
        $now = now()
            ->getTimestamp();
        $this->terms = new OrderTerms(
            'shop-a',
            'purchase-funding',
            'g1',
            1499,
            'USD',
            'sku-1',
            2,
            FundingCatalogue::requiresAuthorization($recipe) ? new AuthorizationTerms(1499, 'USD', $now + 3600) : null,
            $now + 7200,
            $recipe === FundingCatalogue::BANK ? new IncomingPaymentDescriptor(new Identity(
                'shop-a',
                'purchase-funding',
                'g1',
                'incoming'
            ), 'original-bank-reference', 1499, 'USD', $now + 3600) : null,
            $recipe === FundingCatalogue::BANK ? $contract : IncomingPaymentJournal::OPEN
        );
        $authority = new Authority(DB::connection(), 'provider-worker', [$this->terms->subjectKey()], [
            $this->terms->subjectKey() => $recipe,
        ]);
        $this->store = MachineStore::configure($this->app, $authority, $this->provider, $this->incoming);
        $this->store->seed($this->terms);
        $graphs = [$this->terms->descriptor('order'), $this->terms->physicalDescriptor()];
        if ($recipe !== FundingCatalogue::BANK) {
            $graphs[] = FundingCatalogue::requiresAuthorization(
                $recipe
            ) ? $this->terms->financialDescriptor() : $this->terms->descriptor('payment');
        }
        foreach ($graphs as $graph) {
            foreach ($graph as $value) {
                $request = Request::fromArray($value);
                $this->provider->plan($request, $request->action === 'reserve' ? $stock : Status::Confirmed);
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
        $receipt = new IncomingPaymentReceipt(
            $this->terms->incomingPayment->identity->key(),
            $id,
            $amount,
            'USD',
            now()
                ->getTimestamp()
        );
        $this->incoming->receipts->record($this->terms->incomingPayment, $receipt);
        return $receipt;
    }

    private function wakePayment(string $id): bool
    {
        $payment = WorkflowStub::loadRun($this->child('payment')->id, Catalogue::NAMESPACE)->withRequestId($id);
        $result = $payment->attemptSignal('incoming-observed', [
            'full' => true,
        ]);
        $this->pump();
        return $result->accepted();
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
        WorkflowStub::loadRun($this->child('shipment')->id, Catalogue::NAMESPACE)->withRequestId(
            'physical-' . $kind
        )->signal('physical-milestone');
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
