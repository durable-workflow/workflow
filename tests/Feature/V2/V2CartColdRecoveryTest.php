<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\CartStore;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCatalogue;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\Support\CartCheckoutMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** A real process loss separates the original bank refund from release of the selected Cart lease. */
final class V2CartColdRecoveryTest extends TestCase
{
    use CartCheckoutMachineFixture;

    public static function refundCuts(): array
    {
        return [
            'durable-initial-lease' => [false, false],
            'lost-ack-initial-lease' => [true, false],
            'durable-late-lease' => [false, true],
            'lost-ack-late-lease' => [true, true],
        ];
    }

    #[DataProvider('refundCuts')]
    public function testFreshProcessSettlesTheOriginalRefundBeforeReleasingItsSelectedLease(
        bool $loseAcknowledgement,
        bool $late
    ): void {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('The worker-loss control requires POSIX signals.');
        }
        $origin = $this->startCart();
        if ($late) {
            $this->expireCart();
        }
        $this->pay('original-cart-before-worker-loss');
        $key = $this->terms->subjectKey();
        $plan = $this->store->authority->plan($key);
        $order = app(CartStore::class)->order($key);
        $this->assertSame($late ? 'late' : 'initial', $order['selected']);
        $lease = $this->cart->lease($order['selected']);
        $receipt = IncomingPaymentReceipt::fromArray(
            $this->incoming->receipts->inspect($this->terms->incomingPayment)['receipts'][0]
        );
        $refund = IncomingPaymentOperation::refundRequest(
            $plan->childScope('payment'),
            'payment.incoming',
            $this->terms->incomingPayment,
            $receipt
        );
        $this->provider->plan($refund, Status::Confirmed, $loseAcknowledgement);
        $rootHistory = $this->history($origin->runId());
        $scopes = new ScopeRepository();
        $rootBefore = $scopes->inspect($plan->rootScope());
        $paymentBefore = $scopes->inspect($plan->childScope('payment'));
        $paymentHistory = $this->history($paymentBefore['original_run_id']);
        $request = 'cancel-original-cart-before-worker-loss';
        app(CancellationStore::class)->request($key, $origin->runId(), $request);
        $input = tempnam(sys_get_temp_dir(), 'cart-recovery-input-');
        $report = tempnam(sys_get_temp_dir(), 'cart-recovery-report-');
        chmod($input, 0600);
        chmod($report, 0600);
        $process = null;
        try {
            $data = [
                'profile' => 'cart',
                'key' => $key,
                'recipe' => FundingCatalogue::BANK,
                'rootRun' => $origin->runId(),
                'rootScope' => $plan->rootScope()
                    ->toArray(),
                'paymentScope' => $plan->childScope('payment')
                    ->toArray(),
                'provider' => $this->providerPath,
                'clock' => now()
                    ->toISOString(),
                'database' => DB::connection()->getConfig(),
                'redis' => config('database.redis'),
                'cache' => config('cache'),
                'request' => $request,
                'phase' => 'crash',
                'report' => $report,
                'refundId' => $refund->identity->key(),
                'loseAcknowledgement' => $loseAcknowledgement,
            ];
            file_put_contents($input, json_encode($data, JSON_THROW_ON_ERROR));
            $command = [PHP_BINARY, 'vendor/bin/phpunit', 'tests/Support/FundingRecoveryProcessTest.php'];
            $process = new Process($command, dirname(__DIR__, 3), [
                'DWF_PROVIDER_RECOVERY_INPUT' => $input,
            ], timeout: 180);
            $process->start();
            $stoppedPid = $process->getPid();
            try {
                $process->wait();
                $this->fail('The first worker must stop at its original Cart refund cut. ' . $process->getOutput());
            } catch (ProcessSignaledException $error) {
                $this->assertSame(SIGKILL, $error->getProcess()->getTermSignal());
            }
            $cut = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($stoppedPid, $cut['pid']);
            $this->assertNotSame(getmypid(), $stoppedPid);
            $payment = $scopes->inspect($plan->childScope('payment'));
            $this->assertSame(! $loseAcknowledgement, $cut['bankVerified']);
            $this->assertSame('requested', $scopes->metadata($plan->rootScope())['status']);
            $bank = array_values(array_filter(
                $payment['obligations'],
                static fn (array $duty): bool => $duty['descriptor']['key'] === 'payment.incoming'
            ));
            $this->assertCount(1, $bank);
            $this->assertSame(! $loseAcknowledgement, $bank[0]['compensation_status'] === 'verified');
            $held = $this->provider->cartInventory()
                ->inspect($lease);
            $this->assertFalse($held['released']);
            $this->assertSame(2, $held['heldQuantity']);
            $effects = $late ? ['reserve', 'release', 'reserve', 'refund-incoming'] : ['reserve', 'refund-incoming'];
            $this->assertSame($effects, $this->effects());
            $requestsBefore = $this->refundRequests($refund->identity->key());
            $this->assertSame(1, $requestsBefore);

            $data['phase'] = 'resume';
            $data['clock'] = $cut['clock'];
            file_put_contents($input, json_encode($data, JSON_THROW_ON_ERROR));
            file_put_contents($report, '');
            $process = new Process($command, dirname(__DIR__, 3), [
                'DWF_PROVIDER_RECOVERY_INPUT' => $input,
            ], timeout: 180);
            $process->mustRun();
            $resumed = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotSame(getmypid(), $resumed['pid']);
            $this->assertNotSame($stoppedPid, $resumed['pid']);
            $this->assertSame('verified', $resumed['status']);
            $rootAfter = $scopes->inspect($plan->rootScope());
            $paymentAfter = $scopes->inspect($plan->childScope('payment'));
            $this->assertSame('verified', $rootAfter['status']);
            $this->assertSame($rootBefore['original_run_id'], $rootAfter['original_run_id']);
            $this->assertSame($paymentBefore['original_run_id'], $paymentAfter['original_run_id']);
            foreach ([[$rootBefore, $rootAfter], [$paymentBefore, $paymentAfter]] as [$before, $after]) {
                $this->assertSame(
                    array_column($before['obligations'], 'id'),
                    array_column($after['obligations'], 'id')
                );
                $this->assertSame(
                    array_fill(0, count($after['obligations']), 'verified'),
                    array_column($after['obligations'], 'compensation_status')
                );
            }
            $this->assertSame('cancelled', $this->store->authority->subject($key, 'order')->state);
            $this->assertSame([...$effects, 'release'], $this->effects());
            $this->assertSame($order, app(CartStore::class)->order($key));
            $this->assertSame($this->cart->toArray(), app(CartStore::class)->plan($key)->toArray());
            $stock = $this->provider->cartInventory()
                ->inspect($lease);
            $this->assertTrue($stock['released']);
            $this->assertSame(2, $stock['availableQuantity']);
            $requestsAfter = $this->refundRequests($refund->identity->key());
            if ($loseAcknowledgement) {
                $this->assertGreaterThanOrEqual($requestsBefore, $requestsAfter);
            } else {
                $this->assertSame($requestsBefore, $requestsAfter);
            }
            $operation = $this->providerDb->prepare(
                'SELECT request, fingerprint FROM provider_operations WHERE id = ?'
            );
            $this->assertNotFalse($operation);
            $operation->execute([$refund->identity->key()]);
            $recorded = $operation->fetch();
            $this->assertIsArray($recorded);
            $this->assertSame($refund->fingerprint(), $recorded['fingerprint']);
            $this->assertSame($refund->toArray(), Request::fromArray(json_decode(
                $recorded['request'],
                true,
                flags: JSON_THROW_ON_ERROR
            ))->toArray());
            $this->assertSame($rootHistory, array_slice($this->history($origin->runId()), 0, count($rootHistory)));
            $this->assertSame($paymentHistory, array_slice(
                $this->history($paymentBefore['original_run_id']),
                0,
                count($paymentHistory)
            ));
        } finally {
            if ($process?->isRunning()) {
                $process->stop(1);
            }
            unlink($input);
            unlink($report);
        }
    }

    private function refundRequests(string $id): int
    {
        $statement = $this->providerDb->prepare(
            'SELECT COUNT(*) FROM provider_requests WHERE operation_id = ? AND kind = ?'
        );
        $this->assertNotFalse($statement);
        $statement->execute([$id, 'command']);
        return (int) $statement->fetchColumn();
    }
}
