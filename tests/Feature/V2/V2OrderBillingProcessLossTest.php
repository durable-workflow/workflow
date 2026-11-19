<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ProviderStateMachines\BillingPeriod;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCheckout;
use DurableWorkflow\Examples\ProviderStateMachines\FundingPayment;
use Illuminate\Support\Carbon;
use Tests\Support\OrderBillingPeriodFixture;
use Tests\Support\WorkerLossControl;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * PAY-06 SUB-02 under TECH-01: the genuine period Order's on-time capture commits, then its worker process dies
 * before DW records it. The replacement starts only after the fixed ten-day cutoff and recovers the same capture,
 * or the business cancels the period while no worker is alive and recovery refunds that same capture.
 */
final class V2OrderBillingProcessLossTest extends TestCase
{
    use OrderBillingPeriodFixture;
    use WorkerLossControl;

    public function testOnTimeOrderCaptureSurvivesWorkerLossPastTheGraceDeadline(): void
    {
        $this->requireWorkerLossSupport();
        $root = $this->startPeriodOrder(BillingPeriod::GRACE, pump: false);
        $key = $this->period->subjectKey();
        $deadline = $this->period->paymentDeadline;
        $charge = Request::fromArray($this->terms->descriptor('payment')['forward']);
        $row = null;
        [$cut, $resumed] = $this->loseCaptureWorker(
            'ready',
            function (array $cut, array $data) use ($key, $deadline, &$row): array {
                $row = $this->captureObligation($cut);
                // The capture exists at the provider; DW has not recorded it, and nothing else was dispatched.
                $this->assertSame('in_flight', $row['forward_status']);
                $this->assertSame(['capture'], $this->effects());
                Carbon::setTestNow($cut['clock']);
                $this->assertTrue($this->periodStore->access($key)['access']);
                Carbon::setTestNow(Carbon::createFromTimestampUTC($deadline));
                $this->assertFalse($this->periodStore->access($key)['access']);
                return [
                    ...$data,
                    'clock' => $cut['clock'],
                    'notBefore' => $deadline + 60,
                ];
            },
        );
        $this->assertTrue($resumed['reached'], $this->progress());
        Carbon::setTestNow($resumed['clock']);
        $this->assertGreaterThan($deadline, Carbon::now()->getTimestamp());
        $recovered = $this->captureObligation($cut);
        $this->assertSame($row['id'], $recovered['id']);
        $this->assertSame($row['fingerprint'], $recovered['fingerprint']);
        $this->assertSame('paid', $this->state('payment'), $this->progress());
        $this->assertSame(['capture', 'reserve'], $this->effects());
        $this->assertSame(Carbon::parse($cut['clock'])->getTimestamp(), $this->provider->timedLookup(
            $charge
        )->confirmedAt);
        $this->assertSame($deadline, $this->period->paymentDeadline);
        $access = $this->periodStore->access($key);
        $this->assertTrue($access['paidOnTime']);
        $this->assertFalse($access['latePayment']);
        $this->assertTrue($access['access']);
        $this->assertSame($root->runId(), $this->periodStore->admit($key)['runId']);
        $this->assertSame(1, WorkflowRun::query()->where('workflow_class', FundingCheckout::class)->count());
        $this->assertSame(1, WorkflowRun::query()->where('workflow_class', FundingPayment::class)->count());
        $this->assertNextPeriodOrderUntouched();
        $requests = $this->orderRequests();
        app(WorkflowReplayer::class)->replay($this->child('payment'));
        $this->assertSame($requests, $this->orderRequests());
    }

    /**
     * PAY-06 race: the business cancels the period while the Order's committed capture has no live worker.
     * Recovery reconciles that same capture and refunds it once; it reserves no stock and never charges again.
     */
    public function testCancellingThePeriodWhileItsCaptureWorkerIsLostRefundsTheOriginalCapture(): void
    {
        $this->requireWorkerLossSupport();
        $root = $this->startPeriodOrder(BillingPeriod::GRACE, pump: false);
        $key = $this->period->subjectKey();
        $charge = Request::fromArray($this->terms->descriptor('payment')['forward']);
        $row = null;
        [$cut, $resumed] = $this->loseCaptureWorker(
            'cancelled',
            function (array $cut, array $data) use ($key, &$row): array {
                $row = $this->captureObligation($cut);
                $this->assertSame(['capture'], $this->effects());
                Carbon::setTestNow($cut['clock']);
                $this->periodStore->cancel($key, 'cancel-period-while-capture-lost');
                return [
                    ...$data,
                    'clock' => $cut['clock'],
                ];
            },
        );
        Carbon::setTestNow($resumed['clock']);
        $this->assertTrue($resumed['reached'], $this->progress());
        $this->pump();
        $recovered = $this->captureObligation($cut);
        $this->assertSame([$row['id'], $row['fingerprint']], [$recovered['id'], $recovered['fingerprint']]);
        $this->assertSame('verified', $recovered['compensation_status']);
        $refund = Request::fromArray($this->terms->descriptor('payment')['compensation']);
        $this->assertSame(
            ['effect-' . $charge->identity->key(), 'effect-' . $refund->identity->key()],
            json_decode($recovered['evidence'], true)['evidence']
        );
        $this->assertSame(['capture', 'refund'], $this->effects());
        $this->assertSame(Carbon::parse($cut['clock'])->getTimestamp(), $this->provider->timedLookup(
            $charge
        )->confirmedAt);
        $this->assertSame('cancelled', $this->state('order'), $this->progress());
        $this->assertFalse($this->periodStore->access($key)['access']);
        $this->assertSame($root->runId(), $this->periodStore->admit($key)['runId']);
        $this->assertSame(1, WorkflowRun::query()->where('workflow_class', FundingPayment::class)->count());
        $this->assertNextPeriodOrderUntouched();
        $requests = $this->orderRequests();
        app(WorkflowReplayer::class)->replay($this->child('payment'));
        $this->assertSame($requests, $this->orderRequests());
    }

    private function loseCaptureWorker(string $until, \Closure $whileLost): array
    {
        return $this->loseWorkerThenRecover(
            'tests/Support/BillingPeriodProcessLossProcessTest.php',
            'DWF_BILLING_PROCESS_INPUT',
            [
                'association' => 'order',
                'periods' => [$this->period->toArray(), $this->nextPeriod->toArray()],
                'orderKey' => $this->terms->subjectKey(),
                'handler' => 'provider.effect',
                'mode' => 'forward',
                'action' => 'capture',
                'until' => $until,
                'provider' => $this->providerPath,
                'clock' => now()
                    ->toISOString(),
            ],
            $whileLost,
        );
    }

    /**
     * Recovery for this period neither charged nor admitted the next period's Order.
     */
    private function assertNextPeriodOrderUntouched(): void
    {
        $next = Request::fromArray($this->nextPeriod->order->descriptor('payment')['forward']);
        $this->assertSame(0, (int) $this->providerDb->query(
            "SELECT COUNT(*) FROM provider_requests WHERE operation_id = '" . $next->identity->key() . "'"
        )->fetchColumn());
    }

    private function captureObligation(array $cut): array
    {
        $rows = array_values(array_filter(
            (new ScopeRepository())->inspect(ScopeIdentity::fromArray($cut['scope']))['obligations'],
            static fn (array $row): bool => $row['id'] === $cut['operationId']
        ));
        $this->assertCount(1, $rows);
        $this->assertSame('capture', $rows[0]['descriptor']['input']['forward']['action']);
        return $rows[0];
    }

    private function orderRequests(): int
    {
        return (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
            ->fetchColumn();
    }
}
