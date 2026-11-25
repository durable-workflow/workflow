<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundCommand;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\CheckoutOrder;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use DurableWorkflow\Examples\ProviderStateMachines\Payment;
use DurableWorkflow\Examples\ProviderStateMachines\StockAllocation;
use DurableWorkflow\Examples\ProviderStateMachines\StockPolicy;
use DurableWorkflow\Examples\ProviderStateMachines\StockWarehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProviderMachineFixture;
use Tests\Support\WorkerLossControl;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * STK-03: reserved stock found damaged or missing before payment is captured (reserve-first checkout), and
 * stock lost after allocation but before carrier handover (payment-first checkout). Damage found at allocation
 * after payment is in V2PartialStockSelectionTest. Every case releases only the suitable held units, once.
 */
final class V2StockLossMachineTest extends TestCase
{
    use ProviderMachineFixture;
    use WorkerLossControl;

    private StockWarehouse $warehouse;

    private OrderTerms $order;

    private Authority $authority;

    public static function beforePayment(): array
    {
        return [
            'cancel-all, damaged' => [StockAllocation::CANCEL, 'damaged'],
            'cancel-all, missing' => [StockAllocation::CANCEL, 'missing'],
            'wait-complete, damaged' => [StockAllocation::WAIT, 'damaged'],
            'wait-complete, missing' => [StockAllocation::WAIT, 'missing'],
        ];
    }

    #[DataProvider('beforePayment')]
    public function testUnsuitableUnitFoundBeforeCaptureReleasesTheReservationWithoutTakingMoney(
        string $policy,
        string $condition
    ): void {
        $stub = $this->checkout(Catalogue::COMMON_RESERVE_FIRST, $policy, null, stock: 3, untilAllocated: true);
        $key = $this->order->subjectKey();
        $this->assertSame(['reserve'], $this->effects());
        $this->warehouse->disposition($this->reservation(), 'allocation-inspection-1', [1], $condition);
        $this->drain();

        $this->assertSame('cancelled', $this->authority->subject($key, 'order')->state, $this->progress());
        $plan = $this->authority->stock->recorded($key);
        // Before capture nothing is owed back: the plan records no refund units.
        $this->assertSame(['cancel', $policy, 'original-allocation', [1], [0, 2], [], []], [
            $plan['action'], $plan['policy'], $plan['reason'], $plan[$condition . 'Units'], $plan['releaseUnits'],
            $plan['fulfillUnits'], $plan['requiredRefundUnits'],
        ]);
        // No capture, repayment, Payment child or booking; one reservation release.
        $this->assertSame(['reserve', 'release'], $this->effects());
        $this->assertSame([], $this->payouts());
        $this->assertSame(0, WorkflowRun::query()->where('workflow_class', Payment::class)->count());
        $this->assertSame(['stock-plan-original-allocation'], $this->cancellationKeys($key));
        $this->assertReleasedOnce([0, 2], $condition);
        $this->assertCancelledWithoutCompletion($stub);
        $this->assertReplayWithoutProviderCalls($stub);
    }

    /**
     * Without a fault the same reserve-first stock policy captures only after the complete quantity is held.
     */
    public function testCompleteStockBeforeCaptureStillBooksEveryUnit(): void
    {
        $stub = $this->checkout(Catalogue::COMMON_RESERVE_FIRST, StockAllocation::CANCEL, [0, 1, 2], stock: 3);
        $key = $this->order->subjectKey();
        $this->assertSame('booked', $this->authority->subject($key, 'order')->state, $this->progress());
        $plan = $this->authority->stock->recorded($key);
        $this->assertSame(['fulfill', [0, 1, 2], []], [
            $plan['action'], $plan['fulfillUnits'], $plan['requiredRefundUnits'],
        ]);
        $this->assertSame(['reserve', 'capture', 'book'], $this->effects());
        $this->assertSame([], $this->payouts());
        $this->assertReplayWithoutProviderCalls($stub);
    }

    /**
     * Fulfilling part of an unpaid order would need a capture smaller than the original quote. No existing
     * policy defines that, so the reserve-first grant refuses fulfill-partial before any execution.
     */
    public function testPartialFulfilmentBeforeCaptureIsRefusedAtTheTrustedGrant(): void
    {
        $key = $this->terms->subjectKey();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('reserve-first-common accepts only wait-complete or cancel-all');
        new Authority(DB::connection(), 'provider-worker', [$key], [
            $key => Catalogue::COMMON_RESERVE_FIRST,
        ], unitAmounts: [
            $key => $this->unitAmounts(),
        ], stockPolicies: [
            $key => StockAllocation::PARTIAL,
        ]);
    }

