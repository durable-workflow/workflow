<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Closure;
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
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * PAY-06 SUB-03/SUB-04 under TECH-01: a real worker process dies after the provider commits a service period's
 * charge or refund and before DW records it, or after DW admitted the charge and before the provider was called.
 * A fresh process recovers the same original operation through the production watchdog, also while cancellation,
 * future-billing cancellation or re-admission races it. No second charge, no restarted grace interval and no
 * effect on another period.
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
        [$cut, $resumed] = $this->losePeriodWorker(
            $run,
            $mode,
            $refund ? 'verified' : 'completed',
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
        $this->assertNextPeriodUntouched();
        // Request attempts may repeat across processes; committed effects never do.
        $requests = $this->billingRequests();
        $this->assertGreaterThan($this->billingEffects(), $requests);
        app(WorkflowReplayer::class)->replay($run->fresh());
        $this->assertSame($requests, $this->billingRequests());
    }

    /**
     * DW admitted the original period charge and the worker died before calling the provider. Recovery only
     * looks the charge up and leaves it unresolved; cancelling the period settles it through the provider's
     * admission fence. No charge, refund or later forward effect exists, and the next period is untouched.
     */
    public function testWorkerLostAfterAdmittingThePeriodChargeBeforeTheProviderCall(): void
    {
        $this->requireWorkerLossSupport();
        $run = $this->configurePeriod(BillingPeriod::PAYMENT_FIRST, Status::Confirmed, pump: false);
        $key = $this->period->subjectKey();
        $charge = Request::fromArray($this->period->paymentDescriptor()['forward']);
        $row = null;
        [$cut, $resumed] = $this->losePeriodWorker($run, 'before', 'verified', function (array $cut, array $data) use (
            &$row
        ): array {
            $row = $this->periodObligation($cut);
            $this->assertSame('in_flight', $row['forward_status']);
            $this->assertSame(0, $this->billingRequests('command'));
            return [
                ...$data,
                'clock' => $cut['clock'],
            ];
        });
        Carbon::setTestNow($resumed['clock']);
        // Native recovery stopped unresolved: the charge is neither paid nor reported as not executed.
        $this->assertFalse($resumed['reached']);
        $unresolved = $this->periodObligation($cut);
        $this->assertSame([$row['id'], $row['fingerprint']], [$unresolved['id'], $unresolved['fingerprint']]);
        $this->assertSame(['unknown', 'pending'], [
            $unresolved['forward_status'],
            $unresolved['compensation_status'],
        ]);
        $this->assertSame('failed', $run->fresh()->status->value);
        $this->assertSame([0, 0, 2], [
            $this->billingEffects(),
            $this->billingRequests('command'),
            $this->billingRequests('lookup'),
        ]);
        $this->assertFalse($this->periodStore->access($key)['access']);

        $this->assertSame('recovery', $this->periodStore->cancel($key, 'cancel-admitted-charge')['route']);
        $this->pumpBilling();
        $settled = $this->periodObligation($cut);
        $this->assertSame(['unknown', 'verified'], [$settled['forward_status'], $settled['compensation_status']]);
        $this->assertSame(
            ['provider-fenced:' . $charge->identity->key()],
            json_decode($settled['evidence'], true)['evidence']
        );
        $this->assertSame('verified', (new ScopeRepository())->inspect($this->periodStore->scope($key))['status']);
        $this->assertSame([0, 0], [$this->billingEffects(), $this->billingRequests('command')]);
        $this->assertFalse($this->periodStore->access($key)['access']);
        $this->assertSame($run->id, $this->periodStore->admit($key)['runId']);
        $this->assertNextPeriodUntouched();
        $requests = $this->billingRequests();
        try {
            app(WorkflowReplayer::class)->replay($run->fresh());
            $this->fail('The original period must replay its recorded forward failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('scope_forward_unknown', $error->getMessage());
        }
        $this->assertSame($requests, $this->billingRequests());
        // A delayed forward command arriving after the fence cannot create the charge.
        $late = $this->billingProvider->attempt($charge);
        $this->assertSame([Status::NotExecuted, null, true], [$late->status, $late->effectId, $late->admissionClosed]);
        $this->assertSame(0, $this->billingEffects());
    }

    public static function races(): array
    {
        return [
            'cancelling the period refunds the reconciled charge' => ['cancel'],
            'cancelling future billing retains the paid period' => ['future'],
            'duplicate confirmation, re-admission and the next period' => ['admission'],
        ];
    }

    /**
     * PAY-06 races: the period charge committed and its worker died before DW recorded it. While no worker is
     * alive, the business cancels the period, cancels future billing, or re-admits it and sends a duplicate
     * confirmation while the next period is admitted. Recovery reconciles the same original charge once.
     */
    #[DataProvider('races')]
    public function testARaceWithALostPeriodChargeSettlesOnlyTheOriginalCharge(string $race): void
    {
        $this->requireWorkerLossSupport();
        $run = $this->configurePeriod(BillingPeriod::PAYMENT_FIRST, Status::Confirmed, pump: false);
        $key = $this->period->subjectKey();
        $charge = Request::fromArray($this->period->paymentDescriptor()['forward']);
        $next = null;
        $row = null;
        [$cut, $resumed] = $this->losePeriodWorker(
            $run,
            'forward',
            $race === 'cancel' ? 'verified' : 'completed',
            function (array $cut, array $data) use ($race, $key, $run, &$next, &$row): array {
                $row = $this->periodObligation($cut);
                $this->assertSame('in_flight', $row['forward_status']);
                $this->assertSame(1, $this->billingEffects());
                Carbon::setTestNow($cut['clock']);
                if ($race === 'cancel') {
                    $this->periodStore->cancel($key, 'cancel-while-charge-lost');
                } elseif ($race === 'future') {
                    $this->periodStore->cancelFuture(
                        $key,
                        'stop-renewal-while-charge-lost',
                        $this->nextPeriod->startsAt
                    );
                } else {
                    $again = $this->periodStore->admit($key);
                    $this->assertSame(
                        [true, $run->id, true],
                        [$again['accepted'], $again['runId'], $again['existing']]
                    );
                    WorkflowStub::loadRun($run->id)->withRequestId('duplicate-confirmation')->signal(
                        'payment-observed'
                    );
                    Carbon::setTestNow(Carbon::createFromTimestampUTC($this->nextPeriod->startsAt));
                    $next = $this->periodStore->admit($this->nextPeriod->subjectKey());
                    $this->assertTrue($next['accepted']);
                    $this->assertNotSame($run->id, $next['runId']);
                }
                return [
                    ...$data,
                    'clock' => now()
                        ->toISOString(),
                ];
            },
        );
        Carbon::setTestNow($resumed['clock']);
        $this->assertTrue($resumed['reached'], $this->billingProgress());
        $this->pumpBilling();
        $recovered = $this->periodObligation($cut);
        $this->assertSame([$row['id'], $row['fingerprint']], [$recovered['id'], $recovered['fingerprint']]);
        // One provider command and one effect for the original charge, at its original commit time.
        $this->assertSame([1, 1], [$this->billingRequests('command', $charge), $this->billingEffects($charge)]);
        $this->assertSame(Carbon::parse($cut['clock'])->getTimestamp(), $this->billingProvider->timedLookup(
            $charge
        )->confirmedAt);
        $this->assertSame($run->id, $this->periodStore->admit($key)['runId']);
        $access = $this->periodStore->access($key);
        if ($race === 'cancel') {
            // The cancellation covers this period: its reconciled charge is refunded once, never duplicated.
            $this->assertSame('verified', $recovered['compensation_status']);
            $this->assertSame(
                ['effect-' . $charge->identity->key(), 'effect-' . Request::fromArray(
                    $this->period->paymentDescriptor()['compensation']
                )->identity->key()],
                json_decode($recovered['evidence'], true)['evidence']
            );
            $this->assertSame(2, $this->billingEffects());
            $this->assertFalse($access['access']);
            $this->assertSame('cancelled', $run->fresh()->status->value);
            $this->assertNextPeriodUntouched();
        } else {
            $this->assertSame(['observed', 'pending'], [
                $recovered['forward_status'],
                $recovered['compensation_status'],
            ]);
            $this->assertSame('paid', $run->fresh()->workflowOutput()['businessOutcome'], $this->billingProgress());
            $this->assertTrue($access['paidOnTime']);
        }
        if ($race === 'future') {
            // Cancelling future billing retains the already-paid period and blocks the next admission.
            $this->assertSame(1, $this->billingEffects());
            $this->assertTrue($access['access']);
            Carbon::setTestNow(Carbon::createFromTimestampUTC($this->nextPeriod->startsAt));
            $this->assertFalse($this->periodStore->admit($this->nextPeriod->subjectKey())['accepted']);
            $this->assertNextPeriodUntouched();
        }
        if ($race === 'admission') {
            // The next period has its own native owner and charge; recovery targeted only the original one.
            $nextCharge = Request::fromArray($this->nextPeriod->paymentDescriptor()['forward']);
            $this->assertSame([1, 2], [$this->billingEffects($nextCharge), $this->billingEffects()]);
            $nextKey = $this->nextPeriod->subjectKey();
            $this->assertNotSame(
                $this->periodStore->scope($key)
                    ->toArray(),
                $this->periodStore->scope($nextKey)
                    ->toArray()
            );
            $this->assertTrue($this->periodStore->access($nextKey)['access']);
            $this->assertSame($next['runId'], $this->periodStore->admit($nextKey)['runId']);
        }
        $this->assertSame(
            $race === 'admission' ? 2 : 1,
            WorkflowRun::query()->where('workflow_class', ServicePeriodPayment::class)->count()
        );
        $requests = $this->billingRequests();
        app(WorkflowReplayer::class)->replay($run->fresh());
        $this->assertSame($requests, $this->billingRequests());
    }

    private function losePeriodWorker(WorkflowRun $run, string $mode, string $until, Closure $whileLost): array
    {
        return $this->loseWorkerThenRecover(
            'tests/Support/BillingPeriodProcessLossProcessTest.php',
            'DWF_BILLING_PROCESS_INPUT',
            [
                'association' => 'service',
                'periods' => [$this->period->toArray(), $this->nextPeriod->toArray()],
                'key' => $this->period->subjectKey(),
                'runId' => $run->id,
                'handler' => 'billing.period.payment',
                'mode' => $mode,
                'until' => $until,
                'provider' => $this->billingProviderPath,
                'clock' => now()
                    ->toISOString(),
            ],
            $whileLost,
        );
    }

    /**
     * Recovery for the original period neither charged nor admitted the next one.
     */
    private function assertNextPeriodUntouched(): void
    {
        $this->assertSame(0, $this->billingRequests(
            null,
            Request::fromArray($this->nextPeriod->paymentDescriptor()['forward'])
        ));
        $this->assertFalse($this->periodStore->access($this->nextPeriod->subjectKey())['access']);
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

    private function billingEffects(?Request $operation = null): int
    {
        return $this->billingCount('provider_effects', null, $operation);
    }

    private function billingRequests(?string $kind = null, ?Request $operation = null): int
    {
        return $this->billingCount('provider_requests', $kind, $operation);
    }

    private function billingCount(string $table, ?string $kind, ?Request $operation): int
    {
        $query = $this->billingProviderDb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE (? IS NULL OR operation_id = ?)"
            . ($kind === null ? '' : ' AND kind = ?')
        );
        $key = $operation?->identity->key();
        $query->execute($kind === null ? [$key, $key] : [$key, $key, $kind]);
        return (int) $query->fetchColumn();
    }
}
