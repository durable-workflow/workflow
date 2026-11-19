<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\BillingPeriod;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCheckout;
use DurableWorkflow\Examples\ProviderStateMachines\FundingPayment;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use DurableWorkflow\Examples\ProviderStateMachines\OrderBillingPeriodStore;
use DurableWorkflow\Examples\ProviderStateMachines\ServicePeriodPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OrderBillingPeriodFixture;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Genuine native Order association; this does not qualify an installed worker or the full customer matrix. */
final class V2OrderBillingPeriodTest extends TestCase
{
    use OrderBillingPeriodFixture;

    public static function policies(): array
    {
        return [[BillingPeriod::PAYMENT_FIRST], [BillingPeriod::GRACE]];
    }

    public static function unsupportedTimingProfiles(): array
    {
        return [['not-selected', LogicException::class], ['schema-missing', PDOException::class]];
    }

    /**
     * @param class-string<Throwable> $exception
     */
    #[DataProvider('unsupportedTimingProfiles')]
    public function testMissingOriginalTimingCapabilityRefusesBootstrapBeforeNativeAdmission(
        string $case,
        string $exception
    ): void {
        if ($case === 'not-selected') {
            $this->provider = new Journal($this->providerDb, 'shop-a', static fn (): int => Carbon::now()->getTimestamp());
        } else {
            $this->providerDb->exec('DROP TABLE provider_effect_times');
        }
        $refusal = null;
        try {
            $this->startPeriodOrder(BillingPeriod::PAYMENT_FIRST, pump: false);
        } catch (Throwable $error) {
            $refusal = $error;
        }
        if ($refusal === null) {
            // The predecessor admitted this unsupported configuration and could
            // reach money dispatch before discovering the missing time contract.
            $this->pump();
        }
        $this->assertSame([], $this->effects(), 'Missing original timing must refuse before money dispatch.');
        $this->assertSame(0, WorkflowRun::query()->count(), 'Capability admission must precede native admission.');
        $this->assertInstanceOf($exception, $refusal);
    }

