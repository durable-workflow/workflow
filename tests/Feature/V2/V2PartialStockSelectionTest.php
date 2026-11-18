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
use DurableWorkflow\Examples\ProviderStateMachines\StockAllocation;
use DurableWorkflow\Examples\ProviderStateMachines\StockPolicy;
use DurableWorkflow\Examples\ProviderStateMachines\StockSelectionStore;
use DurableWorkflow\Examples\ProviderStateMachines\StockWarehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProviderMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** STOCK-PARTIAL-01/02/03 on the existing common payment-first Order, Payment and booking flow. */
final class V2PartialStockSelectionTest extends TestCase
{
    use ProviderMachineFixture;

    private StockWarehouse $warehouse;

    private OrderTerms $order;

    private Authority $authority;

    public function testPartialPolicyBooksHeldUnitsAndRepaysOnlyTheUnavailableUnit(): void
    {
        $stub = $this->checkout(StockAllocation::PARTIAL, [0, 1]);
        $key = $this->order->subjectKey();
        $this->assertSame('booked', $this->authority->subject($key, 'order')->state, $this->progress());
        $plan = $this->authority->stock->recorded($key);
        $this->assertSame(['fulfill', 3, [0, 1], [2], [2], []], [
            $plan['action'], $plan['requestedQuantity'], $plan['fulfillUnits'], $plan['unavailableUnits'],
            $plan['requiredRefundUnits'], $plan['releaseUnits'],
        ]);
        $this->assertSame(['capture', 'reserve', 'book'], $this->effects());
        $this->assertSame(499, $this->paidBack());
        $this->assertSame([
            'quantity' => 2,
            'units' => [0, 1],
        ], $this->bookedUnits());
        $this->assertSame(0, $this->warehouse->inventory('shop-a', 'sku-1')['available']);

        $requests = $this->providerRequests();
        app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($stub->runId()));
        $this->assertSame($requests, $this->providerRequests());

