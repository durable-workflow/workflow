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
use DurableWorkflow\Examples\ProviderStateMachines\StockWarehouse;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProviderMachineFixture;
use Tests\Support\WorkerLossControl;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * TECH-01/03/06 on the common payment-first checkout with the partial-stock policy (two of three units).
 * A real worker process dies after a provider effect commits and before DW records it. A new process
 * recovers the original operation through the production watchdog. Effects never repeat.
 */
final class V2CheckoutProcessLossTest extends TestCase
{
    use ProviderMachineFixture;
    use WorkerLossControl;

    private OrderTerms $order;

    private Authority $authority;

    private StockWarehouse $warehouse;

    public static function cuts(): array
    {
        return [
            'capture committed, acknowledgement lost' => ['provider.unit-payment', 'forward', false, false],
            'stock allocation committed' => ['provider.stock', 'forward', false, false],
            'shortage repayment committed' => ['provider.unit-refund', 'forward', false, false],
            'carrier booking committed' => ['provider.effect', 'forward', false, false],
            'cancellation races a lost capture' => ['provider.unit-payment', 'forward', true, false],
            'cancellation races a lost booking' => ['provider.effect', 'forward', true, false],
            // Closed child scopes compensate in a native recovery run, which retries after the watchdog repairs
            // the lost lease. The Order's in-origin attempts expire first and the Order closes unsettled; the
            // watchdog routes its accepted request to recovery, and the worker's finalizer commits cancellation.
            'booking cancellation committed' => ['provider.effect', 'compensate', false, true],
            'remaining repayment committed' => ['provider.unit-payment', 'compensate', false, true],
            'reservation release committed' => ['provider.stock', 'compensate', false, false],
        ];
    }

