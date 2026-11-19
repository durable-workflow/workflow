<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\BillingPeriod;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCheckout;
use DurableWorkflow\Examples\ProviderStateMachines\FundingPayment;
use DurableWorkflow\Examples\ProviderStateMachines\ServicePeriodPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OrderBillingPeriodFixture;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Models\WorkflowRun;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * Genuine native Order association; this does not qualify an installed worker or the full customer matrix.
 *
 * Split with V2OrderBillingPeriodLateTest so each fits one feature shard.
 */
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
}
