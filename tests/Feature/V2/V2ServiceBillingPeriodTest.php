<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\BillingPeriod;
use DurableWorkflow\Examples\ProviderStateMachines\ServicePeriodPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BillingPeriodMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

final class V2ServiceBillingPeriodTest extends TestCase
{
    use BillingPeriodMachineFixture;

    public static function policies(): array
    {
        return [[BillingPeriod::PAYMENT_FIRST], [BillingPeriod::GRACE]];
    }

    #[DataProvider('policies')]
    public function testOriginalPeriodPaymentUsesTheCommonRouteWithoutInventingOrderOrShipment(string $policy): void
    {
        $run = $this->configurePeriod($policy);
        $this->assertSame('waiting', $run->fresh()->status->value, $this->billingProgress());
        $this->assertSame(
            $policy === BillingPeriod::GRACE,
            $this->periodStore->access($this->period->subjectKey())['access']
        );
        $this->confirm($run, $this->billingStart + 86400);
        $this->assertSame('completed', $run->fresh()->status->value, $this->billingProgress());
        $this->assertSame('paid', $run->fresh()->workflowOutput()['paymentState']);
        $this->assertTrue($this->periodStore->access($this->period->subjectKey())['access']);
        $this->assertSame(
            [ServicePeriodPayment::class],
            WorkflowRun::query()->distinct()->pluck('workflow_class')->all()
        );
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('provider_machine_assignments'));
        $this->assertSame(
            1,
            (int) $this->billingProviderDb->query('SELECT COUNT(*) FROM provider_effects')
                ->fetchColumn()
        );
        $before = $this->billingProviderDb->query('SELECT COUNT(*) FROM provider_requests')
            ->fetchColumn();
        app(WorkflowReplayer::class)->replay($run->fresh());
        $this->assertSame(
            $before,
            $this->billingProviderDb->query('SELECT COUNT(*) FROM provider_requests')
                ->fetchColumn()
        );
        $this->assertSame($run->id, $this->periodStore->admit($this->period->subjectKey())['runId']);
        $scope = (new ScopeRepository())->inspect($this->periodStore->scope($this->period->subjectKey()));
        $this->assertCount(2, $scope['obligations']);
    }

    public function testUnpaidGraceExpiresAtTheOriginalTenDayDeadlineWhileThePaymentRemainsUnresolved(): void
    {
        $run = $this->configurePeriod(BillingPeriod::GRACE);
        $deadline = $this->period->paymentDeadline;
        Carbon::setTestNow(Carbon::createFromTimestampUTC($deadline - 1));
        $this->pumpBilling();
        $this->assertTrue($this->periodStore->access($this->period->subjectKey())['access']);
        Carbon::setTestNow(Carbon::createFromTimestampUTC($deadline));
        $this->pumpBilling();
        $this->assertFalse($this->periodStore->access($this->period->subjectKey())['access']);
        $this->assertSame(
            $deadline,
            $this->periodStore->assignments->period($this->period->subjectKey())
->paymentDeadline
        );
        $this->assertSame('waiting', $run->fresh()->status->value, $this->billingProgress());
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->period->endsAt));
        $this->pumpBilling();
        $this->assertSame('completed', $run->fresh()->status->value, $this->billingProgress());
        $this->assertSame('unsettled', $run->fresh()->workflowOutput()['businessOutcome']);
        $this->assertSame(
            0,
            (int) $this->billingProviderDb->query('SELECT COUNT(*) FROM provider_effects')
                ->fetchColumn()
        );
        $this->assertNotSame(
            'verified',
            (new ScopeRepository())->inspect($this->periodStore->scope($this->period->subjectKey()))['status']
        );
    }

    public function testLateConfirmedMoneyRemainsFactualWithoutImplicitServiceReactivation(): void
    {
        $run = $this->configurePeriod(BillingPeriod::GRACE);
        $this->confirm($run, $this->period->paymentDeadline);
        $this->assertSame('paid', $run->fresh()->workflowOutput()['paymentState'], $this->billingProgress());
        $value = $this->periodStore->access($this->period->subjectKey());
        $this->assertTrue($value['latePayment']);
        $this->assertFalse($value['access']);
        $this->assertSame(
            1,
            (int) $this->billingProviderDb->query('SELECT COUNT(*) FROM provider_effects')
                ->fetchColumn()
        );
    }

    #[DataProvider('policies')]
    public function testCancellingFutureBillingDoesNotRefundThePaidPeriodOrAdmitTheNextOne(string $policy): void
    {
        $run = $this->configurePeriod($policy, Status::Confirmed);
        $this->assertSame('paid', $run->fresh()->workflowOutput()['paymentState'], $this->billingProgress());
        $cutoff = $this->nextPeriod->startsAt;
        $this->periodStore->cancelFuture($this->period->subjectKey(), 'do-not-renew', $cutoff);
        $this->assertTrue($this->periodStore->access($this->period->subjectKey())['access']);
        Carbon::setTestNow(Carbon::createFromTimestampUTC($cutoff));
        $next = $this->periodStore->admit($this->nextPeriod->subjectKey());
        $this->assertFalse($next['accepted']);
        $this->assertNull($next['runId']);
        $this->assertFalse($this->periodStore->access($this->nextPeriod->subjectKey())['access']);
        $this->periodStore->cancelFuture($this->period->subjectKey(), 'do-not-renew', $cutoff);
        $this->assertSame(1, WorkflowRun::query()->count());
        $this->assertSame(
            1,
            (int) $this->billingProviderDb->query('SELECT COUNT(*) FROM provider_effects')
                ->fetchColumn()
        );
    }

    public function testPaidPeriodRetainsItsOriginalRefundDutyAfterTechnicalCompletion(): void
    {
        $run = $this->configurePeriod(BillingPeriod::PAYMENT_FIRST, Status::Confirmed);
        $this->assertSame('completed', $run->fresh()->status->value, $this->billingProgress());
        $this->periodStore->cancel($this->period->subjectKey(), 'refund-original-paid-period');
        $this->pumpBilling();
        $scope = (new ScopeRepository())->inspect($this->periodStore->scope($this->period->subjectKey()));
        $this->assertSame('verified', $scope['status'], $this->billingProgress());
        $this->assertFalse($this->periodStore->access($this->period->subjectKey())['access']);
        $this->assertSame(
            2,
            (int) $this->billingProviderDb->query('SELECT COUNT(*) FROM provider_effects')
                ->fetchColumn()
        );
        $this->assertSame(1, WorkflowRun::query()->where('workflow_class', ServicePeriodPayment::class)->count());
    }

    public function testAnOnTimeProviderCommitAcknowledgedAfterGraceKeepsItsOriginalTimestamp(): void
    {
        $run = $this->configurePeriod(BillingPeriod::GRACE);
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->period->paymentDeadline - 1));
        $charge = Request::fromArray($this->period->paymentDescriptor()['forward']);
        $this->billingProvider->resolve($charge, Status::Confirmed);
        // No task executes between the provider commit and this later acknowledgement.
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->period->paymentDeadline + 60));
        \Workflow\V2\WorkflowStub::loadRun($run->id)->withRequestId('delayed-original-ack')->signal('payment-observed');
        $this->pumpBilling();
        $this->assertSame('completed', $run->fresh()->status->value, $this->billingProgress());
        $value = $this->periodStore->access($this->period->subjectKey());
        $this->assertTrue($value['paidOnTime']);
        $this->assertTrue($value['access']);
        $this->assertSame(
            $this->period->paymentDeadline - 1,
            $this->billingProvider->timedLookup($charge)
->confirmedAt
        );
        $this->assertSame(
            1,
            (int) $this->billingProviderDb->query('SELECT COUNT(*) FROM provider_effects')
                ->fetchColumn()
        );
    }

    public function testRecoveryOfAnEarlierPeriodCannotRefundOrCloseTheNextPeriod(): void
    {
        $first = $this->configurePeriod(BillingPeriod::PAYMENT_FIRST, Status::Confirmed);
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->nextPeriod->startsAt));
        $nextCharge = Request::fromArray($this->nextPeriod->paymentDescriptor()['forward']);
        $this->billingProvider->plan($nextCharge, Status::Confirmed);
        $next = $this->periodStore->admit($this->nextPeriod->subjectKey());
        $this->pumpBilling();
        $this->assertNotSame($first->id, $next['runId']);
        $this->assertTrue($this->periodStore->access($this->nextPeriod->subjectKey())['access']);
        $this->periodStore->cancel($this->period->subjectKey(), 'old-period-only');
        $this->pumpBilling();
        $this->assertSame(
            'verified',
            (new ScopeRepository())->inspect($this->periodStore->scope($this->period->subjectKey()))['status']
        );
        $nextScope = (new ScopeRepository())->inspect($this->periodStore->scope($this->nextPeriod->subjectKey()));
        $this->assertNotSame('verified', $nextScope['status']);
        $this->assertTrue($this->periodStore->access($this->nextPeriod->subjectKey())['access']);
        $this->assertFalse($this->billingProvider->timedLookup($nextCharge)->observation->admissionClosed);
        $this->assertSame(
            3,
            (int) $this->billingProviderDb->query('SELECT COUNT(*) FROM provider_effects')
                ->fetchColumn()
        );
        $this->assertSame(2, WorkflowRun::query()->where('workflow_class', ServicePeriodPayment::class)->count());
    }

    public function testCancellationBeforeGenuineOwnerInitializationStaysUnresolvedUntilThatOwnerRegisters(): void
    {
        $run = $this->configurePeriod(BillingPeriod::PAYMENT_FIRST, Status::Confirmed, pump: false);
        $result = $this->periodStore->cancel($this->period->subjectKey(), 'cancel-before-initialization');
        $this->assertSame('unresolved', $result['status']);
        $this->assertSame('original_scope_not_initialized', $result['reason']);
        $this->assertSame('pending', $run->fresh()->status->value);
        $this->assertSame(
            0,
            (int) $this->billingProviderDb->query('SELECT COUNT(*) FROM provider_operations')
                ->fetchColumn()
        );
        $this->pumpBilling();
        $scope = (new ScopeRepository())->inspect($this->periodStore->scope($this->period->subjectKey()));
        $this->assertSame('verified', $scope['status'], $this->billingProgress());
        $this->assertSame('compensated', $run->fresh()->workflowOutput()['businessOutcome']);
        $this->assertSame(
            0,
            (int) $this->billingProviderDb->query('SELECT COUNT(*) FROM provider_effects')
                ->fetchColumn()
        );
    }

    public function testAForwardAcknowledgementLossReconcilesTheSameChargeAndOriginalTime(): void
    {
        $run = $this->configurePeriod(BillingPeriod::PAYMENT_FIRST, Status::Confirmed, loseAck: true);
        $this->assertSame(
            1,
            (int) $this->billingProviderDb->query('SELECT COUNT(*) FROM provider_effects')
                ->fetchColumn()
        );
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->billingStart + 60));
        $this->pumpBilling();
        $this->assertSame('completed', $run->fresh()->status->value, $this->billingProgress());
        $this->assertTrue($this->periodStore->access($this->period->subjectKey())['access']);
        $charge = Request::fromArray($this->period->paymentDescriptor()['forward']);
        $this->assertSame($this->billingStart, $this->billingProvider->timedLookup($charge)->confirmedAt);
        $this->assertSame(
            1,
            (int) $this->billingProviderDb->query('SELECT COUNT(*) FROM provider_effects')
                ->fetchColumn()
        );
    }

    public function testMoneyConfirmedAfterTheTechnicalPeriodEndsCanStillBeRefundedByItsOriginalOwner(): void
    {
        $run = $this->configurePeriod(BillingPeriod::GRACE);
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->period->endsAt));
        $this->pumpBilling();
        $this->assertSame('completed', $run->fresh()->status->value, $this->billingProgress());
        $this->assertSame('unsettled', $run->fresh()->workflowOutput()['businessOutcome']);
        $charge = Request::fromArray($this->period->paymentDescriptor()['forward']);
        $this->billingProvider->resolve($charge, Status::Confirmed);
        $this->assertFalse($this->periodStore->access($this->period->subjectKey())['access']);
        $this->periodStore->cancel($this->period->subjectKey(), 'refund-after-period-end');
        $this->pumpBilling();
        $scope = (new ScopeRepository())->inspect($this->periodStore->scope($this->period->subjectKey()));
        $this->assertSame('verified', $scope['status'], $this->billingProgress());
        $this->assertSame(
            2,
            (int) $this->billingProviderDb->query('SELECT COUNT(*) FROM provider_effects')
                ->fetchColumn()
        );
        $this->assertSame($run->id, $this->periodStore->admit($this->period->subjectKey())['runId']);
    }

    public function testPaidAccessRefusesAChangedProtectedReceiptWithoutCallingTheProvider(): void
    {
        $this->configurePeriod(BillingPeriod::PAYMENT_FIRST, Status::Confirmed);
        $before = $this->billingProviderDb->query('SELECT COUNT(*) FROM provider_requests')
            ->fetchColumn();
        DB::connection()->table('example_machine_decisions')->update([
            'request_sha256' => str_repeat('0', 64),
        ]);
        try {
            $this->periodStore->access($this->period->subjectKey());
            $this->fail('A changed protected paid decision cannot authorize service access.');
        } catch (LogicException $error) {
            $this->assertSame('The paid period has no matching immutable payment assessment.', $error->getMessage());
        }
        $this->assertSame(
            $before,
            $this->billingProviderDb->query('SELECT COUNT(*) FROM provider_requests')
                ->fetchColumn()
        );
    }

    public function testGraceAccessAlsoRefusesASubstitutedNativeAdmissionReceipt(): void
    {
        $this->configurePeriod(BillingPeriod::GRACE);
        $this->assertTrue($this->periodStore->access($this->period->subjectKey())['access']);
        $before = $this->billingProviderDb->query('SELECT COUNT(*) FROM provider_requests')
            ->fetchColumn();
        DB::connection()->table('provider_billing_periods')->where('id', $this->period->subjectKey())->update([
            'admission_receipt' => '{}',
            'admission_sha256' => hash('sha256', '{}'),
        ]);
        try {
            $this->periodStore->access($this->period->subjectKey());
            $this->fail('Grace access requires the same original native admission as paid access.');
        } catch (LogicException $error) {
            $this->assertSame('The original native period admission receipt changed.', $error->getMessage());
        }
        $this->assertSame(
            $before,
            $this->billingProviderDb->query('SELECT COUNT(*) FROM provider_requests')
                ->fetchColumn()
        );
    }

    public static function foreignAssignmentReads(): array
    {
        return [['subject'], ['subscription']];
    }

    #[DataProvider('foreignAssignmentReads')]
    public function testAssignmentRepositoryRefusesAnUnassignedPeriodBeforeProtectedSql(string $operation): void
    {
        $this->configurePeriod(BillingPeriod::GRACE, pump: false);
        $foreign = $this->terms('2026-03', $this->nextPeriod->endsAt, BillingPeriod::GRACE);
        $otherHost = new \DurableWorkflow\Examples\ProviderStateMachines\BillingPeriodStore(
            DB::connection(),
            'period-worker',
            [$foreign],
            $this->billingProvider
        );
        $otherHost->seed($foreign);
        $queries = [];
        DB::connection()->listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        try {
            $assigned = $this->periodStore->assignments;
            if ($operation === 'subject') {
                $assigned->subject($foreign->subjectKey(), $foreign);
            } else {
                $assigned->subscription($foreign);
            }
            $this->fail('Same-principal storage does not grant this host an unassigned period.');
        } catch (LogicException $error) {
            $this->assertSame('The original period is outside this authenticated host grant.', $error->getMessage());
        }
        $this->assertSame([], $queries);
    }
}