    #[DataProvider('cuts')]
    public function testFreshWorkerRecoversTheOriginalOperationWithoutRepeatingItsEffect(
        string $handler,
        string $mode,
        bool $cancelWhileLost,
        bool $originClosesUnsettled
    ): void {
        $this->requireWorkerLossSupport();
        $stub = $this->begin();
        $key = $this->order->subjectKey();
        $cancellation = app(CancellationStore::class);
        if ($mode === 'compensate') {
            $this->drain();
            $this->assertSame('booked', $this->authority->subject($key, 'order')->state, $this->progress());
            $cancellation->request($key, $stub->runId(), 'cancel-before-worker-loss');
        }
        $row = null;
        $until = $mode === 'forward' && ! $cancelWhileLost ? 'booked' : 'cancelled';
        [$cut, $resumed] = $this->loseWorkerThenRecover(
            'tests/Support/CheckoutProcessLossProcessTest.php',
            'DWF_CHECKOUT_PROCESS_INPUT',
            $this->lossInput($key, $handler, $mode, 'cancelled'),
            function (array $cut, array $data) use (
                $mode,
                $until,
                $cancelWhileLost,
                $cancellation,
                $key,
                $stub,
                &$row
            ): array {
                $row = $this->obligation($cut);
                // The provider effect exists; DW has not recorded its outcome.
                if ($mode === 'forward') {
                    $this->assertSame('in_flight', $row['forward_status']);
                    $this->assertNull($row['forward_result']);
                } else {
                    $this->assertNotSame('verified', $row['compensation_status']);
                }
                if ($cancelWhileLost) {
                    $cancellation->request($key, $stub->runId(), 'cancel-while-worker-lost');
                }
                return [
                    ...$data,
                    'clock' => $cut['clock'],
                    'until' => $until,
                ];
            },
        );
        $this->assertFalse($resumed['idle'], $this->progress());
        $this->assertSame($until, $resumed['state']);
        \Illuminate\Support\Carbon::setTestNow($resumed['clock']);
        if ($originClosesUnsettled) {
            // The Order's in-origin compensation failed fast and the Order closed unsettled. The fresh
            // worker's watchdog routed its accepted request to recovery, which outlasted the child's lease
            // repair (#1205). The worker application's finalizer then committed the business cancellation.
            $this->assertSame('failed', WorkflowRun::query()->findOrFail($stub->runId())->status->value);
            $root = $this->authority->plan($key)
                ->rootScope();
            $this->assertSame('verified', (new ScopeRepository())->metadata($root)['status'], $this->progress());
        }
        if ($until === 'booked') {
            $cancellation->request($key, $stub->runId(), 'cancel-after-worker-recovery');
            $this->drain();
        }

        $this->assertSame('cancelled', $this->authority->subject($key, 'order')->state, $this->progress());
        $recovered = $this->obligation($cut);
        // The same original operation was recovered; no replacement obligation or identity appeared.
        $this->assertSame($row['id'], $recovered['id']);
        $this->assertSame($row['fingerprint'], $recovered['fingerprint']);
        $this->assertSame('verified', $recovered['compensation_status']);
        $raceOnCapture = $cancelWhileLost && $handler === 'provider.unit-payment';
        $this->assertSame(
            $raceOnCapture ? ['capture'] : ['capture', 'reserve', 'book', 'cancel-booking', 'release'],
            $this->effects()
        );
        $this->assertSame($raceOnCapture ? [1499] : [499, 1000], $this->payouts());
        $this->assertSame(2, $this->warehouse->inventory('shop-a', 'sku-1')['available']);
        // Request attempts may repeat across processes; committed effects never do.
        $this->assertGreaterThanOrEqual(count($this->effects()), $this->providerRequests());
        $requests = $this->providerRequests();
        // Replay reproduces the original root outcome without provider I/O. A root that closed unsettled
        // before automatic recovery replays its same recorded compensation failure.
        try {
            app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($stub->runId()));
            $this->assertFalse($originClosesUnsettled, 'The unsettled original root must replay its recorded failure.');
        } catch (\RuntimeException $error) {
            $this->assertTrue($originClosesUnsettled, $error->getMessage());
            $this->assertSame('scope_compensation_unknown', $error->getMessage());
        }
        $this->assertSame($requests, $this->providerRequests());
    }

    /**
     * DW admitted the original capture attempt and the worker died before calling the provider. Recovery only
     * looks the capture up; a missing provider operation is unknown, never success or non-execution. The
     * business cancellation settles it through the provider's authoritative admission fence, without effects.
     */
    public function testWorkerLostAfterAdmittingTheCaptureBeforeTheProviderCall(): void
    {
        $this->requireWorkerLossSupport();
        $stub = $this->begin();
        $key = $this->order->subjectKey();
        $original = $this->authority->unitMoney->original($this->order);
        $capture = $original->capture;
        $captureRequests = fn (string $kind): int => (int) $this->providerDb->query(
            "SELECT COUNT(*) FROM provider_requests WHERE kind = '{$kind}' AND operation_id = '"
            . $capture->identity->key() . "'"
        )->fetchColumn();
        $row = null;
        [$cut, $resumed] = $this->loseWorkerThenRecover(
            'tests/Support/CheckoutProcessLossProcessTest.php',
            'DWF_CHECKOUT_PROCESS_INPUT',
            $this->lossInput($key, 'provider.unit-payment', 'before', 'cancelled'),
            function (array $cut, array $data) use ($captureRequests, &$row): array {
                $row = $this->obligation($cut);
                // The attempt is admitted; the provider has neither a command nor an operation for it.
                $this->assertSame('in_flight', $row['forward_status']);
                $this->assertSame([0, 1], [$captureRequests('command'), $captureRequests('lookup')]);
                $this->assertSame(0, (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_operations')
                    ->fetchColumn());
                $this->assertSame([], $this->effects());
                return [
                    ...$data,
                    'clock' => $cut['clock'],
                ];
            },
        );
        \Illuminate\Support\Carbon::setTestNow($resumed['clock']);
        // Native recovery stopped without settlement: the forward is unresolved, not success or non-execution.
        $this->assertTrue($resumed['idle']);
        $this->assertSame('received', $resumed['state']);
        $unresolved = $this->obligation($cut);
        $this->assertSame([$row['id'], $row['fingerprint']], [$unresolved['id'], $unresolved['fingerprint']]);
        $this->assertSame('unknown', $unresolved['forward_status']);
        $this->assertSame('pending', $unresolved['compensation_status']);
        $this->assertSame('failed', WorkflowRun::query()->findOrFail($stub->runId())->status->value);
        $this->assertSame([], $this->effects());
        // The two remaining native attempts of the three-attempt budget only looked the capture up.
        $this->assertSame([0, 3], [$captureRequests('command'), $captureRequests('lookup')]);

        $cancellation = app(CancellationStore::class);
        $this->assertSame('requested', $cancellation->request(
            $key,
            $stub->runId(),
            'cancel-admitted-capture'
        )['status']);
        $this->drainLikeAWorker();
        $this->assertSame(
            'started',
            $cancellation->finish($key, $stub->runId(), 'cancel-admitted-capture')['status'],
            $this->progress()
        );
        $this->drainLikeAWorker();
        $this->assertSame('cancelled', $this->authority->subject($key, 'order')->state, $this->progress());
        $settled = $this->obligation($cut);
        $this->assertSame($row['id'], $settled['id']);
        $this->assertSame('unknown', $settled['forward_status']);
        $this->assertSame('verified', $settled['compensation_status']);
        $this->assertSame(
            ['unit-payment-fenced:' . $capture->identity->key()],
            json_decode($settled['evidence'], true)['evidence']
        );
        $this->assertSame([], $this->effects());
        $this->assertSame([], $this->payouts());
        $this->assertSame(2, $this->warehouse->inventory('shop-a', 'sku-1')['available']);
        $requests = $this->providerRequests();
        try {
            app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($stub->runId()));
            $this->fail('The original root must replay its recorded forward failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('scope_forward_unknown', $error->getMessage());
        }
        $this->assertSame($requests, $this->providerRequests());
        // A delayed forward command arriving after the fence cannot create the capture.
        $late = $this->provider->attempt($capture);
        $this->assertSame([Status::NotExecuted, null, true], [$late->status, $late->effectId, $late->admissionClosed]);
        $this->assertSame([], $this->effects());
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

    private function begin(): WorkflowStub
    {
        $this->order = new OrderTerms('shop-a', 'purchase-loss', 'business-generation-1', 1499, 'USD', 'sku-1', 3);
        $key = $this->order->subjectKey();
        $this->authority = new Authority(DB::connection(), 'provider-worker', [$key], [
            $key => Catalogue::PAYMENT_FIRST,
        ], unitAmounts: [
            $key => $this->unitAmounts(),
        ], stockPolicies: [
            $key => StockAllocation::PARTIAL,
        ]);
        $this->warehouse = new StockWarehouse($this->providerDb);
        $this->warehouse->install();
        $this->warehouse->seed('shop-a', 'sku-1', 2);
        $this->store = MachineStore::configure(
            $this->app,
            $this->authority,
            $this->provider,
            unitRefunds: $this->unitRefunds,
            warehouse: $this->warehouse
        );
        $this->store->seed($this->order);
        foreach (['order', 'payment', 'shipment'] as $role) {
            foreach ($this->order->descriptor($role) as $request) {
                if ($role === 'shipment') {
                    $request['terms']['quantity'] = 2;
                    $request['terms']['units'] = [0, 1];
                }
                $this->provider->plan(Request::fromArray($request), Status::Confirmed);
            }
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
        return $stub;
    }

    /**
     * Queue-worker semantics: a recorded activity failure (for example a child scope not yet verified) is
     * retried by the engine; the worker keeps draining instead of stopping.
     */
    private function drainLikeAWorker(): void
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

    private function lossInput(string $key, string $handler, string $mode, string $until): array
    {
        return [
            'key' => $key,
            'policy' => StockAllocation::PARTIAL,
            'unitAmounts' => $this->unitAmounts(),
            'handler' => $handler,
            'mode' => $mode,
            'until' => $until,
            'provider' => $this->providerPath,
            'clock' => now()
                ->toISOString(),
        ];
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