        $this->unitRefunds->plan($this->cancellationRefund(), Status::Confirmed);
        app(CancellationStore::class)->request($key, $stub->runId(), 'cancel-partially-fulfilled-order');
        $this->drain();
        $this->assertSame('cancelled', $this->authority->subject($key, 'order')->state, $this->progress());
        // The earlier shortage repayment stays; cancellation repays only the two fulfilled units.
        $this->assertSame(1499, $this->paidBack());
        $this->assertSame(1000, $this->unitRefunds->lookup($this->cancellationRefund())['ownPayoutMinor']);
        $this->assertSame(['capture', 'reserve', 'book', 'cancel-booking', 'release'], $this->effects());
        $this->assertSame(2, $this->warehouse->inventory('shop-a', 'sku-1')['available']);
        $this->assertSame($plan, $this->authority->stock->recorded($key));
    }

    public function testCancelAllPolicyRefundsEveryUnitAndReleasesOnlyHeldUnits(): void
    {
        $stub = $this->checkout(StockAllocation::CANCEL, null);
        $key = $this->order->subjectKey();
        $this->assertSame('cancelled', $this->authority->subject($key, 'order')->state, $this->progress());
        $plan = $this->authority->stock->recorded($key);
        $this->assertSame(['cancel', [0, 1, 2], [0, 1]], [
            $plan['action'], $plan['requiredRefundUnits'], $plan['releaseUnits'],
        ]);
        $this->assertSame(['capture', 'reserve', 'release'], $this->effects());
        $this->assertSame(1499, $this->paidBack());
        $this->assertSame(2, $this->warehouse->inventory('shop-a', 'sku-1')['available']);
        $this->assertSame('verified', (new ScopeRepository())->metadata(
            $this->authority->plan($key)
                ->rootScope()
        )['status']);
    }

    public function testWaitPolicyBooksOnlyAfterTheCompleteQuantityIsHeld(): void
    {
        $stub = $this->checkout(StockAllocation::WAIT, [0, 1, 2], untilWaiting: true);
        $key = $this->order->subjectKey();
        $this->assertSame('received', $this->authority->subject($key, 'order')->state, $this->progress());
        $this->assertNull($this->authority->stock->recorded($key));
        $this->assertSame(['capture', 'reserve'], $this->effects());
        $this->assertSame(0, $this->paidBack());

        $this->warehouse->receive('shop-a', 'sku-1', 'delivery-note-1', 1);
        // A repeated delivery note is the same physical receipt.
        $this->warehouse->receive('shop-a', 'sku-1', 'delivery-note-1', 1);
        $stub->signal('reconcile-stock');
        $this->drain();
        $this->assertSame('booked', $this->authority->subject($key, 'order')->state, $this->progress());
        $plan = $this->authority->stock->recorded($key);
        $this->assertSame(['fulfill', [0, 1, 2], []], [
            $plan['action'], $plan['fulfillUnits'], $plan['requiredRefundUnits'],
        ]);
        $this->assertSame([
            'quantity' => 3,
            'units' => [0, 1, 2],
        ], $this->bookedUnits());
        $this->assertSame(0, $this->paidBack());
        $this->assertSame(0, $this->warehouse->inventory('shop-a', 'sku-1')['available']);
    }

    public function testWaitPolicyCancelsAtTheOriginalFixedDeadline(): void
    {
        $this->checkout(StockAllocation::WAIT, null);
        $key = $this->order->subjectKey();
        $this->assertSame('cancelled', $this->authority->subject($key, 'order')->state, $this->progress());
        $plan = $this->authority->stock->recorded($key);
        $this->assertSame(['cancel', 'wait-complete', 'complete-order-deadline-expired'], [
            $plan['action'], $plan['policy'], $plan['reason'],
        ]);
        $this->assertSame(['capture', 'reserve', 'release'], $this->effects());
        $this->assertSame(1499, $this->paidBack());
        $this->assertSame(2, $this->warehouse->inventory('shop-a', 'sku-1')['available']);
    }

    public function testCancellationBeforeAnyStockPlanWinsOverPartialFulfilment(): void
    {
        $stub = $this->checkout(StockAllocation::PARTIAL, [0, 1], untilPlan: true);
        $key = $this->order->subjectKey();
        $this->assertSame(
            ['held', 'held', 'unavailable'],
            $this->warehouse->find($this->reservation())['allocation']['units']
        );
        $this->assertNull($this->authority->stock->recorded($key));
        app(CancellationStore::class)->request($key, $stub->runId(), 'cancel-before-original-stock-plan');
        $this->drain();
        $this->assertSame('cancelled', $this->authority->subject($key, 'order')->state, $this->progress());
        // No fulfilment plan, shortage repayment or booking exists after cancellation began.
        $this->assertNull(DB::table('provider_machine_assignments')->where('id', $key)->value('stock_plan'));
        $this->assertSame(['capture', 'reserve', 'release'], $this->effects());
        $this->assertSame([1499], $this->payouts());
        $this->assertSame(2, $this->warehouse->inventory('shop-a', 'sku-1')['available']);
        $this->assertTrue($this->warehouse->find($this->reservation())['closed']);
    }

    public static function unsuitableUnits(): array
    {
        return [
            'damaged' => ['damaged'],
            'missing' => ['missing'],
        ];
    }

    #[DataProvider('unsuitableUnits')]
    public function testDamagedOrMissingReservedUnitCancelsEvenUnderThePartialPolicy(string $condition): void
    {
        $this->checkout(StockAllocation::PARTIAL, null, stock: 3, untilPlan: true);
        $key = $this->order->subjectKey();
        $this->warehouse->disposition($this->reservation(), 'original-inspection-1', [1], $condition);
        $this->drain();
        $this->assertSame('cancelled', $this->authority->subject($key, 'order')->state, $this->progress());
        $plan = $this->authority->stock->recorded($key);
        $this->assertSame(['cancel', 'fulfill-partial', 'original-allocation', [1], [0, 2], [0, 1, 2], []], [
            $plan['action'], $plan['policy'], $plan['reason'], $plan[$condition . 'Units'], $plan['releaseUnits'],
            $plan['requiredRefundUnits'], $plan['fulfillUnits'],
        ]);
        $this->assertSame(['capture', 'reserve', 'release'], $this->effects());
        $this->assertSame([1499], $this->payouts());
        // Only the two suitable units become saleable again; the unsuitable unit keeps its own counter.
        $inventory = $this->warehouse->inventory('shop-a', 'sku-1');
        $this->assertSame([2, 1], [$inventory['available'], $inventory[$condition]]);
    }

    public function testForeignShortageRepaymentIsRefusedBeforeProviderDispatch(): void
    {
        $this->checkout(StockAllocation::PARTIAL, [0, 1]);
        $key = $this->order->subjectKey();
        $scope = $this->authority->plan($key)
            ->rootScope();
        $ledger = (new ScopeRepository())->inspect($scope);
        $row = array_values(array_filter($ledger['obligations'], static fn (array $item): bool =>
            $item['descriptor']['key'] === StockPolicy::SHORTAGE_KEY))[0];
        $input = $this->authority->stock->shortage($key)
->input;
        $input['refund']['units'] = [1, 2];
        $context = new OperationContext(
            $scope,
            new Obligation(StockPolicy::SHORTAGE_KEY, StockPolicy::SHORTAGE_HANDLER, '1', $input),
            $row['id'],
            $ledger['original_run_id'],
            'untrusted-activity',
            'untrusted-attempt',
            false
        );
        $before = $this->paidBack();
        try {
            app(StockSelectionStore::class)->refund($context, 'forward');
            $this->fail('A substituted shortage repayment was authorized.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('another original Order or stock plan', $error->getMessage());
        }
        $this->assertSame($before, $this->paidBack());
    }

    public function testStockSelectionRequiresAnExplicitPaymentFirstUnitMoneyGrant(): void
    {
        $key = $this->terms->subjectKey();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('payment-first unit-money grant');
        new Authority(DB::connection(), 'provider-worker', [$key], [
            $key => Catalogue::COMMON_RESERVE_FIRST,
        ], unitAmounts: [
            $key => [500, 500, 499],
        ], stockPolicies: [
            $key => StockAllocation::PARTIAL,
        ]);
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
     * Two of three requested units are in stock. Null booking units means no booking is expected.
     */
    private function checkout(
        string $policy,
        ?array $booking,
        bool $untilWaiting = false,
        int $stock = 2,
        bool $untilPlan = false
    ): WorkflowStub {
        $this->order = new OrderTerms(
            'shop-a',
            'purchase-stock-' . $policy,
            'business-generation-1',
            1499,
            'USD',
            'sku-1',
            3,
            fulfillmentDeadline: Carbon::now()->addHour()->getTimestamp(),
        );
        $key = $this->order->subjectKey();
        $this->authority = new Authority(DB::connection(), 'provider-worker', [$key], [
            $key => Catalogue::PAYMENT_FIRST,
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
        $shipment = $this->order->descriptor('shipment');
        foreach ($shipment as $request) {
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
        $this->unitRefunds->plan($this->cancellationRefund(), Status::Confirmed);
        $stub = WorkflowStub::make(CheckoutOrder::class, 'provider-order-' . $key, Catalogue::NAMESPACE);
        $stub->start($key);
        // The wait case stops before native time reaches the original deadline timer.
        $stop = $untilWaiting ? static fn (): bool => WorkflowTask::query()
            ->where('status', TaskStatus::Ready)
            ->get()
            ->every(static fn (WorkflowTask $task): bool => $task->available_at?->isFuture() === true) : null;
        if ($untilPlan) {
            // The warehouse allocation commits in the reservation activity; stock-plan needs a later task.
            $stop = fn (): bool => $this->warehouse->find($this->reservation()) !== null;
        }
        $this->drain($stop);
        return $stub;
    }

    private function reservation(): Request
    {
        return Request::fromArray($this->order->descriptor('order')['forward']);
    }

    private function cancellationRefund(): UnitRefundCommand
    {
        return UnitRefundCommand::fromObligation(
            $this->authority->unitMoney->obligation($this->order->subjectKey())
->input
        );
    }

    private function bookedUnits(): array
    {
        $request = json_decode((string) $this->providerDb->query(
            "SELECT o.request FROM provider_operations o WHERE json_extract(o.request, '$.action') = 'book'"
        )->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        return array_intersect_key($request['terms'], array_flip(['quantity', 'units']));
    }

    private function payouts(): array
    {
        return array_map(
            'intval',
            $this->providerDb->query('SELECT amount_minor FROM unit_refund_payouts ORDER BY rowid')
                ->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    private function paidBack(): int
    {
        return (int) $this->providerDb->query('SELECT COALESCE(SUM(amount_minor), 0) FROM unit_refund_payouts')
            ->fetchColumn();
    }

    private function providerRequests(): int
    {
        return (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
            ->fetchColumn();
    }
}
