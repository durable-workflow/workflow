<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CartReservationDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CartReservationTerms;
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
use DurableWorkflow\Examples\ProviderStateMachines\CartCheckout;
use DurableWorkflow\Examples\ProviderStateMachines\CartPlan;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCatalogue;
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

/** Shared source-native cart setup and bounded explicit task driver. */
trait CartCheckoutMachineFixture
{
    use ProviderMachineFixture { tearDown as private tearDownProvider; }

    private IncomingPaymentProvider $incoming;

    private CartPlan $cart;

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
        $schema = require __DIR__ . '/../../examples/provider-state-machines/cart-schema.php';
        $schema(DB::connection()->getSchemaBuilder());
        $now = now()
            ->getTimestamp();
        $this->terms = new OrderTerms(
            'shop-a',
            'purchase-cart',
            'g1',
            1499,
            'USD',
            'sku-a',
            2,
            fulfillmentDeadline: $now + 7200,
            incomingPayment: new IncomingPaymentDescriptor(
                new Identity('shop-a', 'purchase-cart', 'g1', 'incoming'),
                'cart-payment-reference',
                1499,
                'USD',
                $now + 3600
            ),
            incomingAdmissionContract: IncomingPaymentJournal::CLOSABLE
        );
        $initial = $this->lease('initial', $now, $now + 1800);
        $this->cart = new CartPlan(
            'formal-order-cart-1',
            $initial,
            $this->lease('late', $now + 1800, $now + 3900, $initial->reserve->identity),
            $now + 3600
        );
        $this->providerPath = tempnam(sys_get_temp_dir(), 'native-cart-');
        $this->providerDb = new PDO('sqlite:' . $this->providerPath);
        $this->incoming = new IncomingPaymentProvider($this->providerDb, 'shop-a', static fn (): int => now()->getTimestamp());
        $this->provider = $this->incoming->commands;
        $this->provider->install();
        $this->provider->cartInventory()
            ->install();
        $this->provider->cartInventory()
            ->receive('sku-a', 'original-warehouse-receipt', 2);
        $this->provider->shipments()
            ->install();
        $this->incoming->install();
        $key = $this->terms->subjectKey();
        $authority = new Authority(DB::connection(), 'provider-worker', [$key], [
            $key => FundingCatalogue::BANK,
        ], [$key]);
        $this->store = MachineStore::configure($this->app, $authority, $this->provider, $this->incoming);
        $this->store->seed($this->terms, $this->cart);
        foreach ([$this->cart->initial, $this->cart->late] as $lease) {
            $this->provider->plan($lease->reserve, Status::Confirmed);
            $this->provider->plan($lease->release, Status::Confirmed);
        }
        foreach ($this->terms->physicalDescriptor() as $value) {
            $this->provider->plan(Request::fromArray($value), Status::Confirmed);
        }
    }

    protected function tearDown(): void
    {
        foreach (['provider_cart_orders', 'provider_cart_purchases'] as $table) {
            DB::connection()->getSchemaBuilder()->dropIfExists($table);
        }
        unset($this->incoming);
        $this->tearDownProvider();
    }

    private function startCart(Status $late = Status::Confirmed): WorkflowStub
    {
        $this->provider->plan($this->cart->late->reserve, $late);
        $origin = WorkflowStub::make(
            CartCheckout::class,
            'provider-order-' . $this->terms->subjectKey(),
            Catalogue::NAMESPACE
        );
        $origin->start($this->terms->subjectKey());
        $this->pump();
        return $origin;
    }

    private function pay(string $transfer, Status $refund = Status::Confirmed, bool $pump = true): void
    {
        $receipt = new IncomingPaymentReceipt(
            $this->terms->incomingPayment->identity->key(),
            $transfer,
            1499,
            'USD',
            now()
                ->getTimestamp()
        );
        $this->incoming->receipts->record($this->terms->incomingPayment, $receipt);
        $binding = $this->store->authority->plan($this->terms->subjectKey());
        $request = IncomingPaymentOperation::refundRequest(
            $binding->childScope('payment'),
            'payment.incoming',
            $this->terms->incomingPayment,
            $receipt
        );
        $this->provider->plan($request, $refund);
        WorkflowStub::loadRun($this->child('payment')->id, Catalogue::NAMESPACE)->withRequestId($transfer)->signal(
            'incoming-observed'
        );
        if ($pump) {
            $this->pump();
        }
    }

    private function expireCart(): void
    {
        Carbon::setTestNow(now()->copy()->setTimestamp($this->cart->initial->terms->expiresAt + 1));
        $this->pump();
    }

    private function physical(string $event): void
    {
        $this->provider->shipments()
            ->record(
                ShipmentDescriptor::fromArray($this->terms->physicalDescriptor()),
                'original-' . $event,
                $event,
                2
            );
        WorkflowStub::loadRun($this->child('shipment')->id, Catalogue::NAMESPACE)->withRequestId(
            'physical-' . $event
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

    private function lease(
        string $step,
        int $createdAt,
        int $expiresAt,
        ?Identity $previous = null
    ): CartReservationDescriptor {
        $reserve = new Identity('shop-a', 'purchase-cart', 'g1', 'cart.' . $step . '.reserve');
        $release = new Identity('shop-a', 'purchase-cart', 'g1', 'cart.' . $step . '.release');
        $terms = (new CartReservationTerms(
            'cart-before-formal-order',
            'sku-a',
            2,
            $createdAt,
            $expiresAt,
            $release->operation,
            $previous
        ))->toArray();
        return new CartReservationDescriptor(new Request($reserve, 'reserve', $terms), new Request(
            $release,
            'release',
            $terms,
            $reserve
        ));
    }
}