    #[DataProvider('policies')]
    public function testPaidPeriodUsesItsGenuineCommonOrderPaymentAndShipment(string $policy): void
    {
        $root = $this->startPeriodOrder($policy);
        $this->assertSame('ready', $this->state('order'), $this->progress());
        $this->assertSame('paid', $this->state('payment'), $this->progress());
        $access = $this->periodStore->access($this->period->subjectKey());
        $this->assertTrue($access['access']);
        $this->assertTrue($access['paidOnTime']);
        $this->assertSame($this->terms->subjectKey(), $access['orderKey']);
        $this->assertSame($root->runId(), $access['originalRunId']);
        $this->assertSame(1, WorkflowRun::query()->where('workflow_class', FundingCheckout::class)->count());
        $this->assertSame(1, WorkflowRun::query()->where('workflow_class', FundingPayment::class)->count());
        $this->assertSame(0, WorkflowRun::query()->where('workflow_class', ServicePeriodPayment::class)->count());
        $this->assertNotSame($this->terms->descriptor('payment'), $this->period->paymentDescriptor());
        $before = $this->periodStore->admit($this->period->subjectKey());
        $this->assertTrue($before['existing']);
        $this->assertSame($root->runId(), $before['runId']);
        // Original paid money outlives its payment window. Physical fulfillment
        // keeps its separate immutable budget rather than rewinding that window.
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->period->paymentDeadline + 60));
        $this->assertTrue($this->periodStore->access($this->period->subjectKey())['access']);
        $root->withRequestId('fulfill-original-period')
            ->signal('fulfill');
        $this->pump();
        $this->assertSame('booked', $this->state('shipment'), $this->progress());
        $this->recordPhysical('handed-over');
        $this->recordPhysical('delivered');
        $this->assertSame('fulfilled', $this->state('order'), $this->progress());
        $charge = Request::fromArray($this->terms->descriptor('payment')['forward']);
        $this->assertSame(1, $this->providerDb->query(
            "SELECT COUNT(*) FROM provider_effects WHERE operation_id = '" . $charge->identity->key() . "'"
        )->fetchColumn());
    }

    #[DataProvider('policies')]
    public function testGraceAccessExpiresWithoutTurningAnUnpaidOrderIntoFulfillment(string $policy): void
    {
        $this->startPeriodOrder($policy, Status::Processing);
        $access = $this->periodStore->access($this->period->subjectKey());
        $this->assertSame($policy === BillingPeriod::GRACE, $access['access']);
        $this->assertFalse($access['paidOnTime']);
        $this->assertSame(0, DB::table('provider_machine_actors')->where(
            'id',
            Authority::subjectId($this->terms->subjectKey(), 'shipment')
        )->count());
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->period->paymentDeadline));
        $this->assertFalse($this->periodStore->access($this->period->subjectKey())['access']);
        $this->assertSame([], $this->effects());
    }

    #[DataProvider('policies')]
    public function testFutureCancellationKeepsThePaidOriginalOrderAndRejectsTheNextPeriod(string $policy): void
    {
        $this->startPeriodOrder($policy);
        $this->periodStore->cancelFuture(
            $this->period->subjectKey(),
            'stop-next-order',
            $this->nextPeriod->startsAt,
        );
        $this->assertTrue($this->periodStore->access($this->period->subjectKey())['access']);
        $this->assertNotContains('refund', $this->effects());
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->nextPeriod->startsAt));
        $this->assertFalse($this->periodStore->admit($this->nextPeriod->subjectKey())['accepted']);
        $this->assertSame(1, WorkflowRun::query()->where('workflow_class', FundingCheckout::class)->count());
    }

    public function testReplacementWorkerCannotForgetTheOriginalPeriodPolicy(): void
    {
        $this->startPeriodOrder(BillingPeriod::PAYMENT_FIRST);
        $authority = new Authority(DB::connection(), 'provider-worker', [$this->terms->subjectKey()], [
            $this->terms->subjectKey() => FundingCatalogue::IMMEDIATE,
        ]);
        DB::table('provider_machine_assignments')->where('id', $this->terms->subjectKey())
            ->update([
                'terms' => '{invalid protected JSON',
            ]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The original subject authority or selected policy is unavailable.');
        $authority->assignment($this->terms->subjectKey());
    }

    public function testPaidAccessRejectsASubstitutedOriginalPaymentPublication(): void
    {
        $this->startPeriodOrder(BillingPeriod::PAYMENT_FIRST);
        $rows = DB::table('example_machine_publications')->where(
            'subject_id',
            Authority::subjectId($this->terms->subjectKey(), 'payment')
        )->get();
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            if ($payload['state'] === 'paid') {
                $payload['execution']['executionId'] = 'another-period.confirm-receipt.3';
                DB::table('example_machine_publications')->where('id', $row->id)
                    ->update([
                        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    ]);
            }
        }
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The original Order Payment publication is missing or substituted.');
        $this->periodStore->access($this->period->subjectKey());
    }

    #[DataProvider('policies')]
    public function testAnOnTimeCommitAcknowledgedAfterGraceKeepsTheOriginalOrder(string $policy): void
    {
        $root = $this->startPeriodOrder($policy, Status::Processing);
        $charge = Request::fromArray($this->period->order->descriptor('payment')['forward']);
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->period->paymentDeadline - 1));
        $this->provider->resolve($charge, Status::Confirmed);
        // Rebuild the host's policy/handlers while no native task is executing.
        $this->store = MachineStore::configure($this->app, $this->store->authority, $this->provider);
        $this->periodStore = $this->app->make(OrderBillingPeriodStore::class);
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->period->paymentDeadline + 60));
        $this->signalPayment('delayed-original-ack');
        $this->assertSame('paid', $this->state('payment'), $this->progress());
        $this->assertSame('ready', $this->state('order'), $this->progress());
        $this->assertTrue($this->periodStore->access($this->period->subjectKey())['access']);
        $this->assertSame($this->period->paymentDeadline - 1, $this->provider->timedLookup($charge)->confirmedAt);
        $before = $this->effects();
        app(WorkflowReplayer::class)->replay($this->child('payment'));
        $this->assertSame($before, $this->effects());
        $this->assertSame($root->runId(), $this->periodStore->admit($this->period->subjectKey())['runId']);
        $this->assertSame(
            1,
            count(array_filter($this->effects(), static fn (string $effect): bool => $effect === 'capture'))
        );
    }

    #[DataProvider('policies')]
    public function testLateOriginalMoneyDoesNotActivateAccessOrReserveStock(string $policy): void
    {
        $this->startPeriodOrder($policy, Status::Processing);
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->period->paymentDeadline));
        $charge = Request::fromArray($this->period->order->descriptor('payment')['forward']);
        $this->provider->resolve($charge, Status::Confirmed);
        $this->signalPayment('late-original-money');
        $this->assertSame('paid', $this->state('payment'), $this->progress());
        $value = $this->periodStore->access($this->period->subjectKey());
        $this->assertTrue($value['latePayment']);
        $this->assertFalse($value['access']);
        $this->assertNotContains('reserve', $this->effects());
        $this->assertNotContains('ship-book', $this->effects());
        $this->assertSame(['capture'], $this->effects());
    }

    public function testAQueuedOriginalOrderCannotFirstChargeAfterItsPaymentDeadline(): void
    {
        $this->startPeriodOrder(BillingPeriod::GRACE, pump: false);
        $this->assertSame([], $this->effects());
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->period->paymentDeadline));
        $this->pump();
        $this->assertSame([], $this->effects());
        $this->assertSame(0, (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_operations')->fetchColumn());
        $this->assertFalse($this->periodStore->access($this->period->subjectKey())['access']);
        $this->assertNotSame('paid', $this->state('payment'), $this->progress());
    }

    public function testCancellationBeforeOriginalInitializationDoesNotInventAnEmptyScope(): void
    {
        $root = $this->startPeriodOrder(BillingPeriod::PAYMENT_FIRST, pump: false);
        $result = $this->periodStore->cancel($this->period->subjectKey(), 'cancel-before-period-owner');
        $this->assertSame('unresolved', $result['status']);
        $this->assertSame('original_scope_not_initialized', $result['reason']);
        $this->assertSame([], $this->effects());
        $this->pump();
        $proof = (new ScopeRepository())->inspect(
            $this->store->authority->plan($this->terms->subjectKey())
                ->rootScope()
        );
        $this->assertSame('verified', $proof['status'], $this->progress());
        $this->assertSame([], $this->effects());
        $this->assertFalse($this->periodStore->access($this->period->subjectKey())['access']);
        $this->assertSame($root->runId(), $this->periodStore->admit($this->period->subjectKey())['runId']);
    }

    public function testRecoveryOfThePreviousPeriodCannotRefundTheNextOrdersMoney(): void
    {
        $first = $this->startPeriodOrder(BillingPeriod::PAYMENT_FIRST);
        $originalTerms = $this->terms;
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->nextPeriod->startsAt));
        $next = $this->periodStore->admit($this->nextPeriod->subjectKey());
        $this->pump();
        $this->assertNotSame($first->runId(), $next['runId']);
        $this->assertTrue($this->periodStore->access($this->nextPeriod->subjectKey())['access']);
        $this->periodStore->cancel($this->period->subjectKey(), 'original-order-period-only');
        $this->pump();
        $original = $this->store->authority->plan($originalTerms->subjectKey());
        $this->assertSame(
            'verified',
            (new ScopeRepository())->inspect($original->rootScope())['status'],
            $this->progress()
        );
        $this->assertTrue($this->periodStore->access($this->nextPeriod->subjectKey())['access']);
        $this->assertSame(
            'paid',
            $this->store->authority->subject($this->nextPeriod->order->subjectKey(), 'payment')
->state
        );
        $nextCharge = Request::fromArray($this->nextPeriod->order->descriptor('payment')['forward']);
        $this->assertFalse($this->provider->timedLookup($nextCharge)->observation->admissionClosed);
        $this->assertSame(
            2,
            count(array_filter($this->effects(), static fn (string $effect): bool => $effect === 'capture'))
        );
        $this->assertSame(
            1,
            count(array_filter($this->effects(), static fn (string $effect): bool => $effect === 'refund'))
        );
    }

    private function signalPayment(string $request): void
    {
        WorkflowStub::loadRun($this->child('payment')->id, Catalogue::NAMESPACE)
            ->withRequestId($request)
            ->signal('reconcile-funding');
        $this->pump();
    }
}
