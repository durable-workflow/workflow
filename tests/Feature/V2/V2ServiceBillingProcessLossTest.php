<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\BillingPeriod;
use DurableWorkflow\Examples\ProviderStateMachines\ServicePeriodPayment;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BillingPeriodMachineFixture;
use Tests\Support\WorkerLossControl;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * PAY-06 SUB-03/SUB-04 under TECH-01: a real worker process dies after the provider commits a service period's
 * charge or refund and before DW records it. A fresh process recovers the same original operation through the
 * production watchdog. No second charge, no restarted grace interval and no effect on another period.
 */
final class V2ServiceBillingProcessLossTest extends TestCase
{
    use BillingPeriodMachineFixture;
    use WorkerLossControl;

    public static function cuts(): array
    {
        return [
            'payment-first charge committed' => [BillingPeriod::PAYMENT_FIRST, 'forward', false],
            'grace charge committed, replacement after the deadline' => [BillingPeriod::GRACE, 'forward', true],
            'refund committed after cancelling a paid period' => [BillingPeriod::PAYMENT_FIRST, 'compensate', false],
        ];
    }

    #[DataProvider('cuts')]
    public function testFreshWorkerRecoversTheOriginalPeriodEffectOnce(string $policy, string $mode, bool $late): void
    {
        $this->requireWorkerLossSupport();
        $refund = $mode === 'compensate';
        $run = $this->configurePeriod($policy, Status::Confirmed, pump: $refund);
        $key = $this->period->subjectKey();
        $deadline = $this->period->paymentDeadline;
        if ($refund) {
            $this->assertSame('paid', $run->fresh()->workflowOutput()['paymentState'], $this->billingProgress());
            $this->periodStore->cancel($key, 'refund-before-worker-loss');
        }
        $charge = Request::fromArray($this->period->paymentDescriptor()['forward']);
        $row = null;
        [$cut, $resumed] = $this->loseWorkerThenRecover(
            'tests/Support/BillingPeriodProcessLossProcessTest.php',
            'DWF_BILLING_PROCESS_INPUT',
            [
                'association' => 'service',
                'periods' => [$this->period->toArray(), $this->nextPeriod->toArray()],
                'key' => $key,
                'runId' => $run->id,
                'handler' => 'billing.period.payment',
                'mode' => $mode,
                'until' => $refund ? 'verified' : 'completed',
                'provider' => $this->billingProviderPath,
                'clock' => now()
                    ->toISOString(),
            ],
            function (array $cut, array $data) use ($refund, $late, $key, $deadline, &$row): array {
                $row = $this->periodObligation($cut);
                // The provider effect exists; DW has not recorded its outcome.
                $this->assertSame($refund ? 2 : 1, $this->billingEffects());
                if ($refund) {
                    $this->assertNotSame('verified', $row['compensation_status']);
                    return $data;
                }
                $this->assertSame('in_flight', $row['forward_status']);
                $this->assertNull($row['forward_result']);
                Carbon::setTestNow($cut['clock']);
                $this->assertSame($late, $this->periodStore->access($key)['access']);
                if ($late) {
                    // Live access applies the fixed cutoff while no worker can record the on-time money.
                    Carbon::setTestNow(Carbon::createFromTimestampUTC($deadline));
                    $this->assertFalse($this->periodStore->access($key)['access']);
                }
                return [
                    ...$data,
                    'clock' => $cut['clock'],
                    'notBefore' => $late ? $deadline + 60 : null,
                ];
            },
        );
        $this->assertTrue($resumed['reached'], $this->billingProgress());
        Carbon::setTestNow($resumed['clock']);
        $recovered = $this->periodObligation($cut);
        // The same original operation was recovered; no replacement obligation or identity appeared.
        $this->assertSame($row['id'], $recovered['id']);
        $this->assertSame($row['fingerprint'], $recovered['fingerprint']);
        $this->assertSame($refund ? 2 : 1, $this->billingEffects());
        $this->assertSame($deadline, $this->periodStore->assignments->period($key)->paymentDeadline);
        $access = $this->periodStore->access($key);
        if ($refund) {
            $this->assertSame('verified', $recovered['compensation_status']);
            $this->assertFalse($access['access']);
        } else {
            $this->assertSame('completed', $run->fresh()->status->value, $this->billingProgress());
            $this->assertSame('paid', $run->fresh()->workflowOutput()['paymentState']);
            // The original provider commit time decides; replacement or acknowledgement time does not.
            $this->assertSame(Carbon::parse($cut['clock'])->getTimestamp(), $this->billingProvider->timedLookup(
                $charge
            )->confirmedAt);
            $this->assertTrue($access['paidOnTime']);
            $this->assertTrue($access['access']);
            $this->assertSame($late, Carbon::now()->getTimestamp() > $deadline);
        }
        $this->assertSame($run->id, $this->periodStore->admit($key)['runId']);
        $this->assertSame(1, WorkflowRun::query()->where('workflow_class', ServicePeriodPayment::class)->count());
        // Recovery for this period neither charged nor admitted the next one.
        $next = Request::fromArray($this->nextPeriod->paymentDescriptor()['forward']);
        $this->assertSame(0, (int) $this->billingProviderDb->query(
            "SELECT COUNT(*) FROM provider_effects WHERE operation_id = '" . $next->identity->key() . "'"
        )->fetchColumn());
        $this->assertFalse($this->periodStore->access($this->nextPeriod->subjectKey())['access']);
        // Request attempts may repeat across processes; committed effects never do.
        $requests = $this->billingRequests();
        $this->assertGreaterThan($this->billingEffects(), $requests);
        app(WorkflowReplayer::class)->replay($run->fresh());
        $this->assertSame($requests, $this->billingRequests());
    }

    private function periodObligation(array $cut): array
    {
        $ledger = (new ScopeRepository())->inspect(ScopeIdentity::fromArray($cut['scope']));
        $this->assertSame($this->periodStore->scope($this->period->subjectKey())->toArray(), $cut['scope']);
        $rows = array_values(array_filter(
            $ledger['obligations'],
            static fn (array $row): bool => $row['id'] === $cut['operationId']
        ));
        $this->assertCount(1, $rows);
        return $rows[0];
    }

    private function billingEffects(): int
    {
        return (int) $this->billingProviderDb->query('SELECT COUNT(*) FROM provider_effects')
            ->fetchColumn();
    }

    private function billingRequests(): int
    {
        return (int) $this->billingProviderDb->query('SELECT COUNT(*) FROM provider_requests')
            ->fetchColumn();
    }
}