    /**
     * Payment-first, wait-complete: a held unit is lost while the Order waits for the rest of its quantity.
     * The next allocation observation records a cancellation even though the complete quantity is now held.
     */
    public function testUnitLostWhileWaitingForTheCompleteOrderCancelsInsteadOfBooking(): void
    {
        $stub = $this->checkout(Catalogue::PAYMENT_FIRST, StockAllocation::WAIT, null, untilWaiting: true);
        $key = $this->order->subjectKey();
        $this->assertSame('received', $this->authority->subject($key, 'order')->state, $this->progress());
        $this->assertSame(['capture', 'reserve'], $this->effects());
        $this->assertNull($this->authority->stock->recorded($key));

        $this->warehouse->disposition($this->reservation(), 'waiting-inspection-1', [1], 'damaged');
        $this->warehouse->receive('shop-a', 'sku-1', 'delivery-note-1', 1);
        $stub->signal('reconcile-stock');
        $this->drain();

        $this->assertSame('cancelled', $this->authority->subject($key, 'order')->state, $this->progress());
        $plan = $this->authority->stock->recorded($key);
        // The received unit was allocated to ordinal 2; the damaged ordinal 1 still stops fulfilment.
        $this->assertSame(['cancel', 'wait-complete', 'original-allocation', [1], [0, 2], [0, 1, 2], []], [
            $plan['action'], $plan['policy'], $plan['reason'], $plan['damagedUnits'], $plan['releaseUnits'],
            $plan['requiredRefundUnits'], $plan['fulfillUnits'],
        ]);
        $this->assertSame(['capture', 'reserve', 'release'], $this->effects());
        $this->assertSame([1499], $this->payouts());
        $this->assertSame(['stock-plan-original-allocation'], $this->cancellationKeys($key));
        $this->assertReleasedOnce([0, 2], 'damaged');
        $this->assertCancelledWithoutCompletion($stub);
        $this->assertReplayWithoutProviderCalls($stub);
    }

    public static function lostBeforeHandover(): array
    {
        return [
            // Units 0 and 1 booked, unit 2 already repaid (499); unit 1 is then found damaged.
            'fulfill-partial, damaged' => [StockAllocation::PARTIAL, 2, [0, 1], 1, 'damaged', [499, 1000], [0]],
            'cancel-all, missing' => [StockAllocation::CANCEL, 3, [0, 1, 2], 2, 'missing', [1499], [0, 1]],
        ];
    }

    /**
     * The recorded plan is final once booking used it, and no policy re-plans a booked Order to fewer units.
     * The host stops fulfilment with business cancellation; native compensation cancels the booking, repays
     * every unit not already repaid and releases only the suitable held units.
     */
    #[DataProvider('lostBeforeHandover')]
    public function testStockLostAfterBookingBeforeHandoverCancelsAndRepaysTheRemainingUnits(
        string $policy,
        int $stock,
        array $booked,
        int $lost,
        string $condition,
        array $payouts,
        array $released
    ): void {
        $stub = $this->checkout(Catalogue::PAYMENT_FIRST, $policy, $booked, stock: $stock);
        $key = $this->order->subjectKey();
        $this->assertSame('booked', $this->authority->subject($key, 'order')->state, $this->progress());
        $plan = $this->authority->stock->recorded($key);
        $this->assertSame($booked, $plan['fulfillUnits']);

        $this->warehouse->disposition($this->reservation(), 'handover-inspection-1', [$lost], $condition);
        // A warehouse observation alone changes neither the business state nor the recorded plan.
        $this->assertSame('booked', $this->authority->subject($key, 'order')->state);
        $this->assertSame($plan, $this->authority->stock->recorded($key));
        $this->assertSame('requested', app(CancellationStore::class)->request(
            $key,
            $stub->runId(),
            'stock-lost-before-handover'
        )['status']);
        $this->drain();

        $this->assertSame('cancelled', $this->authority->subject($key, 'order')->state, $this->progress());
        $this->assertSame($plan, $this->authority->stock->recorded($key));
        $this->assertSame(['capture', 'reserve', 'book', 'cancel-booking', 'release'], $this->effects());
        $this->assertSame($payouts, $this->payouts());
        $this->assertSame(1499, array_sum($this->payouts()));
        $this->assertSame(['stock-lost-before-handover'], $this->cancellationKeys($key));
        $this->assertReleasedOnce($released, $condition);
        // The open booking-only Order never completed with a booked outcome.
        $this->assertCancelledWithoutCompletion($stub);
        $this->assertReplayWithoutProviderCalls($stub);
    }

