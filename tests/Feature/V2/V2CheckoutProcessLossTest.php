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
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\Support\ProviderMachineFixture;
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
            // watchdog then routes its accepted request to recovery, and the proof-only finalizer settles it.
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
        bool $freshRecovery
    ): void {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('The worker-loss control requires POSIX signals.');
        }
        if (DB::connection()->getConfig('database') === ':memory:') {
            $this->markTestSkipped('A replacement worker process needs the shared application database.');
        }
        $stub = $this->begin();
        $key = $this->order->subjectKey();
        $cancellation = app(CancellationStore::class);
        if ($mode === 'compensate') {
            $this->drain();
            $this->assertSame('booked', $this->authority->subject($key, 'order')->state, $this->progress());
            $cancellation->request($key, $stub->runId(), 'cancel-before-worker-loss');
        }
        $input = tempnam(sys_get_temp_dir(), 'checkout-loss-input-');
        $report = tempnam(sys_get_temp_dir(), 'checkout-loss-report-');
        chmod($input, 0600);
        chmod($report, 0600);
        $process = null;
        try {
            $data = [
                'phase' => 'crash',
                'key' => $key,
                'policy' => StockAllocation::PARTIAL,
                'unitAmounts' => $this->unitAmounts(),
                'handler' => $handler,
                'mode' => $mode,
                'until' => 'cancelled',
                'provider' => $this->providerPath,
                'clock' => now()
                    ->toISOString(),
                'database' => DB::connection()->getConfig(),
                'redis' => config('database.redis'),
                'cache' => config('cache'),
                'report' => $report,
            ];
            $command = [PHP_BINARY, 'vendor/bin/phpunit', 'tests/Support/CheckoutProcessLossProcessTest.php'];
            file_put_contents($input, json_encode($data, JSON_THROW_ON_ERROR));
            $process = new Process($command, dirname(__DIR__, 3), [
                'DWF_CHECKOUT_PROCESS_INPUT' => $input,
            ], timeout: 300);
            $process->start();
            $crashed = $process->getPid();
            try {
                $process->wait();
                $this->fail('The first worker must die at its selected provider cut. ' . $process->getOutput());
            } catch (ProcessSignaledException $error) {
                $this->assertSame(SIGKILL, $error->getProcess()->getTermSignal());
            }
            $cut = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($crashed, $cut['pid']);
            $this->assertNotSame(getmypid(), $crashed);
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

            $data['phase'] = 'resume';
            $data['clock'] = $cut['clock'];
            $data['until'] = $mode === 'forward' && ! $cancelWhileLost ? 'booked' : 'cancelled';
            file_put_contents($input, json_encode($data, JSON_THROW_ON_ERROR));
            file_put_contents($report, '');
            $process = new Process($command, dirname(__DIR__, 3), [
                'DWF_CHECKOUT_PROCESS_INPUT' => $input,
            ], timeout: 300);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
            $resumed = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotSame($crashed, $resumed['pid']);
            $this->assertSame($freshRecovery, $resumed['idle']);
            \Illuminate\Support\Carbon::setTestNow($resumed['clock']);
            if ($freshRecovery) {
                // The Order's in-origin compensation failed fast and the Order closed unsettled. The watchdog in
                // the fresh worker routed its accepted request to recovery, which outlasted the child's lease
                // repair (#1205). No business cancellation is committed until the application finalizes it.
                $this->assertSame('booked', $resumed['state']);
                $root = $this->authority->plan($key)
                    ->rootScope();
                $this->assertSame('verified', (new ScopeRepository())->metadata($root)['status'], $this->progress());
                $this->assertSame('failed', WorkflowRun::query()->findOrFail($stub->runId())->status->value);
                $this->assertSame(['capture', 'reserve', 'book', 'cancel-booking', 'release'], $this->effects());
                $this->assertSame([499, 1000], $this->payouts());
                $this->assertSame(2, $this->warehouse->inventory('shop-a', 'sku-1')['available']);
                $this->assertSame(
                    'started',
                    $cancellation->finish($key, $stub->runId(), 'cancel-before-worker-loss')['status'],
                    $this->progress()
                );
                $this->drainLikeAWorker();
            } else {
                $this->assertSame($data['until'], $resumed['state']);
            }
            if ($data['until'] === 'booked') {
                $cancellation->request($key, $stub->runId(), 'cancel-after-worker-recovery');
                $this->drain();
            }
        } finally {
            if ($process !== null && $process->isRunning()) {
                $process->stop(0, SIGKILL);
            }
            unlink($input);
            unlink($report);
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
        // before the fresh recovery replays its same recorded compensation failure.
        try {
            app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($stub->runId()));
            $this->assertFalse($freshRecovery, 'The unsettled original root must replay its recorded failure.');
        } catch (\RuntimeException $error) {
            $this->assertTrue($freshRecovery, $error->getMessage());
            $this->assertSame('scope_compensation_unknown', $error->getMessage());
        }
        $this->assertSame($requests, $this->providerRequests());
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