    public static function workerLoss(): array
    {
        return [
            'before payment, reserve-first cancel-all' => [Catalogue::COMMON_RESERVE_FIRST, StockAllocation::CANCEL],
            'after booking, payment-first fulfill-partial' => [Catalogue::PAYMENT_FIRST, StockAllocation::PARTIAL],
        ];
    }

    /**
     * A real worker dies after the reservation release commits at the provider and in the warehouse, before DW
     * records it. A fresh process recovers the same obligation; the suitable units return to stock only once.
     */
    #[DataProvider('workerLoss')]
    public function testWorkerLostAfterReleasingTheLostStockReservation(string $recipe, string $policy): void
    {
        $this->requireWorkerLossSupport();
        $beforePayment = $recipe === Catalogue::COMMON_RESERVE_FIRST;
        $stub = $beforePayment
            ? $this->checkout($recipe, $policy, null, stock: 3, untilAllocated: true)
            : $this->checkout($recipe, $policy, [0, 1]);
        $key = $this->order->subjectKey();
        $this->warehouse->disposition($this->reservation(), 'loss-inspection-1', [1], 'damaged');
        if (! $beforePayment) {
            $this->assertSame('booked', $this->authority->subject($key, 'order')->state, $this->progress());
            app(CancellationStore::class)->request($key, $stub->runId(), 'stock-lost-before-handover');
        }
        $row = null;
        [$cut, $resumed] = $this->loseWorkerThenRecover(
            'tests/Support/CheckoutProcessLossProcessTest.php',
            'DWF_CHECKOUT_PROCESS_INPUT',
            [
                'key' => $key,
                'recipe' => $recipe,
                'policy' => $policy,
                'unitAmounts' => $this->unitAmounts(),
                'handler' => StockPolicy::HANDLER,
                'mode' => 'compensate',
                'until' => 'cancelled',
                'provider' => $this->providerPath,
                'clock' => now()
                    ->toISOString(),
            ],
            function (array $cut, array $data) use (&$row): array {
                $row = $this->obligation($cut);
                // The release committed; DW has not recorded it.
                $this->assertNotSame('verified', $row['compensation_status']);
                $this->assertTrue($this->warehouse->find($this->reservation())['closed']);
                return [
                    ...$data,
                    'clock' => $cut['clock'],
                ];
            },
        );
        $this->assertFalse($resumed['idle'], $this->progress());
        Carbon::setTestNow($resumed['clock']);

        $this->assertSame('cancelled', $this->authority->subject($key, 'order')->state, $this->progress());
        $recovered = $this->obligation($cut);
        $this->assertSame([$row['id'], $row['fingerprint']], [$recovered['id'], $recovered['fingerprint']]);
        $this->assertSame('verified', $recovered['compensation_status']);
        $this->assertSame(
            $beforePayment ? ['reserve', 'release'] : ['capture', 'reserve', 'book', 'cancel-booking', 'release'],
            $this->effects()
        );
        $this->assertSame($beforePayment ? [] : [499, 1000], $this->payouts());
        $this->assertReleasedOnce($beforePayment ? [0, 2] : [0], 'damaged');
        $this->assertReplayWithoutProviderCalls($stub);
    }

    protected function recipe(): string
    {
        return Catalogue::PAYMENT_FIRST;
    }

    protected function orderTerms(): OrderTerms
    {
        return new OrderTerms('shop-a', 'purchase-1', 'business-generation-1', 1499, 'USD', 'sku-1', 3);
    }

    protected function unitAmounts(): array
    {
        return [500, 500, 499];
    }

    /**
     * Three units are requested. Null booking units means no booking is expected.
     */
    private function checkout(
        string $recipe,
        string $policy,
        ?array $booking,
        int $stock = 2,
        bool $untilWaiting = false,
        bool $untilAllocated = false
    ): WorkflowStub {
        $this->order = new OrderTerms(
            'shop-a',
            'purchase-stock-loss-' . $recipe . '-' . $policy,
            'business-generation-1',
            1499,
            'USD',
            'sku-1',
            3,
            fulfillmentDeadline: Carbon::now()->addHour()->getTimestamp(),
        );
        $key = $this->order->subjectKey();
        $this->authority = new Authority(DB::connection(), 'provider-worker', [$key], [
            $key => $recipe,
        ], unitAmounts: [
            $key => $this->unitAmounts(),
        ], stockPolicies: [
            $key => $policy,
        ]);
        $this->warehouse = new StockWarehouse($this->providerDb);
        $this->warehouse->install();
        $this->warehouse->seed('shop-a', 'sku-1', $stock);
        $this->store = MachineStore::configure(
            $this->app,
            $this->authority,
            $this->provider,
            unitRefunds: $this->unitRefunds,
            warehouse: $this->warehouse
        );
        $this->store->seed($this->order);
        foreach (['order', 'payment'] as $role) {
            foreach ($this->order->descriptor($role) as $request) {
                $this->provider->plan(Request::fromArray($request), Status::Confirmed);
            }
        }
        foreach ($this->order->descriptor('shipment') as $request) {
            $request['terms']['quantity'] = count($booking ?? [0]);
            $request['terms']['units'] = $booking ?? [0];
            $this->provider->plan(Request::fromArray($request), Status::Confirmed);
        }
        $original = $this->authority->unitMoney->original($this->order);
        $this->unitRefunds->register($original);
        $this->unitRefunds->plan(
            new UnitRefundCommand($original, StockPolicy::SHORTAGE_REQUEST, [2]),
            Status::Confirmed
        );
        $this->unitRefunds->plan(
            UnitRefundCommand::fromObligation($this->authority->unitMoney->obligation($key)->input),
            Status::Confirmed
        );
        $stub = WorkflowStub::make(CheckoutOrder::class, 'provider-order-' . $key, Catalogue::NAMESPACE);
        $stub->start($key);
        $stop = null;
        if ($untilWaiting) {
            // Stop before native time reaches the original deadline timer.
            $stop = static fn (): bool => WorkflowTask::query()
                ->where('status', TaskStatus::Ready)
                ->get()
                ->every(static fn (WorkflowTask $task): bool => $task->available_at?->isFuture() === true);
        }
        if ($untilAllocated) {
            // The warehouse allocation commits in the reservation activity; stock-plan needs a later task.
            $stop = fn (): bool => $this->warehouse->find($this->reservation()) !== null;
        }
        $this->drain($stop);
        return $stub;
    }

    /**
     * The warehouse closed the original reservation with one receipt: only the suitable held units became
     * saleable again, and the lost unit stays in its own counter.
     */
    private function assertReleasedOnce(array $units, string $condition): void
    {
        $snapshot = $this->warehouse->find($this->reservation());
        $this->assertTrue($snapshot['closed']);
        $this->assertSame(1, count(array_filter($this->effects(), static fn (string $action): bool =>
            $action === 'release')));
        // A repeated release returns the same stored receipt and moves no stock.
        $before = $this->warehouse->inventory('shop-a', 'sku-1');
        $receipt = $this->warehouse->release(Request::fromArray($this->order->descriptor('order')['compensation']));
        $this->assertSame($units, $receipt['releasedUnits']);
        $this->assertSame($snapshot['revision'], $receipt['revision']);
        $inventory = $this->warehouse->inventory('shop-a', 'sku-1');
        $this->assertSame($before, $inventory);
        $held = count(array_filter(
            StockAllocation::fromArray($snapshot['allocation'])->units,
            static fn (string $state): bool => $state === 'held'
        ));
        $this->assertSame(count($units), $held);
        $this->assertSame(1, $inventory[$condition]);
        $this->assertSame($inventory['initial_quantity'] - 1, $inventory['available']);
    }

    private function assertReplayWithoutProviderCalls(WorkflowStub $stub): void
    {
        $requests = $this->providerRequests();
        app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($stub->runId()));
        $this->assertSame($requests, $this->providerRequests());
    }

    /**
     * The original Order run ended through native business cancellation; it never returned a booked outcome.
     */
    private function assertCancelledWithoutCompletion(WorkflowStub $stub): void
    {
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $this->assertSame('cancelled', $run->status->value);
        $this->assertNull($run->workflowOutput());
        $this->assertNotContains('WorkflowCompleted', array_column($this->history($run->id), 0));
    }

    private function reservation(): Request
    {
        return Request::fromArray($this->order->descriptor('order')['forward']);
    }

    private function cancellationKeys(string $key): array
    {
        return DB::table('provider_machine_cancellations')->where('root_key', $key)->orderBy('requested_at')
            ->pluck('request_key')
            ->all();
    }

    private function obligation(array $cut): array
    {
        $ledger = (new ScopeRepository())->inspect(ScopeIdentity::fromArray($cut['scope']));
        $rows = array_values(array_filter(
            $ledger['obligations'],
            static fn (array $row): bool => $row['id'] === $cut['operationId']
        ));
        $this->assertCount(1, $rows);
        return $rows[0];
    }

    private function payouts(): array
    {
        return array_map('intval', $this->providerDb->query(
            'SELECT amount_minor FROM unit_refund_payouts ORDER BY rowid'
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    private function providerRequests(): int
    {
        return (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
            ->fetchColumn();
    }
}
